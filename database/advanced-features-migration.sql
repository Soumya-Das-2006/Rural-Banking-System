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
