<?php
/**
 * Settings Controller - Alternative Server Configuration
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
