<?php
declare(strict_types=1);

/**
 * my-rules.php — 個人受信ルール管理
 *
 * 購読しているメールボックスごとに個人ルールを設定できる。
 *
 * セキュリティ（S7）：
 *   - ルール追加・削除時に mailbox_id が自分の購読一覧にあるか検証
 *   - ルール削除時に rule.user_id = 自分であることを検証
 *   - CSRF トークン検証
 */

require_once __DIR__ . '/../src/bootstrap.php';

$pageTitle = '受信ルール設定';
$user      = Auth::getCurrentUser();
$errors  = [];
$success = '';

// セッションフラッシュメッセージ読み出し（PRG パターン）
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ── POST ハンドラ ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'セキュリティエラーが発生しました。';
    } else {
        $action = $_POST['action'] ?? '';

        // ── ルール追加 ─────────────────────────────────────────────
        if ($action === 'add') {
            $mailboxId    = (int)($_POST['mailbox_id']   ?? 0);
            $label        = mb_substr(trim($_POST['label'] ?? ''), 0, 100);
            $matchField   = $_POST['match_field']  ?? '';
            $matchPattern = trim($_POST['match_pattern'] ?? '');
            $ruleAction   = $_POST['rule_action']  ?? '';
            $priority     = (int)($_POST['priority'] ?? 50);

            // S7: mailbox_id が自分の購読一覧に含まれるか確認
            $validMailbox = Database::fetchOne(
                'SELECT mb.id FROM monitored_mailboxes mb
                 INNER JOIN subscriptions s ON s.mailbox_id = mb.id
                 WHERE mb.id = ? AND s.user_id = ?',
                [$mailboxId, (int)$user['id']]
            );

            $validFields   = ['from_address', 'from_domain', 'subject', 'any'];
            $validActions  = ['notify', 'ignore'];

            if (!$validMailbox) {
                $errors[] = '指定されたメールボックスにアクセス権限がありません。';
            } elseif ($label === '') {
                $errors[] = 'ラベルを入力してください。';
            } elseif (!in_array($matchField, $validFields, true)) {
                $errors[] = '無効なマッチフィールドです。';
            } elseif (!in_array($ruleAction, $validActions, true)) {
                $errors[] = '無効なアクションです。';
            } elseif ($matchPattern === '') {
                $errors[] = 'パターンを入力してください。';
            } else {
                // 重複ラベルチェック
                $dupLabel = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=? AND label=?',
                    [$mailboxId, 'personal', (int)$user['id'], $label]
                );
                // 同一条件のルールが既に存在するか確認
                $duplicate = Database::fetchOne(
                    'SELECT id FROM rules
                     WHERE mailbox_id = ? AND scope = ? AND user_id = ?
                       AND match_field = ? AND match_pattern = ? AND action = ?',
                    [$mailboxId, 'personal', (int)$user['id'], $matchField, $matchPattern, $ruleAction]
                );
                if ($dupLabel) {
                    $errors[] = '同じラベルのルールがすでに存在します。';
                } elseif ($duplicate) {
                    $errors[] = '同じ条件のルールがすでに存在します。';
                } else {
                    Database::query(
                        'INSERT INTO rules
                         (mailbox_id, label, scope, user_id, match_field, match_pattern, action, priority, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $mailboxId,
                            $label,
                            'personal',
                            (int)$user['id'],
                            $matchField,
                            $matchPattern,
                            $ruleAction,
                            $priority,
                            (int)$user['id'],
                        ]
                    );
                    // PRG: リダイレクトしてブラウザリフレッシュによる再送信を防止
                    $_SESSION['flash_success'] = 'ルールを追加しました。';
                    Helpers::redirect('/my-rules.php');
                }
            }
        }

        // ── ルール更新 ─────────────────────────────────────────────
        if ($action === 'update') {
            $ruleId       = (int)($_POST['rule_id'] ?? 0);
            $label        = mb_substr(trim($_POST['label'] ?? ''), 0, 100);
            $matchField   = $_POST['match_field']   ?? '';
            $matchPattern = trim($_POST['match_pattern'] ?? '');
            $ruleAction   = $_POST['rule_action']   ?? '';
            $priority     = (int)($_POST['priority'] ?? 50);

            $validFields  = ['from_address', 'from_domain', 'subject', 'any'];
            $validActions = ['notify', 'ignore'];

            // 所有権確認
            $rule = Database::fetchOne(
                'SELECT * FROM rules WHERE id=? AND scope=? AND user_id=?',
                [$ruleId, 'personal', (int)$user['id']]
            );
            if (!$rule) {
                $errors[] = '編集するルールが見つかりません。';
            } elseif ($label === '') {
                $errors[] = 'ラベルを入力してください。';
            } elseif (!in_array($matchField, $validFields, true) || !in_array($ruleAction, $validActions, true)) {
                $errors[] = '入力値が無効です。';
            } elseif ($matchPattern === '') {
                $errors[] = 'パターンを入力してください。';
            } else {
                // 重複ラベルチェック（自分以外）
                $dupLabel = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=? AND label=? AND id!=?',
                    [(int)$rule['mailbox_id'], 'personal', (int)$user['id'], $label, $ruleId]
                );
                // 重複条件チェック（自分以外）
                $dupCond = Database::fetchOne(
                    'SELECT id FROM rules WHERE mailbox_id=? AND scope=? AND user_id=?
                     AND match_field=? AND match_pattern=? AND action=? AND id!=?',
                    [(int)$rule['mailbox_id'], 'personal', (int)$user['id'],
                     $matchField, $matchPattern, $ruleAction, $ruleId]
                );
                if ($dupLabel) {
                    $errors[] = '同じラベルのルールがすでに存在します。';
                } elseif ($dupCond) {
                    $errors[] = '同じ条件のルールがすでに存在します。';
                } else {
                    Database::query(
                        'UPDATE rules SET label=?, match_field=?, match_pattern=?, action=?, priority=? WHERE id=?',
                        [$label, $matchField, $matchPattern, $ruleAction, $priority, $ruleId]
                    );
                    $_SESSION['flash_success'] = 'ルールを更新しました。';
                    Helpers::redirect('/my-rules.php');
                }
            }
        }

        // ── ルール削除 ─────────────────────────────────────────────
        if ($action === 'delete') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);

            // 自分の個人ルールのみ削除可
            $rule = Database::fetchOne(
                'SELECT id FROM rules
                 WHERE id = ? AND scope = ? AND user_id = ?',
                [$ruleId, 'personal', (int)$user['id']]
            );

            if (!$rule) {
                $errors[] = '削除するルールが見つかりません。';
            } else {
                Database::query('DELETE FROM rules WHERE id = ?', [$ruleId]);
                $_SESSION['flash_success'] = 'ルールを削除しました。';
                Helpers::redirect('/my-rules.php');
            }
        }

        // ── グローバルルール適用トグル ──────────────────────────────────
        if ($action === 'toggle_global_rule') {
            $ruleId = (int)($_POST['rule_id'] ?? 0);

            // S7: グローバルルールかつ自分が購読しているメールボックスのルールか検証
            $rule = Database::fetchOne(
                'SELECT r.id FROM rules r
                 INNER JOIN subscriptions s ON s.mailbox_id = r.mailbox_id
                 WHERE r.id = ? AND r.scope = ? AND s.user_id = ?',
                [$ruleId, 'global', (int)$user['id']]
            );

            if (!$rule) {
                $errors[] = '指定されたルールが見つかりません。';
            } else {
                $existing = Database::fetchOne(
                    'SELECT id FROM rule_exclusions WHERE rule_id = ? AND user_id = ?',
                    [$ruleId, (int)$user['id']]
                );
                if ($existing) {
                    // OFF → ON: 除外を解除
                    Database::query(
                        'DELETE FROM rule_exclusions WHERE rule_id = ? AND user_id = ?',
                        [$ruleId, (int)$user['id']]
                    );
                    $_SESSION['flash_success'] = 'システムルールを有効にしました。';
                } else {
                    // ON → OFF: 除外を追加
                    Database::query(
                        'INSERT INTO rule_exclusions (rule_id, user_id) VALUES (?, ?)',
                        [$ruleId, (int)$user['id']]
                    );
                    $_SESSION['flash_success'] = 'システムルールを無効にしました。';
                }
                Helpers::redirect('/my-rules.php');
            }
        }
    }
}

