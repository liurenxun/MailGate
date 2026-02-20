<?php
declare(strict_types=1);

/**
 * admin/users.php — 従業員アカウント管理
 *
 * 機能：
 *   - ユーザー一覧（status / role バッジ表示）
 *   - ユーザー追加（setup メール自動送信）
 *   - 開通メール再送（pending ユーザーのみ）
 *   - アカウント無効化 / 有効化
 *   - アカウント削除（自分自身・最後の admin は不可）
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = 'アカウント管理';
Auth::requireAdmin();
$currentUser = Auth::getCurrentUser();

$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['danger', 'セキュリティエラー。'];
        Helpers::redirect('/admin/users.php');
    }

    $action = $_POST['action'] ?? '';

    // ── ユーザー追加 ──────────────────────────────────────────────
    if ($action === 'add') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'recipient';

        if ($name === '' || $email === '') {
            $errors[] = '氏名とメールアドレスは必須です。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '有効なメールアドレスを入力してください。';
        } elseif (!in_array($role, ['admin', 'recipient'], true)) {
            $errors[] = '権限の指定が無効です。';
        } else {
            $newUser = Auth::createUser($name, $email, $role);
            if ($newUser === null) {
                $errors[] = 'このメールアドレスはすでに登録されています。';
            } else {
                AuditLog::record('user.create', 'user', (int)$newUser['id'], [
                    'name'  => $name,
                    'email' => $email,
                    'role'  => $role,
                ]);
                $_SESSION['flash'] = ['success', "【{$name}】のアカウントを作成し、開通メールを送信しました。"];
                Helpers::redirect('/admin/users.php');
            }
        }
    }

    // ── 開通メール再送 ────────────────────────────────────────────
    if ($action === 'resend') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if (Auth::resendSetupEmail($uid)) {
            AuditLog::record('user.resend_setup', 'user', $uid);
            $_SESSION['flash'] = ['success', '開通メールを再送しました。'];
        } else {
            $_SESSION['flash'] = ['danger', '再送に失敗しました（既に有効なアカウントの可能性があります）。'];
        }
        Helpers::redirect('/admin/users.php');
    }

    // ── 無効化 / 有効化 ──────────────────────────────────────────
    if ($action === 'disable' || $action === 'enable') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$currentUser['id']) {
            $_SESSION['flash'] = ['danger', '自分自身を無効化することはできません。'];
        } else {
            if ($action === 'disable') {
                Auth::disableUser($uid);
                AuditLog::record('user.disable', 'user', $uid);
                $_SESSION['flash'] = ['success', 'アカウントを無効化しました。'];
            } else {
                Auth::enableUser($uid);
                AuditLog::record('user.enable', 'user', $uid);
                $_SESSION['flash'] = ['success', 'アカウントを有効化しました。'];
            }
        }
        Helpers::redirect('/admin/users.php');
    }

    // ── 削除 ──────────────────────────────────────────────────────
    if ($action === 'delete') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$uid]);

        if (!$user) {
            $_SESSION['flash'] = ['danger', 'ユーザーが見つかりません。'];
        } elseif ($uid === (int)$currentUser['id']) {
            $_SESSION['flash'] = ['danger', '自分自身を削除することはできません。'];
        } elseif ($user['role'] === 'admin') {
            // 最後の admin チェック
            $adminCount = (int)(Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM users WHERE role = 'admin' AND status != 'disabled'"
            )['cnt'] ?? 0);
            if ($adminCount <= 1) {
                $_SESSION['flash'] = ['danger', '最後の管理者アカウントは削除できません。'];
            } else {
                AuditLog::record('user.delete', 'user', $uid, [
                    'name'  => $user['name'],
                    'email' => $user['email'],
                ]);
                Database::query('DELETE FROM users WHERE id = ?', [$uid]);
                $_SESSION['flash'] = ['success', 'アカウントを削除しました。'];
            }
        } else {
            AuditLog::record('user.delete', 'user', $uid, [
                'name'  => $user['name'],
                'email' => $user['email'],
            ]);
            Database::query('DELETE FROM users WHERE id = ?', [$uid]);
            $_SESSION['flash'] = ['success', 'アカウントを削除しました。'];
        }
        Helpers::redirect('/admin/users.php');
    }
}

// ── ユーザー一覧取得 ───────────────────────────────────────────────
$users = Database::fetchAll(
    'SELECT u.*,
            COUNT(s.id) AS subscription_count
     FROM users u
     LEFT JOIN subscriptions s ON s.user_id = u.id
     GROUP BY u.id
     ORDER BY u.role ASC, u.status ASC, u.name ASC'
);

$statusLabels = [
    'pending'  => ['warning',   'pending',    '未設定'],
    'active'   => ['success',   'check-circle', '有効'],
    'disabled' => ['secondary', 'x-circle',    '無効'],
];

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/partials/subnav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-people-fill"></i> アカウント管理</h4>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= Helpers::e($flash[0]) ?> alert-autofade py-2">
    <i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
    <?= Helpers::e($flash[1]) ?>
</div>
<?php endif; ?>

<!-- ── アカウント追加フォーム ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-person-plus"></i> 新しいアカウントを追加
    </div>
    <div class="card-body">
        <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-3">
            <?php foreach ($errors as $e): ?>
                <div><i class="bi bi-exclamation-triangle"></i> <?= Helpers::e($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" action="/admin/users.php" novalidate>
            <input type="hidden" name="action"     value="add">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">氏名 <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= Helpers::e($_POST['name'] ?? '') ?>"
                           placeholder="山田 太郎" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">メールアドレス <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= Helpers::e($_POST['email'] ?? '') ?>"
                           placeholder="yamada@example.com" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">権限</label>
                    <select name="role" class="form-select">
                        <option value="recipient">一般</option>
                        <option value="admin">管理者</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send"></i> 追加・送信
                    </button>
                </div>
            </div>
            <div class="form-text mt-2">
                <i class="bi bi-info-circle"></i>
                追加後、パスワード設定用の開通メールが自動送信されます（有効期限 24 時間）。
            </div>
        </form>
    </div>
</div>

<!-- ── ユーザー一覧 ── -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>氏名</th>
                    <th>メールアドレス</th>
                    <th class="text-center">権限</th>
                    <th class="text-center">状態</th>
                    <th class="text-center">購読数</th>
                    <th>登録日</th>
                    <th style="width:200px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <?php [$badgeType, $icon, $statusLabel] = $statusLabels[$u['status']] ?? ['secondary', 'question', '?']; ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= Helpers::e($u['name']) ?></div>
                    <?php if ($u['notify_email']): ?>
                    <div class="small text-muted">
                        <i class="bi bi-bell"></i> <?= Helpers::e($u['notify_email']) ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="small"><?= Helpers::e($u['email']) ?></td>
                <td class="text-center">
                    <?php if ($u['role'] === 'admin'): ?>
                        <span class="badge bg-warning text-dark">管理者</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">一般</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <span class="badge bg-<?= $badgeType ?>">
                        <i class="bi bi-<?= $icon ?>"></i> <?= $statusLabel ?>
                    </span>
                </td>
                <td class="text-center">
                    <span class="badge bg-secondary"><?= (int)$u['subscription_count'] ?></span>
                </td>
                <td class="small text-muted">
                    <?= date('Y/m/d', strtotime($u['created_at'])) ?>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <!-- 開通メール再送（pending のみ） -->
                        <?php if ($u['status'] === 'pending'): ?>
                        <form method="post" class="m-0">
                            <input type="hidden" name="action"  value="resend">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                            <button class="btn btn-sm btn-outline-primary" type="submit"
                                    title="開通メール再送">
                                <i class="bi bi-envelope-arrow-up"></i> 再送
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- 無効化 / 有効化 -->
                        <?php if ($u['id'] !== $currentUser['id']): ?>
                        <form method="post" class="m-0">
                            <input type="hidden" name="action"
                                   value="<?= $u['status'] === 'disabled' ? 'enable' : 'disable' ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                            <button class="btn btn-sm <?= $u['status'] === 'disabled'
                                ? 'btn-outline-success' : 'btn-outline-warning' ?>"
                                    type="submit"
                                    title="<?= $u['status'] === 'disabled' ? '有効化' : '無効化' ?>">
                                <i class="bi bi-<?= $u['status'] === 'disabled' ? 'person-check' : 'person-slash' ?>"></i>
                            </button>
                        </form>

                        <!-- 削除 -->
                        <form method="post" class="m-0">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                    title="削除"
                                    data-confirm="【<?= Helpers::e($u['name']) ?>】を削除しますか？\n購読・通知履歴もすべて削除されます。">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted small">(自分)</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
