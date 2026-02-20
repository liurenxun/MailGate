<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// CSRF チェックの上でログアウト（POST only）
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && Auth::verifyCsrf($_POST['csrf_token'] ?? '')) {
    AuditLog::record('auth.logout');
    Auth::logout();
}

Helpers::redirect('/index.php');
