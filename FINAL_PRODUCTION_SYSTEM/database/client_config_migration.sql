-- =============================================================
-- OEM Activation System — Client Configuration Defaults
-- =============================================================
-- Populates system_config with default values for launcher tasks,
-- activation timing, and network diagnostics settings.
-- =============================================================

INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
-- Pre-Activation Task Toggles
('client_task_wsus_cleanup', '1', 'Enable WSUS cleanup before activation'),
('client_task_security_hardening', '1', 'Enable SMB security hardening'),
('client_task_edrive_format', '1', 'Enable E: drive BIOS partition format'),
('client_task_ps7_install', '1', 'Enable PowerShell 7 auto-install'),
('client_task_self_update', '1', 'Enable launcher self-update'),
-- Activation Timing
('client_activation_delay_seconds', '10', 'Base activation delay in seconds'),
('client_max_retry_attempts', '5', 'Max retry attempts per key'),
('client_max_check_iterations', '6', 'Max license status check iterations'),
('client_check_delay_base', '5', 'Base delay between status checks in seconds'),
-- Network Diagnostics
('client_net_threshold_1', '60', 'Latency threshold tier 1 in ms'),
('client_net_threshold_2', '100', 'Latency threshold tier 2 in ms'),
('client_net_threshold_3', '200', 'Latency threshold tier 3 in ms'),
('client_net_threshold_4', '400', 'Latency threshold tier 4 in ms'),
('client_net_threshold_5', '800', 'Latency threshold tier 5 in ms'),
('client_net_multiplier_1', '0.6', 'Network multiplier for tier 1'),
('client_net_multiplier_2', '0.8', 'Network multiplier for tier 2'),
('client_net_multiplier_3', '1.0', 'Network multiplier for tier 3'),
('client_net_multiplier_4', '1.6', 'Network multiplier for tier 4'),
('client_net_multiplier_5', '2.5', 'Network multiplier for tier 5'),
('client_net_max_multiplier', '2.5', 'Cap multiplier when endpoints unreachable'),
('client_net_ping_samples', '3', 'Number of ping samples per endpoint'),
('client_net_test_endpoint_1', 'https://activation.sls.microsoft.com', 'Microsoft test endpoint 1'),
('client_net_test_endpoint_2', 'https://go.microsoft.com', 'Microsoft test endpoint 2'),
('client_net_test_endpoint_3', 'https://dns.msftncsi.com', 'Microsoft test endpoint 3'),
-- Key Retry & Fallback
('client_max_keys_to_try', '3', 'Max different keys to request before giving up'),
('client_key_exhaustion_action', 'failover', 'Action when all keys fail: stop, failover, or retry_loop'),
('client_retry_cooldown_seconds', '60', 'Cooldown before retry_loop restarts from beginning'),
('client_network_error_retries', '4', 'Extra retry attempts for network errors before skipping key'),
('client_network_reconnect_wait', '30', 'Seconds to wait between internet reconnection checks'),
('client_server_busy_delay', '30', 'Base delay when Microsoft servers are throttling'),
('client_skip_key_on_invalid', '1', 'Immediately skip to next key on key_invalid error'),
('client_skip_key_on_service_error', '0', 'Skip to next key on service errors instead of retrying')
ON DUPLICATE KEY UPDATE config_key = config_key;
