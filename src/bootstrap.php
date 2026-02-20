<?php
declare(strict_types=1);

/**
 * bootstrap.php — 所有公开页面的统一入口
 *
 * 用法（在每个 public/*.php 顶部）：
 *   require_once __DIR__ . '/../src/bootstrap.php';
 */

// ── Composer 自动加载（PHPMailer 等 Composer 包）────────────────
$_composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($_composerAutoload)) {
    require_once $_composerAutoload;
}
unset($_composerAutoload);

// ── 核心类加载（按依赖顺序）──────────────────────────────────────
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/AuditLog.php';

// ── Session 安全启动 ──────────────────────────────────────────────
Auth::startSession();
