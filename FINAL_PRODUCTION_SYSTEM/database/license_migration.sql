-- =============================================================
-- KeyGate — Licensing System
-- =============================================================
-- Tracks instance license, tier limits, and validation history.
-- =============================================================

CREATE TABLE IF NOT EXISTS `#__license_info` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(2048) NOT NULL COMMENT 'JWT license token',
    instance_id VARCHAR(128) NOT NULL COMMENT 'SHA256 fingerprint of this installation',
    tier ENUM('community', 'pro', 'enterprise') NOT NULL DEFAULT 'community',
    licensed_to_email VARCHAR(255) DEFAULT NULL,
    licensed_to_name VARCHAR(255) DEFAULT NULL,
    max_technicians INT NOT NULL DEFAULT 1,
    max_keys INT NOT NULL DEFAULT 50,
    features JSON DEFAULT NULL COMMENT 'Feature flags: {"integrations":true,"compliance":true,...}',
    issued_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    last_validated_at TIMESTAMP NULL DEFAULT NULL,
    validation_status ENUM('valid', 'expired', 'revoked', 'invalid', 'pending') NOT NULL DEFAULT 'pending',
    validation_message VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_instance (instance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default community license limits stored in system_config
INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
    ('license_tier', 'community', 'Current license tier')
ON DUPLICATE KEY UPDATE config_key = config_key;

INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
    ('license_instance_id', '', 'Unique instance fingerprint (auto-generated)')
ON DUPLICATE KEY UPDATE config_key = config_key;
