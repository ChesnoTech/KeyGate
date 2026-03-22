<?php
/**
 * Get Client Configuration (Authenticated)
 *
 * Returns full client configuration (tasks, timing, network diagnostics)
 * for the PowerShell activation script after login.
 *
 * POST /api/get-client-config.php
 * Body: { "session_token": "..." }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$data = ApiMiddleware::bootstrap('get-client-config', ['session_token'], [
    'rate_limit' => false,
    'require_powershell' => false,
]);

$sessionToken = $data['session_token'];

try {
    // Verify valid session
    $stmt = $pdo->prepare("
        SELECT s.technician_id
        FROM active_sessions s
        WHERE s.session_token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        jsonResponse(['success' => false, 'error' => 'Invalid or expired session']);
        return;
    }

    // Build typed config response
    $config = [
        'tasks' => [
            'wsus_cleanup'        => getConfigWithDefault('client_task_wsus_cleanup', '1') === '1',
            'security_hardening'  => getConfigWithDefault('client_task_security_hardening', '1') === '1',
            'edrive_format'       => getConfigWithDefault('client_task_edrive_format', '1') === '1',
            'ps7_install'         => getConfigWithDefault('client_task_ps7_install', '1') === '1',
            'self_update'         => getConfigWithDefault('client_task_self_update', '1') === '1',
        ],
        'timing' => [
            'activation_delay_seconds' => (int) getConfigWithDefault('client_activation_delay_seconds', '10'),
            'max_retry_attempts'       => (int) getConfigWithDefault('client_max_retry_attempts', '5'),
            'max_check_iterations'     => (int) getConfigWithDefault('client_max_check_iterations', '6'),
            'check_delay_base'         => (int) getConfigWithDefault('client_check_delay_base', '5'),
        ],
        'network' => [
            'thresholds' => [
                (int) getConfigWithDefault('client_net_threshold_1', '60'),
                (int) getConfigWithDefault('client_net_threshold_2', '100'),
                (int) getConfigWithDefault('client_net_threshold_3', '200'),
                (int) getConfigWithDefault('client_net_threshold_4', '400'),
                (int) getConfigWithDefault('client_net_threshold_5', '800'),
            ],
            'multipliers' => [
                (float) getConfigWithDefault('client_net_multiplier_1', '0.6'),
                (float) getConfigWithDefault('client_net_multiplier_2', '0.8'),
                (float) getConfigWithDefault('client_net_multiplier_3', '1.0'),
                (float) getConfigWithDefault('client_net_multiplier_4', '1.6'),
                (float) getConfigWithDefault('client_net_multiplier_5', '2.5'),
            ],
            'max_multiplier' => (float) getConfigWithDefault('client_net_max_multiplier', '2.5'),
            'ping_samples'   => (int) getConfigWithDefault('client_net_ping_samples', '3'),
            'test_endpoints' => array_filter([
                getConfigWithDefault('client_net_test_endpoint_1', 'https://activation.sls.microsoft.com'),
                getConfigWithDefault('client_net_test_endpoint_2', 'https://go.microsoft.com'),
                getConfigWithDefault('client_net_test_endpoint_3', 'https://dns.msftncsi.com'),
            ]),
        ],
    ];

    jsonResponse(['success' => true, 'config' => $config]);

} catch (PDOException $e) {
    error_log("get-client-config error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Configuration unavailable']);
}
