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
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by_role` varchar(8) DEFAULT NULL,
  `archived_by_id` int unsigned DEFAULT NULL,
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
