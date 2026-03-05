<?php
declare(strict_types=1);

/**
 * my-settings.php — アカウント設定
 *
 * 機能：
 *   - 通知メールアドレスの変更
 *   - パスワードの変更
 */

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'アカウント設定';
$user      = Auth::getCurrentUser();
$errors    = [];
$success   = '';

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'セキュリティエラーが発生しました。';
    } else {
        $action = $_POST['action'] ?? '';

        // ── 通知メールアドレス変更 ─────────────────────────────────
        if ($action === 'update_notify_email') {
            $notifyEmail = trim($_POST['notify_email'] ?? '');

            if ($notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = '有効なメールアドレスを入力してください。';
            } else {
                // 空文字 = users.email を使う（NULL に相当）
                Database::query(
                    'UPDATE users SET notify_email = ? WHERE id = ?',
                    [$notifyEmail ?: null, (int)$user['id']]
                );
                $success = '通知メールアドレスを更新しました。';
                // キャッシュ破棄
                $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [(int)$user['id']]);
            }
        }

        // ── パスワード変更 ─────────────────────────────────────────
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $pw1     = $_POST['new_password']     ?? '';
            $pw2     = $_POST['new_password_confirm'] ?? '';

            if (!password_verify($current, $user['password_hash'])) {
                $errors[] = '現在のパスワードが正しくありません。';
            } elseif ($pw1 !== $pw2) {
                $errors[] = '新しいパスワードが一致しません。';
            } elseif (!Helpers::validatePassword($pw1)) {
                $errors[] = '新しいパスワードは8文字以上で、英字と数字を含めてください。';
            } else {
                $hash = password_hash($pw1, PASSWORD_BCRYPT, ['cost' => 12]);
                Database::query(
                    'UPDATE users SET password_hash = ? WHERE id = ?',
                    [$hash, (int)$user['id']]
                );
                $success = 'パスワードを変更しました。';
            }
        }

        // ── メール送信設定保存 ────────────────────────────────────
        if ($action === 'save_smtp') {
            $sendMethod = $_POST['send_method'] ?? 'mailto';

            // 「メールソフトを起動する」選択時はレコードを削除して終了
            if ($sendMethod === 'mailto') {
                Database::query('DELETE FROM user_smtp_settings WHERE user_id = ?', [(int)$user['id']]);
                $success  = '設定をクリアしました。返信ボタンはメールソフトを起動します。';
                $userSmtp = null;
                // 後続の処理をスキップ
                goto save_smtp_done;
            }

            $useMail     = $sendMethod === 'mail' ? 1 : 0;
            $smtpHost    = trim($_POST['smtp_host']      ?? '');
            $smtpPort    = (int)($_POST['smtp_port']     ?? 587);
            $smtpEnc     = $_POST['smtp_encryption']     ?? 'tls';
            $smtpUser    = trim($_POST['smtp_user']      ?? '');
            $smtpPass    = $_POST['smtp_pass']           ?? '';
            $fromAddress = trim($_POST['from_address']   ?? '');
            $fromName    = trim($_POST['from_name']      ?? '');

            $validEnc = ['tls', 'ssl', 'none'];
            if (!in_array($smtpEnc, $validEnc, true)) {
                $errors[] = '暗号化方式が無効です。';
            } elseif ($fromAddress !== '' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
                $errors[] = '送信元アドレスの形式が正しくありません。';
            } elseif ($useMail === 0 && $smtpHost === '') {
                $errors[] = 'SMTP を使用する場合は SMTP ホストを入力してください。';
            } else {
                $existing = Database::fetchOne(
                    'SELECT smtp_pass_enc FROM user_smtp_settings WHERE user_id = ?',
                    [(int)$user['id']]
                );
                $passEnc = $existing['smtp_pass_enc'] ?? null;
                if ($smtpPass !== '') {
                    $passEnc = Helpers::encrypt($smtpPass);
                }

                Database::query(
                    'INSERT INTO user_smtp_settings
                         (user_id, smtp_host, smtp_port, smtp_encryption, smtp_user, smtp_pass_enc, use_mail, from_address, from_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                         smtp_host=VALUES(smtp_host), smtp_port=VALUES(smtp_port),
                         smtp_encryption=VALUES(smtp_encryption), smtp_user=VALUES(smtp_user),
                         smtp_pass_enc=VALUES(smtp_pass_enc), use_mail=VALUES(use_mail),
                         from_address=VALUES(from_address), from_name=VALUES(from_name)',
                    [(int)$user['id'], $smtpHost, $smtpPort, $smtpEnc, $smtpUser, $passEnc, $useMail, $fromAddress, $fromName]
                );
                $success  = 'メール送信設定を保存しました。';
                $userSmtp = Database::fetchOne('SELECT * FROM user_smtp_settings WHERE user_id = ?', [(int)$user['id']]);
            }
            save_smtp_done:
        }

        // ── メール送信設定クリア（後方互換のため残す） ───────────
        if ($action === 'clear_smtp') {
            Database::query('DELETE FROM user_smtp_settings WHERE user_id = ?', [(int)$user['id']]);
            $success = 'メール送信設定をクリアしました。';
        }

        // ── 返信テスト送信 ────────────────────────────────────────
        if ($action === 'test_reply') {
            $ok = Mailer::sendReply(
                $user,
                $user['email'],
                'MailGate 返信機能テスト',
                "これは MailGate からの返信機能テストメールです。\n\n送信日時: " . date('Y-m-d H:i:s')
            );
            if ($ok) {
                $success = "テストメールを {$user['email']} に送信しました。";
            } else {
                $errors[] = 'テスト送信に失敗しました。設定を確認してください。';
            }
        }
    }
}

