<?php
declare(strict_types=1);

/**
 * Fetcher — IMAP 邮件拉取
 *
 * 功能：
 *   - 连接 IMAP（SSL / TLS / 明文）
 *   - 基于 UID 的精确增量拉取（F2/T2）：
 *       首次：取最近 fetch_limit 封（防积压超时）
 *       此后：UID > last_fetched_uid 的新邮件
 *   - 通过 php-mime-mail-parser 解析 MIME（含多部分/附件）
 *   - 降级：若 Composer 包未安装，使用内置 imap_* 函数解析
 *   - 附件用 UUID 命名存储到 storage/attachments/（S6 防路径遍历）
 *   - 错误信息写回 monitored_mailboxes.last_error（D8）
 *   - 使用 OP_READONLY 以只读模式连接，不标记邮件已读
 */
class Fetcher
{
    /** @var resource|false|null */
    private mixed $imap = null;

    public function __construct(private readonly array $mailbox) {}

    // ─────────────────────────────────────────────────────────────
    // 公开接口
    // ─────────────────────────────────────────────────────────────

    /**
     * 建立 IMAP 连接
     */
    public function connect(): bool
    {
        $host    = $this->mailbox['imap_host'];
        $port    = (int)$this->mailbox['imap_port'];
        $enc     = $this->mailbox['imap_encryption'];
        $folder  = $this->mailbox['imap_folder'] ?: 'INBOX';

        $flags = match($enc) {
            'ssl'   => '/imap/ssl',
            'tls'   => '/imap/tls',
            'none'  => '/imap/notls',
            default => '/imap/ssl',
        };

        $mailboxStr = '{' . $host . ':' . $port . $flags . '}' . $folder;

        $password = Helpers::decrypt($this->mailbox['imap_pass_enc']);
        if ($password === false) {
            $this->recordError('IMAP 密码解密失败（请检查 encryption_key 配置）');
            return false;
        }

        imap_errors(); // 清除残余错误
        $this->imap = @imap_open($mailboxStr, $this->mailbox['imap_user'], $password, OP_READONLY);

        if ($this->imap === false) {
            $err = imap_last_error() ?: 'Unknown IMAP connection error';
            $this->recordError($err);
            return false;
        }

        return true;
    }

    /**
     * 拉取新邮件，写入 DB，返回已插入的 mail 行数组
     */
    public function fetchNew(): array
    {
        if (!$this->imap) {
            return [];
        }

        $uids = $this->getUidsToFetch();
        if (empty($uids)) {
            $this->updateFetchedAt();
            return [];
        }

        $insertedMails = [];
        $maxUid        = 0;

        foreach ($uids as $uid) {
            $uid = (int)$uid;
            if ($uid > $maxUid) {
                $maxUid = $uid;
            }

            try {
                $mail = $this->fetchAndParseMail($uid);
                if ($mail !== null) {
                    $insertedMails[] = $mail;
                }
            } catch (\Throwable $e) {
                // T3: 解析失败时跳过单封邮件，不中断整批处理
                error_log(sprintf(
                    'MailGate Fetcher: UID %d parse error: %s',
                    $uid,
                    $e->getMessage()
                ));
            }
        }

        if ($maxUid > 0) {
            $this->updateLastUid($maxUid);
        }
        $this->updateFetchedAt();

        return $insertedMails;
    }

