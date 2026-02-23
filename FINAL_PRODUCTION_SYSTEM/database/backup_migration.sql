-- =====================================================
-- Automated Backup Database Migration
-- =====================================================
-- This migration adds database tables for backup tracking and management

-- Table: backup_history
-- Tracks all database backups (automated and manual)
CREATE TABLE IF NOT EXISTS `backup_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `backup_filename` VARCHAR(255) NOT NULL COMMENT 'Filename in backups directory',
    `backup_size_mb` DECIMAL(10,2) NOT NULL COMMENT 'Backup file size in megabytes',
    `backup_type` ENUM('manual', 'scheduled') DEFAULT 'scheduled' COMMENT 'How backup was triggered',
    `backup_status` ENUM('success', 'failed', 'partial') NOT NULL COMMENT 'Backup completion status',
    `backup_duration_seconds` INT NULL COMMENT 'Time taken to complete backup',
    `tables_count` INT NULL COMMENT 'Number of tables backed up',
    `rows_count` BIGINT NULL COMMENT 'Total rows backed up (optional)',
    `compression_ratio` DECIMAL(5,2) NULL COMMENT 'Compression percentage (optional)',
    `error_message` TEXT NULL COMMENT 'Error details if backup failed',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Backup start time',
    `created_by_admin_id` INT NULL COMMENT 'Admin who triggered manual backup',
    `deleted_at` DATETIME NULL COMMENT 'When backup file was deleted (retention)',

    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_backup_status` (`backup_status`),
    INDEX `idx_backup_type` (`backup_type`),
    INDEX `idx_deleted_at` (`deleted_at`),
    INDEX `idx_filename` (`backup_filename`),
    FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Database backup history and tracking';

-- Table: backup_restore_log
-- Tracks database restore operations for disaster recovery audit
CREATE TABLE IF NOT EXISTS `backup_restore_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `backup_history_id` INT NULL COMMENT 'Which backup was restored',
    `backup_filename` VARCHAR(255) NOT NULL COMMENT 'Backup file used for restore',
    `restore_status` ENUM('success', 'failed', 'partial') NOT NULL,
    `restore_duration_seconds` INT NULL,
    `tables_restored` INT NULL,
    `error_message` TEXT NULL,
    `restored_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `restored_by_admin_id` INT NULL COMMENT 'Admin who performed restore',
    `restore_notes` TEXT NULL COMMENT 'Admin notes about restore reason',

    INDEX `idx_restored_at` (`restored_at`),
    INDEX `idx_restore_status` (`restore_status`),
    FOREIGN KEY (`backup_history_id`) REFERENCES `backup_history`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`restored_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Database restore operation audit log';

-- System configuration for automated backups
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('backup_enabled', '1', 'Enable automated database backups (1=yes, 0=no)'),
('backup_retention_days', '30', 'Number of days to keep backups before deletion'),
('backup_schedule', '0 2 * * *', 'Backup cron schedule (default: daily at 2 AM UTC)'),
('backup_email_notification', '1', 'Send email notification on backup completion (1=yes, 0=no)'),
('backup_notification_email', '', 'Email address for backup notifications (comma-separated)'),
('backup_compress', '1', 'Use gzip compression for backups (1=yes, 0=no)'),
('backup_verify', '1', 'Verify backup integrity after creation (1=yes, 0=no)'),
('backup_max_size_mb', '5000', 'Alert if backup exceeds this size (MB)'),
('backup_directory', './backups/', 'Local directory path for backup storage')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);

-- Migration complete
SELECT 'Migration: Backup tracking tables created successfully' AS status;
