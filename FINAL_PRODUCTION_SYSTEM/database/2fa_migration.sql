-- =====================================================
-- 2FA (Two-Factor Authentication) Database Migration
-- =====================================================
-- This migration adds TOTP-based 2FA support for admin accounts
-- with trusted network bypass functionality

-- Table: admin_totp_secrets
-- Stores TOTP secrets and backup codes for admin 2FA
CREATE TABLE IF NOT EXISTS `#__admin_totp_secrets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL COMMENT 'Reference to admin_users.id',
    `totp_secret` VARCHAR(255) NOT NULL COMMENT 'Base32 encoded TOTP secret',
    `totp_enabled` TINYINT(1) DEFAULT 0 COMMENT '1=2FA active, 0=setup incomplete or disabled',
    `backup_codes` TEXT NULL COMMENT 'JSON array of hashed backup codes (bcrypt)',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `verified_at` DATETIME NULL COMMENT 'When user completed 2FA setup verification',
    `last_used_at` DATETIME NULL COMMENT 'Last successful TOTP verification',

    UNIQUE KEY `idx_admin_id` (`admin_id`),
    INDEX `idx_enabled` (`totp_enabled`),
    FOREIGN KEY (`admin_id`) REFERENCES `#__admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='TOTP 2FA secrets for admin accounts';

-- Table: trusted_networks
-- Defines network subnets that are trusted for security bypasses
CREATE TABLE IF NOT EXISTS `#__trusted_networks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `network_name` VARCHAR(100) NOT NULL COMMENT 'Friendly name (e.g., "Office LAN")',
    `ip_range` VARCHAR(45) NOT NULL COMMENT 'CIDR notation (e.g., 192.168.1.0/24)',
    `bypass_2fa` TINYINT(1) DEFAULT 1 COMMENT 'Skip 2FA prompt for this network',
    `allow_usb_auth` TINYINT(1) DEFAULT 1 COMMENT 'Allow USB authentication from this network',
    `description` TEXT NULL COMMENT 'Admin notes about this network',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Enable/disable without deleting',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by_admin_id` INT NULL COMMENT 'Admin who added this network',

    INDEX `idx_active` (`is_active`),
    INDEX `idx_bypass_2fa` (`bypass_2fa`, `is_active`),
    INDEX `idx_usb_auth` (`allow_usb_auth`, `is_active`),
    FOREIGN KEY (`created_by_admin_id`) REFERENCES `#__admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Trusted network subnets for security features';

-- Modify: admin_activity_log
-- Add columns to track 2FA usage and trusted network bypasses
ALTER TABLE `#__admin_activity_log`
ADD COLUMN IF NOT EXISTS `totp_verified` TINYINT(1) NULL COMMENT '1=2FA used, 0=bypassed, NULL=not applicable' AFTER `user_agent`,
ADD COLUMN IF NOT EXISTS `trusted_network_id` INT NULL COMMENT 'If bypassed, which network' AFTER `totp_verified`,
ADD INDEX IF NOT EXISTS `idx_totp_verified` (`totp_verified`);

-- Add foreign key for trusted_network_id (only if column was added successfully)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'admin_activity_log'
    AND CONSTRAINT_NAME = 'fk_admin_activity_log_trusted_network');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `#__admin_activity_log` ADD CONSTRAINT `fk_admin_activity_log_trusted_network`
     FOREIGN KEY (`trusted_network_id`) REFERENCES `#__trusted_networks`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- System configuration for 2FA features
INSERT INTO `#__system_config` (`config_key`, `config_value`, `description`) VALUES
('totp_2fa_available', '1', 'Enable TOTP 2FA feature (1=yes, 0=no)'),
('totp_issuer_name', 'OEM Activation System', 'TOTP issuer name shown in authenticator app'),
('totp_backup_codes_count', '10', 'Number of backup codes to generate per user'),
('totp_window', '1', 'TOTP time window tolerance (±1 = 90 seconds total)')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);

-- Migration complete
SELECT 'Migration: 2FA tables created successfully' AS status;