// ── 購読メールボックス一覧 ─────────────────────────────────────────
$mailboxes = Database::fetchAll(
    'SELECT mb.id, mb.label, mb.email_address
     FROM monitored_mailboxes mb
     INNER JOIN subscriptions s ON s.mailbox_id = mb.id
     WHERE s.user_id = ?
     ORDER BY mb.label ASC',
    [(int)$user['id']]
);

// ── 全部無視ルール：各メールボックスに存在しない場合は自動作成 ───
// 初回アクセス時に自動生成し、既存購読者全員をデフォルト除外（OFF）にする
foreach ($mailboxes as $_mb) {
    $exists = Database::fetchOne(
        "SELECT id FROM rules WHERE mailbox_id=? AND scope='global'
         AND match_field='any' AND match_pattern='*' AND action='ignore' AND priority=999",
        [$_mb['id']]
    );
    if (!$exists) {
        Database::query(
            "INSERT INTO rules
             (mailbox_id, label, scope, user_id, match_field, match_pattern, action, priority, created_by)
             VALUES (?, '全部無視', 'global', NULL, 'any', '*', 'ignore', 999, ?)",
            [$_mb['id'], (int)$user['id']]
        );
        $_ruleId = Database::lastInsertId();
        $_subs = Database::fetchAll(
            'SELECT user_id FROM subscriptions WHERE mailbox_id=?',
            [$_mb['id']]
        );
        foreach ($_subs as $_sub) {
            Database::query(
                'INSERT IGNORE INTO rule_exclusions (rule_id, user_id) VALUES (?,?)',
                [$_ruleId, $_sub['user_id']]
            );
        }
    }
}

