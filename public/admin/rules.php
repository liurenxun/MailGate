<?php
declare(strict_types=1);

/**
 * admin/rules.php — ルール管理
 *
 * 機能：
 *   - グローバルルール管理（全購読者に適用）
 *   - 任意の従業員の個人ルールを代理管理
 *
 * URL パラメータ：
 *   ?mailbox={id}          選択中のメールボックス
 *   ?tab=global|personal   表示タブ（デフォルト: global）
 *   ?user_id={id}          個人ルール表示対象ユーザー（tab=personal 時）
 */

require_once __DIR__ . '/../../src/bootstrap.php';

$pageTitle = 'ルール管理';
Auth::requireAdmin();
$currentUser = Auth::getCurrentUser();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$fieldLabels = [
    'from_address' => '差出人アドレス',
    'from_domain'  => '差出人ドメイン',
    'subject'      => '件名',
    'any'          => 'すべてのフィールド',
];

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['danger', 'セキュリティエラー。'];
        Helpers::redirect('/admin/rules.php');
    }

    $action    = $_POST['action']    ?? '';
    $mailboxId = (int)($_POST['mailbox_id'] ?? 0);
    $tab       = $_POST['tab'] ?? 'global';
    $targetUid = (int)($_POST['target_user_id'] ?? 0);

    $redirectBase = "/admin/rules.php?mailbox={$mailboxId}&tab={$tab}"
        . ($targetUid ? "&user_id={$targetUid}" : '');

    // ── ルール追加 ─────────────────────────────────────────────────
    if ($action === 'add') {
        $scope        = $_POST['scope']          ?? 'global';
        $label        = mb_substr(trim($_POST['label'] ?? ''), 0, 100);
        $matchField   = $_POST['match_field']    ?? '';
        $matchPattern = trim($_POST['match_pattern'] ?? '');
        $ruleAction   = $_POST['rule_action']    ?? '';
        $priority     = (int)($_POST['priority'] ?? 50);

        $validFields  = ['from_address', 'from_domain', 'subject', 'any'];
        $validActions = ['notify', 'ignore'];

        if ($label === '') {
            $_SESSION['flash'] = ['danger', 'ラベルを入力してください。'];
        } elseif ($matchPattern === '') {
            $_SESSION['flash'] = ['danger', 'パターンを入力してください。'];
        } elseif (!in_array($matchField, $validFields, true) || !in_array($ruleAction, $validActions, true)) {
            $_SESSION['flash'] = ['danger', '入力値が無効です。'];
        } elseif ($mailboxId === 0) {
            $_SESSION['flash'] = ['danger', 'メールボックスを選択してください。'];
        } else {
            $uid = ($scope === 'personal') ? $targetUid : null;

            // 重複ラベルチェック
            if ($uid === null) {
                $dupLabel = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id IS NULL AND label=?',
                    [$mailboxId, $scope, $label]
                );
            } else {
                $dupLabel = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=? AND label=?',
                    [$mailboxId, $scope, $uid, $label]
                );
            }

            // 重複条件チェック
            if ($uid === null) {
                $dupCond = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id IS NULL
                     AND match_field=? AND match_pattern=? AND action=?',
                    [$mailboxId, $scope, $matchField, $matchPattern, $ruleAction]
                );
            } else {
                $dupCond = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=?
                     AND match_field=? AND match_pattern=? AND action=?',
                    [$mailboxId, $scope, $uid, $matchField, $matchPattern, $ruleAction]
                );
            }

            if ($dupLabel) {
                $_SESSION['flash'] = ['danger', '同じラベルのルールがすでに存在します。'];
            } elseif ($dupCond) {
                $_SESSION['flash'] = ['danger', '同じ条件のルールがすでに存在します。'];
            } else {
                Database::query(
                    'INSERT INTO rules
                     (mailbox_id, label, scope, user_id, match_field, match_pattern, action, priority, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$mailboxId, $label, $scope, $uid, $matchField, $matchPattern, $ruleAction, $priority, (int)$currentUser['id']]
                );
                AuditLog::record('rule.create', 'rule', Database::lastInsertId(), [
                    'mailbox_id' => $mailboxId,
                    'scope'      => $scope,
                ]);
                $_SESSION['flash'] = ['success', 'ルールを追加しました。'];
            }
        }
        Helpers::redirect($redirectBase);
    }

    // ── ルール削除 ─────────────────────────────────────────────────
    if ($action === 'delete') {
        $ruleId = (int)($_POST['rule_id'] ?? 0);
        Database::query(
            'DELETE FROM rules WHERE id = ? AND mailbox_id = ?',
            [$ruleId, $mailboxId]
        );
        AuditLog::record('rule.delete', 'rule', $ruleId, ['mailbox_id' => $mailboxId]);
        $_SESSION['flash'] = ['success', 'ルールを削除しました。'];
        Helpers::redirect($redirectBase);
    }

    // ── 優先度変更 ─────────────────────────────────────────────────
    if ($action === 'priority') {
        $ruleId   = (int)($_POST['rule_id']  ?? 0);
        $priority = max(1, min(999, (int)($_POST['priority'] ?? 50)));
        Database::query(
            'UPDATE rules SET priority = ? WHERE id = ? AND mailbox_id = ?',
            [$priority, $ruleId, $mailboxId]
        );
        AuditLog::record('rule.priority', 'rule', $ruleId, ['priority' => $priority]);
        $_SESSION['flash'] = ['success', '優先度を更新しました。'];
        Helpers::redirect($redirectBase);
    }

    // ── ルール更新 ─────────────────────────────────────────────────
    if ($action === 'update') {
        $ruleId       = (int)($_POST['rule_id'] ?? 0);
        $label        = mb_substr(trim($_POST['label'] ?? ''), 0, 100);
        $matchField   = $_POST['match_field']   ?? '';
        $matchPattern = trim($_POST['match_pattern'] ?? '');
        $ruleAction   = $_POST['rule_action']   ?? '';
        $priority     = max(1, min(998, (int)($_POST['priority'] ?? 50)));

        $validFields  = ['from_address', 'from_domain', 'subject', 'any'];
        $validActions = ['notify', 'ignore'];

        $rule = Database::fetchOne(
            'SELECT * FROM rules WHERE id=? AND mailbox_id=?',
            [$ruleId, $mailboxId]
        );
        if (!$rule) {
            $_SESSION['flash'] = ['danger', '編集するルールが見つかりません。'];
        } elseif ($label === '') {
            $_SESSION['flash'] = ['danger', 'ラベルを入力してください。'];
        } elseif (!in_array($matchField, $validFields, true) || !in_array($ruleAction, $validActions, true)) {
            $_SESSION['flash'] = ['danger', '入力値が無効です。'];
        } elseif ($matchPattern === '') {
            $_SESSION['flash'] = ['danger', 'パターンを入力してください。'];
        } else {
            $uid = ($rule['scope'] === 'personal') ? (int)$rule['user_id'] : null;
            if ($uid === null) {
                $dupLabel = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id IS NULL AND label=? AND id!=?',
                    [$mailboxId, $rule['scope'], $label, $ruleId]
                );
                $dupCond = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id IS NULL
                     AND match_field=? AND match_pattern=? AND action=? AND id!=?',
                    [$mailboxId, $rule['scope'], $matchField, $matchPattern, $ruleAction, $ruleId]
                );
            } else {
                $dupLabel = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=? AND label=? AND id!=?',
                    [$mailboxId, $rule['scope'], $uid, $label, $ruleId]
                );
                $dupCond = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=?
                     AND match_field=? AND match_pattern=? AND action=? AND id!=?',
                    [$mailboxId, $rule['scope'], $uid, $matchField, $matchPattern, $ruleAction, $ruleId]
                );
            }
            if ($dupLabel) {
                $_SESSION['flash'] = ['danger', '同じラベルのルールがすでに存在します。'];
            } elseif ($dupCond) {
                $_SESSION['flash'] = ['danger', '同じ条件のルールがすでに存在します。'];
            } else {
                Database::query(
                    'UPDATE rules SET label=?, match_field=?, match_pattern=?, action=?, priority=? WHERE id=?',
                    [$label, $matchField, $matchPattern, $ruleAction, $priority, $ruleId]
                );
                AuditLog::record('rule.update', 'rule', $ruleId, ['mailbox_id' => $mailboxId]);
                $_SESSION['flash'] = ['success', 'ルールを更新しました。'];
            }
        }
        Helpers::redirect($redirectBase);
    }

    // ── グローバルルール適用トグル（代理設定）────────────────────────
    if ($action === 'proxy_toggle_rule') {
        $ruleId         = (int)($_POST['rule_id']        ?? 0);
        $proxyTargetUid = (int)($_POST['target_user_id'] ?? 0);

        $rule = Database::fetchOne(
            "SELECT id FROM rules WHERE id=? AND mailbox_id=? AND scope='global'",
            [$ruleId, $mailboxId]
        );
        if (!$rule || !$proxyTargetUid) {
            $_SESSION['flash'] = ['danger', 'ルールが見つかりません。'];
        } else {
            $existing = Database::fetchOne(
                'SELECT id FROM rule_exclusions WHERE rule_id=? AND user_id=?',
                [$ruleId, $proxyTargetUid]
            );
            if ($existing) {
                Database::query(
                    'DELETE FROM rule_exclusions WHERE rule_id=? AND user_id=?',
                    [$ruleId, $proxyTargetUid]
                );
                $_SESSION['flash'] = ['success', 'システムルールを有効にしました。'];
            } else {
                Database::query(
                    'INSERT INTO rule_exclusions (rule_id, user_id) VALUES (?,?)',
                    [$ruleId, $proxyTargetUid]
                );
                $_SESSION['flash'] = ['success', 'システムルールを無効にしました。'];
            }
        }
        Helpers::redirect($redirectBase);
    }
}

