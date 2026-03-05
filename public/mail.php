<?php
declare(strict_types=1);

/**
 * mail.php — メール詳細
 *
 * アクセス制御：
 *   - notification.user_id = ログインユーザーの検証（GET パラメータ n= で指定）
 *   - ページ表示時に is_read = 1 に更新
 *
 * セキュリティ（S1）：
 *   - HTML 本文は <iframe sandbox=""> の srcdoc 属性に埋め込み
 *   - sandbox="" = allow-same-origin も allow-scripts も無し → XSS 完全隔離
 */

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'メール詳細';
$user      = Auth::getCurrentUser();

// ── notification ID 取得 & アクセス制御 ────────────────────────────
$nid = (int)($_GET['n'] ?? 0);
if ($nid <= 0) {
    Helpers::redirect('/dashboard.php');
}

// 自分の通知のみ参照可（mail_id ではなく notification_id で照合）
$notification = Database::fetchOne(
    'SELECT n.*,
            m.id            AS mail_id,
            m.message_id,
            m.from_address,
            m.from_name,
            m.to_address,
            m.cc,
            m.subject,
            m.body_text,
            m.body_html,
            m.received_at,
            mb.id           AS mailbox_id,
            mb.label        AS mailbox_label,
            mb.email_address AS mailbox_email
     FROM notifications n
     INNER JOIN mails m              ON m.id  = n.mail_id
     INNER JOIN monitored_mailboxes mb ON mb.id = m.mailbox_id
     WHERE n.id = ? AND n.user_id = ?',
    [$nid, (int)$user['id']]
);

if ($notification === null) {
    // 他ユーザーの通知、または存在しない → 403 相当
    http_response_code(403);
    include __DIR__ . '/partials/header.php';
    echo '<div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> アクセス権限がありません。</div>';
    include __DIR__ . '/partials/footer.php';
    exit;
}

// ── 既読にする ─────────────────────────────────────────────────────
if (!$notification['is_read']) {
    Database::query(
        'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?',
        [$nid]
    );
}

// ── 添付ファイル一覧 ───────────────────────────────────────────────
$attachments = Database::fetchAll(
    'SELECT id, filename, mime_type, size, content_id
     FROM attachments
     WHERE mail_id = ?
     ORDER BY id ASC',
    [(int)$notification['mail_id']]
);

$pageTitle = $notification['subject'] ?: '（件名なし）';

// ── 返信用データ準備 ──────────────────────────────────────────────
$replyDate    = date('Y/m/d H:i', strtotime($notification['received_at']));
$replyFrom    = $notification['from_name']
    ? $notification['from_name'] . ' <' . $notification['from_address'] . '>'
    : $notification['from_address'];
