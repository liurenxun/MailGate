-- MailGate マイグレーション: user_smtp_settings テーブル追加 + use_php_mail デフォルト修正
-- 既存DBに対して実行してください（新規インストールは schema.sql のみで OK）

-- 1. ユーザー個別SMTP設定テーブル
CREATE TABLE IF NOT EXISTS `user_smtp_settings` (
    `user_id`         INT UNSIGNED  NOT NULL,
    `smtp_host`       VARCHAR(255)  NOT NULL DEFAULT '',
    `smtp_port`       SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    `smtp_encryption` ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
    `smtp_user`       VARCHAR(255)  NOT NULL DEFAULT '',
    `smtp_pass_enc`   TEXT          NULL DEFAULT NULL,
    `from_address`    VARCHAR(255)  NOT NULL DEFAULT '',
    `from_name`       VARCHAR(255)  NOT NULL DEFAULT '',
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_uss_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. use_php_mail のデフォルト修正（'0'のままの場合のみ '1' に更新）
UPDATE `system_settings` SET `value` = '1' WHERE `key` = 'use_php_mail' AND `value` = '0';
