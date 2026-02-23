<?php
declare(strict_types=1);

/**
 * index.php — 認証ページ
 *
 * GET パラメータによる動作切替：
 *   (なし)       → ログインフォーム
 *   ?setup=token → 初回パスワード設定
 *   ?forgot      → パスワードリセット申請
 *   ?reset=token → パスワードリセット実行
 */

require_once __DIR__ . '/../src/bootstrap.php';

// ログイン済みはダッシュボードへ
if (Auth::getCurrentUser() !== null) {
    Helpers::redirect('/dashboard.php');
}

$config  = Database::config();
$appName = $config['app_name'] ?? 'MailGate';

$errors  = [];
$success = '';
$mode    = 'login';   // login | setup | forgot | forgot_sent | reset | expired
$token   = '';

$sessionExpiredMsg = isset($_GET['expired'])
    ? 'セッションが期限切れました。再度ログインしてください。'
    : '';

// ── GET: モード判定 ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['setup'])) {
        $token = trim($_GET['setup']);
        $mode  = Auth::findUserBySetupToken($token) ? 'setup' : 'expired';

    } elseif (isset($_GET['reset'])) {
        $token = trim($_GET['reset']);
        $mode  = Auth::findUserByResetToken($token) ? 'reset' : 'expired';

    } elseif (isset($_GET['forgot'])) {
        $mode = 'forgot';
    }
}

// ── POST: 各フォーム処理 ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postMode = $_POST['mode'] ?? '';

    // CSRF チェック（ログイン前なので session ベースで確認）
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'セキュリティエラーが発生しました。もう一度お試しください。';
        $mode = $postMode ?: 'login';
    } else {
        switch ($postMode) {

            // ── ログイン ──────────────────────────────────────────
            case 'login':
                $mode  = 'login';
                $email = trim($_POST['email']    ?? '');
                if (Auth::login($email, trim($_POST['password'] ?? ''))) {
                    AuditLog::record('auth.login');
                    Helpers::redirect('/dashboard.php');
                }
                AuditLog::record('auth.login_failed', null, null, ['email' => $email]);
                $errors[] = 'メールアドレスまたはパスワードが正しくありません。';
                break;

            // ── 初回パスワード設定 ────────────────────────────────
            case 'setup':
                $token = trim($_POST['token'] ?? '');
                $pw1   = $_POST['password']         ?? '';
                $pw2   = $_POST['password_confirm'] ?? '';
                $mode  = 'setup';

                if ($pw1 !== $pw2) {
                    $errors[] = 'パスワードが一致しません。';
                } elseif (!Helpers::validatePassword($pw1)) {
                    $errors[] = 'パスワードは8文字以上で、英字と数字を含めてください。';
                } elseif (Auth::setupPassword($token, $pw1)) {
                    $success = 'パスワードを設定しました。ログインしてください。';
                    $mode    = 'login';
                } else {
                    $errors[] = 'リンクが無効または期限切れです。管理者にお問い合わせください。';
                    $mode     = 'expired';
                }
                break;

            // ── パスワードリセット申請 ─────────────────────────────
            case 'forgot':
                Auth::requestPasswordReset(trim($_POST['email'] ?? ''));
                // 防ユーザー列挙：メールの有無に関わらず同一メッセージを表示
                $success = 'メールアドレスが登録されている場合、リセット用メールを送信しました。';
                $mode    = 'forgot_sent';
                break;

            // ── パスワードリセット実行 ─────────────────────────────
            case 'reset':
                $token = trim($_POST['token'] ?? '');
                $pw1   = $_POST['password']         ?? '';
                $pw2   = $_POST['password_confirm'] ?? '';
                $mode  = 'reset';

                if ($pw1 !== $pw2) {
                    $errors[] = 'パスワードが一致しません。';
                } elseif (!Helpers::validatePassword($pw1)) {
                    $errors[] = 'パスワードは8文字以上で、英字と数字を含めてください。';
                } elseif (Auth::resetPassword($token, $pw1)) {
                    $success = 'パスワードをリセットしました。ログインしてください。';
                    $mode    = 'login';
                } else {
                    $errors[] = 'リンクが無効または期限切れです。再度申請してください。';
                    $mode     = 'expired';
                }
                break;
        }
    }
}

// ── HTML 出力 ──────────────────────────────────────────────────────
$titles = [
    'login'       => 'ログイン',
    'setup'       => 'パスワード設定',
    'forgot'      => 'パスワードをお忘れの方',
    'forgot_sent' => 'メール送信完了',
    'reset'       => '新しいパスワードを設定',
    'expired'     => 'リンク期限切れ',
];
$formTitle = $titles[$mode] ?? 'ログイン';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Helpers::e($formTitle) ?> — <?= Helpers::e($appName) ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-light">

