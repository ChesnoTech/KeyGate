<?php
/**
 * Integration Controller — CRUD for external integrations
 * Manages configuration, testing, and event retry for osTicket, 1C, etc.
 */

require_once dirname(__DIR__, 2) . '/functions/integration-helpers.php';

function handle_list_integrations(PDO $pdo, array $admin_session): void {
    requirePermission('system_settings', $admin_session);

    $stmt = $pdo->query("SELECT * FROM `" . t('integrations') . "` ORDER BY id ASC");
    $rows = $stmt->fetchAll();

    // Decode config JSON and mask sensitive fields
    foreach ($rows as &$row) {
        $config = json_decode($row['config'] ?: '{}', true) ?: [];
        // Mask API keys and passwords
        foreach (['api_key', 'password'] as $sensitive) {
            if (!empty($config[$sensitive])) {
                $config[$sensitive] = str_repeat('*', min(8, strlen($config[$sensitive]))) . substr($config[$sensitive], -4);
            }
        }
        $row['config'] = $config;

        // Get event counts
        $countStmt = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(status = 'failed') as failed,
                SUM(status = 'pending') as pending
            FROM `" . t('integration_events') . "` WHERE integration_id = ?
        ");
        $countStmt->execute([$row['id']]);
        $row['event_counts'] = $countStmt->fetch();
    }
    unset($row);

    jsonResponse(['success' => true, 'integrations' => $rows]);
}

function handle_get_integration(PDO $pdo, array $admin_session): void {
    requirePermission('system_settings', $admin_session);

    $key = $_GET['integration_key'] ?? '';
    if (empty($key)) {
        jsonResponse(['success' => false, 'error' => 'integration_key required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM `" . t('integrations') . "` WHERE integration_key = ?");
    $stmt->execute([$key]);
    $intg = $stmt->fetch();
    if (!$intg) {
        jsonResponse(['success' => false, 'error' => 'Integration not found']);
        return;
    }

    $intg['config'] = json_decode($intg['config'] ?: '{}', true) ?: [];

    // Recent events (last 20)
    $evtStmt = $pdo->prepare("
        SELECT id, event_type, status, response_code, error_message, created_at, processed_at
        FROM `" . t('integration_events') . "`
        WHERE integration_id = ?
        ORDER BY created_at DESC LIMIT 20
    ");
    $evtStmt->execute([$intg['id']]);
    $intg['recent_events'] = $evtStmt->fetchAll();

    jsonResponse(['success' => true, 'integration' => $intg]);
}

function handle_save_integration(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('system_settings', $admin_session);

    if (!$json_input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    $key = $json_input['integration_key'] ?? '';
    if (empty($key)) {
        jsonResponse(['success' => false, 'error' => 'integration_key required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM `" . t('integrations') . "` WHERE integration_key = ?");
    $stmt->execute([$key]);
    $intg = $stmt->fetch();
    if (!$intg) {
        jsonResponse(['success' => false, 'error' => 'Integration not found']);
        return;
    }

    $enabled = isset($json_input['enabled']) ? (int)(bool)$json_input['enabled'] : (int)$intg['enabled'];
    $config = $json_input['config'] ?? json_decode($intg['config'] ?: '{}', true);

    // Merge password fields — if masked (starts with *), keep existing value
    $existingConfig = json_decode($intg['config'] ?: '{}', true) ?: [];
    foreach (['api_key', 'password'] as $sensitive) {
        if (isset($config[$sensitive]) && preg_match('/^\*+/', $config[$sensitive])) {
            $config[$sensitive] = $existingConfig[$sensitive] ?? '';
        }
    }

    // Validate: if enabling, require base_url
    if ($enabled && empty($config['base_url'] ?? '')) {
        jsonResponse(['success' => false, 'error' => 'Base URL is required to enable this integration']);
        return;
    }

    $updateStmt = $pdo->prepare("
        UPDATE `" . t('integrations') . "`
        SET enabled = ?, config = ?, updated_at = NOW()
        WHERE integration_key = ?
    ");
    $updateStmt->execute([$enabled, json_encode($config), $key]);

    // If disabling, reset status to disconnected
    if (!$enabled) {
        updateIntegrationStatus($intg['id'], 'disconnected');
    }

    // Clear cache
    global $integrationCache;
    $integrationCache = [];

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_INTEGRATION',
        "Updated integration: $key (enabled: $enabled)"
    );

    jsonResponse(['success' => true]);
}

function handle_test_integration(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('system_settings', $admin_session);

    $key = $json_input['integration_key'] ?? '';
    if (empty($key)) {
        jsonResponse(['success' => false, 'error' => 'integration_key required']);
        return;
    }

    $intg = getIntegration($key);
    if (!$intg) {
        jsonResponse(['success' => false, 'error' => 'Integration not found']);
        return;
    }

    $config = $intg['config'];
    $baseUrl = $config['base_url'] ?? '';
    if (empty($baseUrl)) {
        jsonResponse(['success' => false, 'error' => 'Base URL not configured']);
        return;
    }

    // Load integration-specific test function
    $clientFile = dirname(__DIR__, 2) . "/functions/integrations/{$key}-client.php";
    if (file_exists($clientFile)) {
        require_once $clientFile;
        $testFunc = str_replace('-', '_', $key) . '_test_connection';
        if (function_exists($testFunc)) {
            $result = $testFunc($config);
            if ($result['success'] ?? false) {
                updateIntegrationStatus($intg['id'], 'connected');
            } else {
                updateIntegrationStatus($intg['id'], 'error', $result['error'] ?? 'Connection test failed');
            }
            jsonResponse($result);
            return;
        }
    }

    // Generic HTTP test: try to reach the base URL
    $ch = curl_init($baseUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => true,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 500) {
        updateIntegrationStatus($intg['id'], 'connected');
        jsonResponse(['success' => true, 'message' => "Connection successful (HTTP $httpCode)"]);
    } else {
        $msg = $error ?: "HTTP $httpCode";
        updateIntegrationStatus($intg['id'], 'error', $msg);
        jsonResponse(['success' => false, 'error' => "Connection failed: $msg"]);
    }
}

function handle_retry_integration_events(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('system_settings', $admin_session);

    $key = $json_input['integration_key'] ?? '';
    if (empty($key)) {
        jsonResponse(['success' => false, 'error' => 'integration_key required']);
        return;
    }

    $result = retryFailedEvents($key, 50);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'RETRY_INTEGRATION_EVENTS',
        "Retried events for $key: {$result['retried']} retried, {$result['succeeded']} succeeded"
    );

    jsonResponse($result);
}