$originalText = $notification['body_text'] ?: strip_tags(str_replace(
    ['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $notification['body_html'] ?? ''
));
$quotedLines  = array_map(fn($line) => '> ' . $line, explode("\n", rtrim($originalText)));
$replySubject = 'Re: ' . ($notification['subject'] ?: '');
$replyBody    = "\n\n---- 元のメッセージ ----\n"
    . "差出人: {$replyFrom}\n"
    . "日時: {$replyDate}\n"
    . "件名: " . ($notification['subject'] ?: '') . "\n\n"
    . implode("\n", $quotedLines);

// ユーザーのメール送信設定確認
$userSmtpRow     = Database::fetchOne(
    'SELECT use_mail, smtp_host, from_address, from_name FROM user_smtp_settings WHERE user_id = ?',
    [(int)$user['id']]
);
// 設定あり → モーダル（Web内返信）、設定なし → mailto:（メールソフト起動）
$userHasMailSetting = ($userSmtpRow !== null);
$userUseMail        = $userHasMailSetting && (int)$userSmtpRow['use_mail'] === 1;
$userHasSmtp        = $userHasMailSetting && !$userUseMail && ($userSmtpRow['smtp_host'] ?? '') !== '';

// 差出人の選択肢（ユーザー自身 or 監視メールボックス）
$userFromAddress = (!empty($userSmtpRow['from_address'])) ? $userSmtpRow['from_address'] : $user['email'];
$userFromName    = (!empty($userSmtpRow['from_name']))    ? $userSmtpRow['from_name']    : $user['name'];

// mailto: リンク（設定なしの場合のフォールバック）
$replyHref = 'mailto:' . rawurlencode($notification['from_address'])
    . '?subject=' . rawurlencode($replySubject)
    . '&cc='      . rawurlencode($notification['mailbox_email'])
    . '&body='    . rawurlencode($replyBody);

// ── POST ハンドラ ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        Helpers::redirect('/mail.php?n=' . $nid);
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'trash') {
        Database::query(
            'UPDATE notifications SET is_trashed=1, trashed_at=NOW() WHERE id=?',
            [$nid]
        );
        Helpers::redirect('/dashboard.php');
    }

    if ($action === 'reply') {
        $replyTo       = trim($_POST['reply_to']           ?? '');
        $replySubjectP = trim($_POST['reply_subject']      ?? '');
        $replyCCP      = trim($_POST['reply_cc']           ?? '');
        $replyBodyP    = trim($_POST['reply_body']         ?? '');
        $replyFromAddr = trim($_POST['reply_from_address'] ?? '');
        $inReplyTo     = $notification['message_id'] ?? '';

        // 差出人は許可リスト内のアドレスのみ受け付ける
        $allowedFrom = [
            $userFromAddress                     => $userFromName,
            $notification['mailbox_email']       => $notification['mailbox_label'],
        ];
        if (!array_key_exists($replyFromAddr, $allowedFrom)) {
            $replyFromAddr = $userFromAddress;
        }
        $replyFromName = $allowedFrom[$replyFromAddr];

        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || $replyBodyP === '') {
            Helpers::redirect('/mail.php?n=' . $nid . '&reply_err=1');
        }

        $ok = Mailer::sendReply(
            $user, $replyTo, $replySubjectP, $replyBodyP,
            $replyCCP, $inReplyTo, $replyFromAddr, $replyFromName
        );
        Helpers::redirect('/mail.php?n=' . $nid . ($ok ? '&replied=1' : '&reply_err=1'));
    }
}

include __DIR__ . '/partials/header.php';
?>

<!-- パンくずリスト -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item">
            <a href="/dashboard.php"><i class="bi bi-house-door"></i> ダッシュボード</a>
        </li>
        <li class="breadcrumb-item active">メール詳細</li>
    </ol>
</nav>

<?php if (isset($_GET['replied'])): ?>
<div class="alert alert-success alert-autofade py-2">
    <i class="bi bi-check-circle"></i> 返信を送信しました。
