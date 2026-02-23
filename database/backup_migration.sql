-- ========================================
-- Automated Backup Migration
-- ========================================

-- Table: backup_history
CREATE TABLE IF NOT EXISTS `backup_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `backup_filename` VARCHAR(255) NOT NULL,
    `backup_size_mb` DECIMAL(10,2) NOT NULL,
    `backup_type` ENUM('manual', 'scheduled') DEFAULT 'scheduled',
    `backup_status` ENUM('success', 'failed', 'partial') NOT NULL,
    `backup_duration_seconds` INT NULL,
    `tables_count` INT NULL,
    `error_message` TEXT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_by_admin_id` INT NULL COMMENT 'For manual backups',

    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_backup_status` (`backup_status`),
    INDEX `idx_backup_type` (`backup_type`),
    FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Database backup tracking';

-- Table: backup_restore_log
CREATE TABLE IF NOT EXISTS `backup_restore_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `backup_filename` VARCHAR(255) NOT NULL,
    `restore_status` ENUM('success', 'failed', 'partial') NOT NULL,
    `restore_duration_seconds` INT NULL,
    `error_message` TEXT NULL,
    `restored_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `restored_by_admin_id` INT NULL,

    INDEX `idx_restored_at` (`restored_at`),
    INDEX `idx_restore_status` (`restore_status`),
    FOREIGN KEY (`restored_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Backup restore operation audit';

-- System configuration for backups
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('backup_enabled', '1', 'Enable automated database backups (1=yes, 0=no)'),
('backup_retention_days', '30', 'Number of days to keep backups before deletion'),
('backup_schedule', '0 2 * * *', 'Backup cron schedule (default: daily at 2 AM)'),
('backup_email_notification', '0', 'Send email notification on backup completion (1=yes, 0=no)'),
('backup_notification_email', '', 'Email address for backup notifications')
ON DUPLICATE KEY UPDATE
    `config_value` = VALUES(`config_value`),
    `description` = VALUES(`description`);

-- Success message
SELECT 'Backup migration completed successfully' AS status;
