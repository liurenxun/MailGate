<?php
/**
 * partials/header.php — 共用 HTML 头部 + 导航栏
 *
 * 使用前请在调用页设定：
 *   $pageTitle = 'ページ名';       // 必填
 *   $requireLogin = true/false;    // 默认 true
 *
 * 若 $requireLogin = true，会自动调用 Auth::requireLogin()。
 */

if (!isset($requireLogin) || $requireLogin) {
    Auth::requireLogin();
}

$_user    = Auth::getCurrentUser();
$_config  = Database::config();
$_appName = $_config['app_name'] ?? 'MailGate';

// 未读通知数（用于导航栏 badge）
$_unread = 0;
if ($_user) {
    $_unread = (int)(Database::fetchOne(
        'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0',
        [(int)$_user['id']]
    )['cnt'] ?? 0);
}

$_currentPage  = basename($_SERVER['PHP_SELF']);
$_isAdminPage  = str_contains($_SERVER['PHP_SELF'], '/admin/');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Helpers::e($pageTitle ?? $_appName) ?> — <?= Helpers::e($_appName) ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="bg-light">

<?php if ($_user): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container-fluid px-4">

        <a class="navbar-brand fw-bold" href="/dashboard.php">
            <i class="bi bi-envelope-check-fill text-primary"></i>
            <?= Helpers::e($_appName) ?>
        </a>

        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#navMain"
                aria-controls="navMain" aria-expanded="false">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= $_currentPage === 'dashboard.php' ? 'active' : '' ?>"
                       href="/dashboard.php">
                        <i class="bi bi-house-door"></i> ダッシュボード
                        <?php if ($_unread > 0): ?>
                            <span class="badge rounded-pill bg-danger"><?= $_unread ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $_currentPage === 'my-rules.php' ? 'active' : '' ?>"
                       href="/my-rules.php">
                        <i class="bi bi-funnel"></i> 受信ルール
                    </a>
                </li>
                <?php if ($_user['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $_isAdminPage ? 'active' : '' ?>"
                       href="/admin/mailboxes.php">
                        <i class="bi bi-shield-gear"></i> 管理者メニュー
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button"
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                        <?= Helpers::e($_user['name']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item <?= $_currentPage === 'my-settings.php' ? 'active' : '' ?>"
                               href="/my-settings.php">
                                <i class="bi bi-gear"></i> アカウント設定
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="/logout.php" class="m-0">
                                <input type="hidden" name="csrf_token"
                                       value="<?= Helpers::e(Auth::csrfToken()) ?>">
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right"></i> ログアウト
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

    </div>
</nav>
<?php endif; ?>

<div class="container-fluid px-4 py-4">
