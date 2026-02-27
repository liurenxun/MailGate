<?php
declare(strict_types=1);

/**
 * dashboard.php — 受信通知一覧（従業員ホーム）
 *
 * フィルタ（GETパラメータ）：
 *   ?mailbox={id}  : 指定メールボックスで絞り込み
 *   ?read={0|1|}   : 未読(0) / 既読(1) / すべて('') 絞り込み
 *   ?rule={id}     : 命中ルール ID で絞り込み
 *   ?q={keyword}   : 件名・差出人キーワード検索
 *   ?page={n}      : ページ番号
 */

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = 'ダッシュボード';
$user      = Auth::getCurrentUser(); // requireLogin は header.php で呼ばれる

// ── POST ハンドラ（AJAX + form POST）─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        Helpers::json(['error' => 'CSRF error'], 403);
    }
    $action = $_POST['action'] ?? '';
    $nid    = (int)($_POST['nid'] ?? 0);
    $uid    = (int)$user['id'];

    $own = fn() => Database::fetchOne(
        'SELECT id FROM notifications WHERE id = ? AND user_id = ?',
        [$nid, $uid]
    );

    if ($action === 'trash' && $own()) {
        Database::query(
            'UPDATE notifications SET is_trashed=1, trashed_at=NOW() WHERE id=?',
            [$nid]
        );
        Helpers::json(['ok' => true]);
    }
    if ($action === 'restore' && $own()) {
        Database::query(
            'UPDATE notifications SET is_trashed=0, trashed_at=NULL WHERE id=?',
            [$nid]
        );
        Helpers::json(['ok' => true]);
    }
    if ($action === 'delete_permanent' && $own()) {
        Database::query('DELETE FROM notifications WHERE id=?', [$nid]);
        Helpers::json(['ok' => true]);
    }
    if ($action === 'empty_trash') {
        Database::query(
            'DELETE FROM notifications WHERE user_id=? AND is_trashed=1',
            [$uid]
        );
        Helpers::redirect('/dashboard.php?view=trash');
    }
    Helpers::json(['error' => 'Invalid action'], 400);
}

// ── フィルタ値取得 ─────────────────────────────────────────────────
$viewTrash     = isset($_GET['view']) && $_GET['view'] === 'trash';
$viewIgnored   = isset($_GET['view']) && $_GET['view'] === 'ignored';
$filterMailbox = (int)($_GET['mailbox'] ?? 0);
$filterRead    = $_GET['read'] ?? '';   // '0' | '1' | ''
$filterRule    = (int)($_GET['rule'] ?? 0);
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 30;
$sortBy        = $_GET['sort'] ?? '';
$orderSQL      = match($sortBy) {
    'from'   => 'm.from_name ASC, m.from_address ASC',
    'server' => 'SUBSTRING_INDEX(m.from_address, \'@\', -1) ASC',
    default  => 'm.received_at DESC',
};