// ── データ取得 ─────────────────────────────────────────────────────
$mailboxes = Database::fetchAll(
    'SELECT * FROM monitored_mailboxes ORDER BY label ASC'
);

$selectedMailboxId = (int)($_GET['mailbox'] ?? ($mailboxes[0]['id'] ?? 0));
$selectedMailbox   = $selectedMailboxId
    ? Database::fetchOne('SELECT * FROM monitored_mailboxes WHERE id = ?', [$selectedMailboxId])
    : null;

$activeTab = in_array($_GET['tab'] ?? '', ['global', 'personal'], true) ? $_GET['tab'] : 'global';
$targetUid = (int)($_GET['user_id'] ?? 0);

// ── 全部無視ルール：選択中のメールボックスに存在しない場合は自動作成 ──
if ($selectedMailboxId) {
    $ignoreAllExists = Database::fetchOne(
        "SELECT id FROM rules WHERE mailbox_id=? AND scope='global'
         AND match_field='any' AND match_pattern='*' AND action='ignore' AND priority=999",
        [$selectedMailboxId]
    );
    if (!$ignoreAllExists) {
        Database::query(
            "INSERT INTO rules
             (mailbox_id, label, scope, user_id, match_field, match_pattern, action, priority, created_by)
             VALUES (?, '全部無視', 'global', NULL, 'any', '*', 'ignore', 999, ?)",
            [$selectedMailboxId, (int)$currentUser['id']]
        );
        $_ruleId = Database::lastInsertId();
        $_subs   = Database::fetchAll(
            'SELECT user_id FROM subscriptions WHERE mailbox_id=?',
            [$selectedMailboxId]
        );
        foreach ($_subs as $_sub) {
            Database::query(
                'INSERT IGNORE INTO rule_exclusions (rule_id, user_id) VALUES (?,?)',
                [$_ruleId, $_sub['user_id']]
            );
        }
    }
}

