-- MailGate Database Schema
-- Charset: utf8mb4 / Engine: InnoDB
-- 修正说明（来自架构评审）:
--   D1: mails UNIQUE KEY 改为 (mailbox_id, message_id) + (mailbox_id, imap_uid)
--   D2: mails 增加 to_address, cc 字段
--   D3: notifications 增加 UNIQUE KEY (mail_id, user_id)
--   D4: 所有 FK 明确 ON DELETE 行为
--   D6: users, rules 增加 updated_at
--   D7: attachments 增加 content_id（区分内联附件和普通附件）
--   D8: monitored_mailboxes 增加 imap_folder, fetch_limit, last_error, last_error_at
--   D10: users 增加 setup_token_hash / reset_token_hash 索引

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────
-- users
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`                 VARCHAR(100)    NOT NULL,
    `email`                VARCHAR(255)    NOT NULL,
    `password_hash`        VARCHAR(255)    NULL DEFAULT NULL,
    `role`                 ENUM('admin','recipient') NOT NULL DEFAULT 'recipient',
    `status`               ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',

    -- 初次设密令牌（数据库仅存 SHA-256 哈希，原始 token 只出现在邮件链接中）
    `setup_token_hash`     VARCHAR(64)     NULL DEFAULT NULL,
    `setup_token_expires`  DATETIME        NULL DEFAULT NULL,

    -- 忘记密码令牌
    `reset_token_hash`     VARCHAR(64)     NULL DEFAULT NULL,
    `reset_token_expires`  DATETIME        NULL DEFAULT NULL,

    -- 通知送达地址（NULL 则使用 email 字段）
    `notify_email`         VARCHAR(255)    NULL DEFAULT NULL,

    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_users_email`        (`email`),
    INDEX       `idx_setup_token`       (`setup_token_hash`),   -- D10: 防止全表扫描
    INDEX       `idx_reset_token`       (`reset_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- monitored_mailboxes — 被监控的共用邮箱
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `monitored_mailboxes` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `label`            VARCHAR(100)    NOT NULL,
    `email_address`    VARCHAR(255)    NOT NULL,
    `imap_host`        VARCHAR(255)    NOT NULL,
    `imap_port`        SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    `imap_encryption`  ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    `imap_user`        VARCHAR(255)    NOT NULL,
    -- AES-256-CBC 加密；格式: base64(iv[16bytes] + ciphertext)
    `imap_pass_enc`    TEXT            NOT NULL,
    `imap_folder`      VARCHAR(255)    NOT NULL DEFAULT 'INBOX',   -- D8
    `fetch_limit`      SMALLINT UNSIGNED NOT NULL DEFAULT 100,     -- D8: 首次拉取上限
    `is_active`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `last_fetched_at`  DATETIME        NULL DEFAULT NULL,
    `last_fetched_uid` INT UNSIGNED    NULL DEFAULT NULL,          -- 精确增量拉取 (F2/T2)
    `last_error`       TEXT            NULL DEFAULT NULL,          -- D8: 最后一次错误信息
    `last_error_at`    DATETIME        NULL DEFAULT NULL,          -- D8
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- subscriptions — 员工订阅关系
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `subscriptions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mailbox_id`  INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED NOT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_subscription` (`mailbox_id`, `user_id`),
    -- D4: 邮箱或用户删除时级联清理订阅
    CONSTRAINT `fk_sub_mailbox` FOREIGN KEY (`mailbox_id`)
        REFERENCES `monitored_mailboxes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_user`    FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rules — 通知规则
-- ─────────────────────────────────────────────────────────────────
-- 规则解析语义（规则级回退，非系统级覆盖）：
--   Step1: 个人规则 (scope=personal, user_id=U) 按 priority ASC 匹配，命中即止
--   Step2: 若 Step1 无命中，执行全局规则 (scope=global) 按 priority ASC 匹配，命中即止
--   Step3: 以上均无命中 → 默认 notify
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rules` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mailbox_id`    INT UNSIGNED NOT NULL,
    `label`         VARCHAR(100) NOT NULL DEFAULT '',  -- ルールの表示名（必須）
    `scope`         ENUM('global','personal') NOT NULL,
    `user_id`       INT UNSIGNED NULL DEFAULT NULL,  -- scope=global 时为 NULL
    `match_field`   ENUM('from_address','from_domain','subject','any') NOT NULL,
    -- 支持 * 通配符（前缀/后缀/中间匹配），如 *@client.co.jp、*urgent*
    `match_pattern` VARCHAR(255) NOT NULL,
    `action`        ENUM('notify','ignore') NOT NULL,
    `priority`      INT          NOT NULL DEFAULT 50,  -- 数字小优先
    `created_by`    INT UNSIGNED NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- D6

    PRIMARY KEY (`id`),
    -- 复合索引：Classifier 查询按此顺序过滤
    INDEX `idx_rules_lookup` (`mailbox_id`, `scope`, `user_id`, `priority`),
    -- D4: 邮箱删除时级联删除规则
    CONSTRAINT `fk_rules_mailbox`     FOREIGN KEY (`mailbox_id`)
        REFERENCES `monitored_mailboxes` (`id`) ON DELETE CASCADE,
    -- D4: 目标用户删除时级联删除其个人规则
    CONSTRAINT `fk_rules_user`        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    -- D4: 创建者被删除时阻止删除（防意外丢失规则归属）
    CONSTRAINT `fk_rules_created_by`  FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- mails — 从监控邮箱拉取的邮件
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mails` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mailbox_id`   INT UNSIGNED NOT NULL,
    `imap_uid`     INT UNSIGNED NULL DEFAULT NULL, -- IMAP UID，用于精确去重
    `message_id`   VARCHAR(255) NULL DEFAULT NULL, -- 原始 Message-ID（部分邮件可能无此头）
    `from_address` VARCHAR(255) NOT NULL DEFAULT '',
    `from_name`    VARCHAR(255) NOT NULL DEFAULT '',
    `to_address`   TEXT         NULL DEFAULT NULL, -- D2
    `cc`           TEXT         NULL DEFAULT NULL, -- D2
    `subject`      VARCHAR(500) NOT NULL DEFAULT '',
    `body_text`    LONGTEXT     NULL DEFAULT NULL,
    `body_html`    LONGTEXT     NULL DEFAULT NULL,
    `received_at`  DATETIME     NOT NULL,
    `fetched_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- D1: UNIQUE 粒度改为 (mailbox_id, imap_uid) 和 (mailbox_id, message_id)
    -- NULL 在 MySQL UNIQUE 中不相等，允许多条 NULL 记录（正确语义）
    UNIQUE KEY `uq_mails_uid`    (`mailbox_id`, `imap_uid`),
    UNIQUE KEY `uq_mails_msgid`  (`mailbox_id`, `message_id`),
    INDEX      `idx_mails_recv`  (`received_at`),
    -- D4: 邮箱删除时级联删除其所有邮件
    CONSTRAINT `fk_mails_mailbox` FOREIGN KEY (`mailbox_id`)
        REFERENCES `monitored_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- notifications — 通知记录（员工 × 邮件）
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mail_id`           INT UNSIGNED NOT NULL,
    `user_id`           INT UNSIGNED NOT NULL,
    `is_read`           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `read_at`           DATETIME     NULL DEFAULT NULL,
    `notified_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `email_sent_at`     DATETIME     NULL DEFAULT NULL,    -- NULL = 未发或发送失败
    `email_retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 超过 3 次不再重试 (F3)
    `is_trashed`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `trashed_at`        DATETIME         NULL     DEFAULT NULL,
    `is_ignored`        TINYINT(1)       NOT NULL DEFAULT 0    COMMENT '0=通常 1=無視（ルール/手動）',
    `matched_rule_id`   INT UNSIGNED     NULL     DEFAULT NULL COMMENT '命中ルール ID',

    PRIMARY KEY (`id`),
    -- D3: 防止 Cron 重跑产生重复通知
    UNIQUE KEY `uq_notification`       (`mail_id`, `user_id`),
    INDEX      `idx_notif_user_read`   (`user_id`, `is_read`, `is_trashed`),
    -- Cron 重试查询索引：找出 email_sent_at IS NULL 且重试次数未达上限的记录
    INDEX      `idx_notif_retry`       (`email_sent_at`, `email_retry_count`),
    INDEX      `idx_notif_ignored`     (`user_id`, `is_ignored`, `is_trashed`),
    -- D4: 邮件删除时级联删除通知
    CONSTRAINT `fk_notif_mail` FOREIGN KEY (`mail_id`)
        REFERENCES `mails` (`id`) ON DELETE CASCADE,
    -- D4: 用户删除时级联删除其通知
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_rule` FOREIGN KEY (`matched_rule_id`)
        REFERENCES `rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- attachments — 邮件附件
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `attachments` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mail_id`      INT UNSIGNED NOT NULL,
    `filename`     VARCHAR(255) NOT NULL,
    `mime_type`    VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    `size`         INT UNSIGNED NOT NULL DEFAULT 0,
    -- 存储路径使用 UUID 命名（非原始文件名），防路径遍历 (S6)
    -- 相对于 storage/attachments/ 的路径
    `storage_path` VARCHAR(500) NOT NULL,
    -- D7: 内联附件的 Content-ID（HTML 正文 <img src="cid:..."> 引用）
    `content_id`   VARCHAR(255) NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    INDEX `idx_attach_mail` (`mail_id`),
    -- D4: 邮件删除时级联删除附件记录
    CONSTRAINT `fk_attach_mail` FOREIGN KEY (`mail_id`)
        REFERENCES `mails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- system_settings — 系统配置（KV 存储）
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `system_settings` (
    `key`   VARCHAR(100) NOT NULL,
    `value` TEXT         NULL DEFAULT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认 SMTP 配置（初始为空，通过 admin/settings.php 配置）
INSERT IGNORE INTO `system_settings` (`key`, `value`) VALUES
    ('smtp_host',         ''),
    ('smtp_port',         '587'),
    ('smtp_encryption',   'tls'),
    ('smtp_user',         ''),
    ('smtp_pass_enc',     ''),
    ('smtp_from_address', ''),
    ('smtp_from_name',    'MailGate'),
    ('use_php_mail',      '0');

-- ─────────────────────────────────────────────────────────────────
-- audit_logs — 操作監査ログ（Phase 5）
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED  NULL DEFAULT NULL,
    `action`      VARCHAR(100)  NOT NULL,
    `target_type` VARCHAR(50)   NULL DEFAULT NULL,
    `target_id`   INT UNSIGNED  NULL DEFAULT NULL,
    `detail`      TEXT          NULL DEFAULT NULL,   -- JSON 文字列
    `ip_address`  VARCHAR(45)   NULL DEFAULT NULL,   -- IPv6 対応
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_user`    (`user_id`),
    INDEX `idx_audit_created` (`created_at`),
    -- ON DELETE SET NULL: ユーザー削除後もログを残し監査完全性を保つ
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- rule_exclusions — ユーザーがオプトアウトしたグローバルルール
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rule_exclusions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_id`    INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rule_exclusion` (`rule_id`, `user_id`),
    INDEX `idx_excl_user_rule` (`user_id`, `rule_id`),
    CONSTRAINT `fk_excl_rule` FOREIGN KEY (`rule_id`)
        REFERENCES `rules` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_excl_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────
-- 初始管理员账号（FC2）
-- 请在部署后通过以下方式创建第一个管理员账号：
--
--   方法 A（推荐）：运行 php /path/to/MailGate/setup-admin.php
--   方法 B（手动）：执行以下 SQL（替换 name/email），然后
--                   通过 admin 邮箱收取设密邮件完成注册
--
-- INSERT INTO `users` (name, email, role, status, setup_token_hash, setup_token_expires)
-- VALUES (
--     'Administrator',
--     'admin@example.com',
--     'admin',
--     'pending',
--     SHA2('your-random-token-here', 256),
--     DATE_ADD(NOW(), INTERVAL 24 HOUR)
-- );
-- ─────────────────────────────────────────────────────────────────
