-- =====================================================
-- Rate Limiting Database Migration
-- =====================================================
-- This migration adds database tables for rate limiting tracking
-- Note: Primary rate limiting uses Redis, this is for violation logging

-- Table: rate_limit_violations
-- Logs when API rate limits are exceeded for security monitoring
CREATE TABLE IF NOT EXISTS `#__rate_limit_violations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(100) NOT NULL COMMENT 'IP address or user ID',
    `action` VARCHAR(50) NOT NULL COMMENT 'Endpoint action (login, get-key, etc.)',
    `endpoint` VARCHAR(255) NOT NULL COMMENT 'Full API endpoint path',
    `client_ip` VARCHAR(45) NOT NULL COMMENT 'Request IP address',
    `user_agent` TEXT NULL COMMENT 'Browser/client user agent',
    `request_count` INT DEFAULT 1 COMMENT 'Number of requests in window',
    `limit_threshold` INT NOT NULL COMMENT 'Rate limit that was exceeded',
    `window_seconds` INT NOT NULL COMMENT 'Time window in seconds',
    `violated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_identifier` (`identifier`),
    INDEX `idx_action` (`action`),
    INDEX `idx_client_ip` (`client_ip`),
    INDEX `idx_violated_at` (`violated_at`),
    INDEX `idx_action_ip` (`action`, `client_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Rate limit violation log for security monitoring';

-- System configuration for rate limiting
INSERT INTO `#__system_config` (`config_key`, `config_value`, `description`) VALUES
('rate_limit_enabled', '1', 'Enable API rate limiting (1=yes, 0=no)'),
('rate_limit_global_per_minute', '100', 'Max requests per minute per IP (all endpoints)'),
('rate_limit_login_per_hour', '20', 'Max login attempts per hour per IP'),
('rate_limit_report_per_hour', '50', 'Max report-result requests per hour per technician'),
('rate_limit_get_key_per_minute', '100', 'Max get-key requests per minute per IP'),
('rate_limit_usb_auth_per_hour', '50', 'Max USB authentication attempts per hour per IP'),
('redis_host', 'redis', 'Redis server hostname'),
('redis_port', '6379', 'Redis server port'),
('redis_password', 'redis_password_123', 'Redis authentication password'),
('redis_connection_timeout', '5', 'Redis connection timeout in seconds')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);

-- Migration complete
SELECT 'Migration: Rate limiting tables created successfully' AS status;
