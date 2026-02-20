<?php
/**
 * admin/partials/subnav.php — 管理者サブナビゲーション
 * 各管理ページの include __DIR__ . '/partials/subnav.php'; で使用
 */
$_adminNav = [
    '/admin/mailboxes.php'     => ['bi-inbox-fill',       '監視メールボックス'],
    '/admin/users.php'         => ['bi-people-fill',      'アカウント管理'],
    '/admin/subscriptions.php' => ['bi-arrow-left-right', '購読管理'],
    '/admin/rules.php'         => ['bi-funnel-fill',      'ルール管理'],
    '/admin/settings.php'      => ['bi-sliders',          'システム設定'],
    '/admin/audit.php'         => ['bi-journal-text',     '操作ログ'],
];
$_adminCurrent = '/' . ltrim(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/');
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <nav class="nav nav-pills flex-wrap gap-1">
            <?php foreach ($_adminNav as $href => [$icon, $label]): ?>
            <a class="nav-link py-1 px-3 <?= $href === $_adminCurrent ? 'active' : '' ?>"
               href="<?= $href ?>">
                <i class="bi <?= $icon ?>"></i> <?= $label ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
