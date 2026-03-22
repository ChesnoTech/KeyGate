<?php
/**
 * Get Alternative Server Configuration API
 *
 * Returns alternative server configuration along with the technician's preferred server
 * This is called by the PowerShell client after login to determine activation server settings
 *
 * @author KeyGate
 * @version 1.0
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$data = ApiMiddleware::bootstrap('get-alt-server-config', ['session_token'], [
    'rate_limit' => false,
    'require_powershell' => false,
]);

$sessionToken = $data['session_token'];

try {
    // Verify valid session and get technician preferences
    $stmt = $pdo->prepare("
        SELECT s.technician_id, t.preferred_server
        FROM active_sessions s
        INNER JOIN technicians t ON s.technician_id = t.technician_id
        WHERE s.session_token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired session']);
        exit;
    }

    // Helper function to get config value
    function getConfig($key) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['config_value'] : null;
    }

    // Get alternative server configuration
    $config = [
        'enabled' => (bool)(getConfig('alt_server_enabled') === '1'),
        'prompt_technician' => (bool)(getConfig('alt_server_prompt_technician') === '1'),
        'auto_failover' => (bool)(getConfig('alt_server_auto_failover') === '1'),
        'script_path' => getConfig('alt_server_script_path') ?? '',
        'pre_command' => getConfig('alt_server_pre_command') ?? '',
        'script_args' => getConfig('alt_server_script_args') ?? '',
        'script_type' => getConfig('alt_server_script_type') ?? 'cmd',
        'timeout_seconds' => (int)(getConfig('alt_server_timeout_seconds') ?? 300),
        'verify_activation' => (bool)(getConfig('alt_server_verify_activation') === '1'),
        'preferred_server' => $session['preferred_server'] ?? 'oem' // NEW: Technician's preference
    ];

    echo json_encode([
        'success' => true,
        'config' => $config
    ]);

} catch (PDOException $e) {
    error_log("Alt server config error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>