</div>
<?php elseif (isset($_GET['reply_err'])): ?>
<div class="alert alert-danger alert-autofade py-2">
    <i class="bi bi-exclamation-triangle"></i> 返信の送信に失敗しました。
    <a href="/my-settings.php#smtp" class="alert-link">送信設定</a>を確認してください。
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── メインカード ── -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">

            <!-- カードヘッダー：件名 + アクション -->
            <div class="card-header bg-white py-3 d-flex align-items-start gap-3 flex-wrap">
                <div class="flex-grow-1">
                    <h5 class="mb-1">
                        <?= Helpers::e($notification['subject'] ?: '（件名なし）') ?>
                    </h5>
                    <span class="badge bg-secondary">
                        <i class="bi bi-inbox"></i>
                        <?= Helpers::e($notification['mailbox_label']) ?>
                        (<?= Helpers::e($notification['mailbox_email']) ?>)
                    </span>
                </div>
                <div class="d-flex flex-column flex-sm-row gap-2 flex-wrap">
                    <a href="/my-rules.php?nid=<?= (int)$nid ?>"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-funnel"></i> ルール設定
                    </a>
                    <form method="post" action="/mail.php?n=<?= (int)$nid ?>" class="m-0">
                        <input type="hidden" name="action" value="trash">
                        <input type="hidden" name="csrf_token"
                               value="<?= Helpers::e(Auth::csrfToken()) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                data-confirm="このメールをゴミ箱に移動しますか？">
                            <i class="bi bi-trash3"></i> 削除
                        </button>
                    </form>
                    <?php if ($userHasMailSetting): ?>
                    <button type="button"
                            class="btn btn-sm btn-outline-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#replyModal">
                        <i class="bi bi-reply"></i> 返信
                    </button>
                    <?php else: ?>
                    <a href="<?= Helpers::e($replyHref) ?>"
                       class="btn btn-sm btn-outline-primary"
                       title="メールソフトで返信（送信設定を行うとここから直接送信できます）">
                        <i class="bi bi-reply"></i> 返信
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="history.length > 1 ? history.back() : location.href='/dashboard.php'">
                        <i class="bi bi-arrow-left"></i> 戻る
                    </button>
                </div>
            </div>

            <!-- メタ情報テーブル -->
            <div class="card-body pb-0">
                <table class="table table-borderless table-sm mail-header-table mb-3">
                    <tbody>
                        <tr>
                            <th>差出人</th>
                            <td>
                                <?= Helpers::e($notification['from_name']) ?>
                                &lt;<?= Helpers::e($notification['from_address']) ?>&gt;
                            </td>
                        </tr>
                        <?php if ($notification['to_address']): ?>
                        <tr>
                            <th>宛先</th>
                            <td class="text-muted"><?= Helpers::e($notification['to_address']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($notification['cc']): ?>
                        <tr>
                            <th>CC</th>
                            <td class="text-muted"><?= Helpers::e($notification['cc']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>受信日時</th>
                            <td>
                                <?= Helpers::e(date('Y年m月d日 H:i', strtotime($notification['received_at']))) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!empty($attachments)): ?>
                <!-- 添付ファイル一覧 -->
                <div class="mb-3 p-3 bg-light rounded">
                    <p class="small fw-semibold text-muted mb-2">
                        <i class="bi bi-paperclip"></i>
                        添付ファイル（<?= count($attachments) ?>件）
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($attachments as $att): ?>
                        <?php
                            // 内联附件（CID 付き）はここでは非表示
                            if ($att['content_id'] && $notification['body_html']) {
                                continue;
                            }
                            $size = $att['size'] < 1024
                                ? $att['size'] . ' B'
                                : ($att['size'] < 1048576
                                    ? round($att['size'] / 1024, 1) . ' KB'
                                    : round($att['size'] / 1048576, 1) . ' MB');
                        ?>
                        <div class="d-flex align-items-center border rounded px-2 py-1 bg-white"
                             title="<?= Helpers::e($att['filename']) ?>">
                            <i class="bi bi-file-earmark me-1 text-muted"></i>
                            <span class="small text-truncate" style="max-width:180px">
                                <?= Helpers::e($att['filename']) ?>
                            </span>
                            <span class="text-muted small ms-1">(<?= $size ?>)</span>
                            <a href="/download.php?id=<?= (int)$att['id'] ?>"
                               class="ms-2 btn btn-sm btn-outline-secondary py-0 px-1"
                               title="ダウンロード" download>
                                <i class="bi bi-download" style="font-size:.8rem"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- メール本文 -->
            <div class="card-body pt-0">
                <hr class="mt-0">

                <?php if ($notification['body_html']): ?>
                <!-- HTML 本文タブ切替 -->
                <ul class="nav nav-tabs mb-3" id="mailBodyTab">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab"
                                data-bs-target="#tabHtml" type="button">
                            <i class="bi bi-filetype-html"></i> HTML
                        </button>
                    </li>
                    <?php if ($notification['body_text']): ?>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab"
                                data-bs-target="#tabText" type="button">
                            <i class="bi bi-file-text"></i> テキスト
                        </button>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="tab-content">
                    <!-- HTML 本文: sandbox="" で完全隔離（S1）-->
                    <div class="tab-pane fade show active" id="tabHtml">
                        <iframe
                            id="mail-body-frame"
                            class="mail-iframe"
                            sandbox=""
                            srcdoc="<?= htmlspecialchars(
                                $notification['body_html'],
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            ) ?>"
                            title="メール本文（サンドボックス）"
                            scrolling="auto">
                        </iframe>
                    </div>
                    <?php if ($notification['body_text']): ?>
                    <div class="tab-pane fade" id="tabText">
                        <pre class="bg-light p-3 rounded"
                             style="white-space:pre-wrap;word-break:break-word;font-size:.85rem;max-height:600px;overflow:auto"
                        ><?= Helpers::e($notification['body_text']) ?></pre>
                    </div>
                    <?php endif; ?>
                </div>

                <?php elseif ($notification['body_text']): ?>
                <!-- テキスト本文のみ -->
                <pre class="bg-light p-3 rounded"
                     style="white-space:pre-wrap;word-break:break-word;font-size:.85rem;max-height:600px;overflow:auto"
                ><?= Helpers::e($notification['body_text']) ?></pre>

                <?php else: ?>
                <p class="text-muted text-center py-4">
                    <i class="bi bi-file-earmark-x display-6 d-block mb-2"></i>
                    本文がありません
                </p>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- ── 返信モーダル ── -->