// 購読しているアクティブユーザー一覧（personal タブ用）
$subscribers = [];
if ($selectedMailboxId) {
    $subscribers = Database::fetchAll(
        "SELECT u.id, u.name, u.email
         FROM users u
         INNER JOIN subscriptions s ON s.user_id = u.id
         WHERE s.mailbox_id = ? AND u.status = 'active'
         ORDER BY u.name ASC",
        [$selectedMailboxId]
    );
}

// ルール一覧
$globalRules        = [];
$personalRules      = [];
$globalRulesForProxy = [];
if ($selectedMailboxId) {
    $globalRules = Database::fetchAll(
        "SELECT r.*, u.name AS created_by_name
         FROM rules r
         LEFT JOIN users u ON u.id = r.created_by
         WHERE r.mailbox_id = ? AND r.scope = 'global'
           AND NOT (r.match_field='any' AND r.match_pattern='*'
                    AND r.action='ignore' AND r.priority=999)
         ORDER BY r.priority ASC, r.id ASC",
        [$selectedMailboxId]
    );

    if ($targetUid) {
        $personalRules = Database::fetchAll(
            "SELECT r.*, u.name AS created_by_name
             FROM rules r
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.mailbox_id = ? AND r.scope = 'personal' AND r.user_id = ?
             ORDER BY r.priority ASC, r.id ASC",
            [$selectedMailboxId, $targetUid]
        );

        // グローバルルール + 対象ユーザー除外状態（代理システムルール表示用）
        $globalRulesForProxy = Database::fetchAll(
            "SELECT r.*,
                    CASE WHEN re.id IS NOT NULL THEN 1 ELSE 0 END AS excluded
             FROM rules r
             LEFT JOIN rule_exclusions re ON re.rule_id = r.id AND re.user_id = ?
             WHERE r.mailbox_id = ? AND r.scope = 'global'
             ORDER BY CASE WHEN r.match_field='any' AND r.match_pattern='*'
                                AND r.action='ignore' AND r.priority=999
                           THEN 0 ELSE 1 END ASC,
                      r.priority ASC, r.id ASC",
            [$targetUid, $selectedMailboxId]
        );
    }
}

