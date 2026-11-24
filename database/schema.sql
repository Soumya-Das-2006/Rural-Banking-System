-- Rural Banking System - database schema (MySQL 5.7+ / MariaDB 10.3+)
-- (c) 2026 Soumya Das
-- All table names are lowercase so the app works on Linux and Windows hosts alike.
--
-- Import this file after selecting or creating the database in phpMyAdmin.
-- Shared hosts do not normally permit applications to create/use arbitrary databases.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ib_systemsettings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sys_name` varchar(200) NOT NULL DEFAULT 'Bank Of Soumya',
  `sys_tagline` varchar(255) NOT NULL DEFAULT '',
  `sys_logo` varchar(200) NOT NULL DEFAULT 'bank-of-soumya-logo-optimized.png',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_admin` (
  `admin_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `email` varchar(190) NOT NULL,
  `number` varchar(200) NOT NULL DEFAULT '',
  `password` varchar(255) NOT NULL,
  `profile_pic` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_staff` (
  `staff_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `staff_number` varchar(200) NOT NULL DEFAULT '',
  `phone` varchar(200) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `sex` varchar(50) NOT NULL DEFAULT '',
  `profile_pic` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `uq_staff_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_clients` (
  `client_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `national_id` varchar(200) NOT NULL DEFAULT '',
  `phone` varchar(200) NOT NULL DEFAULT '',
  `address` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_pic` varchar(200) NOT NULL DEFAULT '',
  `client_number` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`client_id`),
  UNIQUE KEY `uq_client_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_acc_types` (
  `acctype_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` longtext NOT NULL,
  `rate` varchar(50) NOT NULL DEFAULT '0',
  `code` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`acctype_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_bankaccounts` (
  `account_id` int NOT NULL AUTO_INCREMENT,
  `acc_name` varchar(200) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `acc_type` varchar(200) NOT NULL,
  `acc_rates` varchar(50) NOT NULL DEFAULT '0',
  `acc_status` varchar(50) NOT NULL DEFAULT 'Active',
  `acc_amount` varchar(50) NOT NULL DEFAULT '0',
  `client_id` int NOT NULL,
  `client_name` varchar(200) NOT NULL DEFAULT '',
  `client_national_id` varchar(200) NOT NULL DEFAULT '',
  `client_phone` varchar(200) NOT NULL DEFAULT '',
  `client_number` varchar(100) NOT NULL DEFAULT '',
  `client_email` varchar(190) NOT NULL DEFAULT '',
  `client_adr` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `uq_account_number` (`account_number`),
  KEY `idx_acc_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_transactions` (
  `tr_id` int NOT NULL AUTO_INCREMENT,
  `tr_code` varchar(60) NOT NULL,
  `account_id` int NOT NULL,
  `acc_name` varchar(200) NOT NULL DEFAULT '',
  `account_number` varchar(50) NOT NULL DEFAULT '',
  `acc_type` varchar(200) NOT NULL DEFAULT '',
  `acc_amount` varchar(50) NOT NULL DEFAULT '',
  `tr_type` varchar(30) NOT NULL,
  `tr_status` varchar(30) NOT NULL DEFAULT 'Success',
  `tr_channel` varchar(20) NOT NULL DEFAULT 'standard',
  `client_id` int NOT NULL,
  `client_name` varchar(200) NOT NULL DEFAULT '',
  `client_national_id` varchar(200) NOT NULL DEFAULT '',
  `transaction_amt` decimal(14,2) NOT NULL,
  `client_phone` varchar(200) NOT NULL DEFAULT '',
  `receiving_acc_no` varchar(50) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `receiving_acc_name` varchar(200) NOT NULL DEFAULT '',
  `receiving_acc_holder` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`tr_id`),
  UNIQUE KEY `uq_tr_code` (`tr_code`),
  KEY `idx_tr_account` (`account_id`),
  KEY `idx_tr_client` (`client_id`),
  KEY `idx_tr_receiving` (`receiving_acc_no`),
  KEY `idx_tr_channel_created` (`tr_channel`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_notifications` (
  `notification_id` int NOT NULL AUTO_INCREMENT,
  `notification_details` text NOT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `email` varchar(190) NOT NULL DEFAULT '',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_ip` (`ip`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_user_activity` (
  `event_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_role` varchar(12) NOT NULL,
  `actor_id` int NOT NULL DEFAULT 0,
  `event_type` varchar(24) NOT NULL,
  `page` varchar(190) NOT NULL DEFAULT '',
  `request_method` varchar(8) NOT NULL DEFAULT 'GET',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `location` varchar(120) NOT NULL DEFAULT 'Unavailable',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`event_id`),
  KEY `idx_user_activity_created` (`created_at`,`event_id`),
  KEY `idx_user_activity_actor` (`actor_role`,`actor_id`,`created_at`),
  KEY `idx_user_activity_type` (`event_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_public_form_attempts` (
  `attempt_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `form_name` varchar(32) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_public_form_rate` (`form_name`,`ip_address`,`attempted_at`),
  KEY `idx_public_form_cleanup` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_translation_cache` (
  `lang` varchar(8) NOT NULL,
  `text_hash` char(40) NOT NULL,
  `source` text NOT NULL,
  `translated` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`lang`,`text_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_api_usage` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_fraud_alerts` (
  `alert_id` int NOT NULL AUTO_INCREMENT,
  `tr_id` int NOT NULL,
  `account_id` int NOT NULL,
  `client_id` int NOT NULL,
  `rule_code` varchar(30) NOT NULL,
  `severity` varchar(10) NOT NULL DEFAULT 'Medium',
  `details` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'Open',
  `reviewed_by` varchar(60) NOT NULL DEFAULT '',
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alert_id`),
  UNIQUE KEY `uq_alert_rule` (`tr_id`,`rule_code`),
  KEY `idx_alert_status` (`status`,`alert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_ai_usage` (
  `id` int NOT NULL AUTO_INCREMENT,
  `actor` varchar(60) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor` (`actor`,`kind`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `ib_testimonials` (
  `testimonial_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `quote_text` varchar(1200) NOT NULL,
  `display_name` varchar(80) NOT NULL,
  `attribution` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  `consent_confirmed` tinyint unsigned NOT NULL DEFAULT 0,
  `consent_confirmed_by` int DEFAULT NULL,
  `consent_confirmed_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`testimonial_id`),
  KEY `idx_testimonial_public` (`status`,`consent_confirmed`,`sort_order`,`created_at`),
  KEY `idx_testimonial_updated` (`updated_at`),
  CONSTRAINT `fk_testimonial_consent_admin` FOREIGN KEY (`consent_confirmed_by`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_testimonial_created_admin` FOREIGN KEY (`created_by`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_testimonial_updated_admin` FOREIGN KEY (`updated_by`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS `ib_credit_profiles` (
  `client_id` int NOT NULL,
  `monthly_income` decimal(14,2) NOT NULL DEFAULT 0.00,
  `monthly_obligations` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_limit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit_used` decimal(14,2) NOT NULL DEFAULT 0.00,
  `on_time_pct` tinyint unsigned NOT NULL DEFAULT 0,
  `history_months` smallint unsigned NOT NULL DEFAULT 0,
  `recent_inquiries` tinyint unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_credit_score_history` (
  `history_id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `score` smallint unsigned NOT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`history_id`),
  KEY `idx_credit_history_client_created` (`client_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_demo_cards` (
  `card_id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `card_type` varchar(12) NOT NULL,
  `card_status` varchar(12) NOT NULL,
  `card_reference` varchar(32) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `frozen_until` datetime DEFAULT NULL,
  `atm_daily_limit` decimal(14,2) NOT NULL DEFAULT 25000.00,
  `pos_transaction_limit` decimal(14,2) NOT NULL DEFAULT 5000.00,
  `online_transaction_limit` decimal(14,2) NOT NULL DEFAULT 5000.00,
  `replacement_for_card_id` int unsigned DEFAULT NULL,
  `replaced_by_card_id` int unsigned DEFAULT NULL,
  `reviewed_by_role` varchar(8) DEFAULT NULL,
  `reviewed_by_id` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`card_id`),
  UNIQUE KEY `uq_demo_card_reference` (`card_reference`),
  KEY `idx_demo_card_owner_account` (`client_id`,`account_id`,`card_type`,`card_status`),
  KEY `idx_demo_card_replacement` (`replacement_for_card_id`,`card_status`),
  KEY `idx_demo_card_review_queue` (`card_status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_demo_card_transactions` (
  `card_transaction_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int unsigned NOT NULL,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `channel` varchar(8) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `decision` varchar(8) NOT NULL,
  `reason` varchar(160) NOT NULL,
  `request_key` char(32) NOT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`card_transaction_id`),
  UNIQUE KEY `uq_demo_card_request` (`request_key`),
  KEY `idx_demo_card_txn_owner_created` (`client_id`,`created_at`),
  KEY `idx_demo_card_txn_card_channel_created` (`card_id`,`channel`,`decision`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Starter data. CHANGE THE DEFAULT PASSWORDS IMMEDIATELY AFTER FIRST LOGIN.
--   admin@mail.com  /  Admin@12345
--   staff@mail.com  /  Staff@12345
-- (They are upgraded to modern salted hashes the first time you sign in.)
-- ---------------------------------------------------------------------
INSERT INTO `ib_systemsettings` (`id`,`sys_name`,`sys_tagline`,`sys_logo`) VALUES
(1,'Bank Of Soumya','Simple, secure banking for every village.','bank-of-soumya-logo-optimized.png');

INSERT INTO `ib_admin` (`name`,`email`,`number`,`password`,`profile_pic`) VALUES
('System Administrator','admin@mail.com','RB-ADM-0001','0d26f6463f80b1ec07d00e76d8e704df1ea4ffab','');

INSERT INTO `ib_staff` (`name`,`staff_number`,`phone`,`email`,`password`,`sex`,`profile_pic`) VALUES
('Branch Officer','RB-STAFF-0001','9000000001','staff@mail.com','ed918147b0d2b438e5907bab4a922262547388c8','Male','');

INSERT INTO `ib_acc_types` (`acctype_id`,`name`,`description`,`rate`,`code`) VALUES
(1,'Savings','<p>An everyday account for individuals and families to save safely and earn interest.</p>','4','ACC-SAV01'),
(2,'Kisan Savings','<p>A savings account designed for farmers, with seasonal deposits around harvest.</p>','5','ACC-KSN02'),
(3,'Recurring Deposit','<p>Save a fixed amount every month for a set period and earn a higher rate.</p>','6','ACC-RDP03'),
(4,'Fixed Deposit','<p>Lock a lump sum for a fixed term at a guaranteed rate of interest.</p>','7','ACC-FDP04'),
(5,'Current','<p>For shops, traders and small businesses with frequent transactions.</p>','0','ACC-CUR05');
