<?php
declare(strict_types=1);

/**
 * Notifier — 通知分发
 *
 * 功能：
 *   1. processNewMail()  — 对一封新邮件，找出所有需通知的订阅员工并发送
 *   2. retryPending()    — 重试 24 小时内发送失败的通知（F3，Cron 开头调用）
 *
 * 幂等性：
 *   INSERT IGNORE 依赖 UNIQUE KEY (mail_id, user_id)，
 *   Cron 重跑或并发时不会产生重复通知（D3）。
 */
class Notifier
{
    /**
     * 处理一封新邮件：按规则引擎判断，向命中 notify 的订阅员工发送通知
     *
     * @param array $mail    mails 表行
     * @param array $mailbox monitored_mailboxes 表行
     */
    public static function processNewMail(array $mail, array $mailbox): void
    {
        // 获取该邮箱的所有活跃订阅员工
        $subscribers = Database::fetchAll(
            'SELECT u.*
             FROM users u
             INNER JOIN subscriptions s ON s.user_id = u.id
             WHERE s.mailbox_id = ? AND u.status = ?',
            [(int)$mailbox['id'], 'active']
        );

        foreach ($subscribers as $user) {
            $result    = Classifier::resolveWithRule($mail, (int)$user['id'], (int)$mailbox['id']);
            $isIgnored = $result['action'] === 'ignore' ? 1 : 0;
            $ruleId    = $result['rule_id'];

            // INSERT IGNORE — UNIQUE KEY (mail_id, user_id) 保证幂等；始终记录（含无视）
            $pdo  = Database::get();
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO notifications (mail_id, user_id, is_ignored, matched_rule_id)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([(int)$mail['id'], (int)$user['id'], $isIgnored, $ruleId]);

            if ($stmt->rowCount() === 0) {
                continue; // 已存在，跳过（Cron 重跑保护）
            }

            $notificationId = Database::lastInsertId();

            // 只有 notify 才发邮件
            if (!$isIgnored) {
                $sent = Mailer::sendNotification($user, $mail, $mailbox, $notificationId);

                if ($sent) {
                    Database::query(
                        'UPDATE notifications SET email_sent_at = NOW() WHERE id = ?',
                        [$notificationId]
                    );
                } else {
                    // email_retry_count = 1，下次 Cron 会重试（F3）
                    Database::query(
                        'UPDATE notifications SET email_retry_count = 1 WHERE id = ?',
                        [$notificationId]
                    );
                }
            }
        }
    }

    /**
     * 重试发送失败的通知邮件（F3）
     *
     * 查询条件：
     *   - email_sent_at IS NULL（未成功发送）
     *   - email_retry_count < 3（未超过最大重试次数）
     *   - notified_at > NOW() - 24h（仅重试近期通知）
     *   - 用户 status = active
     *
     * @return int 本次重试的条数
     */
    public static function retryPending(): int
    {
        $pending = Database::fetchAll(
            'SELECT
                 n.id,
                 n.mail_id,
                 n.user_id,
                 n.email_retry_count,
                 u.name         AS user_name,
                 u.email        AS user_email,
                 u.notify_email,
                 m.subject,
                 m.from_address,
                 m.from_name,
                 m.received_at,
                 m.body_text,
                 m.body_html,
                 mb.id          AS mailbox_id,
                 mb.label       AS mailbox_label,
                 mb.email_address AS mailbox_email
             FROM notifications n
             INNER JOIN users u              ON u.id  = n.user_id
             INNER JOIN mails m              ON m.id  = n.mail_id
             INNER JOIN monitored_mailboxes mb ON mb.id = m.mailbox_id
             WHERE n.email_sent_at IS NULL
               AND n.email_retry_count < 3
               AND n.notified_at > NOW() - INTERVAL 24 HOUR
               AND n.is_ignored = 0
               AND u.status = ?',
            ['active']
        );

        $retried = 0;

        foreach ($pending as $row) {
            $user = [
                'id'           => $row['user_id'],
                'name'         => $row['user_name'],
                'email'        => $row['user_email'],
                'notify_email' => $row['notify_email'],
            ];
            $mail = [
                'subject'      => $row['subject'],
                'from_address' => $row['from_address'],
                'from_name'    => $row['from_name'],
                'received_at'  => $row['received_at'],
                'body_text'    => $row['body_text'],
                'body_html'    => $row['body_html'],
            ];
            $mailbox = [
                'id'            => $row['mailbox_id'],
                'label'         => $row['mailbox_label'],
                'email_address' => $row['mailbox_email'],
            ];

            $sent = Mailer::sendNotification($user, $mail, $mailbox, (int)$row['id']);

            if ($sent) {
                Database::query(
                    'UPDATE notifications SET email_sent_at = NOW() WHERE id = ?',
                    [(int)$row['id']]
                );
            } else {
                Database::query(
                    'UPDATE notifications SET email_retry_count = email_retry_count + 1 WHERE id = ?',
                    [(int)$row['id']]
                );
            }

            $retried++;
        }

        return $retried;
    }
}
