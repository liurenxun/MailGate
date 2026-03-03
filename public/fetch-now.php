<?php
declare(strict_types=1);

/**
 * fetch-now.php — 手動受信トリガー（AJAX POST）
 *
 * ログインユーザーが購読している全アクティブメールボックスを即時取得する。
 * POST のみ可・CSRF 検証あり・30秒間レート制限あり・cron と並行ロック共有。
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Classifier.php';
require_once __DIR__ . '/../src/Notifier.php';
require_once __DIR__ . '/../src/Fetcher.php';

Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::json(['error' => 'method_not_allowed'], 405);
}
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
    Helpers::json(['error' => 'csrf_error'], 403);
}

$user = Auth::getCurrentUser();
$uid  = (int)$user['id'];

// ── レート制限（30秒に1回）──────────────────────────────────────────
$lastFetch = (int)($_SESSION['last_manual_fetch'] ?? 0);
$wait      = 30 - (time() - $lastFetch);
if ($wait > 0) {
    Helpers::json(['error' => 'rate_limit', 'wait' => $wait], 429);
}

// ── 並行ロック（cron と同じロックファイルを共有）────────────────────
$lockPath = sys_get_temp_dir() . '/mailgate_fetch.lock';
$lock     = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($lock !== false) fclose($lock);
    Helpers::json(['error' => 'busy'], 503);
}

$_SESSION['last_manual_fetch'] = time();
set_time_limit(120);

$fetched   = 0;
$errors    = [];
$startTime = microtime(true);

try {
    // 未送信通知のリトライ（F3）
    Notifier::retryPending();

    // ユーザーが購読している有効メールボックスを取得
    $mailboxes = Database::fetchAll(
        "SELECT mb.* FROM monitored_mailboxes mb
         INNER JOIN subscriptions s ON s.mailbox_id = mb.id AND s.user_id = ?
         WHERE mb.is_active = 1
         ORDER BY mb.id ASC",
        [$uid]
    );

    foreach ($mailboxes as $mailbox) {
        $fetcher = new Fetcher($mailbox);
        if (!$fetcher->connect()) {
            $errors[] = $mailbox['label'] . ': 接続失敗';
            continue;
        }
        try {
            $newMails = $fetcher->fetchNew();
            $fetched += count($newMails);
            foreach ($newMails as $mail) {
                Notifier::processNewMail($mail, $mailbox);
            }
        } catch (\Throwable $e) {
            $errors[] = $mailbox['label'] . ': ' . $e->getMessage();
        } finally {
            $fetcher->disconnect();
        }
    }
} catch (\Throwable $e) {
    $errors[] = $e->getMessage();
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

Helpers::json([
    'ok'       => true,
    'fetched'  => $fetched,
    'errors'   => $errors,
    'duration' => round(microtime(true) - $startTime, 1),
]);