// ── メール送信設定を読み込む ────────────────────────────────────────
$userSmtp = Database::fetchOne(
    'SELECT * FROM user_smtp_settings WHERE user_id = ?',
    [(int)$user['id']]
);

include __DIR__ . '/partials/header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-7 col-xl-6">

<h4 class="mb-4"><i class="bi bi-gear"></i> アカウント設定</h4>

<?php if ($errors): ?>
<div class="alert alert-danger alert-autofade py-2">
    <?php foreach ($errors as $e): ?>
        <div><i class="bi bi-exclamation-triangle"></i> <?= Helpers::e($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-autofade py-2">
    <i class="bi bi-check-circle"></i> <?= Helpers::e($success) ?>
</div>
<?php endif; ?>

<!-- ── ユーザー情報（読み取り専用） ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">アカウント情報</div>
    <div class="card-body">
        <table class="table table-borderless table-sm mb-0">
            <tr>
                <th class="text-muted" style="width:140px">氏名</th>
                <td><?= Helpers::e($user['name']) ?></td>
            </tr>
            <tr>
                <th class="text-muted">ログインID</th>
                <td><?= Helpers::e($user['email']) ?></td>
            </tr>
            <tr>
                <th class="text-muted">権限</th>
                <td>
                    <?php if ($user['role'] === 'admin'): ?>
                        <span class="badge bg-warning text-dark">管理者</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">一般</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- ── 通知メールアドレス ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">通知メールアドレス</div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            空欄の場合はログインID（<?= Helpers::e($user['email']) ?>）に通知が届きます。<br>
            別のアドレスに通知を転送したい場合に設定してください。
        </p>
        <form method="post" action="/my-settings.php" novalidate>
            <input type="hidden" name="action"     value="update_notify_email">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
            <div class="input-group">
                <input type="email" name="notify_email"
                       class="form-control"
                       value="<?= Helpers::e($user['notify_email'] ?? '') ?>"
                       placeholder="例: another@example.com（空欄=ログインIDに送信）"
                       autocomplete="email">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> 保存
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── パスワード変更 ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">パスワード変更</div>
    <div class="card-body">
        <form method="post" action="/my-settings.php" novalidate>
            <input type="hidden" name="action"     value="change_password">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

            <div class="mb-3">
                <label class="form-label" for="cur_pw">現在のパスワード</label>
                <input type="password" id="cur_pw" name="current_password"
                       class="form-control" autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_pw1">新しいパスワード</label>
                <input type="password" id="new_pw1" name="new_password"
                       class="form-control" autocomplete="new-password" required>
                <div class="form-text">8文字以上、英字と数字を含めること</div>
            </div>
            <div class="mb-4">
                <label class="form-label" for="new_pw2">新しいパスワード（確認）</label>
                <input type="password" id="new_pw2" name="new_password_confirm"
                       class="form-control" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-key"></i> パスワードを変更する
            </button>
        </form>
    </div>
</div>

<!-- ── メール送信設定（返信機能用） ── -->
<?php
$encOptions   = [
    'tls'  => 'TLS (STARTTLS) — ポート 587',
    'ssl'  => 'SSL/TLS — ポート 465',
    'none' => '暗号化なし — ポート 25',
];
$hasSmtpPass  = !empty($userSmtp['smtp_pass_enc']);
$currentMethod = $userSmtp ? ((int)$userSmtp['use_mail'] === 1 ? 'mail' : 'smtp') : 'mailto';
?>
<div class="card border-0 shadow-sm mt-4" id="smtp">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-envelope-gear"></i> メール送信設定
        <span class="text-muted fw-normal small ms-2">（返信機能で使用）</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            未設定の場合、「返信」ボタンはメールソフトを起動します（従来通り）。<br>
            MailGate 内で直接返信を送りたい場合はここで設定してください。
        </p>

        <form method="post" action="/my-settings.php" novalidate id="smtpForm">
            <input type="hidden" name="action"     value="save_smtp">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

            <!-- 送信方式 -->
            <div class="mb-4">
                <label class="form-label fw-semibold">返信方式</label>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="send_method"
                           id="method_mailto" value="mailto"
                           <?= $currentMethod === 'mailto' ? 'checked' : '' ?>
                           onchange="toggleSmtpFields()">
                    <label class="form-check-label" for="method_mailto">
                        メールソフトを起動する
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="send_method"
                           id="method_mail" value="mail"
                           <?= $currentMethod === 'mail' ? 'checked' : '' ?>
                           onchange="toggleSmtpFields()">
                    <label class="form-check-label" for="method_mail">
                        PHP <code>mail()</code>（サーバーの sendmail 経由）
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="send_method"
                           id="method_smtp" value="smtp"
                           <?= $currentMethod === 'smtp' ? 'checked' : '' ?>
                           onchange="toggleSmtpFields()">
                    <label class="form-check-label" for="method_smtp">
                        独自 SMTP サーバーを使用する
                    </label>
                </div>
            </div>

            <!-- SMTP フィールド（独自 SMTP 選択時のみ表示） -->
            <div id="smtpFields" <?= $currentMethod !== 'smtp' ? 'style="display:none"' : '' ?>>
                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label">SMTP ホスト</label>
                        <input type="text" name="smtp_host" class="form-control"
                               value="<?= Helpers::e($userSmtp['smtp_host'] ?? '') ?>"
                               placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ポート</label>
                        <input type="number" name="smtp_port" class="form-control"
                               value="<?= (int)($userSmtp['smtp_port'] ?? 587) ?>"
                               min="1" max="65535">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">暗号化</label>
                        <select name="smtp_encryption" class="form-select">
                            <?php foreach ($encOptions as $v => $l): ?>
                            <option value="<?= $v ?>"
                                    <?= ($userSmtp['smtp_encryption'] ?? 'tls') === $v ? 'selected' : '' ?>>
                                <?= Helpers::e($l) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SMTP ユーザー名</label>
                        <input type="text" name="smtp_user" class="form-control"
                               value="<?= Helpers::e($userSmtp['smtp_user'] ?? '') ?>"
                               autocomplete="off" placeholder="user@gmail.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            SMTP パスワード
                            <?php if ($hasSmtpPass): ?>
                                <span class="text-muted small">（変更する場合のみ入力）</span>
                            <?php endif; ?>
                        </label>
                        <div class="input-group">
                            <input type="password" name="smtp_pass" class="form-control"
                                   autocomplete="new-password">
                            <?php if ($hasSmtpPass): ?>
                            <span class="input-group-text text-success small">
                                <i class="bi bi-lock-fill me-1"></i>設定済み
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 送信元情報（mail / SMTP のみ表示） -->
            <div id="fromFields" <?= $currentMethod === 'mailto' ? 'style="display:none"' : '' ?>>
                <hr class="my-3">
                <p class="small text-muted mb-2">送信元情報</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">
                            送信元アドレス
                            <span class="text-muted small">（空欄 = ログインIDを使用）</span>
                        </label>
                        <input type="email" name="from_address" class="form-control"
                               value="<?= Helpers::e($userSmtp['from_address'] ?? '') ?>"
                               placeholder="<?= Helpers::e($user['email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">
                            送信元名
                            <span class="text-muted small">（空欄 = 氏名を使用）</span>
                        </label>
                        <input type="text" name="from_name" class="form-control"
                               value="<?= Helpers::e($userSmtp['from_name'] ?? '') ?>"
                               placeholder="<?= Helpers::e($user['name']) ?>">
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2 flex-wrap align-items-center">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> 保存
                </button>
                <?php if ($userSmtp && $currentMethod !== 'mailto'): ?>
                <button type="button" class="btn btn-outline-secondary"
                        onclick="document.getElementById('testSmtpForm').submit()">
                    <i class="bi bi-send"></i> テスト送信
                </button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($userSmtp): ?>
        <form id="testSmtpForm" method="post" action="/my-settings.php" class="d-none">
            <input type="hidden" name="action"     value="test_reply">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleSmtpFields() {
    const method = document.querySelector('input[name="send_method"]:checked')?.value;
    document.getElementById('smtpFields').style.display = method === 'smtp'                          ? '' : 'none';
    document.getElementById('fromFields').style.display = (method === 'mail' || method === 'smtp') ? '' : 'none';
}
</script>

</div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
