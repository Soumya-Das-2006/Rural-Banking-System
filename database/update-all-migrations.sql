-- Combined additive upgrade for an EXISTING Rural Banking System database.
-- Select the correct database in phpMyAdmin before importing this file.
-- Creates feature tables and default expense categories without replacing customer,
-- account, or transaction records. Safe to run when the individual migrations were
-- already imported; the QR transaction column and index are checked before adding.
-- Do not import schema.sql or demo_data.sql over an existing production database.

-- ===== Included migration: credit-simulation-migration.sql =====
-- Add customer-owned credit health simulations to an existing database.
-- Select the existing Rural Banking System database before importing.
SET NAMES utf8mb4;

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


-- ===== Included migration: card-management-migration.sql =====
-- Add fictional demo debit-card controls and sandbox authorization history.
-- Select the existing Rural Banking System database before importing.
-- No PAN, CVV, PIN, OTP, or payment-network credentials are stored.
SET NAMES utf8mb4;

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


-- ===== Included migration: email-notification-migration.sql =====
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


-- ===== Included migration: qr-notification-migration.sql =====
-- Add internal-only QR/UPI simulation and customer notification records.
-- Select the existing Rural Banking System database before importing.
-- No real UPI, payment network, or external money movement is connected.
SET NAMES utf8mb4;

-- Add the QR channel column and index only if this migration has not already added them.
SET @rb_channel_column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ib_transactions'
    AND COLUMN_NAME = 'tr_channel'
);
SET @rb_migration_sql = IF(
  @rb_channel_column_exists = 0,
  'ALTER TABLE `ib_transactions` ADD COLUMN `tr_channel` varchar(20) NOT NULL DEFAULT ''standard'' AFTER `tr_status`',
  'SELECT 1'
);
PREPARE rb_migration_stmt FROM @rb_migration_sql;
EXECUTE rb_migration_stmt;
DEALLOCATE PREPARE rb_migration_stmt;

SET @rb_channel_index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ib_transactions'
    AND INDEX_NAME = 'idx_tr_channel_created'
);
SET @rb_migration_sql = IF(
  @rb_channel_index_exists = 0,
  'ALTER TABLE `ib_transactions` ADD KEY `idx_tr_channel_created` (`tr_channel`,`created_at`)',
  'SELECT 1'
);
PREPARE rb_migration_stmt FROM @rb_migration_sql;
EXECUTE rb_migration_stmt;
DEALLOCATE PREPARE rb_migration_stmt;

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


