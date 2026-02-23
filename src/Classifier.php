<?php
declare(strict_types=1);

/**
 * Classifier — 规则匹配引擎
 *
 * 规则解析语义（规则级回退，非系统级覆盖）：
 *
 *   Step 1 — 个人规则（scope=personal, user_id=U）
 *     按 priority ASC, id ASC 依次匹配
 *     → 命中 → 按该规则 action 执行，结束
 *
 *   Step 2 — 全局规则（scope=global）
 *     仅在 Step 1 无任何命中时到达
 *     按 priority ASC, id ASC 依次匹配
 *     → 命中 → 按该规则 action 执行，结束
 *
 *   Step 3 — 兜底
 *     → 默认 notify
 *
 * 注意（F1 规则语义）：
 *   个人规则是"逐条命中即止"，不是"有个人规则就整体替换全局规则"。
 *   若员工的所有个人规则均未命中，流程落入 Step 2 执行全局规则。
 *
 * 通配符（F8）：
 *   支持 * 作为前缀/后缀/中间通配符（大小写不敏感）。
 *   match_field=any：pattern 与 from_address、from_name、subject 任一匹配则命中。
 */
class Classifier
{
    /**
     * 判断是否应通知指定用户
     *
     * @param array $mail     mails 表行
     * @param int   $userId
     * @param int   $mailboxId
     * @return string 'notify' | 'ignore'
     */
    public static function resolve(array $mail, int $userId, int $mailboxId): string
    {
        // Step 1: 个人规则
        $personal = Database::fetchAll(
            'SELECT * FROM rules
             WHERE mailbox_id = ? AND scope = ? AND user_id = ?
             ORDER BY priority ASC, id ASC',
            [$mailboxId, 'personal', $userId]
        );

        foreach ($personal as $rule) {
            if (self::matches($rule, $mail)) {
                return $rule['action'];
            }
        }

        // Step 2: 全局规则（ユーザーが除外したルールはスキップ）
        $global = Database::fetchAll(
            'SELECT r.* FROM rules r
             WHERE r.mailbox_id = ? AND r.scope = ?
               AND NOT EXISTS (
                   SELECT 1 FROM rule_exclusions re
                   WHERE re.rule_id = r.id AND re.user_id = ?
               )
             ORDER BY r.priority ASC, r.id ASC',
            [$mailboxId, 'global', $userId]
        );

        foreach ($global as $rule) {
            if (self::matches($rule, $mail)) {
                return $rule['action'];
            }
        }

        // Step 3: 兜底
        return 'notify';
    }

    /**
     * 测试单条规则是否命中某封邮件（供管理界面预览使用）
     *
     * @param array $rule rules 表行
     * @param array $mail mails 表行（或包含相同字段的数组）
     */
    public static function matches(array $rule, array $mail): bool
    {
        $pattern = $rule['match_pattern'];

        return match($rule['match_field']) {
            'from_address' => Helpers::wildcardMatch(
                $pattern,
                $mail['from_address'] ?? ''
            ),

            'from_domain'  => Helpers::wildcardMatch(
                $pattern,
                Helpers::extractDomain($mail['from_address'] ?? '')
            ),

            'subject'      => Helpers::wildcardMatch(
                $pattern,
                $mail['subject'] ?? ''
            ),

            // any：命中 from_address、from_name、subject 任一字段（F8）
            'any'          => Helpers::wildcardMatch($pattern, $mail['from_address'] ?? '')
                           || Helpers::wildcardMatch($pattern, $mail['from_name'] ?? '')
                           || Helpers::wildcardMatch($pattern, $mail['subject'] ?? ''),

            default        => false,
        };
    }
}
