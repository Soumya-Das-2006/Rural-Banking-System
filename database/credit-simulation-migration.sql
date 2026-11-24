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
