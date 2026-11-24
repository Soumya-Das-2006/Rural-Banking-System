-- Add email-only SMTP outbox support to an existing database.
-- Select the Rural Banking System database before importing.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ib_email_outbox` (
  `outbox_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `attempts` smallint unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(200) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `sent_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`outbox_id`),
  KEY `idx_email_outbox_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