<div class="min-vh-100 d-flex align-items-center justify-content-center py-5">
    <div class="w-100" style="max-width:420px; padding: 0 1rem;">

        <!-- ロゴ -->
        <div class="text-center mb-4">
            <div class="display-6 fw-bold text-primary">
                <i class="bi bi-envelope-check-fill"></i>
            </div>
            <h1 class="h4 fw-bold mt-1"><?= Helpers::e($appName) ?></h1>
            <p class="text-muted small"><?= Helpers::e($formTitle) ?></p>
        </div>

        <!-- セッション期限切れメッセージ -->
        <?php if ($sessionExpiredMsg): ?>
            <div class="alert alert-warning py-2">
                <i class="bi bi-clock"></i> <?= Helpers::e($sessionExpiredMsg) ?>
            </div>
        <?php endif; ?>

        <!-- エラー / 成功 メッセージ -->
        <?php if ($errors): ?>
            <div class="alert alert-danger py-2 alert-autofade">
                <?php foreach ($errors as $err): ?>
                    <div><i class="bi bi-exclamation-triangle"></i> <?= Helpers::e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success py-2">
                <i class="bi bi-check-circle"></i> <?= Helpers::e($success) ?>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">

                <?php /* ──────────── ログイン ──────────── */ ?>
                <?php if ($mode === 'login'): ?>
                <form method="post" action="/index.php" novalidate>
                    <input type="hidden" name="mode" value="login">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">メールアドレス</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?= Helpers::e($_POST['email'] ?? '') ?>"
                               autocomplete="email" autofocus required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="password">パスワード</label>
                        <input type="password" id="password" name="password"
                               class="form-control" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right"></i> ログイン
                    </button>
                </form>
                <hr class="my-3">
                <div class="text-center small">
                    <a href="/index.php?forgot" class="text-muted">
                        パスワードをお忘れですか？
                    </a>
                </div>

                <?php /* ──────────── 初回設定 ──────────── */ ?>
                <?php elseif ($mode === 'setup'): ?>
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle"></i>
                    アカウントが作成されました。パスワードを設定してください。<br>
                    <strong>8文字以上、英字と数字を含めること</strong>
                </p>
                <form method="post" action="/index.php" novalidate>
                    <input type="hidden" name="mode"  value="setup">
                    <input type="hidden" name="token" value="<?= Helpers::e($token) ?>">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="pw1">新しいパスワード</label>
                        <input type="password" id="pw1" name="password"
                               class="form-control" autocomplete="new-password" autofocus required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="pw2">パスワード（確認）</label>
                        <input type="password" id="pw2" name="password_confirm"
                               class="form-control" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key"></i> パスワードを設定する
                    </button>
                </form>

                <?php /* ──────────── 忘れた申請 ──────────── */ ?>
                <?php elseif ($mode === 'forgot'): ?>
                <p class="text-muted small mb-3">
                    登録済みのメールアドレスを入力してください。
                    パスワードリセット用のメールをお送りします。
                </p>
                <form method="post" action="/index.php" novalidate>
                    <input type="hidden" name="mode" value="forgot">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="email">メールアドレス</label>
                        <input type="email" id="email" name="email"
                               class="form-control" autocomplete="email" autofocus required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-envelope"></i> リセットメールを送信
                    </button>
                </form>
                <hr class="my-3">
                <div class="text-center small">
                    <a href="/index.php" class="text-muted">← ログインに戻る</a>
                </div>

                <?php /* ──────────── 送信完了 ──────────── */ ?>
                <?php elseif ($mode === 'forgot_sent'): ?>
                <div class="text-center py-2">
                    <i class="bi bi-envelope-check display-4 text-success"></i>
                    <p class="mt-3 text-muted small">
                        メールを確認し、記載されたリンクからパスワードを再設定してください。<br>
                        リンクの有効期限は <strong>1時間</strong> です。
                    </p>
                    <a href="/index.php" class="btn btn-outline-secondary btn-sm">
                        ログインページへ戻る
                    </a>
                </div>

                <?php /* ──────────── パスワードリセット ──────────── */ ?>
                <?php elseif ($mode === 'reset'): ?>
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle"></i>
                    新しいパスワードを設定してください。<br>
                    <strong>8文字以上、英字と数字を含めること</strong>
                </p>
                <form method="post" action="/index.php" novalidate>
                    <input type="hidden" name="mode"  value="reset">
                    <input type="hidden" name="token" value="<?= Helpers::e($token) ?>">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="pw1">新しいパスワード</label>
                        <input type="password" id="pw1" name="password"
                               class="form-control" autocomplete="new-password" autofocus required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="pw2">パスワード（確認）</label>
                        <input type="password" id="pw2" name="password_confirm"
                               class="form-control" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-key"></i> パスワードをリセットする
                    </button>
                </form>

                <?php /* ──────────── 期限切れ ──────────── */ ?>
                <?php elseif ($mode === 'expired'): ?>
                <div class="text-center py-2">
                    <i class="bi bi-exclamation-circle display-4 text-danger"></i>
                    <p class="mt-3 text-muted small">
                        このリンクは無効または期限切れです。<br>
                        管理者に再発行を依頼するか、パスワードリセットを再申請してください。
                    </p>
                    <a href="/index.php?forgot" class="btn btn-outline-primary btn-sm me-2">
                        リセット再申請
                    </a>
                    <a href="/index.php" class="btn btn-outline-secondary btn-sm">
                        ログインへ戻る
                    </a>
                </div>

                <?php endif; ?>
            </div>
        </div>

        <p class="text-center text-muted small mt-4">
            &copy; <?= date('Y') ?> <?= Helpers::e($appName) ?>
        </p>
    </div>
</div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