// ── 各メールボックスのルール一覧 ──────────────────────────────────
$rulesByMailbox = [];
if (!empty($mailboxes)) {
    $mbIds = array_column($mailboxes, 'id');
    $in    = implode(',', array_fill(0, count($mbIds), '?'));
    $rows  = Database::fetchAll(
        "SELECT * FROM rules
         WHERE mailbox_id IN ({$in}) AND scope = 'personal' AND user_id = ?
         ORDER BY mailbox_id ASC, priority ASC, id ASC",
        array_merge($mbIds, [(int)$user['id']])
    );
    foreach ($rows as $row) {
        $rulesByMailbox[$row['mailbox_id']][] = $row;
    }
}

// ── 各メールボックスのグローバルルール一覧（除外状態付き）────────
$globalRulesByMailbox = [];
if (!empty($mailboxes)) {
    $mbIds = array_column($mailboxes, 'id');
    $in    = implode(',', array_fill(0, count($mbIds), '?'));
    $rows  = Database::fetchAll(
        "SELECT r.*,
                CASE WHEN re.id IS NOT NULL THEN 1 ELSE 0 END AS excluded
         FROM rules r
         LEFT JOIN rule_exclusions re
             ON re.rule_id = r.id AND re.user_id = ?
         WHERE r.mailbox_id IN ({$in}) AND r.scope = 'global'
         ORDER BY r.mailbox_id ASC,
                  CASE WHEN r.match_field='any' AND r.match_pattern='*'
                            AND r.action='ignore' AND r.priority=999
                       THEN 0 ELSE 1 END ASC,
                  r.priority ASC, r.id ASC",
        array_merge([(int)$user['id']], $mbIds)
    );
    foreach ($rows as $row) {
        $globalRulesByMailbox[$row['mailbox_id']][] = $row;
    }
}

// ── ラベル変換ヘルパー ─────────────────────────────────────────────
$fieldLabels = [
    'from_address' => '差出人アドレス',
    'from_domain'  => '差出人ドメイン',
    'subject'      => '件名',
    'any'          => 'すべてのフィールド',
];
$actionLabels = [
    'notify' => '通知する',
    'ignore' => '通知しない（無視）',
];

include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-funnel"></i> 受信ルール設定</h4>
</div>

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

