-- =====================================================
-- RBAC (Role-Based Access Control) Database Migration
-- =====================================================
-- This migration adds RBAC tracking to existing admin_activity_log
-- Note: The role field already exists in admin_users table

-- Verify admin_users.role column exists with proper enum values
-- This should already exist but we'll verify
SET @role_column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'admin_users'
    AND COLUMN_NAME = 'role');

-- If role column doesn't exist, create it
SET @sql = IF(@role_column_exists = 0,
    'ALTER TABLE `#__admin_users` ADD COLUMN `role` ENUM(''super_admin'', ''admin'', ''viewer'') DEFAULT ''admin'' COMMENT ''Admin role for RBAC''',
    'SELECT "Role column already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add role tracking to admin_activity_log
ALTER TABLE `#__admin_activity_log`
ADD COLUMN IF NOT EXISTS `admin_role` ENUM('super_admin', 'admin', 'viewer') NULL COMMENT 'Role of admin at time of action' AFTER `user_agent`,
ADD INDEX IF NOT EXISTS `idx_admin_role` (`admin_role`);

-- Create table `#__for` permission audit trail
CREATE TABLE IF NOT EXISTS `#__rbac_permission_denials` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL COMMENT 'Admin who was denied',
    `session_id` VARCHAR(64) NULL COMMENT 'Session ID if available',
    `admin_role` ENUM('super_admin', 'admin', 'viewer') NOT NULL COMMENT 'Role at time of denial',
    `requested_action` VARCHAR(100) NOT NULL COMMENT 'Action that was denied',
    `endpoint` VARCHAR(255) NULL COMMENT 'API endpoint or page',
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `denied_at` DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_admin_role` (`admin_role`),
    INDEX `idx_requested_action` (`requested_action`),
    INDEX `idx_denied_at` (`denied_at`),
    FOREIGN KEY (`admin_id`) REFERENCES `#__admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RBAC permission denial audit log';

-- System configuration for RBAC
INSERT INTO `#__system_config` (`config_key`, `config_value`, `description`) VALUES
('rbac_enabled', '1', 'Enable role-based access control (1=yes, 0=no)'),
('rbac_log_denials', '1', 'Log permission denials to rbac_permission_denials table'),
('rbac_strict_mode', '1', 'Deny access to undefined permissions (1=yes, 0=allow)')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);

-- Verify we have at least one super_admin account
SET @super_admin_count = (SELECT COUNT(*) FROM `#__admin_users` WHERE `role` = 'super_admin');

-- If no super_admin exists, promote the first admin to super_admin
UPDATE `#__admin_users` SET `role` = 'super_admin'
WHERE `id` = (SELECT `id` FROM (SELECT `id` FROM `#__admin_users` ORDER BY `id` ASC LIMIT 1) AS temp)
AND @super_admin_count = 0;

-- Migration complete
SELECT 'Migration: RBAC tables and permissions configured successfully' AS status;
SELECT CONCAT('Super admins: ', COUNT(*)) AS super_admin_count FROM `#__admin_users` WHERE `role` = 'super_admin';