$targetUser = $targetUid
    ? Database::fetchOne('SELECT id, name, email FROM users WHERE id = ?', [$targetUid])
    : null;

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/partials/subnav.php';

// 共通: ルールテーブル出力関数（管理者用・全操作可）
function renderRuleTable(array $rules, string $scope, int $mailboxId, int $targetUid, array $fieldLabels): void
{
    $csrfToken   = Auth::csrfToken();
    $redirectTab = $scope === 'global' ? 'global' : 'personal';
    ?>
    <?php if (empty($rules)): ?>
    <p class="text-muted small">ルールが設定されていません。</p>
    <?php else: ?>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>ラベル</th>
                    <th style="width:60px" class="text-center">優先度</th>
                    <th>対象フィールド</th>
                    <th>パターン</th>
                    <th style="width:150px">アクション</th>
                    <th style="width:80px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
            <tr>
                <td class="small fw-semibold"><?= Helpers::e($rule['label']) ?></td>
                <td class="text-center small"><?= (int)$rule['priority'] ?></td>
                <td class="small">
                    <?= Helpers::e($fieldLabels[$rule['match_field']] ?? $rule['match_field']) ?>
                </td>
                <td><code><?= Helpers::e($rule['match_pattern']) ?></code></td>
                <td>
                    <?php if ($rule['action'] === 'notify'): ?>
                        <span class="badge bg-primary"><i class="bi bi-bell"></i> 通知する</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="bi bi-bell-slash"></i> 無視</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <button type="button"
                                class="btn btn-sm btn-outline-primary admin-rule-edit-btn"
                                data-bs-toggle="modal"
                                data-bs-target="#adminRuleEditModal"
                                data-rule-id="<?= (int)$rule['id'] ?>"
                                data-label="<?= Helpers::e($rule['label']) ?>"
                                data-match-field="<?= Helpers::e($rule['match_field']) ?>"
                                data-match-pattern="<?= Helpers::e($rule['match_pattern']) ?>"
                                data-rule-action="<?= Helpers::e($rule['action']) ?>"
                                data-priority="<?= (int)$rule['priority'] ?>"
                                data-tab="<?= $redirectTab ?>"
                                data-target-uid="<?= $targetUid ?>"
                                title="編集">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" class="m-0">
                            <input type="hidden" name="action"         value="delete">
                            <input type="hidden" name="rule_id"        value="<?= (int)$rule['id'] ?>">
                            <input type="hidden" name="mailbox_id"     value="<?= $mailboxId ?>">
                            <input type="hidden" name="tab"            value="<?= $redirectTab ?>">
                            <input type="hidden" name="target_user_id" value="<?= $targetUid ?>">
                            <input type="hidden" name="csrf_token"     value="<?= Helpers::e($csrfToken) ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                    title="削除" data-confirm="このルールを削除しますか？">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif;
}

