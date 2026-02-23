-- ========================================
-- Rate Limiting Migration
-- ========================================

-- Table: rate_limit_violations
CREATE TABLE IF NOT EXISTS `rate_limit_violations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(100) NOT NULL COMMENT 'IP address or user ID',
    `action` VARCHAR(50) NOT NULL COMMENT 'Endpoint action (login, get-key, etc.)',
    `endpoint` VARCHAR(255) NOT NULL COMMENT 'Full API endpoint path',
    `client_ip` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `violated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_identifier` (`identifier`),
    INDEX `idx_action` (`action`),
    INDEX `idx_violated_at` (`violated_at`),
    INDEX `idx_client_ip` (`client_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rate limit violation audit log';

-- System configuration for rate limiting
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('rate_limit_enabled', '1', 'Enable API rate limiting (1=yes, 0=no)'),
('rate_limit_global_per_minute', '100', 'Max requests per minute per IP (all endpoints)'),
('rate_limit_login_per_hour', '20', 'Max login attempts per hour per IP'),
('rate_limit_report_per_hour', '50', 'Max report-result requests per hour per technician'),
('rate_limit_usb_auth_per_hour', '50', 'Max USB authentication attempts per hour per IP')
ON DUPLICATE KEY UPDATE
    `config_value` = VALUES(`config_value`),
    `description` = VALUES(`description`);

-- Success message
SELECT 'Rate limiting migration completed successfully' AS status;
