<?php
declare(strict_types=1);

/**
 * dashboard.php — 受信通知一覧（従業員ホーム）
 *
 * フィルタ（GETパラメータ）：
 *   ?mailbox={id}  : 指定メールボックスで絞り込み
 *   ?read={0|1}    : 未読(0) / 既読(1) 絞り込み
 *   ?q={keyword}   : 件名・差出人キーワード検索
 *   ?page={n}      : ページ番号
 */

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'ダッシュボード';
$user      = Auth::getCurrentUser(); // requireLogin は header.php で呼ばれる

// ── フィルタ値取得 ─────────────────────────────────────────────────
$filterMailbox = (int)($_GET['mailbox'] ?? 0);
$filterRead    = $_GET['read'] ?? '';   // '0' | '1' | ''
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 30;

// ── サイドバー：購読メールボックス一覧 ────────────────────────────
$mailboxes = Database::fetchAll(
    'SELECT mb.id, mb.label, mb.email_address,
            COUNT(n.id)                                   AS total_count,
            SUM(CASE WHEN n.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
     FROM monitored_mailboxes mb
     INNER JOIN subscriptions s ON s.mailbox_id = mb.id AND s.user_id = ?
     LEFT  JOIN mails m          ON m.mailbox_id = mb.id
     LEFT  JOIN notifications n  ON n.mail_id = m.id AND n.user_id = ?
     GROUP BY mb.id, mb.label, mb.email_address
     ORDER BY mb.label ASC',
    [(int)$user['id'], (int)$user['id']]
);

// ── 通知クエリ（動的 WHERE 構築）──────────────────────────────────
$where  = ['n.user_id = ?'];
$params = [(int)$user['id']];

if ($filterMailbox > 0) {
    $where[]  = 'mb.id = ?';
    $params[] = $filterMailbox;
}
if ($filterRead === '0') {
    $where[] = 'n.is_read = 0';
} elseif ($filterRead === '1') {
    $where[] = 'n.is_read = 1';
}
if ($search !== '') {
    $where[]  = '(m.subject LIKE ? OR m.from_address LIKE ? OR m.from_name LIKE ?)';
    $q = '%' . $search . '%';
    array_push($params, $q, $q, $q);
}

$whereSQL = implode(' AND ', $where);
$baseSQL  = "FROM notifications n
             INNER JOIN mails m              ON m.id  = n.mail_id
             INNER JOIN monitored_mailboxes mb ON mb.id = m.mailbox_id
             WHERE {$whereSQL}";

// 総件数
$total  = (int)(Database::fetchOne("SELECT COUNT(*) AS cnt {$baseSQL}", $params)['cnt'] ?? 0);
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

// データ取得
$notifications = Database::fetchAll(
    "SELECT n.id, n.is_read, n.notified_at,
            m.subject, m.from_name, m.from_address, m.received_at,
            mb.id AS mailbox_id, mb.label AS mailbox_label,
            (SELECT COUNT(*) FROM attachments
             WHERE mail_id = m.id AND content_id IS NULL) AS attachment_count
     {$baseSQL}
     ORDER BY n.notified_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── 現在のフィルタ用クエリ文字列ビルダー ──────────────────────────
function buildQuery(array $overrides = []): string
{
    $base = [
        'mailbox' => $_GET['mailbox'] ?? '',
        'read'    => $_GET['read']    ?? '',
        'q'       => $_GET['q']       ?? '',
        'page'    => '1',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== '0' || $v === '0');
    // 常に page だけ残す
    $params = array_merge($base, $overrides);
    $query  = http_build_query(array_filter($params, fn($v) => $v !== ''));
    return $query ? '?' . $query : '';
}

include __DIR__ . '/partials/header.php';
?>

<div class="row g-3">

    <!-- ── サイドバー：メールボックスフィルタ ── -->
    <div class="col-lg-2 col-md-3">
        <div class="card border-0 shadow-sm mailbox-sidebar">
            <div class="card-header bg-white fw-semibold py-2 small text-uppercase text-muted">
                メールボックス
            </div>
            <div class="list-group list-group-flush">
                <a href="/dashboard.php<?= buildQuery(['mailbox' => '', 'page' => '1']) ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= $filterMailbox === 0 ? 'active' : '' ?>">
                    <span><i class="bi bi-inbox me-1"></i> すべて</span>
                </a>
                <?php foreach ($mailboxes as $mb): ?>
                <a href="/dashboard.php<?= buildQuery(['mailbox' => $mb['id'], 'page' => '1']) ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= $filterMailbox === (int)$mb['id'] ? 'active' : '' ?>">
                    <span class="text-truncate" style="max-width:120px"
                          title="<?= Helpers::e($mb['email_address']) ?>">
                        <?= Helpers::e($mb['label']) ?>
                    </span>
                    <?php if ($mb['unread_count'] > 0): ?>
                        <span class="badge bg-primary rounded-pill"><?= (int)$mb['unread_count'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── メインコンテンツ：通知リスト ── -->
    <div class="col-lg-10 col-md-9">
        <div class="card border-0 shadow-sm">

            <!-- ヘッダー：タブ + 検索 -->
            <div class="card-header bg-white py-2 d-flex flex-wrap gap-2 align-items-center justify-content-between">

                <!-- 既読/未読タブ -->
                <ul class="nav nav-pills nav-sm gap-1 mb-0">
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterRead === '' ? 'active' : '' ?>"
                           href="/dashboard.php<?= buildQuery(['read' => '', 'page' => '1']) ?>">
                            すべて
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterRead === '0' ? 'active' : '' ?>"
                           href="/dashboard.php<?= buildQuery(['read' => '0', 'page' => '1']) ?>">
                            <i class="bi bi-circle-fill text-primary" style="font-size:.5rem;vertical-align:middle"></i>
                            未読
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link py-1 px-3 <?= $filterRead === '1' ? 'active' : '' ?>"
                           href="/dashboard.php<?= buildQuery(['read' => '1', 'page' => '1']) ?>">
                            既読
                        </a>
                    </li>
                </ul>

                <!-- キーワード検索 -->
                <form method="get" action="/dashboard.php" class="d-flex search-form">
                    <?php if ($filterMailbox): ?>
                        <input type="hidden" name="mailbox" value="<?= $filterMailbox ?>">
                    <?php endif; ?>
                    <?php if ($filterRead !== ''): ?>
                        <input type="hidden" name="read" value="<?= Helpers::e($filterRead) ?>">
                    <?php endif; ?>
                    <input type="search" name="q" class="form-control form-control-sm me-2"
                           placeholder="件名・差出人を検索..."
                           value="<?= Helpers::e($search) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>

            <!-- 通知テーブル -->
            <div class="table-responsive">
                <table class="table table-hover mb-0 notif-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:16px"></th>
                            <th>差出人</th>
                            <th>件名</th>
                            <th style="width:130px">メールボックス</th>
                            <th style="width:130px">受信日時</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($notifications)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                <i class="bi bi-inbox display-6 d-block mb-2"></i>
                                通知はありません
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $n): ?>
                        <?php $rowClass = $n['is_read'] ? 'notif-read' : 'notif-unread'; ?>
                        <tr class="<?= $rowClass ?>"
                            onclick="location.href='/mail.php?n=<?= (int)$n['id'] ?>'"
                            style="cursor:pointer">
                            <td>
                                <?php if (!$n['is_read']): ?>
                                    <i class="bi bi-circle-fill text-primary" style="font-size:.5rem"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-truncate" style="max-width:180px">
                                <?= Helpers::e($n['from_name'] ?: $n['from_address']) ?>
                            </td>
                            <td class="notif-subject">
                                <?= Helpers::e($n['subject'] ?: '（件名なし）') ?>
                                <?php if ((int)($n['attachment_count'] ?? 0) > 0): ?>
                                    <i class="bi bi-paperclip text-muted ms-1" title="添付ファイルあり"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?= Helpers::e($n['mailbox_label']) ?>
                            </td>
                            <td class="text-muted small text-nowrap">
                                <?= Helpers::e(date('Y/m/d H:i', strtotime($n['notified_at']))) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ページネーション -->
            <?php if ($pages > 1): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2">
                <small class="text-muted">
                    全 <?= number_format($total) ?> 件 &nbsp;/&nbsp;
                    <?= $page ?> / <?= $pages ?> ページ
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="/dashboard.php<?= buildQuery(['page' => $page - 1]) ?>">
                                &lsaquo;
                            </a>
                        </li>
                        <?php
                        $pageStart = max(1, $page - 2);
                        $pageEnd   = min($pages, $page + 2);
                        for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link"
                               href="/dashboard.php<?= buildQuery(['page' => $p]) ?>">
                                <?= $p ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                            <a class="page-link"
                               href="/dashboard.php<?= buildQuery(['page' => $page + 1]) ?>">
                                &rsaquo;
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
