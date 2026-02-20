<?php
declare(strict_types=1);

/**
 * admin/audit.php — 操作ログ一覧（Phase 5）
 *
 * 機能：
 *   - 最新 200 件の監査ログ表示
 *   - カテゴリフィルタ（auth / user / mailbox / rule / subscription / settings / attachment）
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = '操作ログ';
Auth::requireAdmin();

// ── フィルタ ───────────────────────────────────────────────────────
$filterCategory = $_GET['cat'] ?? '';

$validCategories = ['auth', 'user', 'mailbox', 'rule', 'subscription', 'settings', 'attachment'];

$where  = [];
$params = [];

if ($filterCategory !== '' && in_array($filterCategory, $validCategories, true)) {
    $where[]  = 'al.action LIKE ?';
    $params[] = $filterCategory . '.%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── データ取得（最新 200 件）──────────────────────────────────────
$logs = Database::fetchAll(
    "SELECT al.id, al.action, al.target_type, al.target_id,
            al.detail, al.ip_address, al.created_at,
            u.name AS user_name, u.email AS user_email
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     {$whereSQL}
     ORDER BY al.created_at DESC
     LIMIT 200",
    $params
);

// ── アクション → バッジ色マッピング ───────────────────────────────
function auditBadgeClass(string $action): string
{
    return match(true) {
        str_starts_with($action, 'auth.')         => 'bg-primary',
        str_starts_with($action, 'user.')         => 'bg-info text-dark',
        str_starts_with($action, 'mailbox.')      => 'bg-warning text-dark',
        str_starts_with($action, 'rule.')         => 'bg-secondary',
        str_starts_with($action, 'subscription.') => 'bg-success',
        str_starts_with($action, 'settings.')     => 'bg-dark',
        str_starts_with($action, 'attachment.')   => 'bg-light text-dark border',
        default                                   => 'bg-secondary',
    };
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/partials/subnav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-journal-text"></i> 操作ログ</h4>
    <span class="text-muted small">最新 200 件</span>
</div>

<!-- カテゴリフィルタ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <nav class="nav nav-pills flex-wrap gap-1">
            <a class="nav-link py-1 px-3 <?= $filterCategory === '' ? 'active' : '' ?>"
               href="/admin/audit.php">
                すべて
            </a>
            <?php
            $catLabels = [
                'auth'         => ['bi-box-arrow-in-right', '認証'],
                'user'         => ['bi-person',             'ユーザー'],
                'mailbox'      => ['bi-inbox',              'メールボックス'],
                'rule'         => ['bi-funnel',             'ルール'],
                'subscription' => ['bi-arrow-left-right',   '購読'],
                'settings'     => ['bi-sliders',            '設定'],
                'attachment'   => ['bi-paperclip',          '添付'],
            ];
            foreach ($catLabels as $cat => [$icon, $label]):
            ?>
            <a class="nav-link py-1 px-3 <?= $filterCategory === $cat ? 'active' : '' ?>"
               href="/admin/audit.php?cat=<?= $cat ?>">
                <i class="bi <?= $icon ?>"></i> <?= $label ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>

<!-- ログテーブル -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:155px">日時</th>
                    <th style="width:160px">操作者</th>
                    <th style="width:200px">アクション</th>
                    <th>対象 / 詳細</th>
                    <th style="width:120px">IP アドレス</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                        <i class="bi bi-journal-x display-6 d-block mb-2"></i>
                        ログがありません
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <?php
                    // 詳細 JSON をデコード
                    $detail = [];
                    if ($log['detail'] !== null) {
                        $detail = json_decode($log['detail'], true) ?? [];
                    }
                ?>
                <tr>
                    <td class="text-muted small text-nowrap">
                        <?= Helpers::e(date('Y/m/d H:i:s', strtotime($log['created_at']))) ?>
                    </td>
                    <td class="small">
                        <?php if ($log['user_name']): ?>
                            <div><?= Helpers::e($log['user_name']) ?></div>
                            <div class="text-muted"><?= Helpers::e($log['user_email'] ?? '') ?></div>
                        <?php else: ?>
                            <span class="text-muted">—（削除済み / システム）</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= auditBadgeClass($log['action']) ?>">
                            <?= Helpers::e($log['action']) ?>
                        </span>
                    </td>
                    <td class="small">
                        <?php if ($log['target_type'] && $log['target_id']): ?>
                            <span class="text-muted"><?= Helpers::e($log['target_type']) ?> #<?= (int)$log['target_id'] ?></span>
                            <?php if (!empty($detail)): ?>
                                &nbsp;
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($detail)): ?>
                            <code class="small"><?= Helpers::e(json_encode($detail, JSON_UNESCAPED_UNICODE)) ?></code>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= Helpers::e($log['ip_address'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