// 共通: ルール追加フォーム出力関数
function renderAddRuleForm(string $scope, int $mailboxId, int $targetUid, array $fieldLabels): void
{
    $csrfToken = Auth::csrfToken();
    ?>
    <div class="border rounded p-3 bg-light">
        <p class="small fw-semibold mb-2">
            <i class="bi bi-plus-circle text-success"></i> ルールを追加
        </p>
        <form method="post" action="/admin/rules.php">
            <input type="hidden" name="action"         value="add">
            <input type="hidden" name="scope"          value="<?= $scope ?>">
            <input type="hidden" name="mailbox_id"     value="<?= $mailboxId ?>">
            <input type="hidden" name="tab"            value="<?= $scope === 'global' ? 'global' : 'personal' ?>">
            <input type="hidden" name="target_user_id" value="<?= $targetUid ?>">
            <input type="hidden" name="csrf_token"     value="<?= Helpers::e($csrfToken) ?>">
            <div class="row g-2">
                <div class="col-sm-3">
                    <label class="form-label small mb-1">ラベル <span class="text-danger">*</span></label>
                    <input type="text" name="label"
                           class="form-control form-control-sm"
                           placeholder="例: 取引先通知、スパム除外"
                           maxlength="100" required>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small mb-1">対象フィールド</label>
                    <select name="match_field" class="form-select form-select-sm" required>
                        <?php foreach ($fieldLabels as $val => $lbl): ?>
                        <option value="<?= $val ?>"><?= Helpers::e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label small mb-1">
                        パターン <span class="text-muted">（* でワイルドカード）</span>
                    </label>
                    <input type="text" name="match_pattern"
                           class="form-control form-control-sm"
                           placeholder="例: *@example.com, *請求*" required>
                </div>
                <div class="col-sm-2">
                    <label class="form-label small mb-1">アクション</label>
                    <select name="rule_action" class="form-select form-select-sm" required>
                        <option value="notify">通知する</option>
                        <option value="ignore">通知しない（無視）</option>
                    </select>
                </div>
                <div class="col-sm-1">
                    <label class="form-label small mb-1">優先度</label>
                    <input type="number" name="priority"
                           class="form-control form-control-sm"
                           value="50" min="1" max="999">
                </div>
                <div class="col-sm-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php
}

