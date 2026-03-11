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

    echo json_encode(['success' => true, 'config' => $config]);
}

function handle_save_alt_server_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $input = $json_input;
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    // Validate
    if ($input['alt_server_enabled'] === '1' && empty($input['alt_server_script_path'])) {
        echo json_encode(['success' => false, 'error' => 'Script path is required when alternative server is enabled']);
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

    foreach ($configs as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, updated_at)
            VALUES (?, ?, '', NOW())
            ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $value]);
    }

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_ALT_SERVER_SETTINGS',
        'Updated alternative server configuration'
    );

    echo json_encode(['success' => true]);
}

// ── Order Field Configuration ────────────────────────────────

function handle_get_order_field_settings(PDO $pdo, array $admin_session): void {
    $config = getOrderFieldConfig();
    $pattern = buildOrderNumberPattern($config);

    echo json_encode([
        'success' => true,
        'config' => $config,
        'computed_pattern' => $pattern,
    ]);
}

function handle_save_order_field_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    if (!$json_input) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    // Validate lengths
    $minLen = (int) ($json_input['order_field_min_length'] ?? 1);
    $maxLen = (int) ($json_input['order_field_max_length'] ?? 10);

    if ($minLen < 1 || $maxLen > ORDER_NUMBER_MAX_DB_LENGTH) {
        echo json_encode(['success' => false, 'error' => "Length must be between 1 and " . ORDER_NUMBER_MAX_DB_LENGTH]);
        return;
    }
    if ($minLen > $maxLen) {
        echo json_encode(['success' => false, 'error' => 'Minimum length cannot exceed maximum length']);
        return;
    }

    // Validate char_type
    $allowedTypes = ['digits_only', 'alphanumeric', 'alphanumeric_dash', 'custom'];
    $charType = $json_input['order_field_char_type'] ?? 'alphanumeric';
    if (!in_array($charType, $allowedTypes)) {
        echo json_encode(['success' => false, 'error' => 'Invalid character type']);
        return;
    }

    // Validate custom regex
    if ($charType === 'custom') {
        $customRegex = $json_input['order_field_custom_regex'] ?? '';
        if (empty($customRegex)) {
            echo json_encode(['success' => false, 'error' => 'Custom regex is required when character type is "custom"']);
            return;
        }
        if (@preg_match($customRegex, '') === false) {
            echo json_encode(['success' => false, 'error' => 'Invalid regex pattern: ' . preg_last_error_msg()]);
            return;
        }
    }

    // Validate labels/prompts are non-empty
    $labelEn = trim($json_input['order_field_label_en'] ?? '');
    $labelRu = trim($json_input['order_field_label_ru'] ?? '');
    if ($labelEn === '' || $labelRu === '') {
        echo json_encode(['success' => false, 'error' => 'Labels cannot be empty']);
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

    foreach ($configs as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description, updated_at)
            VALUES (?, ?, '', NOW())
            ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()
        ");
        $stmt->execute([$key, $value, $value]);
    }

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_ORDER_FIELD_SETTINGS',
        'Updated order field configuration'
    );

    // Return updated config with computed pattern
    $newConfig = getOrderFieldConfig();
    echo json_encode([
        'success' => true,
        'config' => $newConfig,
        'computed_pattern' => buildOrderNumberPattern($newConfig),
    ]);
}