<!-- ルールの説明 -->
<div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle"></i>
    <strong>個人ルールについて：</strong>
    メールがルールに一致した場合、そのアクションが実行されます。
    優先度（小さい数ほど先に評価）順に判定し、一致したらそこで終了します。
    一致しない場合は、下記のシステムルールが評価されます（スイッチでオフにすると適用されません）。
</div>

<?php if (empty($mailboxes)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-inbox display-6 d-block mb-2"></i>
        購読しているメールボックスがありません。<br>
        管理者にメールボックスへの追加を依頼してください。
    </div>
</div>
<?php else: ?>

<!-- ── メールボックスごとのルール管理（アコーディオン）── -->
<div class="accordion" id="rulesAccordion">
    <?php foreach ($mailboxes as $i => $mb): ?>
    <?php $rules = $rulesByMailbox[$mb['id']] ?? []; ?>
    <div class="accordion-item border-0 shadow-sm mb-3 rounded">
        <h2 class="accordion-header">
            <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?> rounded fw-semibold"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#collapse-<?= (int)$mb['id'] ?>"
                    aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
                <span>
                    <i class="bi bi-inbox me-2 text-primary"></i>
                    <?= Helpers::e($mb['label']) ?>
                    <small class="text-muted fw-normal ms-2"><?= Helpers::e($mb['email_address']) ?></small>
                </span>
                <?php if (!empty($rules)): ?>
                <span class="badge bg-primary rounded-pill ms-2"><?= count($rules) ?></span>
                <?php endif; ?>
            </button>
        </h2>
        <div id="collapse-<?= (int)$mb['id'] ?>"
             class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
             data-bs-parent="#rulesAccordion">
            <div class="accordion-body">

                <!-- 現在のルール一覧 -->
                <?php if (empty($rules)): ?>
                <p class="text-muted small">このメールボックスに個人ルールはありません。</p>
                <?php else: ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>ラベル</th>
                                <th style="width:60px">優先度</th>
                                <th>対象フィールド</th>
                                <th>パターン</th>
                                <th style="width:140px">アクション</th>
                                <th style="width:100px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                            <tr>
                                <td class="small fw-semibold"><?= Helpers::e($rule['label']) ?></td>
                                <td class="text-center"><?= (int)$rule['priority'] ?></td>
                                <td class="small"><?= Helpers::e($fieldLabels[$rule['match_field']] ?? $rule['match_field']) ?></td>
                                <td><code><?= Helpers::e($rule['match_pattern']) ?></code></td>
                                <td>
                                    <?php if ($rule['action'] === 'notify'): ?>
                                        <span class="badge bg-primary">
                                            <i class="bi bi-bell"></i> 通知する
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            <i class="bi bi-bell-slash"></i> 無視
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary rule-edit-btn"
                                                data-rule-id="<?= (int)$rule['id'] ?>"
                                                data-label="<?= Helpers::e($rule['label']) ?>"
                                                data-match-field="<?= Helpers::e($rule['match_field']) ?>"
                                                data-match-pattern="<?= Helpers::e($rule['match_pattern']) ?>"
                                                data-rule-action="<?= Helpers::e($rule['action']) ?>"
                                                data-priority="<?= (int)$rule['priority'] ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#ruleEditModal">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post" action="/my-rules.php" class="m-0">
                                            <input type="hidden" name="action"     value="delete">
                                            <input type="hidden" name="rule_id"   value="<?= (int)$rule['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= Helpers::e(Auth::csrfToken()) ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    data-confirm="このルールを削除しますか？">
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
                <?php endif; ?>

                <!-- システムルール（読み取り専用 + 適用トグル）-->
                <?php $globalRules = $globalRulesByMailbox[$mb['id']] ?? []; ?>
                <?php if (!empty($globalRules)): ?>
                <div class="mb-3">
                    <p class="small fw-semibold text-secondary mb-2">
                        <i class="bi bi-globe2"></i> システムルール
                        <span class="badge bg-secondary ms-1"><?= count($globalRules) ?></span>
                        <span class="text-muted fw-normal">（管理者設定・変更不可）</span>
                    </p>
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
                                <?php foreach ($globalRules as $gRule): ?>
                                <?php $isExcluded  = (bool)$gRule['excluded']; ?>
                                <?php $isIgnoreAll = ($gRule['match_field'] === 'any'
                                    && $gRule['match_pattern'] === '*'
                                    && $gRule['action']        === 'ignore'
                                    && (int)$gRule['priority'] === 999); ?>
                                <tr class="<?= $isExcluded ? 'text-muted' : '' ?>">
                                    <td class="small"><?= Helpers::e($gRule['label']) ?></td>
                                    <td class="text-center"><?= (int)$gRule['priority'] ?></td>
                                    <td class="small"><?= $isIgnoreAll ? '—' : Helpers::e($fieldLabels[$gRule['match_field']] ?? $gRule['match_field']) ?></td>
                                    <td><code <?= $isExcluded ? 'class="text-muted"' : '' ?>><?= $isIgnoreAll ? '全部来件' : Helpers::e($gRule['match_pattern']) ?></code></td>
                                    <td>
                                        <?php if ($gRule['action'] === 'notify'): ?>
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
                                        <form method="post" action="/my-rules.php" class="m-0">
                                            <input type="hidden" name="action" value="toggle_global_rule">
                                            <input type="hidden" name="rule_id" value="<?= (int)$gRule['id'] ?>">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= Helpers::e(Auth::csrfToken()) ?>">
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       id="gr-<?= (int)$gRule['id'] ?>-<?= (int)$mb['id'] ?>"
                                                       <?= !$isExcluded ? 'checked' : '' ?>
                                                       onchange="this.form.submit()">
                                                <label class="form-check-label small text-muted"
                                                       for="gr-<?= (int)$gRule['id'] ?>-<?= (int)$mb['id'] ?>">
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
                </div>
                <hr class="my-3">
                <?php endif; ?>

                <!-- ルール追加フォーム -->
                <div class="border rounded p-3 bg-light">
                    <p class="small fw-semibold mb-2">
                        <i class="bi bi-plus-circle text-success"></i> ルールを追加
                    </p>
                    <form method="post" action="/my-rules.php">
                        <input type="hidden" name="action"     value="add">
                        <input type="hidden" name="mailbox_id" value="<?= (int)$mb['id'] ?>">
                        <input type="hidden" name="csrf_token"
                               value="<?= Helpers::e(Auth::csrfToken()) ?>">
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
                                    パターン
                                    <span class="text-muted">（* でワイルドカード）</span>
                                </label>
                                <input type="text" name="match_pattern"
                                       class="form-control form-control-sm"
                                       placeholder="例: *@example.com, *請求*"
                                       required>
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
                                <input type="number" name="priority" class="form-control form-control-sm"
                                       value="50" min="1" max="999">
                            </div>
                            <div class="col-sm-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-success w-100">
                                    <i class="bi bi-plus"></i> 追加
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ── 編集モーダル ── -->
<div class="modal fade" id="ruleEditModal" tabindex="-1" aria-labelledby="ruleEditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="/my-rules.php" class="modal-content">
            <input type="hidden" name="action"     value="update">
            <input type="hidden" name="rule_id"    id="editRuleId">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e(Auth::csrfToken()) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="ruleEditModalLabel"><i class="bi bi-pencil"></i> ルールを編集</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small mb-1">ラベル <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="editLabel"
                               class="form-control form-control-sm"
                               maxlength="100" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">対象フィールド</label>
                        <select name="match_field" id="editMatchField" class="form-select form-select-sm" required>
                            <?php foreach ($fieldLabels as $val => $lbl): ?>
                            <option value="<?= $val ?>"><?= Helpers::e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">
                            パターン <span class="text-muted small">（* でワイルドカード）</span>
                        </label>
                        <input type="text" name="match_pattern" id="editMatchPattern"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">アクション</label>
                        <select name="rule_action" id="editRuleAction" class="form-select form-select-sm" required>
                            <option value="notify">通知する</option>
                            <option value="ignore">通知しない（無視）</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1">優先度</label>
                        <input type="number" name="priority" id="editPriority"
                               class="form-control form-control-sm"
                               min="1" max="999">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">キャンセル</button>
                <button type="submit" class="btn btn-primary btn-sm">保存</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
