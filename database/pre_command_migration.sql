-- Migration: Add pre_command field for alternative server configuration
-- This allows using commands like irm, iex, curl.exe before the script path
-- for remote script execution and URL-based activation

INSERT INTO system_config (config_key, config_value, description, updated_at)
VALUES ('alt_server_pre_command', '', 'Optional command prefix for alternative server (e.g., irm, curl.exe, iex). Leave empty for local file execution.', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;
