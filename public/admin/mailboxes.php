<?php
declare(strict_types=1);

/**
 * admin/mailboxes.php — 監視メールボックス管理
 *
 * 機能：
 *   - メールボックス一覧（ステータス・最終取得時刻・エラー表示）
 *   - 追加 / 編集フォーム（IMAP 認証情報を AES-256-CBC で暗号化保存）
 *   - 削除（CASCADE で関連レコードも削除）
 *   - IMAP 接続テスト
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = '監視メールボックス管理';
Auth::requireAdmin();

// ── フラッシュメッセージ ──────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$errors = [];

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['danger', 'セキュリティエラー。再度お試しください。'];
        Helpers::redirect('/admin/mailboxes.php');
    }

    $action = $_POST['action'] ?? '';

    // ── 保存（追加 / 編集）──────────────────────────────────────────
    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $label       = trim($_POST['label']       ?? '');
        $emailAddr   = trim($_POST['email_address'] ?? '');
        $imapHost    = trim($_POST['imap_host']   ?? '');
        $imapPort    = (int)($_POST['imap_port']  ?? 993);
        $imapEnc     = $_POST['imap_encryption']  ?? 'ssl';
        $imapUser    = trim($_POST['imap_user']   ?? '');
        $imapPass    = $_POST['imap_pass']        ?? '';
        $imapFolder  = trim($_POST['imap_folder'] ?? 'INBOX') ?: 'INBOX';
        $fetchLimit  = max(1, (int)($_POST['fetch_limit'] ?? 100));
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        $validEnc = ['ssl', 'tls', 'none'];
        if ($label === '' || $emailAddr === '' || $imapHost === '' || $imapUser === '') {
            $errors[] = '必須フィールドを入力してください。';
        } elseif (!in_array($imapEnc, $validEnc, true)) {
            $errors[] = '暗号化方式が無効です。';
        } elseif ($id === 0 && $imapPass === '') {
            $errors[] = '新規追加時はパスワードを入力してください。';
        } else {
            if ($id === 0) {
                // 新規追加
                $passEnc = Helpers::encrypt($imapPass);
                Database::query(
                    'INSERT INTO monitored_mailboxes
                     (label, email_address, imap_host, imap_port, imap_encryption,
                      imap_user, imap_pass_enc, imap_folder, fetch_limit, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$label, $emailAddr, $imapHost, $imapPort, $imapEnc,
                     $imapUser, $passEnc, $imapFolder, $fetchLimit, $isActive]
                );
                AuditLog::record('mailbox.create', 'mailbox', Database::lastInsertId(), ['label' => $label]);
                $_SESSION['flash'] = ['success', 'メールボックスを追加しました。'];
            } else {
                // 編集
                if ($imapPass !== '') {
                    $passEnc = Helpers::encrypt($imapPass);
                    Database::query(
                        'UPDATE monitored_mailboxes
                         SET label=?, email_address=?, imap_host=?, imap_port=?,
                             imap_encryption=?, imap_user=?, imap_pass_enc=?,
                             imap_folder=?, fetch_limit=?, is_active=?
                         WHERE id=?',
                        [$label, $emailAddr, $imapHost, $imapPort, $imapEnc,
                         $imapUser, $passEnc, $imapFolder, $fetchLimit, $isActive, $id]
                    );
                } else {
                    // パスワード変更なし
                    Database::query(
                        'UPDATE monitored_mailboxes
                         SET label=?, email_address=?, imap_host=?, imap_port=?,
                             imap_encryption=?, imap_user=?,
                             imap_folder=?, fetch_limit=?, is_active=?
                         WHERE id=?',
                        [$label, $emailAddr, $imapHost, $imapPort, $imapEnc,
                         $imapUser, $imapFolder, $fetchLimit, $isActive, $id]
                    );
                }
                AuditLog::record('mailbox.update', 'mailbox', $id, ['label' => $label]);
                $_SESSION['flash'] = ['success', 'メールボックスを更新しました。'];
            }
            Helpers::redirect('/admin/mailboxes.php');
        }
    }

    // ── 削除 ──────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id     = (int)($_POST['mailbox_id'] ?? 0);
        $delMb  = Database::fetchOne('SELECT label FROM monitored_mailboxes WHERE id = ?', [$id]);
        AuditLog::record('mailbox.delete', 'mailbox', $id, [
            'label' => $delMb['label'] ?? '',
        ]);
        Database::query('DELETE FROM monitored_mailboxes WHERE id = ?', [$id]);
        $_SESSION['flash'] = ['success', 'メールボックスを削除しました。'];
        Helpers::redirect('/admin/mailboxes.php');
    }

    // ── 接続テスト ─────────────────────────────────────────────────
    if ($action === 'test') {
        $id = (int)($_POST['mailbox_id'] ?? 0);
        $mb = Database::fetchOne('SELECT * FROM monitored_mailboxes WHERE id = ?', [$id]);
        if ($mb) {
            $password = Helpers::decrypt($mb['imap_pass_enc']);
            $flags    = match($mb['imap_encryption']) {
                'ssl'   => '/imap/ssl',
                'tls'   => '/imap/tls',
                default => '/imap/notls',
            };
            $mbStr = '{' . $mb['imap_host'] . ':' . $mb['imap_port'] . $flags . '}' . $mb['imap_folder'];
            imap_errors();
            $conn = @imap_open($mbStr, $mb['imap_user'], $password, OP_READONLY);
            if ($conn) {
                imap_close($conn);
                Database::query(
                    'UPDATE monitored_mailboxes SET last_error = NULL, last_error_at = NULL WHERE id = ?',
                    [$id]
                );
                AuditLog::record('mailbox.test_imap', 'mailbox', $id, ['result' => 'success']);
                $_SESSION['flash'] = ['success', '【' . $mb['label'] . '】接続テスト成功しました。'];
            } else {
                $err = imap_last_error() ?: 'Unknown error';
                Database::query(
                    'UPDATE monitored_mailboxes SET last_error = ?, last_error_at = NOW() WHERE id = ?',
                    [mb_substr($err, 0, 1000), $id]
                );
                AuditLog::record('mailbox.test_imap', 'mailbox', $id, ['result' => 'failure']);
                $_SESSION['flash'] = ['danger', '【' . $mb['label'] . '】接続失敗: ' . $err];
            }
        }
        Helpers::redirect('/admin/mailboxes.php');
    }
}

// ── データ取得 ─────────────────────────────────────────────────────
$mailboxes = Database::fetchAll(
    'SELECT mb.*,
            COUNT(s.id)  AS subscriber_count
     FROM monitored_mailboxes mb
     LEFT JOIN subscriptions s ON s.mailbox_id = mb.id
     GROUP BY mb.id
     ORDER BY mb.label ASC'
);

// 編集時：対象レコード取得
$editMb = null;
$viewMode = 'list'; // list | add | edit
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'add') {
        $viewMode = 'add';
    } elseif ($_GET['action'] === 'edit' && isset($_GET['id'])) {
        $editMb   = Database::fetchOne(
            'SELECT * FROM monitored_mailboxes WHERE id = ?',
            [(int)$_GET['id']]
        );
        $viewMode = $editMb ? 'edit' : 'list';
    }
}

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/partials/subnav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-inbox-fill"></i> 監視メールボックス管理</h4>
    <?php if ($viewMode === 'list'): ?>
    <a href="/admin/mailboxes.php?action=add" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> メールボックスを追加
    </a>
    <?php else: ?>
    <a href="/admin/mailboxes.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> 一覧へ戻る
    </a>
    <?php endif; ?>
</div>

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

<?php /* ──────────── 追加 / 編集 フォーム ──────────── */ ?>
<?php if ($viewMode !== 'list'): ?>
<?php $isEdit = $viewMode === 'edit'; $mb = $editMb; ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        <?= $isEdit ? 'メールボックスを編集' : '新しいメールボックスを追加' ?>
    </div>
    <div class="card-body">
        <form method="post" action="/admin/mailboxes.php" novalidate>
            <input type="hidden" name="action"     value="save">
            <input type="hidden" name="id"         value="<?= $isEdit ? (int)$mb['id'] : 0 ?>">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">表示名 <span class="text-danger">*</span></label>
                    <input type="text" name="label" class="form-control" required
                           value="<?= Helpers::e($_POST['label'] ?? $mb['label'] ?? '') ?>"
                           placeholder="例: 営業問合せ">
                </div>
                <div class="col-md-6">
                    <label class="form-label">メールアドレス <span class="text-danger">*</span></label>
                    <input type="email" name="email_address" class="form-control" required
                           value="<?= Helpers::e($_POST['email_address'] ?? $mb['email_address'] ?? '') ?>"
                           placeholder="sales@example.com">
                </div>

                <div class="col-12"><hr class="mt-1 mb-0"><p class="small text-muted mt-2 mb-0">IMAP 設定</p></div>

                <div class="col-md-5">
                    <label class="form-label">IMAP ホスト <span class="text-danger">*</span></label>
                    <input type="text" name="imap_host" class="form-control" required
                           value="<?= Helpers::e($_POST['imap_host'] ?? $mb['imap_host'] ?? '') ?>"
                           placeholder="imap.example.com">
                </div>
                <div class="col-md-2">
                    <label class="form-label">ポート</label>
                    <input type="number" name="imap_port" class="form-control"
                           value="<?= (int)($_POST['imap_port'] ?? $mb['imap_port'] ?? 993) ?>"
                           min="1" max="65535">
                </div>
                <div class="col-md-3">
                    <label class="form-label">暗号化</label>
                    <select name="imap_encryption" class="form-select">
                        <?php foreach (['ssl' => 'SSL', 'tls' => 'TLS (STARTTLS)', 'none' => 'なし'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= ($mb['imap_encryption'] ?? 'ssl') === $v ? 'selected' : '' ?>>
                            <?= $l ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">フォルダ</label>
                    <input type="text" name="imap_folder" class="form-control"
                           value="<?= Helpers::e($_POST['imap_folder'] ?? $mb['imap_folder'] ?? 'INBOX') ?>">
                </div>

                <div class="col-md-5">
                    <label class="form-label">ユーザー名 <span class="text-danger">*</span></label>
                    <input type="text" name="imap_user" class="form-control" required
                           autocomplete="off"
                           value="<?= Helpers::e($_POST['imap_user'] ?? $mb['imap_user'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">
                        パスワード
                        <?php if ($isEdit): ?>
                            <span class="text-muted small">（変更する場合のみ入力）</span>
                        <?php else: ?>
                            <span class="text-danger">*</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="imap_pass" class="form-control"
                           autocomplete="new-password"
                           <?= !$isEdit ? 'required' : '' ?>>
                </div>
                <div class="col-md-2">
                    <label class="form-label">取得上限</label>
                    <input type="number" name="fetch_limit" class="form-control"
                           value="<?= (int)($_POST['fetch_limit'] ?? $mb['fetch_limit'] ?? 100) ?>"
                           min="1" max="9999">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active"
                               id="is_active" value="1"
                               <?= ($mb['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">
                            有効（Cron による定期取得を行う）
                        </label>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> <?= $isEdit ? '更新する' : '追加する' ?>
                </button>
                <a href="/admin/mailboxes.php" class="btn btn-outline-secondary">キャンセル</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php /* ──────────── 一覧 ──────────── */ ?>
<?php if ($viewMode === 'list'): ?>
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>表示名</th>
                    <th>メールアドレス</th>
                    <th class="text-center">購読者</th>
                    <th class="text-center">状態</th>
                    <th>最終取得</th>
                    <th style="width:180px"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($mailboxes)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="bi bi-inbox display-6 d-block mb-2"></i>
                        メールボックスが登録されていません
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($mailboxes as $mb): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= Helpers::e($mb['label']) ?></div>
                        <div class="small text-muted"><?= Helpers::e($mb['imap_host']) ?></div>
                        <?php if ($mb['last_error']): ?>
                        <div class="small text-danger mt-1" title="<?= Helpers::e($mb['last_error']) ?>">
                            <i class="bi bi-exclamation-circle"></i>
                            <?= Helpers::e(mb_substr($mb['last_error'], 0, 60)) ?>…
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= Helpers::e($mb['email_address']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-secondary"><?= (int)$mb['subscriber_count'] ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($mb['is_active']): ?>
                            <span class="badge bg-success">有効</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">無効</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= $mb['last_fetched_at']
                            ? date('Y/m/d H:i', strtotime($mb['last_fetched_at']))
                            : '未取得' ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <!-- 接続テスト -->
                            <form method="post" class="m-0">
                                <input type="hidden" name="action"     value="test">
                                <input type="hidden" name="mailbox_id" value="<?= (int)$mb['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-info"
                                        title="接続テスト">
                                    <i class="bi bi-wifi"></i>
                                </button>
                            </form>
                            <!-- 編集 -->
                            <a href="/admin/mailboxes.php?action=edit&id=<?= (int)$mb['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="編集">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <!-- 削除 -->
                            <form method="post" class="m-0">
                                <input type="hidden" name="action"     value="delete">
                                <input type="hidden" name="mailbox_id" value="<?= (int)$mb['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        title="削除"
                                        data-confirm="【<?= Helpers::e($mb['label']) ?>】を削除しますか？\n関連する購読・ルール・メールもすべて削除されます。">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
