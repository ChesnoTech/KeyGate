-- ========================================
-- 2FA (Two-Factor Authentication) Migration
-- ========================================

-- Table: admin_totp_secrets
CREATE TABLE IF NOT EXISTS `admin_totp_secrets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL,
    `totp_secret` VARCHAR(32) NOT NULL COMMENT 'Base32 encoded secret',
    `totp_enabled` TINYINT(1) DEFAULT 0 COMMENT 'User opted-in to 2FA',
    `backup_codes` TEXT NULL COMMENT 'JSON array of hashed backup codes',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `verified_at` DATETIME NULL COMMENT 'When user completed 2FA setup',
    `last_used_at` DATETIME NULL COMMENT 'When 2FA was last used for login',

    UNIQUE KEY `idx_admin_id` (`admin_id`),
    INDEX `idx_totp_enabled` (`totp_enabled`),
    FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='TOTP secrets and backup codes for admin 2FA';

-- Table: trusted_networks
CREATE TABLE IF NOT EXISTS `trusted_networks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `network_name` VARCHAR(100) NOT NULL COMMENT 'Friendly name (e.g., "Office LAN")',
    `ip_range` VARCHAR(45) NOT NULL COMMENT 'CIDR notation (e.g., 192.168.1.0/24)',
    `bypass_2fa` TINYINT(1) DEFAULT 1 COMMENT 'Skip 2FA for this network',
    `allow_usb_auth` TINYINT(1) DEFAULT 1 COMMENT 'Allow USB authentication from this network',
    `description` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_by_admin_id` INT NULL,

    INDEX `idx_active` (`is_active`),
    INDEX `idx_usb_auth` (`allow_usb_auth`, `is_active`),
    INDEX `idx_bypass_2fa` (`bypass_2fa`, `is_active`),
    FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Network subnets for 2FA bypass and USB auth control';

-- System configuration for 2FA
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('totp_2fa_available', '1', 'Enable TOTP 2FA feature (1=yes, 0=no)'),
('totp_issuer_name', 'OEM Activation System', 'TOTP issuer name shown in authenticator app'),
('totp_backup_codes_count', '10', 'Number of backup codes to generate per user'),
('totp_window', '1', 'TOTP time window (30-second intervals, ±1 = 90 seconds total)')
ON DUPLICATE KEY UPDATE
    `config_value` = VALUES(`config_value`),
    `description` = VALUES(`description`);

-- Success message
SELECT '2FA migration completed successfully' AS status;
