<?php
declare(strict_types=1);

/**
 * download.php — 認証済み添付ファイルダウンロードハンドラ（Phase 5）
 *
 * セキュリティ：
 *   1. ログイン必須
 *   2. notifications JOIN で所有権確認（自分の通知に紐づく添付のみ）
 *   3. storage_path に ".." が含まれないことを検証（パストラバーサル防止）
 *   4. ファイル存在確認
 *   5. RFC 5987 対応のダウンロードヘッダーで送信
 */

require_once __DIR__ . '/../src/bootstrap.php';

Auth::requireLogin();

$user  = Auth::getCurrentUser();
$attId = (int)($_GET['id'] ?? 0);

if ($attId <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

// ── 所有権確認（自分の通知に紐づく添付のみ許可）──────────────────
$att = Database::fetchOne(
    'SELECT a.id, a.filename, a.mime_type, a.size, a.storage_path
     FROM attachments a
     INNER JOIN notifications n ON n.mail_id = a.mail_id
     WHERE a.id = ? AND n.user_id = ?
     LIMIT 1',
    [$attId, (int)$user['id']]
);

if ($att === null) {
    http_response_code(403);
    exit('Access denied');
}

// ── パストラバーサル防止 ──────────────────────────────────────────
// storage_path は UUID ベースのため ".." が含まれることはないが念のため検証
if (str_contains($att['storage_path'], '..')) {
    http_response_code(403);
    exit('Access denied');
}

// ── ファイル存在確認 ──────────────────────────────────────────────
$basePath = dirname(__DIR__) . '/storage/attachments/';
$fullPath = $basePath . $att['storage_path'];

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

// ── 監査ログ ────────────────────────────────────────────────────
AuditLog::record('attachment.download', 'attachment', (int)$att['id'], [
    'filename' => $att['filename'],
]);

// ── ダウンロードヘッダー送信（RFC 5987: UTF-8 ファイル名対応）───
$mimeType = $att['mime_type'] ?: 'application/octet-stream';
$filename = $att['filename'];

// ASCII セーフなフォールバック（非 ASCII 文字を _ に置換）
$asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename);

// 出力バッファをクリア
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header(
    'Content-Disposition: attachment;'
    . ' filename="' . str_replace('"', '\\"', $asciiFilename) . '";'
    . " filename*=UTF-8''" . rawurlencode($filename)
);
header('Content-Length: ' . (int)$att['size']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($fullPath);
exit;
