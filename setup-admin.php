<?php
/**
 * MailGate 管理员账号初始化脚本（FC2）
 *
 * 仅在首次部署时运行一次，用于创建第一个管理员账号并发送设密邮件。
 * 运行后请立即删除或移出 Web 可访问目录。
 *
 * 使用方法：
 *   php setup-admin.php
 *
 * 安全提醒：
 *   - 此脚本只能通过 CLI 运行（已阻止 Web 访问）
 *   - 运行完成后请删除此文件
 */

// 阻止 Web 访问
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Helpers.php';
require_once __DIR__ . '/src/Mailer.php';
require_once __DIR__ . '/src/Auth.php';

echo "=== MailGate 管理员账号初始化 ===\n\n";

// 检查是否已有管理员
$existing = Database::fetchOne(
    "SELECT COUNT(*) as cnt FROM users WHERE role = 'admin'"
);
if (($existing['cnt'] ?? 0) > 0) {
    echo "管理员账号已存在，无需重复初始化。\n";
    exit(0);
}

// 交互式输入
echo "请输入管理员姓名: ";
$name = trim(fgets(STDIN));

echo "请输入管理员邮箱: ";
$email = trim(fgets(STDIN));

if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "错误：姓名和邮箱不能为空，且邮箱格式必须合法。\n";
    exit(1);
}

$user = Auth::createUser($name, $email, 'admin');

if ($user === null) {
    echo "错误：该邮箱已存在。\n";
    exit(1);
}

echo "\n✓ 管理员账号已创建。\n";
echo "  姓名: {$name}\n";
echo "  邮箱: {$email}\n";
echo "\n  设密邮件已发送到 {$email}，请检查邮箱完成密码设置。\n";
echo "\n【重要】请在完成初始化后删除此脚本文件：\n";
echo "  rm setup-admin.php\n\n";
