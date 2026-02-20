<?php
declare(strict_types=1);

/**
 * admin/subscriptions.php — 購読関係管理
 *
 * 機能：
 *   - メールボックスごとに購読している従業員を管理
 *   - 購読追加 / 削除
 *
 * URL パラメータ：
 *   ?mailbox={id}  選択中のメールボックス
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = '購読管理';
Auth::requireAdmin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['danger', 'セキュリティエラー。'];
        Helpers::redirect('/admin/subscriptions.php');
    }

    $action    = $_POST['action']    ?? '';
    $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
    $userId    = (int)($_POST['user_id']    ?? 0);

    if ($action === 'add' && $mailboxId && $userId) {
        try {
            Database::query(
                'INSERT IGNORE INTO subscriptions (mailbox_id, user_id) VALUES (?, ?)',
                [$mailboxId, $userId]
            );
            AuditLog::record('subscription.add', 'subscription', null, [
                'mailbox_id' => $mailboxId,
                'user_id'    => $userId,
            ]);
            $_SESSION['flash'] = ['success', '購読を追加しました。'];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['danger', '追加に失敗しました: ' . $e->getMessage()];
        }
        Helpers::redirect('/admin/subscriptions.php?mailbox=' . $mailboxId);
    }

    if ($action === 'remove') {
        $subId = (int)($_POST['sub_id'] ?? 0);
        Database::query('DELETE FROM subscriptions WHERE id = ?', [$subId]);
        AuditLog::record('subscription.remove', 'subscription', null, [
            'mailbox_id' => $mailboxId,
        ]);
        $_SESSION['flash'] = ['success', '購読を解除しました。'];
        Helpers::redirect('/admin/subscriptions.php?mailbox=' . $mailboxId);
    }
}

// ── データ取得 ─────────────────────────────────────────────────────
$mailboxes = Database::fetchAll(
    'SELECT * FROM monitored_mailboxes ORDER BY label ASC'
);

$selectedMailboxId = (int)($_GET['mailbox'] ?? ($mailboxes[0]['id'] ?? 0));
$selectedMailbox   = null;
$subscribedUsers   = [];
$availableUsers    = [];

if ($selectedMailboxId) {
    $selectedMailbox = Database::fetchOne(
        'SELECT * FROM monitored_mailboxes WHERE id = ?',
        [$selectedMailboxId]
    );

    // 既に購読しているユーザー
    $subscribedUsers = Database::fetchAll(
        'SELECT u.id, u.name, u.email, u.role, u.status, s.id AS sub_id
         FROM subscriptions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.mailbox_id = ?
         ORDER BY u.name ASC',
        [$selectedMailboxId]
    );

    $subscribedIds = array_column($subscribedUsers, 'id');

    // まだ購読していないアクティブなユーザー
    $allActiveUsers = Database::fetchAll(
        "SELECT id, name, email FROM users WHERE status = 'active' ORDER BY name ASC"
    );
    $availableUsers = array_filter(
        $allActiveUsers,
        fn($u) => !in_array((int)$u['id'], $subscribedIds, true)
    );
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/partials/subnav.php';
?>

<h4 class="mb-3"><i class="bi bi-arrow-left-right"></i> 購読管理</h4>

<?php if ($flash): ?>
<div class="alert alert-<?= Helpers::e($flash[0]) ?> alert-autofade py-2">
    <i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
    <?= Helpers::e($flash[1]) ?>
</div>
<?php endif; ?>

<?php if (empty($mailboxes)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    監視メールボックスが登録されていません。
    <a href="/admin/mailboxes.php">メールボックスを追加</a>してください。
</div>
<?php else: ?>

<div class="row g-4">

    <!-- ── 左：メールボックス選択 ── -->
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white small fw-semibold text-uppercase text-muted py-2">
                メールボックス
            </div>
            <div class="list-group list-group-flush mailbox-sidebar">
                <?php foreach ($mailboxes as $mb): ?>
                <a href="/admin/subscriptions.php?mailbox=<?= (int)$mb['id'] ?>"
                   class="list-group-item list-group-item-action
                          <?= (int)$mb['id'] === $selectedMailboxId ? 'active' : '' ?>">
                    <div class="fw-semibold"><?= Helpers::e($mb['label']) ?></div>
                    <div class="small text-muted"><?= Helpers::e($mb['email_address']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── 右：購読管理パネル ── -->
    <div class="col-md-9">
        <?php if ($selectedMailbox): ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                <span class="fw-semibold">
                    <i class="bi bi-inbox-fill text-primary"></i>
                    <?= Helpers::e($selectedMailbox['label']) ?>
                    <span class="text-muted fw-normal small ms-2">
                        <?= Helpers::e($selectedMailbox['email_address']) ?>
                    </span>
                </span>
                <span class="badge bg-primary">
                    <?= count($subscribedUsers) ?> 人
                </span>
            </div>

            <!-- 購読中ユーザー -->
            <div class="card-body pb-2">
                <?php if (empty($subscribedUsers)): ?>
                <p class="text-muted small mb-2">購読者はいません。</p>
                <?php else: ?>
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>氏名</th>
                            <th>メールアドレス</th>
                            <th class="text-center">権限</th>
                            <th class="text-center">状態</th>
                            <th style="width:80px"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($subscribedUsers as $u): ?>
                    <tr>
                        <td><?= Helpers::e($u['name']) ?></td>
                        <td class="small text-muted"><?= Helpers::e($u['email']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $u['role'] === 'admin' ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                                <?= $u['role'] === 'admin' ? '管理者' : '一般' ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $u['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $u['status'] === 'active' ? '有効' : $u['status'] ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" class="m-0">
                                <input type="hidden" name="action"     value="remove">
                                <input type="hidden" name="mailbox_id" value="<?= $selectedMailboxId ?>">
                                <input type="hidden" name="sub_id"     value="<?= (int)$u['sub_id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                        data-confirm="【<?= Helpers::e($u['name']) ?>】の購読を解除しますか？">
                                    <i class="bi bi-person-dash"></i> 解除
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- 購読追加 -->
            <?php if (!empty($availableUsers)): ?>
            <div class="card-footer bg-light">
                <form method="post" action="/admin/subscriptions.php" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="action"     value="add">
                    <input type="hidden" name="mailbox_id" value="<?= $selectedMailboxId ?>">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                    <select name="user_id" class="form-select form-select-sm" required>
                        <option value="">— ユーザーを選択 —</option>
                        <?php foreach ($availableUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>">
                            <?= Helpers::e($u['name']) ?>（<?= Helpers::e($u['email']) ?>）
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-success flex-shrink-0" type="submit">
                        <i class="bi bi-person-plus"></i> 追加
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div class="card-footer bg-light text-muted small">
                すべてのアクティブユーザーが購読済みです。
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                左のメールボックスを選択してください。
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
