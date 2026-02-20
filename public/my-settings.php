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
    }
}

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

</div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
