<?php
declare(strict_types=1);

/**
 * admin/settings.php — システム設定（SMTP 等）
 *
 * 機能：
 *   - SMTP 設定の保存（パスワードは AES-256-CBC で暗号化）
 *   - テストメール送信
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = 'システム設定';
Auth::requireAdmin();

$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];

// ── 現在の設定を DB から読み込む ────────────────────────────────────
$settingKeys = [
    'use_php_mail', 'smtp_host', 'smtp_port', 'smtp_encryption',
    'smtp_user', 'smtp_pass_enc', 'smtp_from_address', 'smtp_from_name',
];
$rows = Database::fetchAll(
    'SELECT `key`, `value` FROM system_settings WHERE `key` IN ('
    . implode(',', array_fill(0, count($settingKeys), '?'))
    . ')',
    $settingKeys
);
$settings = [];
foreach ($rows as $row) {
    $settings[$row['key']] = $row['value'];
}

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['danger', 'セキュリティエラー。'];
        Helpers::redirect('/admin/settings.php');
    }

    $action = $_POST['action'] ?? '';

    // ── 設定保存 ──────────────────────────────────────────────────
    if ($action === 'save') {
        $useMail     = isset($_POST['use_php_mail']) ? '1' : '0';
        $smtpHost    = trim($_POST['smtp_host']         ?? '');
        $smtpPort    = (int)($_POST['smtp_port']        ?? 587);
        $smtpEnc     = $_POST['smtp_encryption']        ?? 'tls';
        $smtpUser    = trim($_POST['smtp_user']         ?? '');
        $smtpPass    = $_POST['smtp_pass']              ?? '';
        $fromAddress = trim($_POST['smtp_from_address'] ?? '');
        $fromName    = trim($_POST['smtp_from_name']    ?? 'MailGate');

        $validEnc = ['tls', 'ssl', 'none'];
        if (!in_array($smtpEnc, $validEnc, true)) {
            $errors[] = '暗号化方式が無効です。';
        } elseif ($fromAddress !== '' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '送信元メールアドレスの形式が正しくありません。';
        } else {
            // パスワード処理：空 = 変更なし
            $passEnc = $settings['smtp_pass_enc'] ?? '';
            if ($smtpPass !== '') {
                $passEnc = Helpers::encrypt($smtpPass);
            }

            $toSave = [
                'use_php_mail'       => $useMail,
                'smtp_host'          => $smtpHost,
                'smtp_port'          => (string)$smtpPort,
                'smtp_encryption'    => $smtpEnc,
                'smtp_user'          => $smtpUser,
                'smtp_pass_enc'      => $passEnc,
                'smtp_from_address'  => $fromAddress,
                'smtp_from_name'     => $fromName,
            ];

            foreach ($toSave as $key => $value) {
                Database::query(
                    'INSERT INTO system_settings (`key`, `value`) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                    [$key, $value]
                );
            }

            // ローカル設定を更新して画面に反映
            $settings = array_merge($settings, $toSave);
            AuditLog::record('settings.update');
            $_SESSION['flash'] = ['success', '設定を保存しました。'];
            Helpers::redirect('/admin/settings.php');
        }
    }

    // ── テストメール送信 ──────────────────────────────────────────
    if ($action === 'test_mail') {
        $testTo = trim($_POST['test_email'] ?? '');
        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['danger', '有効なメールアドレスを入力してください。'];
        } else {
            $ok = Mailer::sendTestEmail($testTo);
            if ($ok) {
                AuditLog::record('settings.test_mail', null, null, ['to' => $testTo, 'result' => 'success']);
                $_SESSION['flash'] = ['success', "テストメールを {$testTo} に送信しました。"];
            } else {
                AuditLog::record('settings.test_mail', null, null, ['to' => $testTo, 'result' => 'failure']);
                $_SESSION['flash'] = ['danger', 'メール送信に失敗しました。SMTP 設定を確認してください。'];
            }
        }
        Helpers::redirect('/admin/settings.php');
    }
}

$encOptions = [
    'tls'  => 'TLS (STARTTLS) — ポート 587',
    'ssl'  => 'SSL/TLS — ポート 465',
    'none' => '暗号化なし — ポート 25',
];

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/partials/subnav.php';
?>

<h4 class="mb-3"><i class="bi bi-sliders"></i> システム設定</h4>

<?php if ($flash): ?>
<div class="alert alert-<?= Helpers::e($flash[0]) ?> alert-autofade py-2">
    <i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
    <?= Helpers::e($flash[1]) ?>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <?php foreach ($errors as $e): ?>
        <div><i class="bi bi-exclamation-triangle"></i> <?= Helpers::e($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-4">
<div class="col-lg-8">

<!-- ── SMTP / メール設定 ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-envelope-gear"></i> メール送信設定
    </div>
    <div class="card-body">
        <form method="post" action="/admin/settings.php" novalidate>
            <input type="hidden" name="action"     value="save">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

            <!-- 送信方式 -->
            <div class="mb-4">
                <label class="form-label fw-semibold">送信方式</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="use_php_mail"
                           name="use_php_mail" value="1"
                           <?= ($settings['use_php_mail'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="use_php_mail">
                        PHP <code>mail()</code> を使用する
                        <span class="text-muted small">（チェックなし = SMTP を使用）</span>
                    </label>
                </div>
            </div>

            <hr>

            <!-- SMTP 設定 -->
            <p class="fw-semibold small text-uppercase text-muted mb-3">SMTP サーバー設定</p>

            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label">SMTP ホスト</label>
                    <input type="text" name="smtp_host" class="form-control"
                           value="<?= Helpers::e($settings['smtp_host'] ?? '') ?>"
                           placeholder="smtp.gmail.com">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ポート</label>
                    <input type="number" name="smtp_port" class="form-control"
                           value="<?= (int)($settings['smtp_port'] ?? 587) ?>"
                           min="1" max="65535">
                </div>
                <div class="col-md-3">
                    <label class="form-label">暗号化</label>
                    <select name="smtp_encryption" class="form-select">
                        <?php foreach ($encOptions as $v => $l): ?>
                        <option value="<?= $v ?>"
                                <?= ($settings['smtp_encryption'] ?? 'tls') === $v ? 'selected' : '' ?>>
                            <?= Helpers::e($l) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label class="form-label">SMTP ユーザー名</label>
                    <input type="text" name="smtp_user" class="form-control"
                           value="<?= Helpers::e($settings['smtp_user'] ?? '') ?>"
                           autocomplete="off"
                           placeholder="user@gmail.com">
                </div>
                <div class="col-md-5">
                    <label class="form-label">
                        SMTP パスワード
                        <?php if (!empty($settings['smtp_pass_enc'])): ?>
                            <span class="text-muted small">（変更する場合のみ入力）</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="smtp_pass" class="form-control"
                           autocomplete="new-password">
                </div>
                <?php if (!empty($settings['smtp_pass_enc'])): ?>
                <div class="col-md-2 d-flex align-items-end">
                    <span class="text-success small"><i class="bi bi-lock-fill"></i> 設定済み</span>
                </div>
                <?php endif; ?>
            </div>

            <hr>

            <!-- 送信元情報 -->
            <p class="fw-semibold small text-uppercase text-muted mb-3">送信元情報</p>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">送信元メールアドレス</label>
                    <input type="email" name="smtp_from_address" class="form-control"
                           value="<?= Helpers::e($settings['smtp_from_address'] ?? '') ?>"
                           placeholder="noreply@example.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label">送信元名</label>
                    <input type="text" name="smtp_from_name" class="form-control"
                           value="<?= Helpers::e($settings['smtp_from_name'] ?? 'MailGate') ?>"
                           placeholder="MailGate">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> 設定を保存する
                </button>
            </div>
        </form>
    </div>
</div>

</div>

<!-- ── 右サイドバー：テストメール ── -->
<div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-send"></i> テストメール送信
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                現在の設定でメール送信テストを行います。
                設定を保存してから実施してください。
            </p>
            <form method="post" action="/admin/settings.php" novalidate>
                <input type="hidden" name="action"     value="test_mail">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                <div class="mb-3">
                    <label class="form-label">送信先</label>
                    <input type="email" name="test_email" class="form-control"
                           placeholder="your@email.com" required>
                </div>
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-envelope-arrow-up"></i> テスト送信
                </button>
            </form>
        </div>
    </div>

    <!-- Cron 設定ガイド -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-clock"></i> Cron 設定
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Xserver コントロールパネル → Cron 設定に以下を登録してください：
            </p>
            <pre class="bg-dark text-light p-2 rounded small" style="font-size:.75rem;overflow-x:auto">*/5 * * * * /usr/bin/php8.3 \
  /path/to/cron/fetch.php \
  >> /path/to/logs/mailgate.log 2>&1</pre>
            <p class="text-muted small mb-0">
                ※ パスはサーバーの実際のインストールパスに合わせてください。
            </p>
        </div>
    </div>
</div>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
