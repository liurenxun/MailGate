<?php
declare(strict_types=1);

/**
 * Mailer — 邮件发送封装
 *
 * 支持两种发送方式（通过 system_settings.use_php_mail 切换）：
 *   0 = PHPMailer SMTP（默认，推荐用于生产）
 *   1 = PHP mail()（备用，适合简单环境）
 *
 * 公开接口（Phase 1/2 保持一致）：
 *   Mailer::sendSetupEmail($user, $token)
 *   Mailer::sendPasswordResetEmail($user, $token)
 *   Mailer::sendNotification($user, $mail, $mailbox, $notificationId)
 *
 * 安全（S5）：
 *   使用 mail() 时，subject/to 中的换行符会被过滤，防止邮件头注入。
 *   PHPMailer 内部已处理此问题。
 */
class Mailer
{
    // ─────────────────────────────────────────────────────────────
    // 业务邮件方法（对外接口）
    // ─────────────────────────────────────────────────────────────

    /**
     * 发送账号开通邮件（首次设密 Setup Token）
     */
    public static function sendSetupEmail(array $user, string $token): bool
    {
        $config  = Database::config();
        $baseUrl = rtrim($config['base_url'], '/');
        $appName = $config['app_name'] ?? 'MailGate';
        $link    = $baseUrl . '/index.php?setup=' . urlencode($token);

        $to      = $user['notify_email'] ?? $user['email'];
        $name    = $user['name'];
        $subject = "【{$appName}】アカウントが作成されました";

        $body = self::dedent(<<<TEXT
            {$name} 様、アカウントが作成されました。

            以下のリンクから初回ログインしてパスワードを設定してください。

            ▶ {$link}

            このリンクの有効期限は24時間です。

            ────────────────────────────────
            このメールは {$appName} システムから自動送信されています。
            TEXT);

        return self::send($to, $subject, $body);
    }

    /**
     * 発送密码重置邮件（Forgot Password Reset Token）
     */
    public static function sendPasswordResetEmail(array $user, string $token): bool
    {
        $config  = Database::config();
        $baseUrl = rtrim($config['base_url'], '/');
        $appName = $config['app_name'] ?? 'MailGate';
        $link    = $baseUrl . '/index.php?reset=' . urlencode($token);

        $to      = $user['notify_email'] ?? $user['email'];
        $name    = $user['name'];
        $subject = "【{$appName}】パスワードリセットのご案内";

        $body = self::dedent(<<<TEXT
            {$name} 様

            パスワードリセットのリクエストを受け付けました。
            以下のリンクから新しいパスワードを設定してください。

            ▶ {$link}

            このリンクの有効期限は1時間です。
            心当たりがない場合は、このメールを無視してください。

            ────────────────────────────────
            このメールは {$appName} システムから自動送信されています。
            TEXT);

        return self::send($to, $subject, $body);
    }

    /**
     * 发送测试邮件（管理者システム設定画面から呼ぶ）
     *
     * @param string $to 送信先メールアドレス
     * @return bool 送信成功時 true
     */
    public static function sendTestEmail(string $to): bool
    {
        $config  = Database::config();
        $appName = $config['app_name'] ?? 'MailGate';
        $now     = date('Y-m-d H:i:s');

        $subject = "【{$appName}】メール送信テスト";
        $body = self::dedent(<<<TEXT
            これは {$appName} からのメール送信テストです。

            このメールが届いていれば、メール送信設定は正常に機能しています。

            送信日時: {$now}

            ────────────────────────────────
            このメールは {$appName} システムから自動送信されています。
            TEXT);

        return self::send($to, $subject, $body);
    }

    /**
     * 発送新邮件到达通知（Notifier 调用）
     *
     * @param array $user           users 表行
     * @param array $mail           mails 表行（或含相同字段的子集）
     * @param array $mailbox        monitored_mailboxes 表行（或含 label/email_address）
     * @param int   $notificationId notifications.id（用于生成详情链接）
     */
    public static function sendNotification(
        array $user,
        array $mail,
        array $mailbox,
        int $notificationId
    ): bool {
        $config  = Database::config();
        $baseUrl = rtrim($config['base_url'], '/');
        $appName = $config['app_name'] ?? 'MailGate';

        $to          = $user['notify_email'] ?? $user['email'];
        $name        = $user['name'];
        $subject     = "【{$appName} / {$mailbox['label']}】{$mail['subject']}";
        $link        = $baseUrl . '/mail.php?n=' . $notificationId;
        $receivedAt  = date('Y-m-d H:i', strtotime($mail['received_at']));

        $preview = self::bodyPreview($mail);
        $previewSection = '';
        if ($preview !== '') {
            $previewSection = "\n\n─ 本文（冒頭）──────────────────────\n{$preview}\n────────────────────────────────";
        }

        $body = self::dedent(<<<TEXT
            {$name} 様

            「{$mailbox['label']}」({$mailbox['email_address']}) に新しいメールが届いています。

            差出人 : {$mail['from_name']} <{$mail['from_address']}>
            件名   : {$mail['subject']}
            受信日 : {$receivedAt}{$previewSection}

            ▶ 詳細を確認する
              {$link}

            ────────────────────────────────
            このメールは {$appName} システムから自動送信されています。
            TEXT);

        return self::send($to, $subject, $body);
    }

    // ─────────────────────────────────────────────────────────────
    // 内部发送：根据 system_settings 选择 PHPMailer 或 mail()
    // ─────────────────────────────────────────────────────────────

