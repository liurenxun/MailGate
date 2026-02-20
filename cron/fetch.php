<?php
declare(strict_types=1);

/**
 * cron/fetch.php — Cron 入口
 *
 * Xserver 控制面板 → Cron 設定：
 *   ＊/5 ＊ ＊ ＊ ＊  /usr/bin/php8.3 /home/{user}/{domain}/cron/fetch.php >> /home/{user}/logs/mailgate.log 2>&1
 *
 * 仅允许 CLI 运行（拒绝 Web 访问）。
 * 并发锁（S4）：flock() 保证同一时刻只有一个实例运行。
 *
 * 执行流程：
 *   1. 获取进程锁
 *   2. 重试近 24h 内发送失败的通知（F3）
 *   3. 对每个活跃监控邮箱：拉取新邮件 → 规则引擎 → 发送通知
 *   4. 释放锁
 */

// ── 安全：仅 CLI 可运行 ────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: This script can only be run from the command line.' . PHP_EOL);
}

define('MAILGATE_ROOT', dirname(__DIR__));

// ── 加载 Composer 自动加载（PHPMailer / php-mime-mail-parser）──────
$autoload = MAILGATE_ROOT . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    fwrite(STDERR, "[WARN] vendor/autoload.php not found. Run: composer install\n");
    fwrite(STDERR, "       PHPMailer and php-mime-mail-parser will not be available.\n");
}

// ── 加载核心类 ─────────────────────────────────────────────────────
require_once MAILGATE_ROOT . '/src/Database.php';
require_once MAILGATE_ROOT . '/src/Helpers.php';
require_once MAILGATE_ROOT . '/src/Mailer.php';
require_once MAILGATE_ROOT . '/src/Classifier.php';
require_once MAILGATE_ROOT . '/src/Notifier.php';
require_once MAILGATE_ROOT . '/src/Fetcher.php';

// ── 并发锁（S4）────────────────────────────────────────────────────
$lockPath = sys_get_temp_dir() . '/mailgate_fetch.lock';
$lock     = fopen($lockPath, 'c');

if ($lock === false) {
    log_msg('ERROR: Cannot open lock file: ' . $lockPath);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    log_msg('Another instance is running. Exiting.');
    fclose($lock);
    exit(0);
}

// ── メイン処理 ─────────────────────────────────────────────────────
$exitCode = 0;

try {
    log_msg('=== Fetch cycle start ===');

    // Step 1: 重试发送失败的通知（F3）
    $retried = Notifier::retryPending();
    log_msg("  Retry: {$retried} pending notification(s) processed.");

    // Step 2: 拉取各活跃邮箱
    $mailboxes = Database::fetchAll(
        "SELECT * FROM monitored_mailboxes WHERE is_active = 1 ORDER BY id ASC"
    );

    if (empty($mailboxes)) {
        log_msg('  No active mailboxes configured.');
    }

    foreach ($mailboxes as $mailbox) {
        log_msg(sprintf(
            '  Mailbox [%s] <%s>',
            $mailbox['label'],
            $mailbox['email_address']
        ));

        $fetcher = new Fetcher($mailbox);

        if (!$fetcher->connect()) {
            log_msg("    ERROR: IMAP connection failed. See last_error in DB.");
            continue;
        }

        try {
            $newMails = $fetcher->fetchNew();
            log_msg('    Fetched: ' . count($newMails) . ' new mail(s).');

            foreach ($newMails as $mail) {
                Notifier::processNewMail($mail, $mailbox);
            }
        } catch (\Throwable $e) {
            log_msg('    ERROR during fetch/notify: ' . $e->getMessage());
            $exitCode = 1;
        } finally {
            $fetcher->disconnect();
        }
    }

    log_msg('=== Fetch cycle complete ===');
} catch (\Throwable $e) {
    log_msg('FATAL: ' . $e->getMessage());
    log_msg('Trace: ' . $e->getTraceAsString());
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit($exitCode);

// ── ヘルパー ───────────────────────────────────────────────────────

/**
 * ログ出力（stdout）
 * Cron 設定で >> mailgate.log にリダイレクトされる
 */
function log_msg(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}