// ── サイドバー：購読メールボックス一覧 ────────────────────────────
$mailboxes = Database::fetchAll(
    'SELECT mb.id, mb.label, mb.email_address,
            COUNT(n.id)                                   AS total_count,
            SUM(CASE WHEN n.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
     FROM monitored_mailboxes mb
     INNER JOIN subscriptions s ON s.mailbox_id = mb.id AND s.user_id = ?
     LEFT  JOIN mails m          ON m.mailbox_id = mb.id
     LEFT  JOIN notifications n  ON n.mail_id = m.id AND n.user_id = ? AND n.is_trashed = 0
     GROUP BY mb.id, mb.label, mb.email_address
     ORDER BY mb.label ASC',
    [(int)$user['id'], (int)$user['id']]
);

// ── 通知クエリ（動的 WHERE 構築）──────────────────────────────────
$where  = ['n.user_id = ?'];
$params = [(int)$user['id']];

if ($viewTrash) {
    $where[] = 'n.is_trashed = 1';
} elseif ($viewIgnored) {
    $where[] = 'n.is_ignored = 1';
} else {
    $where[] = 'n.is_trashed = 0';
    // すべて は is_ignored を問わず表示（無視含む）
}

if ($filterMailbox > 0) {
    $where[]  = 'mb.id = ?';
    $params[] = $filterMailbox;
}
// 未読/既読フィルタは通常ビューのみ
if (!$viewTrash && !$viewIgnored) {
    if ($filterRead === '0') {
        $where[] = 'n.is_read = 0';
        $where[] = 'n.is_ignored = 0';   // 未読は無視を除外
    } elseif ($filterRead === '1') {
        $where[] = 'n.is_read = 1';
        $where[] = 'n.is_ignored = 0';   // 既読は無視を除外
    }
    // read='' (すべて): is_ignored 条件なし
}
if ($filterRule > 0) {
    // matched_rule_id が NULL の旧レコードもカバーするためリアルタイム SQL マッチを併用
    $ruleRow = Database::fetchOne('SELECT * FROM rules WHERE id=?', [$filterRule]);
    if ($ruleRow) {
        // PHP ワイルドカード(*) → SQL LIKE(%) へ変換（% _ をエスケープ）
        $lp = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ruleRow['match_pattern']);
        $lp = str_replace('*', '%', $lp);
        switch ($ruleRow['match_field']) {
            case 'from_address':
                $where[] = "(n.matched_rule_id = ? OR (n.matched_rule_id IS NULL AND m.from_address LIKE ?))";
                array_push($params, $filterRule, $lp);
                break;
            case 'from_domain':
                $where[] = "(n.matched_rule_id = ? OR (n.matched_rule_id IS NULL AND SUBSTRING_INDEX(m.from_address,'@',-1) LIKE ?))";
                array_push($params, $filterRule, $lp);
                break;
            case 'subject':
                $where[] = "(n.matched_rule_id = ? OR (n.matched_rule_id IS NULL AND m.subject LIKE ?))";
                array_push($params, $filterRule, $lp);
                break;
            case 'any':
                $where[] = "(n.matched_rule_id = ? OR (n.matched_rule_id IS NULL AND (m.from_address LIKE ? OR m.from_name LIKE ? OR m.subject LIKE ?)))";
                array_push($params, $filterRule, $lp, $lp, $lp);
                break;
            default:
                $where[]  = 'n.matched_rule_id = ?';
                $params[] = $filterRule;
        }
    } else {
        $where[]  = 'n.matched_rule_id = ?';
        $params[] = $filterRule;
    }
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
    "SELECT n.id, n.is_read, n.is_ignored, n.matched_rule_id, n.notified_at,
            m.subject, m.from_name, m.from_address, m.received_at,
            LEFT(IFNULL(m.body_text,''), 200) AS body_preview,
            mb.id AS mailbox_id, mb.label AS mailbox_label,
            (SELECT COUNT(*) FROM attachments
             WHERE mail_id = m.id AND content_id IS NULL) AS attachment_count
     {$baseSQL}
     ORDER BY {$orderSQL}
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

// ── 現在のフィルタ用クエリ文字列ビルダー ──────────────────────────
function buildQuery(array $overrides = []): string
{
    $base = [
        'view'    => $_GET['view']    ?? '',
        'mailbox' => $_GET['mailbox'] ?? '',
        'read'    => $_GET['read']    ?? '',
        'rule'    => $_GET['rule']    ?? '',
        'q'       => $_GET['q']       ?? '',
        'sort'    => $_GET['sort']    ?? '',
        'page'    => '1',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== '0' || $v === '0');
    // 常に page だけ残す
    $params = array_merge($base, $overrides);
    $query  = http_build_query(array_filter($params, fn($v) => $v !== ''));
    return $query ? '?' . $query : '';
}

// ── 受信日時の短縮表示（Outlook スタイル）────────────────────────
function fmtDate(string $datetime): string
{
    $ts  = strtotime($datetime);
    $dow = ['日','月','火','水','木','金','土'][(int)date('w', $ts)];
    if (date('Y', $ts) === date('Y')) {
        return date('n/j', $ts) . "({$dow})";
    }
    return date('Y/n/j', $ts) . "({$dow})";
}

// ── ルールフィルタ選択肢 ──────────────────────────────────────────
$filterRuleOptions = [];
if (!$viewTrash) {  // 通常ビュー・無視ビュー両方で表示
    $mbCond   = $filterMailbox > 0 ? 'AND r.mailbox_id=?' : '';
    $mbParams = $filterMailbox > 0 ? [$filterMailbox] : [];
    $filterRuleOptions = Database::fetchAll(
        "SELECT r.id, r.label, r.scope, mb.label AS mailbox_label
         FROM rules r
         INNER JOIN monitored_mailboxes mb ON mb.id = r.mailbox_id
         INNER JOIN subscriptions s ON s.mailbox_id = r.mailbox_id AND s.user_id = ?
         WHERE (
             (r.scope='personal' AND r.user_id=?)
             OR
             (r.scope='global' AND NOT EXISTS (
                 SELECT 1 FROM rule_exclusions re WHERE re.rule_id=r.id AND re.user_id=?
             ))
         )
         AND NOT (r.match_field='any' AND r.match_pattern='*'
                  AND r.action='ignore' AND r.priority=999)
         {$mbCond}
         ORDER BY mb.label ASC, r.scope DESC, r.label ASC",
        array_merge([(int)$user['id'], (int)$user['id'], (int)$user['id']], $mbParams)
    );
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

                <!-- フィルタ群 + 無視・ゴミ箱タブ -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if ($viewTrash || $viewIgnored): ?>
                    <a href="/dashboard.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> 一覧へ戻る
                    </a>
                    <?php else: ?>
                    <select class="form-select form-select-sm" style="width:auto"
                            onchange="location.href=this.value">
                        <option value="/dashboard.php<?= buildQuery(['read' => '',  'page' => '1']) ?>"
                                <?= $filterRead === ''  ? 'selected' : '' ?>>すべて</option>
                        <option value="/dashboard.php<?= buildQuery(['read' => '0', 'page' => '1']) ?>"
                                <?= $filterRead === '0' ? 'selected' : '' ?>>未読</option>
                        <option value="/dashboard.php<?= buildQuery(['read' => '1', 'page' => '1']) ?>"
                                <?= $filterRead === '1' ? 'selected' : '' ?>>既読</option>
                    </select>
                    <?php endif; ?>

                    <?php if (!$viewTrash && !empty($filterRuleOptions)): ?>
                    <select class="form-select form-select-sm" style="width:auto"
                            onchange="location.href=this.value">
                        <option value="/dashboard.php<?= buildQuery(['rule' => '', 'page' => '1']) ?>"
                                <?= $filterRule === 0 ? 'selected' : '' ?>>受信ルール: すべて</option>
                        <?php foreach ($filterRuleOptions as $ro): ?>
                        <option value="/dashboard.php<?= buildQuery(['rule' => $ro['id'], 'page' => '1']) ?>"
                                <?= $filterRule === (int)$ro['id'] ? 'selected' : '' ?>>
                            <?= Helpers::e(($ro['scope'] === 'global' ? '[G] ' : '') . $ro['label']
                                . ($filterMailbox === 0 ? ' — ' . $ro['mailbox_label'] : '')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php
                    $ignoredCount = (int)(Database::fetchOne(
                        'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=? AND is_ignored=1 AND is_trashed=0',
                        [(int)$user['id']]
                    )['cnt'] ?? 0);
                    $trashCount = (int)(Database::fetchOne(
                        'SELECT COUNT(*) AS cnt FROM notifications WHERE user_id=? AND is_trashed=1',
                        [(int)$user['id']]
                    )['cnt'] ?? 0);
                    ?>

                    <?php if (!$viewTrash): ?>
                    <?php if ($viewIgnored): ?>
                    <span class="nav-link py-1 px-2 active" style="pointer-events:none">
                        <i class="bi bi-eye-slash"></i> 無視
                        <?php if ($ignoredCount > 0): ?>
                            <span class="badge bg-secondary ms-1"><?= $ignoredCount ?></span>
                        <?php endif; ?>
                    </span>
                    <?php else: ?>
                    <a class="nav-link py-1 px-2"
                       href="/dashboard.php<?= buildQuery(['view' => 'ignored', 'read' => '', 'page' => '1']) ?>">
                        <i class="bi bi-eye-slash"></i> 無視
                        <?php if ($ignoredCount > 0): ?>
                            <span class="badge bg-secondary ms-1"><?= $ignoredCount ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$viewIgnored): ?>
                    <?php if ($viewTrash): ?>
                    <span class="nav-link py-1 px-2 active" style="pointer-events:none">
                        <i class="bi bi-trash3"></i> ゴミ箱
                        <?php if ($trashCount > 0): ?>
                            <span class="badge bg-secondary ms-1"><?= $trashCount ?></span>
                        <?php endif; ?>
                    </span>
                    <?php else: ?>
                    <a class="nav-link py-1 px-2"
                       href="/dashboard.php<?= buildQuery(['view' => 'trash', 'read' => '', 'page' => '1']) ?>">
                        <i class="bi bi-trash3"></i> ゴミ箱
                        <?php if ($trashCount > 0): ?>
                            <span class="badge bg-secondary ms-1"><?= $trashCount ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- キーワード検索 + ソート -->
                <form method="get" action="/dashboard.php" class="d-flex gap-2 search-form">
                    <?php if ($viewTrash): ?>
                        <input type="hidden" name="view" value="trash">
                    <?php endif; ?>
                    <?php if ($filterMailbox): ?>
                        <input type="hidden" name="mailbox" value="<?= $filterMailbox ?>">
                    <?php endif; ?>
                    <?php if ($filterRead !== ''): ?>
                        <input type="hidden" name="read" value="<?= Helpers::e($filterRead) ?>">
                    <?php endif; ?>
                    <?php if ($filterRule > 0): ?>
                        <input type="hidden" name="rule" value="<?= $filterRule ?>">
                    <?php endif; ?>
                    <select name="sort" class="form-select form-select-sm"
                            style="width:auto" onchange="this.form.submit()">
                        <option value=""       <?= $sortBy === ''       ? 'selected' : '' ?>>受信日時 ↓</option>
                        <option value="from"   <?= $sortBy === 'from'   ? 'selected' : '' ?>>差出人 A→Z</option>
                        <option value="server" <?= $sortBy === 'server' ? 'selected' : '' ?>>発信サーバー A→Z</option>
                    </select>
                    <input type="search" name="q" class="form-control form-control-sm"
                           placeholder="件名・差出人を検索..."
                           value="<?= Helpers::e($search) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>

            <!-- 通知リスト（Outlook スタイル 3行レイアウト）-->
            <meta name="csrf-token" content="<?= Helpers::e(Auth::csrfToken()) ?>">
            <div class="notif-list">
                <?php if (empty($notifications)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox display-6 d-block mb-2"></i>
                    通知はありません
                </div>
                <?php else: ?>
                    <?php foreach ($notifications as $n): ?>
                    <?php $itemClass = $n['is_ignored'] ? 'notif-ignored' : ($n['is_read'] ? 'notif-read' : 'notif-unread'); ?>
                    <div class="notif-item <?= $itemClass ?>"
                         onclick="location.href='/mail.php?n=<?= (int)$n['id'] ?>'">
                        <!-- 未読ドット -->
                        <div class="notif-dot">
                            <?php if (!$n['is_read'] && !$n['is_ignored']): ?>
                                <i class="bi bi-circle-fill text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <!-- コンテンツ -->
                        <div class="notif-content">
                            <!-- 1行目: 差出人 | 宛先メールボックス -->
                            <div class="notif-row1">
                                <span class="notif-from">
                                    <?= Helpers::e($n['from_name'] ?: $n['from_address']) ?>
                                </span>
                                <span class="notif-to">
                                    To:&nbsp;<?= Helpers::e($n['mailbox_label']) ?>
                                </span>
                            </div>
                            <!-- 2行目: 件名 | 受信日時 + アクション -->
                            <div class="notif-row2">
                                <span class="notif-subject">
                                    <?= Helpers::e($n['subject'] ?: '（件名なし）') ?>
                                    <?php if ((int)($n['attachment_count'] ?? 0) > 0): ?>
                                        <i class="bi bi-paperclip ms-1" title="添付ファイルあり"></i>
                                    <?php endif; ?>
                                    <?php if ($n['is_ignored']): ?>
                                        <span class="badge bg-secondary ms-1 opacity-50">無視</span>
                                    <?php endif; ?>
                                </span>
                                <div class="notif-actions" onclick="event.stopPropagation()">
                                    <span class="notif-time"><?= Helpers::e(fmtDate($n['received_at'])) ?></span>
                                    <?php if (!$viewTrash): ?>
                                    <button class="btn notif-trash-btn"
                                            data-nid="<?= (int)$n['id'] ?>" title="ゴミ箱へ移動">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                    <?php else: ?>
                                    <button class="btn notif-restore-btn"
                                            data-nid="<?= (int)$n['id'] ?>" title="受信箱に戻す">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                    <button class="btn notif-delete-btn"
                                            data-nid="<?= (int)$n['id'] ?>" title="完全削除">
                                        <i class="bi bi-trash3-fill"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- 3行目: 本文プレビュー -->
                            <div class="notif-preview">
                                <?= Helpers::e(mb_substr(trim($n['body_preview'] ?? ''), 0, 150)) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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

            <?php if ($viewTrash && $trashCount > 0): ?>
            <div class="card-footer bg-white py-2 text-end">
                <form method="post" action="/dashboard.php?view=trash" class="d-inline">
                    <input type="hidden" name="action" value="empty_trash">
                    <input type="hidden" name="csrf_token"
                           value="<?= Helpers::e(Auth::csrfToken()) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"
                            data-confirm="ゴミ箱をすべて完全削除しますか？この操作は取り消せません。">
                        <i class="bi bi-trash3-fill"></i> すべて完全削除
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
