-- Add alternative server configuration to system_config
USE oem_activation;

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

-- Generate unique IDs for existing activation records (legacy activations)
UPDATE activation_attempts
SET activation_unique_id = CONCAT('LEGACY-', LPAD(id, 24, '0'))
WHERE activation_unique_id IS NULL;