-- ===== Included migration: advanced-features-migration.sql =====
-- Additive tables for simulated finance products, customer tools, operations
-- and security. Import after schema.sql and the email/QR migrations.
-- Existing rows are not altered or removed. All financial activity stays in
-- the existing simulated ledger.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ib_loans` (
  `loan_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `requested_amount` decimal(14,2) NOT NULL,
  `annual_rate` decimal(6,3) NOT NULL,
  `term_months` smallint unsigned NOT NULL,
  `monthly_emi` decimal(14,2) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `disbursement_reference` varchar(60) NOT NULL DEFAULT '',
  `review_note` varchar(500) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`loan_id`),
  KEY `idx_loans_client_status` (`client_id`,`status`,`created_at`),
  KEY `idx_loans_review_queue` (`status`,`created_at`),
  CONSTRAINT `fk_loans_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_loans_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_loans_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_loan_repayments` (
  `repayment_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint unsigned NOT NULL,
  `installment_no` smallint unsigned NOT NULL,
  `due_date` date NOT NULL,
  `principal_amount` decimal(14,2) NOT NULL,
  `interest_amount` decimal(14,2) NOT NULL,
  `total_amount` decimal(14,2) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'due',
  `transaction_id` int DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`repayment_id`),
  UNIQUE KEY `uq_loan_installment` (`loan_id`,`installment_no`),
  KEY `idx_repayments_due` (`status`,`due_date`),
  CONSTRAINT `fk_repayments_loan` FOREIGN KEY (`loan_id`) REFERENCES `ib_loans` (`loan_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_fixed_deposits` (
  `fd_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `principal` decimal(14,2) NOT NULL,
  `annual_rate` decimal(6,3) NOT NULL,
  `term_months` smallint unsigned NOT NULL,
  `maturity_amount` decimal(14,2) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `matures_at` date NOT NULL,
  PRIMARY KEY (`fd_id`),
  KEY `idx_fd_client_status` (`client_id`,`status`,`matures_at`),
  CONSTRAINT `fk_fd_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fd_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_recurring_deposits` (
  `rd_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `monthly_amount` decimal(14,2) NOT NULL,
  `annual_rate` decimal(6,3) NOT NULL,
  `term_months` smallint unsigned NOT NULL,
  `maturity_estimate` decimal(14,2) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `next_due_at` date NOT NULL,
  PRIMARY KEY (`rd_id`),
  KEY `idx_rd_client_status_due` (`client_id`,`status`,`next_due_at`),
  CONSTRAINT `fk_rd_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rd_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_expense_categories` (
  `category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int DEFAULT NULL,
  `name` varchar(60) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_expense_category_client_name` (`client_id`,`name`),
  CONSTRAINT `fk_expense_category_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ib_expense_categories` (`client_id`,`name`,`is_system`)
SELECT NULL, defaults.name, 1
FROM (
  SELECT 'Food' AS name UNION ALL SELECT 'Housing' UNION ALL SELECT 'Transport'
  UNION ALL SELECT 'Healthcare' UNION ALL SELECT 'Education' UNION ALL SELECT 'Other'
) AS defaults
WHERE NOT EXISTS (
  SELECT 1 FROM `ib_expense_categories` existing
  WHERE existing.client_id IS NULL AND existing.name = defaults.name
);

CREATE TABLE IF NOT EXISTS `ib_expense_entries` (
  `entry_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `transaction_id` int NOT NULL,
  `category_id` int unsigned NOT NULL,
  `note` varchar(180) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`entry_id`),
  UNIQUE KEY `uq_expense_transaction` (`transaction_id`),
  KEY `idx_expense_client_date` (`client_id`,`created_at`,`category_id`),
  CONSTRAINT `fk_expense_entry_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expense_entry_category` FOREIGN KEY (`category_id`) REFERENCES `ib_expense_categories` (`category_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_budgets` (
  `budget_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `category_id` int unsigned NOT NULL,
  `budget_month` char(7) NOT NULL,
  `limit_amount` decimal(14,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`budget_id`),
  UNIQUE KEY `uq_budget_client_category_month` (`client_id`,`category_id`,`budget_month`),
  CONSTRAINT `fk_budget_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_budget_category` FOREIGN KEY (`category_id`) REFERENCES `ib_expense_categories` (`category_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_savings_goals` (
  `goal_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `goal_type` varchar(30) NOT NULL DEFAULT 'other',
  `target_amount` decimal(14,2) NOT NULL,
  `saved_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `target_date` date DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`goal_id`),
  KEY `idx_goals_client_status` (`client_id`,`status`,`target_date`),
  CONSTRAINT `fk_goals_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_beneficiaries` (
  `beneficiary_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `receiving_account_number` varchar(50) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`beneficiary_id`),
  KEY `idx_beneficiary_owner_status` (`client_id`,`account_id`,`status`),
  CONSTRAINT `fk_beneficiary_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_beneficiary_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_cheque_requests` (
  `cheque_request_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `leaf_count` smallint unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`cheque_request_id`),
  KEY `idx_cheque_request_status` (`status`,`created_at`),
  KEY `idx_cheque_request_client` (`client_id`,`created_at`),
  CONSTRAINT `fk_cheque_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cheque_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cheque_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_branches` (
  `branch_id` int NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(24) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(24) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`),
  UNIQUE KEY `uq_branch_code` (`branch_code`),
  KEY `idx_branch_status_name` (`status`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_agent_profiles` (
  `agent_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int DEFAULT NULL,
  `agent_code` varchar(24) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(24) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`agent_id`),
  UNIQUE KEY `uq_agent_code` (`agent_code`),
  UNIQUE KEY `uq_agent_email` (`email`),
  KEY `idx_agent_branch_status` (`branch_id`,`status`),
  CONSTRAINT `fk_agent_branch` FOREIGN KEY (`branch_id`) REFERENCES `ib_branches` (`branch_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_agent_activity` (
  `activity_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned NOT NULL,
  `client_id` int DEFAULT NULL,
  `activity_type` varchar(24) NOT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'recorded',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`activity_id`),
  KEY `idx_agent_activity_agent_created` (`agent_id`,`created_at`),
  CONSTRAINT `fk_agent_activity_agent` FOREIGN KEY (`agent_id`) REFERENCES `ib_agent_profiles` (`agent_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_agent_activity_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_rewards` (
  `reward_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `points_delta` int NOT NULL,
  `reason` varchar(80) NOT NULL,
  `reference_code` varchar(60) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`reward_id`),
  UNIQUE KEY `uq_reward_client_reference_reason` (`client_id`,`reference_code`,`reason`),
  KEY `idx_rewards_client_created` (`client_id`,`created_at`),
  CONSTRAINT `fk_rewards_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_user_permissions` (
  `permission_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int NOT NULL,
  `permission_key` varchar(40) NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `uq_staff_permission` (`staff_id`,`permission_key`),
  KEY `idx_permission_key_allowed` (`permission_key`,`is_allowed`),
  CONSTRAINT `fk_permission_staff` FOREIGN KEY (`staff_id`) REFERENCES `ib_staff` (`staff_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_permission_admin` FOREIGN KEY (`updated_by`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_kyc_reviews` (
  `kyc_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reviewer_admin_id` int DEFAULT NULL,
  `review_note` varchar(500) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`kyc_id`),
  UNIQUE KEY `uq_kyc_client` (`client_id`),
  KEY `idx_kyc_review_queue` (`status`,`created_at`),
  CONSTRAINT `fk_kyc_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_kyc_reviewer` FOREIGN KEY (`reviewer_admin_id`) REFERENCES `ib_admin` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_login_devices` (
  `device_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `device_hash` char(64) NOT NULL,
  `label` varchar(100) NOT NULL DEFAULT 'Browser',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `status` varchar(12) NOT NULL DEFAULT 'active',
  `first_seen` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `last_seen` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `uq_login_device_hash` (`client_id`,`device_hash`),
  KEY `idx_login_device_owner_status` (`client_id`,`status`,`last_seen`),
  CONSTRAINT `fk_login_device_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_support_tickets` (
  `ticket_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `subject` varchar(120) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `assigned_staff_id` int DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ticket_id`),
  KEY `idx_support_status_created` (`status`,`created_at`),
  KEY `idx_support_client_created` (`client_id`,`created_at`),
  CONSTRAINT `fk_support_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_support_staff` FOREIGN KEY (`assigned_staff_id`) REFERENCES `ib_staff` (`staff_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_support_responses` (
  `response_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `admin_id` int NOT NULL,
  `response` text NOT NULL,
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`response_id`),
  KEY `idx_support_response_ticket` (`ticket_id`,`response_id`),
  CONSTRAINT `fk_support_response_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `ib_support_tickets` (`ticket_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_support_response_admin` FOREIGN KEY (`admin_id`) REFERENCES `ib_admin` (`admin_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_audit_log` (
  `audit_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_role` varchar(12) NOT NULL,
  `actor_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(40) NOT NULL DEFAULT '',
  `entity_id` varchar(60) NOT NULL DEFAULT '',
  `details` varchar(500) NOT NULL DEFAULT '',
  `ip_hash` char(64) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_actor_time` (`actor_role`,`actor_id`,`created_at`),
  KEY `idx_audit_action_time` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_announcements` (
  `announcement_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `subject` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'draft',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`announcement_id`),
  KEY `idx_announcement_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_announcement_admin` FOREIGN KEY (`admin_id`) REFERENCES `ib_admin` (`admin_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_backup_records` (
  `backup_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `backup_type` varchar(20) NOT NULL DEFAULT 'database',
  `status` varchar(16) NOT NULL DEFAULT 'recorded',
  `storage_reference` varchar(190) NOT NULL DEFAULT '',
  `notes` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`backup_id`),
  KEY `idx_backup_created` (`created_at`,`status`),
  CONSTRAINT `fk_backup_admin` FOREIGN KEY (`admin_id`) REFERENCES `ib_admin` (`admin_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ib_scheduled_transfers` (
  `schedule_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `account_id` int NOT NULL,
  `receiving_account_no` varchar(50) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `frequency` varchar(12) NOT NULL,
  `next_run_at` datetime NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'pending',
  `created_at` timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `last_run_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`schedule_id`),
  KEY `idx_scheduled_transfers_due` (`status`,`next_run_at`),
  KEY `idx_scheduled_transfers_client` (`client_id`,`status`),
  CONSTRAINT `fk_schedule_client` FOREIGN KEY (`client_id`) REFERENCES `ib_clients` (`client_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_schedule_account` FOREIGN KEY (`account_id`) REFERENCES `ib_bankaccounts` (`account_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in and authenticated-page activity, plus public signup rate limiting.
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

-- Admin-managed customer testimonials; only consent-confirmed published rows are public.
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

-- Rename only the shipped default; preserve any custom system name.
UPDATE `ib_systemsettings`
SET `sys_name` = 'Bank Of Soumya'
WHERE `sys_name` = 'Rural Banking System';

