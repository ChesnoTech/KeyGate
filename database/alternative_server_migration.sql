-- ============================================================================
-- Alternative Activation Server Migration
-- ============================================================================
-- Adds support for alternative/backup activation server with per-technician
-- preferences, unique activation IDs, and automatic failover capabilities
-- ============================================================================

USE oem_activation;

-- Add server tracking fields to activation_attempts
ALTER TABLE activation_attempts
ADD COLUMN activation_server ENUM('oem', 'alternative', 'manual') DEFAULT 'oem'
    COMMENT 'Which server was used: oem=primary OEM system, alternative=backup server (auto-failover), manual=technician selected alternative',
ADD COLUMN activation_unique_id VARCHAR(32) NULL DEFAULT NULL
    COMMENT 'Globally unique identifier for this activation attempt (UUID - NULL for legacy records)',
ADD INDEX idx_activation_server (activation_server),
ADD INDEX idx_activation_unique_id (activation_unique_id);

-- Generate unique IDs for existing records (legacy activations)
UPDATE activation_attempts
SET activation_unique_id = CONCAT('LEGACY-', LPAD(id, 24, '0'))
WHERE activation_unique_id IS NULL;

-- Now make activation_unique_id unique (after all rows have values)
ALTER TABLE activation_attempts
ADD UNIQUE KEY unique_activation_id (activation_unique_id);

-- Add per-technician preferred server field
ALTER TABLE technicians
ADD COLUMN preferred_server ENUM('oem', 'alternative') DEFAULT 'oem'
    COMMENT 'Technician default preferred activation server',
ADD INDEX idx_preferred_server (preferred_server);

-- Add alternative server configuration to system_config
INSERT INTO system_config (config_key, config_value, description) VALUES
('alt_server_enabled', '0', 'Enable alternative activation server (0=disabled, 1=enabled)'),
('alt_server_script_path', '', 'Full path to alternative server CMD/PowerShell script'),
('alt_server_script_args', '', 'Command-line arguments for alternative server script'),
('alt_server_script_type', 'cmd', 'Script type: cmd, powershell, or executable'),
('alt_server_auto_failover', '1', 'Automatically failover to alternative server when OEM keys depleted (0=no, 1=yes)'),
('alt_server_prompt_technician', '1', 'Prompt technician to choose server at startup (0=no, 1=yes)'),
('alt_server_timeout_seconds', '300', 'Maximum execution time for alternative server script (seconds)'),
('alt_server_verify_activation', '1', 'Verify Windows activation status after alternative server runs (0=no, 1=yes)')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), description = VALUES(description);

-- ============================================================================
-- Migration Complete
-- ============================================================================
-- Summary of changes:
-- 1. activation_attempts: Added activation_server ENUM and activation_unique_id VARCHAR(32)
-- 2. technicians: Added preferred_server ENUM('oem', 'alternative')
-- 3. system_config: Added 8 configuration entries for alternative server
-- ============================================================================
