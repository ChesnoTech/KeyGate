<?php
/**
 * Get Launcher Configuration (Public — No Auth Required)
 *
 * Returns pre-activation task toggles for the CMD launcher.
 * Called before authentication, so no session token is needed.
 *
 * GET /api/get-launcher-config.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config.php';

try {
    $tasks = [
        'wsus_cleanup'        => getConfigWithDefault('client_task_wsus_cleanup', '1') === '1',
        'security_hardening'  => getConfigWithDefault('client_task_security_hardening', '1') === '1',
        'edrive_format'       => getConfigWithDefault('client_task_edrive_format', '1') === '1',
        'ps7_install'         => getConfigWithDefault('client_task_ps7_install', '1') === '1',
        'self_update'         => getConfigWithDefault('client_task_self_update', '1') === '1',
    ];

    echo json_encode(['success' => true, 'tasks' => $tasks]);
} catch (Exception $e) {
    error_log("get-launcher-config error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Configuration unavailable']);
}
