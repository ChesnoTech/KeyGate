-- USB Automatic Authentication Migration
-- Adds USB-based passwordless authentication with password fallback
-- Created: 2026-01-30

USE oem_activation;

-- Create usb_devices table
CREATE TABLE IF NOT EXISTS `usb_devices` (
    `device_id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_serial_number` VARCHAR(255) UNIQUE NOT NULL COMMENT 'USB device serial number from Win32_DiskDrive',
    `device_name` VARCHAR(100) NOT NULL COMMENT 'Friendly name (e.g., "John Work USB")',
    `technician_id` VARCHAR(20) NOT NULL COMMENT 'Owner technician',
    `device_status` ENUM('active', 'disabled', 'lost', 'stolen') DEFAULT 'active',
    `device_manufacturer` VARCHAR(100) DEFAULT NULL COMMENT 'USB manufacturer',
    `device_model` VARCHAR(100) DEFAULT NULL COMMENT 'USB model',
    `device_capacity_gb` DECIMAL(10,2) DEFAULT NULL COMMENT 'USB capacity in GB',
    `device_description` TEXT COMMENT 'Admin notes',

    `registered_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `registered_by_admin_id` INT DEFAULT NULL COMMENT 'Admin who registered this device',

    `last_used_date` DATETIME NULL COMMENT 'Last successful authentication',
    `last_used_ip` VARCHAR(45) NULL COMMENT 'IP address of last use',
    `last_used_computer_name` VARCHAR(100) NULL COMMENT 'Computer name where last used',

    `disabled_date` DATETIME NULL COMMENT 'When device was disabled',
    `disabled_by_admin_id` INT NULL COMMENT 'Admin who disabled this device',
    `disabled_reason` TEXT NULL COMMENT 'Reason for disabling',

    `usage_count` INT DEFAULT 0 COMMENT 'Total successful authentications',

    INDEX `idx_serial` (`device_serial_number`),
    INDEX `idx_technician` (`technician_id`),
    INDEX `idx_status` (`device_status`),
    INDEX `idx_tech_status` (`technician_id`, `device_status`),

    FOREIGN KEY (`technician_id`) REFERENCES `technicians`(`technician_id`) ON DELETE CASCADE,
    FOREIGN KEY (`registered_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`disabled_by_admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='USB devices for single-factor authentication';

-- Modify active_sessions table
ALTER TABLE `active_sessions`
ADD COLUMN IF NOT EXISTS `auth_method` ENUM('password', 'usb') DEFAULT 'password' COMMENT 'How technician authenticated',
ADD COLUMN IF NOT EXISTS `usb_device_id` INT NULL COMMENT 'USB device used (if USB auth)',
ADD COLUMN IF NOT EXISTS `computer_name` VARCHAR(100) NULL COMMENT 'Client computer name';

-- Add indexes if they don't exist
ALTER TABLE `active_sessions`
ADD INDEX IF NOT EXISTS `idx_usb_device` (`usb_device_id`),
ADD INDEX IF NOT EXISTS `idx_auth_method` (`auth_method`);

-- Add foreign key if it doesn't exist
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = 'oem_activation'
                  AND TABLE_NAME = 'active_sessions'
                  AND CONSTRAINT_NAME = 'active_sessions_ibfk_usb');

SET @sql_fk_sessions = IF(@fk_exists = 0,
    'ALTER TABLE `active_sessions` ADD FOREIGN KEY (`usb_device_id`) REFERENCES `usb_devices`(`device_id`) ON DELETE SET NULL',
    'SELECT "FK already exists on active_sessions"');

PREPARE stmt FROM @sql_fk_sessions;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Modify activation_attempts table
ALTER TABLE `activation_attempts`
ADD COLUMN IF NOT EXISTS `auth_method` ENUM('password', 'usb') DEFAULT 'password' COMMENT 'How technician authenticated',
ADD COLUMN IF NOT EXISTS `usb_device_id` INT NULL COMMENT 'USB device used (if USB auth)',
ADD COLUMN IF NOT EXISTS `computer_name` VARCHAR(100) NULL COMMENT 'Client computer name';

-- Add indexes if they don't exist
ALTER TABLE `activation_attempts`
ADD INDEX IF NOT EXISTS `idx_usb_device` (`usb_device_id`),
ADD INDEX IF NOT EXISTS `idx_auth_method` (`auth_method`);

-- Add foreign key if it doesn't exist
SET @fk_exists2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA = 'oem_activation'
                   AND TABLE_NAME = 'activation_attempts'
                   AND CONSTRAINT_NAME = 'activation_attempts_ibfk_usb');

SET @sql_fk_attempts = IF(@fk_exists2 = 0,
    'ALTER TABLE `activation_attempts` ADD FOREIGN KEY (`usb_device_id`) REFERENCES `usb_devices`(`device_id`) ON DELETE SET NULL',
    'SELECT "FK already exists on activation_attempts"');

PREPARE stmt2 FROM @sql_fk_attempts;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Add system configuration
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('usb_auth_enabled', '1', 'Enable USB automatic authentication (0=disabled, 1=enabled)'),
('usb_auth_require_active_technician', '1', 'Verify technician is_active=1 during USB auth (0=no, 1=yes)'),
('usb_auth_log_failures', '1', 'Log failed USB authentication attempts (0=no, 1=yes)'),
('usb_auth_max_devices_per_tech', '5', 'Maximum USB devices per technician (0=unlimited)')
ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);

-- Create audit log table for USB auth attempts
CREATE TABLE IF NOT EXISTS `usb_auth_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `device_serial_number` VARCHAR(255) NOT NULL COMMENT 'USB serial that was tried',
    `technician_id` VARCHAR(20) NULL COMMENT 'Technician if successful',
    `device_id` INT NULL COMMENT 'Device ID if recognized',
    `attempt_result` ENUM('success', 'no_match', 'disabled_device', 'inactive_technician', 'system_disabled', 'network_restricted') NOT NULL,
    `attempted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `client_ip` VARCHAR(45) NULL,
    `computer_name` VARCHAR(100) NULL,
    `error_message` TEXT NULL,

    INDEX `idx_serial` (`device_serial_number`),
    INDEX `idx_result` (`attempt_result`),
    INDEX `idx_attempted_at` (`attempted_at`),
    INDEX `idx_tech` (`technician_id`),

    FOREIGN KEY (`technician_id`) REFERENCES `technicians`(`technician_id`) ON DELETE SET NULL,
    FOREIGN KEY (`device_id`) REFERENCES `usb_devices`(`device_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit log for USB authentication attempts';

-- Display success message
SELECT 'USB authentication migration completed successfully!' AS Status;
SELECT 'New tables: usb_devices, usb_auth_attempts' AS Info;
SELECT 'Modified tables: active_sessions, activation_attempts' AS Info;
SELECT 'System config entries added: usb_auth_*' AS Info;