// 代理設定用：グローバルルール + ユーザー除外状態テーブル（my-rules.php と同等）
function renderProxySystemRuleTable(array $globalRules, int $mailboxId, int $targetUid): void
{
    if (empty($globalRules)) {
        echo '<p class="text-muted small">グローバルルールが設定されていません。</p>';
        return;
    }
    $csrfToken   = Auth::csrfToken();
    $fieldLabels = [
        'from_address' => '差出人アドレス',
        'from_domain'  => '差出人ドメイン',
        'subject'      => '件名',
        'any'          => 'すべてのフィールド',
    ];
    ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>ラベル</th>
                    <th style="width:60px">優先度</th>
                    <th>対象フィールド</th>
                    <th>パターン</th>
                    <th style="width:140px">アクション</th>
                    <th style="width:110px">適用</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($globalRules as $rule): ?>
            <?php $isExcluded  = (bool)$rule['excluded']; ?>
            <?php $isIgnoreAll = ($rule['match_field'] === 'any'
                && $rule['match_pattern'] === '*'
                && $rule['action']        === 'ignore'
                && (int)$rule['priority'] === 999); ?>
            <tr class="<?= $isExcluded ? 'text-muted' : '' ?>">
                <td class="small fw-semibold"><?= Helpers::e($rule['label']) ?></td>
                <td class="text-center"><?= (int)$rule['priority'] ?></td>
                <td class="small"><?= $isIgnoreAll ? '—' : Helpers::e($fieldLabels[$rule['match_field']] ?? $rule['match_field']) ?></td>
                <td><code <?= $isExcluded ? 'class="text-muted"' : '' ?>><?= $isIgnoreAll ? '全部来件' : Helpers::e($rule['match_pattern']) ?></code></td>
                <td>
                    <?php if ($rule['action'] === 'notify'): ?>
                        <span class="badge bg-primary <?= $isExcluded ? 'opacity-50' : '' ?>">
                            <i class="bi bi-bell"></i> 通知する
                        </span>
                    <?php else: ?>
                        <span class="badge bg-danger <?= $isExcluded ? 'opacity-50' : '' ?>">
                            <i class="bi bi-bell-slash"></i> 無視
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="/admin/rules.php" class="m-0">
                        <input type="hidden" name="action"         value="proxy_toggle_rule">
                        <input type="hidden" name="rule_id"        value="<?= (int)$rule['id'] ?>">
                        <input type="hidden" name="mailbox_id"     value="<?= $mailboxId ?>">
                        <input type="hidden" name="tab"            value="personal">
                        <input type="hidden" name="target_user_id" value="<?= $targetUid ?>">
                        <input type="hidden" name="csrf_token"     value="<?= Helpers::e($csrfToken) ?>">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="pgr-<?= (int)$rule['id'] ?>"
                                   <?= !$isExcluded ? 'checked' : '' ?>
                                   onchange="this.form.submit()">
                            <label class="form-check-label small text-muted"
                                   for="pgr-<?= (int)$rule['id'] ?>">
                                <?= $isExcluded ? '無効' : '有効' ?>
                            </label>
                        </div>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>

<h4 class="mb-3"><i class="bi bi-funnel-fill"></i> ルール管理</h4>

<?php if ($flash): ?>
<div class="alert alert-<?= Helpers::e($flash[0]) ?> alert-autofade py-2">
    <i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
    <?= Helpers::e($flash[1]) ?>
</div>
<?php endif; ?>

<?php if (empty($mailboxes)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> 監視メールボックスが登録されていません。
</div>
<?php else: ?>

<!-- メールボックス選択 -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3 d-flex flex-wrap gap-3 align-items-center">
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 fw-semibold text-nowrap">メールボックス：</label>
            <select class="form-select form-select-sm" style="width:auto"
                    onchange="location.href='/admin/rules.php?mailbox='+this.value+'&tab=<?= $activeTab ?>'">
                <?php foreach ($mailboxes as $mb): ?>
                <option value="<?= (int)$mb['id'] ?>"
                        <?= (int)$mb['id'] === $selectedMailboxId ? 'selected' : '' ?>>
                    <?= Helpers::e($mb['label']) ?> (<?= Helpers::e($mb['email_address']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<?php if ($selectedMailbox): ?>
<!-- タブ -->
<ul class="nav nav-tabs mb-0" id="ruleTabs">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'global' ? 'active' : '' ?>"
           href="/admin/rules.php?mailbox=<?= $selectedMailboxId ?>&tab=global">
            <i class="bi bi-globe2"></i> グローバルルール
            <span class="badge bg-secondary ms-1"><?= count($globalRules) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'personal' ? 'active' : '' ?>"
           href="/admin/rules.php?mailbox=<?= $selectedMailboxId ?>&tab=personal<?= $targetUid ? '&user_id='.$targetUid : '' ?>">
            <i class="bi bi-person-gear"></i> 個人ルール（代理設定）
        </a>
    </li>
</ul>

<div class="card border-0 shadow-sm border-top-0" style="border-radius:0 0 .5rem .5rem">
    <div class="card-body">

        <?php /* ──── グローバルルール ──── */ ?>
        <?php if ($activeTab === 'global'): ?>
        <p class="small text-muted mb-3">
            <i class="bi bi-info-circle"></i>
            グローバルルールは、このメールボックスのすべての購読者に適用されます。
            個人ルールに命中しなかった場合に評価されます。
        </p>
        <?php renderRuleTable($globalRules, 'global', $selectedMailboxId, 0, $fieldLabels); ?>
        <?php renderAddRuleForm('global', $selectedMailboxId, 0, $fieldLabels); ?>

        <?php /* ──── 個人ルール（代理設定）──── */ ?>
        <?php elseif ($activeTab === 'personal'): ?>
        <?php if (empty($subscribers)): ?>
            <p class="text-muted small">このメールボックスに購読者がいません。</p>
        <?php else: ?>
            <!-- ユーザー選択 -->
            <div class="mb-3 d-flex align-items-center gap-2">
                <label class="form-label mb-0 fw-semibold text-nowrap">対象ユーザー：</label>
                <select class="form-select form-select-sm" style="width:auto"
                        onchange="location.href='/admin/rules.php?mailbox=<?= $selectedMailboxId ?>&tab=personal&user_id='+this.value">
                    <option value="0">— 選択してください —</option>
                    <?php foreach ($subscribers as $sub): ?>
                    <option value="<?= (int)$sub['id'] ?>"
                            <?= (int)$sub['id'] === $targetUid ? 'selected' : '' ?>>
                        <?= Helpers::e($sub['name']) ?> (<?= Helpers::e($sub['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($targetUser): ?>
            <div class="alert alert-light border py-2 small mb-3">
                <i class="bi bi-person-gear"></i>
                <strong><?= Helpers::e($targetUser['name']) ?></strong> さんの個人ルール（管理者による代理設定）
            </div>

            <!-- システムルール（グローバルルール + 対象ユーザー除外トグル）-->
            <div class="mb-4">
                <p class="small fw-semibold text-secondary mb-2">
                    <i class="bi bi-globe2"></i> システムルール
                    <span class="badge bg-secondary ms-1"><?= count($globalRulesForProxy) ?></span>
                    <span class="text-muted fw-normal">（管理者設定・変更不可）</span>
                </p>
                <?php renderProxySystemRuleTable($globalRulesForProxy, $selectedMailboxId, $targetUid); ?>
            </div>
            <hr class="my-3">

            <!-- 個人ルール -->
            <p class="small fw-semibold text-secondary mb-2">
                <i class="bi bi-person"></i> 個人ルール
                <span class="badge bg-secondary ms-1"><?= count($personalRules) ?></span>
            </p>
            <?php renderRuleTable($personalRules, 'personal', $selectedMailboxId, $targetUid, $fieldLabels); ?>
            <?php renderAddRuleForm('personal', $selectedMailboxId, $targetUid, $fieldLabels); ?>
            <?php else: ?>
            <p class="text-muted small">左のドロップダウンからユーザーを選択してください。</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($selectedMailbox): ?>
<!-- ── ルール編集モーダル ──────────────────────────────────────────── -->
<div class="modal fade" id="adminRuleEditModal" tabindex="-1"
     aria-labelledby="adminRuleEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="/admin/rules.php" class="modal-content">
            <input type="hidden" name="action"         value="update">
            <input type="hidden" name="rule_id"        id="adminEditRuleId">
            <input type="hidden" name="mailbox_id"     value="<?= $selectedMailboxId ?>">
            <input type="hidden" name="tab"            id="adminEditTab"       value="global">
            <input type="hidden" name="target_user_id" id="adminEditTargetUid" value="0">
            <input type="hidden" name="csrf_token"     value="<?= Helpers::e(Auth::csrfToken()) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="adminRuleEditModalLabel">
                    <i class="bi bi-pencil"></i> ルールを編集
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small">ラベル <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="adminEditLabel"
                               class="form-control form-control-sm" maxlength="100" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">対象フィールド</label>
                        <select name="match_field" id="adminEditMatchField"
                                class="form-select form-select-sm" required>
                            <option value="from_address">差出人アドレス</option>
                            <option value="from_domain">差出人ドメイン</option>
                            <option value="subject">件名</option>
                            <option value="any">すべてのフィールド</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">アクション</label>
                        <select name="rule_action" id="adminEditRuleAction"
                                class="form-select form-select-sm" required>
                            <option value="notify">通知する</option>
                            <option value="ignore">通知しない（無視）</option>
                        </select>
                    </div>
                    <div class="col-8">
                        <label class="form-label small">
                            パターン <span class="text-muted">（* でワイルドカード）</span>
                        </label>
                        <input type="text" name="match_pattern" id="adminEditMatchPattern"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">優先度 <span class="text-muted">（1–998）</span></label>
                        <input type="number" name="priority" id="adminEditPriority"
                               class="form-control form-control-sm" min="1" max="998">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm"
                        data-bs-dismiss="modal">キャンセル</button>
                <button type="submit" class="btn btn-primary btn-sm">保存</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.admin-rule-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('adminEditRuleId').value       = btn.dataset.ruleId;
        document.getElementById('adminEditLabel').value        = btn.dataset.label ?? '';
        document.getElementById('adminEditMatchField').value   = btn.dataset.matchField;
        document.getElementById('adminEditMatchPattern').value = btn.dataset.matchPattern;
        document.getElementById('adminEditRuleAction').value   = btn.dataset.ruleAction;
        document.getElementById('adminEditPriority').value     = btn.dataset.priority;
        document.getElementById('adminEditTab').value          = btn.dataset.tab;
        document.getElementById('adminEditTargetUid').value    = btn.dataset.targetUid ?? '0';
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
