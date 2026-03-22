<?php
/**
 * Settings Controller - Alternative Server & Order Field Configuration
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_get_alt_server_settings(PDO $pdo, array $admin_session): void {
    $config = [
        'alt_server_enabled' => getConfig('alt_server_enabled') ?? '0',
        'alt_server_script_path' => getConfig('alt_server_script_path') ?? '',
        'alt_server_pre_command' => getConfig('alt_server_pre_command') ?? '',
        'alt_server_script_args' => getConfig('alt_server_script_args') ?? '',
        'alt_server_script_type' => getConfig('alt_server_script_type') ?? 'cmd',
        'alt_server_timeout_seconds' => getConfig('alt_server_timeout_seconds') ?? '300',
        'alt_server_prompt_technician' => getConfig('alt_server_prompt_technician') ?? '1',
        'alt_server_auto_failover' => getConfig('alt_server_auto_failover') ?? '1',
        'alt_server_verify_activation' => getConfig('alt_server_verify_activation') ?? '1'
    ];

    jsonResponse(['success' => true, 'config' => $config]);
}

function handle_save_alt_server_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $input = $json_input;
    if (!$input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    // Validate
    if ($input['alt_server_enabled'] === '1' && empty($input['alt_server_script_path'])) {
        jsonResponse(['success' => false, 'error' => 'Script path is required when alternative server is enabled']);
        return;
    }

    // Update system_config
    $configs = [
        'alt_server_enabled' => $input['alt_server_enabled'],
        'alt_server_script_path' => $input['alt_server_script_path'],
        'alt_server_pre_command' => $input['alt_server_pre_command'] ?? '',
        'alt_server_script_args' => $input['alt_server_script_args'],
        'alt_server_script_type' => $input['alt_server_script_type'],
        'alt_server_timeout_seconds' => $input['alt_server_timeout_seconds'],
        'alt_server_prompt_technician' => $input['alt_server_prompt_technician'],
        'alt_server_auto_failover' => $input['alt_server_auto_failover'],
        'alt_server_verify_activation' => $input['alt_server_verify_activation']
    ];

    saveConfigBatch($pdo, $configs);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_ALT_SERVER_SETTINGS',
        'Updated alternative server configuration'
    );

    jsonResponse(['success' => true]);
}

// ── Order Field Configuration ────────────────────────────────

function handle_get_order_field_settings(PDO $pdo, array $admin_session): void {
    $config = getOrderFieldConfig();
    $pattern = buildOrderNumberPattern($config);

    jsonResponse([
        'success' => true,
        'config' => $config,
        'computed_pattern' => $pattern,
    ]);
}

function handle_save_order_field_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    if (!$json_input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    // Validate lengths
    $minLen = (int) ($json_input['order_field_min_length'] ?? 1);
    $maxLen = (int) ($json_input['order_field_max_length'] ?? 10);

    if ($minLen < 1 || $maxLen > ORDER_NUMBER_MAX_DB_LENGTH) {
        jsonResponse(['success' => false, 'error' => "Length must be between 1 and " . ORDER_NUMBER_MAX_DB_LENGTH]);
        return;
    }
    if ($minLen > $maxLen) {
        jsonResponse(['success' => false, 'error' => 'Minimum length cannot exceed maximum length']);
        return;
    }

    // Validate char_type
    $allowedTypes = ['digits_only', 'alphanumeric', 'alphanumeric_dash', 'custom'];
    $charType = $json_input['order_field_char_type'] ?? 'alphanumeric';
    if (!in_array($charType, $allowedTypes)) {
        jsonResponse(['success' => false, 'error' => 'Invalid character type']);
        return;
    }

    // Validate custom regex
    if ($charType === 'custom') {
        $customRegex = $json_input['order_field_custom_regex'] ?? '';
        if (empty($customRegex)) {
            jsonResponse(['success' => false, 'error' => 'Custom regex is required when character type is "custom"']);
            return;
        }
        if (@preg_match($customRegex, '') === false) {
            jsonResponse(['success' => false, 'error' => 'Invalid regex pattern: ' . preg_last_error_msg()]);
            return;
        }
    }

    // Validate labels/prompts are non-empty
    $labelEn = trim($json_input['order_field_label_en'] ?? '');
    $labelRu = trim($json_input['order_field_label_ru'] ?? '');
    if ($labelEn === '' || $labelRu === '') {
        jsonResponse(['success' => false, 'error' => 'Labels cannot be empty']);
        return;
    }

    // Save all config keys
    $configs = [
        'order_field_label_en'     => $labelEn,
        'order_field_label_ru'     => $labelRu,
        'order_field_prompt_en'    => trim($json_input['order_field_prompt_en'] ?? 'Enter order number'),
        'order_field_prompt_ru'    => trim($json_input['order_field_prompt_ru'] ?? 'Введите номер заказа'),
        'order_field_min_length'   => (string) $minLen,
        'order_field_max_length'   => (string) $maxLen,
        'order_field_char_type'    => $charType,
        'order_field_custom_regex' => $json_input['order_field_custom_regex'] ?? '',
    ];

    saveConfigBatch($pdo, $configs);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_ORDER_FIELD_SETTINGS',
        'Updated order field configuration'
    );

    // Return updated config with computed pattern
    $newConfig = getOrderFieldConfig();
    jsonResponse([
        'success' => true,
        'config' => $newConfig,
        'computed_pattern' => buildOrderNumberPattern($newConfig),
    ]);
}

// ── Session Settings ────────────────────────────────────────

function handle_get_session_settings(PDO $pdo, array $admin_session): void {
    $config = [
        'admin_session_timeout_minutes' => getConfigWithDefault('admin_session_timeout_minutes', defined('DEFAULT_ADMIN_SESSION_TIMEOUT_MINUTES') ? DEFAULT_ADMIN_SESSION_TIMEOUT_MINUTES : 30),
        'admin_max_failed_logins' => getConfigWithDefault('admin_max_failed_logins', defined('DEFAULT_MAX_FAILED_LOGINS') ? DEFAULT_MAX_FAILED_LOGINS : 3),
        'admin_lockout_duration_minutes' => getConfigWithDefault('admin_lockout_duration_minutes', defined('DEFAULT_LOCKOUT_DURATION_MINUTES') ? DEFAULT_LOCKOUT_DURATION_MINUTES : 30),
        'admin_force_password_change_days' => getConfigWithDefault('admin_force_password_change_days', 90),
    ];
    jsonResponse(['success' => true, 'config' => $config]);
}

// ── Client Configuration ────────────────────────────────────

function getClientConfigDefaults(): array {
    return [
        'client_task_wsus_cleanup'        => '1',
        'client_task_security_hardening'  => '1',
        'client_task_edrive_format'       => '1',
        'client_task_ps7_install'         => '1',
        'client_task_self_update'         => '1',
        'client_activation_delay_seconds' => '10',
        'client_max_retry_attempts'       => '5',
        'client_max_check_iterations'     => '6',
        'client_check_delay_base'         => '5',
        'client_net_threshold_1'          => '60',
        'client_net_threshold_2'          => '100',
        'client_net_threshold_3'          => '200',
        'client_net_threshold_4'          => '400',
        'client_net_threshold_5'          => '800',
        'client_net_multiplier_1'         => '0.6',
        'client_net_multiplier_2'         => '0.8',
        'client_net_multiplier_3'         => '1.0',
        'client_net_multiplier_4'         => '1.6',
        'client_net_multiplier_5'         => '2.5',
        'client_net_max_multiplier'       => '2.5',
        'client_net_ping_samples'         => '3',
        'client_net_test_endpoint_1'      => 'https://activation.sls.microsoft.com',
        'client_net_test_endpoint_2'      => 'https://go.microsoft.com',
        'client_net_test_endpoint_3'      => 'https://dns.msftncsi.com',
        // Key Retry & Fallback
        'client_max_keys_to_try'          => '3',
        'client_key_exhaustion_action'    => 'failover',
        'client_retry_cooldown_seconds'   => '60',
        'client_network_error_retries'    => '4',
        'client_network_reconnect_wait'   => '30',
        'client_server_busy_delay'        => '30',
        'client_skip_key_on_invalid'      => '1',
        'client_skip_key_on_service_error' => '0',
    ];
}

function handle_get_client_config_settings(PDO $pdo, array $admin_session): void {
    requirePermission('system_settings', $admin_session);
    $defaults = getClientConfigDefaults();
    $config = [];
    foreach ($defaults as $key => $default) {
        $config[$key] = getConfigWithDefault($key, $default);
    }
    jsonResponse(['success' => true, 'config' => $config]);
}

function handle_save_client_config_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('system_settings', $admin_session);
    if (!$json_input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    $defaults = getClientConfigDefaults();
    $configs = [];

    foreach ($defaults as $key => $default) {
        if (!isset($json_input[$key])) continue;
        $val = $json_input[$key];

        // Validate toggles
        if (str_starts_with($key, 'client_task_')) {
            $configs[$key] = in_array($val, ['0', '1', 0, 1, true, false], true) ? ($val ? '1' : '0') : $default;
            continue;
        }
        // Validate integers
        if (in_array($key, ['client_activation_delay_seconds', 'client_max_retry_attempts', 'client_max_check_iterations', 'client_check_delay_base', 'client_net_ping_samples'])) {
            $int = (int) $val;
            if ($int < 1 || $int > 120) $int = (int) $default;
            $configs[$key] = (string) $int;
            continue;
        }
        // Validate thresholds (positive integers)
        if (str_starts_with($key, 'client_net_threshold_')) {
            $int = (int) $val;
            if ($int < 1 || $int > 30000) $int = (int) $default;
            $configs[$key] = (string) $int;
            continue;
        }
        // Validate multipliers (positive floats)
        if (str_starts_with($key, 'client_net_multiplier_') || $key === 'client_net_max_multiplier') {
            $float = (float) $val;
            if ($float < 0.1 || $float > 10.0) $float = (float) $default;
            $configs[$key] = (string) round($float, 2);
            continue;
        }
        // Validate endpoints (URLs or empty)
        if (str_starts_with($key, 'client_net_test_endpoint_')) {
            $configs[$key] = (is_string($val) && (empty($val) || str_starts_with($val, 'https://'))) ? $val : $default;
            continue;
        }
        // Validate key exhaustion action (enum)
        if ($key === 'client_key_exhaustion_action') {
            $configs[$key] = in_array($val, ['stop', 'failover', 'retry_loop']) ? $val : $default;
            continue;
        }
        $configs[$key] = (string) $val;
    }

    saveConfigBatch($pdo, $configs);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_CLIENT_CONFIG',
        'Updated client configuration settings'
    );

    jsonResponse(['success' => true]);
}

function handle_save_session_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    if (!$json_input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
        return;
    }

    // Validate session timeout (5-1440 minutes = 5 min to 24 hours)
    $timeout = (int) ($json_input['admin_session_timeout_minutes'] ?? 30);
    if ($timeout < 5 || $timeout > 1440) {
        jsonResponse(['success' => false, 'error' => 'Session timeout must be between 5 and 1440 minutes'], 400);
        return;
    }

    $maxFailed = max(1, min(20, (int) ($json_input['admin_max_failed_logins'] ?? 3)));
    $lockoutDuration = max(1, min(1440, (int) ($json_input['admin_lockout_duration_minutes'] ?? 30)));
    $passwordDays = max(0, min(365, (int) ($json_input['admin_force_password_change_days'] ?? 90)));

    $configs = [
        'admin_session_timeout_minutes' => (string) $timeout,
        'admin_max_failed_logins' => (string) $maxFailed,
        'admin_lockout_duration_minutes' => (string) $lockoutDuration,
        'admin_force_password_change_days' => (string) $passwordDays,
    ];

    saveConfigBatch($pdo, $configs);

    jsonResponse(['success' => true, 'config' => [
        'admin_session_timeout_minutes' => $timeout,
        'admin_max_failed_logins' => $maxFailed,
        'admin_lockout_duration_minutes' => $lockoutDuration,
        'admin_force_password_change_days' => $passwordDays,
    ]]);
}
