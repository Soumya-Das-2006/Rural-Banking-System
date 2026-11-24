-- Add internal-only QR/UPI simulation and customer notification records.
-- Select the existing Rural Banking System database before importing.
-- No real UPI, payment network, or external money movement is connected.
SET NAMES utf8mb4;

ALTER TABLE `ib_transactions`
  ADD COLUMN `tr_channel` varchar(20) NOT NULL DEFAULT 'standard' AFTER `tr_status`,
  ADD KEY `idx_tr_channel_created` (`tr_channel`,`created_at`);

CREATE TABLE IF NOT EXISTS `ib_upi_profiles` (
  `profile_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `upi_id` varchar(64) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `profile_type` varchar(12) NOT NULL DEFAULT 'personal',
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`profile_id`),
  UNIQUE KEY `uq_upi_id` (`upi_id`),
  UNIQUE KEY `uq_upi_client_account` (`client_id`,`account_id`),
  KEY `idx_upi_status` (`status`,`profile_type`),
  CONSTRAINT `fk_upi_profile_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_upi_profile_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_qr_codes` (
  `qr_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint unsigned NOT NULL,
  `qr_token` char(32) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`qr_id`),
  UNIQUE KEY `uq_qr_token` (`qr_token`),
  UNIQUE KEY `uq_qr_profile` (`profile_id`),
  KEY `idx_qr_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_qr_profile` FOREIGN KEY (`profile_id`) REFERENCES `ib_upi_profiles` (`profile_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_payment_requests` (
  `request_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_code` char(32) NOT NULL,
  `payer_client_id` int NOT NULL,
  `payee_profile_id` bigint unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `transaction_id` int DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_payment_request_code` (`request_code`),
  KEY `idx_payment_request_payer_status` (`payer_client_id`,`status`,`created_at`),
  KEY `idx_payment_request_payee_status` (`payee_profile_id`,`status`,`created_at`),
  CONSTRAINT `fk_payment_request_payer` FOREIGN KEY (`payer_client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payment_request_payee` FOREIGN KEY (`payee_profile_id`) REFERENCES `ib_upi_profiles` (`profile_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_user_notifications` (
  `notification_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `category` varchar(24) NOT NULL DEFAULT 'account',
  `subject` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `email_outbox_id` bigint unsigned DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_notifications_client_read` (`client_id`,`read_at`,`created_at`),
  CONSTRAINT `fk_user_notification_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_user_notification_email` FOREIGN KEY (`email_outbox_id`) REFERENCES `ib_email_outbox` (`outbox_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