    private static function send(string $to, string $subject, string $body): bool
    {
        $settings = self::loadSmtpSettings();

        if ($settings['use_php_mail']) {
            return self::sendViaMail($to, $subject, $body, $settings);
        }

        return self::sendViaSmtp($to, $subject, $body, $settings);
    }

    // ──── PHPMailer SMTP ──────────────────────────────────────────

    private static function sendViaSmtp(
        string $to,
        string $subject,
        string $body,
        array $settings
    ): bool {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // PHPMailer 未安装，降级到 mail()
            return self::sendViaMail($to, $subject, $body, $settings);
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true); // true = exceptions

            $mail->isSMTP();
            $mail->Host       = $settings['smtp_host'];
            $mail->Port       = (int)$settings['smtp_port'];
            $mail->SMTPAuth   = !empty($settings['smtp_user']);
            $mail->Username   = $settings['smtp_user'];
            $mail->Password   = $settings['smtp_pass'];
            $mail->SMTPSecure = match(strtolower($settings['smtp_encryption'])) {
                'ssl'   => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
                'tls'   => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };

            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';

            $mail->setFrom($settings['smtp_from_address'], $settings['smtp_from_name']);
            $mail->addAddress($to);

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->isHTML(false);

            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('MailGate Mailer SMTP error: ' . $e->getMessage());
            return false;
        }
    }

    // ──── PHP mail() ──────────────────────────────────────────────

    private static function sendViaMail(
        string $to,
        string $subject,
        string $body,
        array $settings
    ): bool {
        // S5: 过滤换行符防邮件头注入
        $to      = self::sanitizeHeader($to);
        $subject = self::sanitizeHeader($subject);

        $fromAddress = $settings['smtp_from_address'] ?: 'noreply@localhost';
        $fromName    = $settings['smtp_from_name'] ?: 'MailGate';
        $from        = self::sanitizeHeader("{$fromName} <{$fromAddress}>");

        $headers = "From: {$from}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n";

        if (function_exists('mb_send_mail')) {
            mb_language('Japanese');
            mb_internal_encoding('UTF-8');
            return mb_send_mail($to, $subject, $body, $headers);
        }

        return mail($to, $subject, $body, $headers);
    }

    // ─────────────────────────────────────────────────────────────
    // SMTP 设置加载
    // ─────────────────────────────────────────────────────────────

    private static function loadSmtpSettings(): array
    {
        $defaults = [
            'use_php_mail'       => true,
            'smtp_host'          => '',
            'smtp_port'          => 587,
            'smtp_encryption'    => 'tls',
            'smtp_user'          => '',
            'smtp_pass'          => '',
            'smtp_from_address'  => 'noreply@localhost',
            'smtp_from_name'     => 'MailGate',
        ];

        try {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM system_settings
                 WHERE `key` IN (
                     'use_php_mail','smtp_host','smtp_port','smtp_encryption',
                     'smtp_user','smtp_pass_enc','smtp_from_address','smtp_from_name'
                 )"
            );
        } catch (\Throwable) {
            // DB 未初始化或不可用时，使用 mail() 兜底
            return $defaults;
        }

        $kv = [];
        foreach ($rows as $row) {
            $kv[$row['key']] = $row['value'];
        }

        // 解密 SMTP 密码
        $smtpPass = '';
        if (!empty($kv['smtp_pass_enc'])) {
            $decrypted = Helpers::decrypt($kv['smtp_pass_enc']);
            $smtpPass  = $decrypted !== false ? $decrypted : '';
        }

        // 若 SMTP 未配置主机，强制使用 mail()
        $useMail = !empty($kv['use_php_mail'])
            ? (bool)(int)$kv['use_php_mail']
            : empty($kv['smtp_host']);

        return [
            'use_php_mail'      => $useMail,
            'smtp_host'         => $kv['smtp_host'] ?? '',
            'smtp_port'         => (int)($kv['smtp_port'] ?? 587),
            'smtp_encryption'   => $kv['smtp_encryption'] ?? 'tls',
            'smtp_user'         => $kv['smtp_user'] ?? '',
            'smtp_pass'         => $smtpPass,
            'smtp_from_address' => $kv['smtp_from_address'] ?? 'noreply@localhost',
            'smtp_from_name'    => $kv['smtp_from_name'] ?? 'MailGate',
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 工具
    // ─────────────────────────────────────────────────────────────

    /** 移除邮件头中的换行符（防头注入，S5） */
    private static function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    /**
     * 从邮件正文提取约 100 字的预览文本
     *
     * 优先使用 body_text；若为空则 strip_tags(body_html)。
     * 压缩连续空白为单个空格，超出长度时追加省略号。
     */
    private static function bodyPreview(array $mail, int $maxLen = 100): string
    {
        $text = trim($mail['body_text'] ?? '');
        if ($text === '') {
            $text = trim(strip_tags($mail['body_html'] ?? ''));
        }
        if ($text === '') {
            return '';
        }
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text, 'UTF-8') <= $maxLen) {
            return $text;
        }
        return mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
    }

    /** 去除 heredoc 每行前导空格（用于格式化邮件正文） */
    private static function dedent(string $text): string
    {
        $lines = explode("\n", $text);
        $trimmed = array_map(fn($line) => ltrim($line, " \t"), $lines);
        return implode("\n", $trimmed);
    }
}