    /**
     * 关闭连接
     */
    public function disconnect(): void
    {
        if ($this->imap !== null && $this->imap !== false) {
            imap_close($this->imap);
            $this->imap = null;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // UID 获取策略
    // ─────────────────────────────────────────────────────────────

    private function getUidsToFetch(): array
    {
        $lastUid    = $this->mailbox['last_fetched_uid'];
        $fetchLimit = max(1, (int)($this->mailbox['fetch_limit'] ?: 100));

        if ($lastUid === null) {
            // 首次拉取：取最后 N 封（按 UID 升序排列后取末尾）
            $allUids = @imap_search($this->imap, 'ALL', SE_UID);
            if (!$allUids) {
                return [];
            }
            sort($allUids, SORT_NUMERIC);
            return array_slice($allUids, -$fetchLimit);
        }

        // 增量拉取：UID > last_fetched_uid
        // UID X:* 検索で X が最大 UID を超えるとサーバーがエラーを返し、
        // c-client がエラーキューに積む → PHP シャットダウン時に Notice が出る。
        // imap_errors() でキューを明示的に消去して抑制する。
        $uids = @imap_search($this->imap, 'UID ' . ((int)$lastUid + 1) . ':*', SE_UID);
        imap_errors();

        if (!empty($uids)) {
            return $uids;
        }

        // フォールバック：UID 増分検索が空の場合、最新 N 封を取得して DB 側で重複排除。
        // INSERT IGNORE + UNIQUE KEY(mailbox_id, imap_uid) により既存メールは無視される。
        $allUids = @imap_search($this->imap, 'ALL', SE_UID);
        imap_errors();
        if (!$allUids) {
            return [];
        }
        sort($allUids, SORT_NUMERIC);
        return array_slice($allUids, -$fetchLimit);
    }

    // ─────────────────────────────────────────────────────────────
    // 邮件解析（优先 php-mime-mail-parser，降级用内置函数）
    // ─────────────────────────────────────────────────────────────

    private function fetchAndParseMail(int $uid): ?array
    {
        // FT_PEEK：只读，不标记为已读
        $header = imap_fetchheader($this->imap, $uid, FT_UID);
        $body   = imap_body($this->imap, $uid, FT_UID | FT_PEEK);
        $raw    = $header . $body;

        if (trim($raw) === '') {
            return null;
        }

        if (class_exists('ZBateson\MailMimeParser\Message')) {
            return $this->parseWithMimeParser($uid, $raw);
        }

        // 降级解析（无 Composer 包时）
        return $this->parseWithImap($uid);
    }

    // ──── 主解析路径：php-mime-mail-parser ────────────────────────

    private function parseWithMimeParser(int $uid, string $raw): ?array
    {
        $message = \ZBateson\MailMimeParser\Message::from($raw, false);

        $messageId   = $this->cleanHeader($message->getMessageId() ?: '') ?: null;
        $fromHeader  = $message->getHeader('from');
        $fromAddress = $fromHeader ? ($fromHeader->getEmail() ?: '') : '';
        $fromName    = $fromHeader ? ($fromHeader->getPersonName() ?: $fromAddress) : $fromAddress;
        $subject     = $message->getSubject() ?: '';
        $toAddress   = $message->getHeaderValue('to') ?: null;
        $cc          = $message->getHeaderValue('cc') ?: null;
        $receivedAt  = $this->parseDate($message->getHeaderValue('date') ?: '');

        $bodyText = $this->truncateBody($message->getTextContent() ?: '');
        $bodyHtml = $this->truncateBody($message->getHtmlContent() ?: '', 5 * 1024 * 1024);

        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO mails
             (mailbox_id, imap_uid, message_id, from_address, from_name,
              to_address, cc, subject, body_text, body_html, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->mailbox['id'],
            $uid,
            $messageId,
            $fromAddress,
            $fromName,
            $toAddress,
            $cc,
            $subject,
            $bodyText ?: null,
            $bodyHtml ?: null,
            $receivedAt,
        ]);

        if ($stmt->rowCount() === 0) {
            return null; // 已存在，跳过
        }

        $mailId = Database::lastInsertId();
        $this->storeAttachments($message, $mailId);

        return Database::fetchOne('SELECT * FROM mails WHERE id = ?', [$mailId]);
    }

    // ──── 降级解析路径：imap_* 内置函数 ──────────────────────────

    private function parseWithImap(int $uid): ?array
    {
        $overview = @imap_fetch_overview($this->imap, (string)$uid, FT_UID);
        if (!$overview) {
            return null;
        }
        $ov = $overview[0];

        $messageId   = isset($ov->message_id) ? $this->cleanHeader($ov->message_id) : null;
        $subject     = $this->decodeHeader($ov->subject ?? '');
        $from        = $ov->from ?? '';
        $fromAddress = Helpers::extractEmail($from);
        $fromName    = $this->extractDisplayName($from);
        $receivedAt  = $this->parseDate($ov->date ?? '');

        // 尝试获取纯文本 body
        $structure = @imap_fetchstructure($this->imap, $uid, FT_UID);
        $bodyText  = $structure ? $this->extractTextBody($uid, $structure) : '';

        $stmt = Database::get()->prepare(
            'INSERT IGNORE INTO mails
             (mailbox_id, imap_uid, message_id, from_address, from_name,
              subject, body_text, received_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->mailbox['id'],
            $uid,
            $messageId ?: null,
            $fromAddress,
            $fromName,
            $subject,
            $this->truncateBody($bodyText) ?: null,
            $receivedAt,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return Database::fetchOne('SELECT * FROM mails WHERE id = ?', [Database::lastInsertId()]);
    }

    // ──── 附件存储 ────────────────────────────────────────────────

    private function storeAttachments(\ZBateson\MailMimeParser\Message $message, int $mailId): void
    {
        $storageBase = dirname(__DIR__)
            . '/storage/attachments/'
            . $this->mailbox['id']
            . '/'
            . date('Y-m');

        foreach ($message->getAllAttachmentParts() as $attachment) {
            $filename  = $attachment->getFilename() ?: 'attachment';
            $mimeType  = $attachment->getContentType() ?: 'application/octet-stream';
            $contentId = $attachment->getContentId() ?: null;

            // S6: UUID 命名，禁止使用原始文件名生成路径
            $ext          = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($filename, PATHINFO_EXTENSION));
            $storedName   = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
            $relativePath = $this->mailbox['id'] . '/' . date('Y-m') . '/' . $storedName;
            $fullPath     = dirname(__DIR__) . '/storage/attachments/' . $relativePath;

            if (!is_dir($storageBase)) {
                mkdir($storageBase, 0750, true);
            }

            // 使用 saveContent 写入二进制内容（避免 getContent() 的字符集转换影响二进制附件）
            $handle = fopen($fullPath, 'wb');
            if ($handle === false) {
                error_log("MailGate: failed to open attachment for writing: $fullPath");
                continue;
            }
            $attachment->saveContent($handle);
            $size = ftell($handle);
            fclose($handle);

            Database::query(
                'INSERT INTO attachments
                 (mail_id, filename, mime_type, size, storage_path, content_id)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$mailId, $filename, $mimeType, $size, $relativePath, $contentId]
            );
        }
    }

    // ──── 辅助：降级 body 提取 ────────────────────────────────────

    private function extractTextBody(int $uid, object $structure): string
    {
        if (!isset($structure->parts)) {
            // 单部分邮件
            $body = imap_fetchbody($this->imap, $uid, '1', FT_UID | FT_PEEK);
            return $this->decodeBodyPart($body, $structure->encoding ?? 0);
        }

        // 多部分：找第一个 text/plain
        foreach ($structure->parts as $i => $part) {
            if (strtoupper($part->subtype ?? '') === 'PLAIN') {
                $body = imap_fetchbody($this->imap, $uid, (string)($i + 1), FT_UID | FT_PEEK);
                return $this->decodeBodyPart($body, $part->encoding ?? 0);
            }
        }

        return '';
    }

    private function decodeBodyPart(string $body, int $encoding): string
    {
        return match($encoding) {
            1 => quoted_printable_decode($body),
            2 => base64_decode($body),
            3 => imap_base64($body),
            4 => imap_qprint($body),
            default => $body,
        };
    }

    // ──── 辅助：头部解析 ──────────────────────────────────────────

    /**
     * 解码 MIME 编码的邮件头（=?charset?enc?text?=）
     */
    private function decodeHeader(string $header): string
    {
        $parts = imap_mime_header_decode($header);
        if (!$parts) {
            return trim($header);
        }

        $result = '';
        foreach ($parts as $part) {
            $charset = strtolower($part->charset ?: 'UTF-8');
            $text    = $part->text;

            if ($charset !== 'utf-8' && $charset !== 'default') {
                $converted = mb_convert_encoding($text, 'UTF-8', $charset);
                $text = $converted !== false ? $converted : $text;
            }
            $result .= $text;
        }

        return trim($result);
    }

    /**
     * 从 From 头提取显示名称
     * 支持格式：「Name <addr>」「"Name" <addr>」「addr」
     */
    private function extractDisplayName(string $fromHeader): string
    {
        if (preg_match('/^"?([^"<>]+?)"?\s*<[^>]+>$/u', trim($fromHeader), $m)) {
            return trim($m[1]);
        }
        return Helpers::extractEmail($fromHeader);
    }

    /**
     * 清理 Message-ID（去除 < > 和空白）
     */
    private function cleanHeader(string $value): string
    {
        return trim(str_replace(['<', '>'], '', $value));
    }

    /**
     * 解析日期字符串为 MySQL DATETIME 格式
     */
    private function parseDate(string $dateStr): string
    {
        if (empty($dateStr)) {
            return date('Y-m-d H:i:s');
        }

        $ts = strtotime($dateStr);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }

    /**
     * 截断超大 body（T3）
     */
    private function truncateBody(string $body, int $maxBytes = 524288): string // 512 KB
    {
        if (strlen($body) <= $maxBytes) {
            return $body;
        }
        return substr($body, 0, $maxBytes) . "\n\n[... 内容超过上限，已截断 ...]";
    }

    // ──── 辅助：DB 更新 ───────────────────────────────────────────

    private function updateLastUid(int $uid): void
    {
        Database::query(
            'UPDATE monitored_mailboxes SET last_fetched_uid = ? WHERE id = ?',
            [$uid, $this->mailbox['id']]
        );
    }

    private function updateFetchedAt(): void
    {
        Database::query(
            'UPDATE monitored_mailboxes
             SET last_fetched_at = NOW(), last_error = NULL, last_error_at = NULL
             WHERE id = ?',
            [$this->mailbox['id']]
        );
    }

    /**
     * 将错误信息写回 DB（D8：管理员可在界面查看）
     */
    private function recordError(string $error): void
    {
        Database::query(
            'UPDATE monitored_mailboxes
             SET last_error = ?, last_error_at = NOW()
             WHERE id = ?',
            [mb_substr($error, 0, 1000), $this->mailbox['id']]
        );
    }
}
