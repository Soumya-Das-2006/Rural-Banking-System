-- Add reversible archive metadata to existing demo-card records.
-- Select the Rural Banking System database before importing.
-- Card and authorization records are retained; this migration deletes nothing.
SET NAMES utf8mb4;

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

SET @rb_archive_at_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ib_demo_cards'
    AND COLUMN_NAME = 'archived_at'
);
SET @rb_migration_sql = IF(@rb_archive_at_exists = 0,
  'ALTER TABLE `ib_demo_cards` ADD COLUMN `archived_at` timestamp NULL DEFAULT NULL',
  'SELECT 1');
PREPARE rb_archive_stmt FROM @rb_migration_sql;
EXECUTE rb_archive_stmt;
DEALLOCATE PREPARE rb_archive_stmt;

SET @rb_archive_role_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ib_demo_cards'
    AND COLUMN_NAME = 'archived_by_role'
);
SET @rb_migration_sql = IF(@rb_archive_role_exists = 0,
  'ALTER TABLE `ib_demo_cards` ADD COLUMN `archived_by_role` varchar(8) DEFAULT NULL',
  'SELECT 1');
PREPARE rb_archive_stmt FROM @rb_migration_sql;
EXECUTE rb_archive_stmt;
DEALLOCATE PREPARE rb_archive_stmt;

SET @rb_archive_id_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ib_demo_cards'
    AND COLUMN_NAME = 'archived_by_id'
);
SET @rb_migration_sql = IF(@rb_archive_id_exists = 0,
  'ALTER TABLE `ib_demo_cards` ADD COLUMN `archived_by_id` int unsigned DEFAULT NULL',
  'SELECT 1');
PREPARE rb_archive_stmt FROM @rb_migration_sql;
EXECUTE rb_archive_stmt;
DEALLOCATE PREPARE rb_archive_stmt;