<div class="modal fade" id="replyModal" tabindex="-1"
     aria-labelledby="replyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="replyModalLabel">
                    <i class="bi bi-reply"></i> 返信
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="/mail.php?n=<?= (int)$nid ?>" novalidate>
                <input type="hidden" name="action"       value="reply">
                <input type="hidden" name="csrf_token"   value="<?= Helpers::e(Auth::csrfToken()) ?>">
                <input type="hidden" name="reply_subject" value="<?= Helpers::e($replySubject) ?>">

                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="form-label text-muted small mb-1">差出人</label>
                            <select name="reply_from_address" class="form-select form-select-sm">
                                <option value="<?= Helpers::e($userFromAddress) ?>">
                                    <?= Helpers::e($userFromName) ?>
                                    &lt;<?= Helpers::e($userFromAddress) ?>&gt;
                                    （自分）
                                </option>
                                <?php if ($userFromAddress !== $notification['mailbox_email']): ?>
                                <option value="<?= Helpers::e($notification['mailbox_email']) ?>">
                                    <?= Helpers::e($notification['mailbox_label']) ?>
                                    &lt;<?= Helpers::e($notification['mailbox_email']) ?>&gt;
                                    （監視メールボックス）
                                </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small mb-1">宛先</label>
                            <input type="email" name="reply_to"
                                   class="form-control form-control-sm"
                                   value="<?= Helpers::e($notification['from_address']) ?>"
                                   required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted small mb-1">CC</label>
                            <input type="text" name="reply_cc"
                                   class="form-control form-control-sm"
                                   value="<?= Helpers::e($notification['mailbox_email']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label text-muted small mb-1">件名</label>
                            <div class="form-control form-control-sm bg-light">
                                <?= Helpers::e($replySubject) ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small mb-1">本文</label>
                        <textarea name="reply_body" class="form-control" rows="12"
                                  style="font-size:.85rem;font-family:monospace" required
                        ><?= Helpers::e($replyBody) ?></textarea>
                    </div>

                    <div class="text-muted small">
                        <i class="bi bi-info-circle"></i>
                        送信元: <strong><?= Helpers::e($user['email']) ?></strong>
                        <?php if ($userHasSmtp): ?>
                            <span class="text-success ms-1">（個人SMTP設定を使用）</span>
                        <?php elseif ($userUseMail): ?>
                            <span class="ms-1">（mail()/sendmail を使用）</span>
                        <?php endif; ?>
                        — <a href="/my-settings.php#smtp" class="text-muted small">送信設定を変更</a>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send"></i> 送信する
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
