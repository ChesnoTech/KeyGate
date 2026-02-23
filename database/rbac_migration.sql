-- ========================================
-- RBAC (Role-Based Access Control) Migration
-- ========================================

-- Table: rbac_permission_denials
CREATE TABLE IF NOT EXISTS `rbac_permission_denials` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NULL COMMENT 'Admin who was denied',
    `session_id` VARCHAR(64) NULL COMMENT 'Session ID',
    `admin_role` ENUM('super_admin', 'admin', 'viewer') NOT NULL COMMENT 'Admin role at time of denial',
    `requested_action` VARCHAR(100) NOT NULL COMMENT 'Action that was denied',
    `endpoint` VARCHAR(255) NULL COMMENT 'API endpoint or page',
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `denied_at` DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_admin_role` (`admin_role`),
    INDEX `idx_requested_action` (`requested_action`),
    INDEX `idx_denied_at` (`denied_at`),
    FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RBAC permission denial tracking';

-- Update admin_activity_log to include role
ALTER TABLE `admin_activity_log`
ADD COLUMN IF NOT EXISTS `admin_role` ENUM('super_admin', 'admin', 'viewer') NULL AFTER `user_agent`,
ADD INDEX IF NOT EXISTS `idx_admin_role` (`admin_role`);

-- Success message
SELECT 'RBAC migration completed successfully' AS status;
