<?php
declare(strict_types=1);

/**
 * AuditLog — 操作監査ログ（Phase 5）
 *
 * すべてのメソッドは静的。ログ失敗は業務処理を止めない（サイレント失敗）。
 */
class AuditLog
{
    private function __construct() {}

    /**
     * 監査ログを記録する
     *
     * @param string      $action     アクション識別子（例: auth.login, user.create）
     * @param string|null $targetType 対象リソース種別（例: user, mailbox）
     * @param int|null    $targetId   対象リソース ID
     * @param array       $detail     追加情報（JSON シリアライズして保存）
     */
    public static function record(
        string  $action,
        ?string $targetType = null,
        ?int    $targetId   = null,
        array   $detail     = []
    ): void {
        try {
            $user   = Auth::getCurrentUser();
            $userId = $user ? (int)$user['id'] : null;
            $ip     = isset($_SERVER['REMOTE_ADDR'])
                ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
            $detailJson = empty($detail)
                ? null : json_encode($detail, JSON_UNESCAPED_UNICODE);

            Database::query(
                'INSERT INTO audit_logs
                 (user_id, action, target_type, target_id, detail, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $action, $targetType, $targetId, $detailJson, $ip]
            );
        } catch (\Throwable) {
            // ログ失敗で業務処理を止めない（サイレント失敗）
        }
    }
}
