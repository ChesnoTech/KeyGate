<?php
// API endpoint to get an OEM key for activation
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$input = ApiMiddleware::bootstrap('get-key', ['technician_id', 'order_number'], [
    'rate_limit' => RATE_LIMIT_GET_KEY,
]);

$technician_id = $input['technician_id'];
$order_number = $input['order_number'];
$computerName = $input['computer_name'] ?? null;

ApiMiddleware::validateTechnicianId($technician_id);
ApiMiddleware::validateOrderNumber($order_number);

try {
    // QC Compliance gate: block key if unresolved blocking issues
    try {
        require_once __DIR__ . '/../functions/qc-compliance.php';
        if (qcIsEnabled($pdo)) {
            $globalSettings = qcGetGlobalSettings($pdo);
            if (!empty($globalSettings['blocking_prevents_key'])) {
                $hwStmt = $pdo->prepare("SELECT id FROM `" . t('hardware_info') . "` WHERE order_number = ? ORDER BY collection_timestamp DESC LIMIT 1");
                $hwStmt->execute([$order_number]);
                $hwRow = $hwStmt->fetch(PDO::FETCH_ASSOC);
                if ($hwRow && qcHasBlockingIssues($pdo, (int) $hwRow['id'])) {
                    jsonResponse([
                        'success' => false,
                        'error' => 'Hardware compliance check failed. Blocking issues must be resolved before activation.',
                        'error_code' => 'QC_COMPLIANCE_BLOCKED',
                        'failover_available' => false
                    ], 403);
                }
            }
        }
    } catch (Exception $qcErr) {
        error_log("QC gate error (non-fatal): " . $qcErr->getMessage());
    }

    $pdo->beginTransaction();

    // Use our new concurrency-safe function to check for existing sessions
    $existing_session = getActiveSession($pdo, $technician_id);
    
    if ($existing_session) {
        // Update existing session with new order number if different
        if ($existing_session['order_number'] !== $order_number) {
            $stmt = $pdo->prepare("
                UPDATE `" . t('active_sessions') . "` 
                SET order_number = ?, expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                WHERE id = ?
            ");
            $timeout_minutes = (int) getConfigWithDefault('session_timeout_minutes', DEFAULT_SESSION_TIMEOUT_MINUTES);
            $stmt->execute([$order_number, $timeout_minutes, $existing_session['id']]);
        }
        
        $pdo->commit();
        jsonResponse([
            'success' => true,
            'session_token' => $existing_session['session_token'],
            'product_key' => $existing_session['product_key'],
            'oem_identifier' => $existing_session['oem_identifier'],
            'key_status' => $existing_session['key_status'],
            'fail_counter' => (int)$existing_session['fail_counter'],
            'message' => 'Resuming existing session'
        ]);
    }
    
    // ATOMIC KEY ALLOCATION - Prevent race conditions with database locking
    $key = allocateKeyAtomically($pdo, $technician_id, $order_number);

    if (!$key) {
        $pdo->rollback();

        // Check if ANY keys exist vs. all keys exhausted (for automatic failover)
        $stmt = $pdo->prepare("SELECT COUNT(*) as available_count FROM `" . t('oem_keys') . "` WHERE key_status IN ('unused', 'retry')");
        $stmt->execute();
        $availableCount = $stmt->fetch(PDO::FETCH_ASSOC)['available_count'];

        if ($availableCount == 0) {
            // No keys available - trigger automatic failover
            jsonResponse([
                'success' => false,
                'error' => 'No OEM keys available',
                'failover_available' => true,
                'error_code' => 'NO_KEYS_AVAILABLE'
            ], 503);
        } else {
            // Keys exist but locked/allocated (concurrency issue)
            jsonResponse([
                'success' => false,
                'error' => 'No keys currently available. Try again in a moment.',
                'failover_available' => false,
                'error_code' => 'KEYS_TEMPORARILY_UNAVAILABLE'
            ], 503);
        }
    }
    
    // Create new session with the atomically allocated key
    $session_token = generateToken();
    $timeout_minutes = (int) getConfigWithDefault('session_timeout_minutes', DEFAULT_SESSION_TIMEOUT_MINUTES);
    $expires_at = date('Y-m-d H:i:s', strtotime("+{$timeout_minutes} minutes"));
    
    // Insert new session (we already checked for existing sessions above)
    $stmt = $pdo->prepare("
        INSERT INTO `" . t('active_sessions') . "` (technician_id, session_token, key_id, order_number, expires_at, auth_method, computer_name)
        VALUES (?, ?, ?, ?, ?, 'password', ?)
    ");
    $stmt->execute([$technician_id, $session_token, $key['id'], $order_number, $expires_at, $computerName]);
    
    $pdo->commit();

    appLog('info', 'Key allocated', [
        'event'         => 'key_assigned',
        'technician_id' => $technician_id,
        'order_number'  => $order_number,
        'key_id'        => $key['id'],
    ]);

    // Check key pool levels and send alerts if needed
    try {
        $edition = $key['product_type'] ?? 'Unknown';
        $poolStmt = $pdo->prepare("SELECT COUNT(*) as remaining FROM `" . t('oem_keys') . "` WHERE key_status IN ('unused', 'retry') AND product_type = ?");
        $poolStmt->execute([$edition]);
        $remaining = (int)$poolStmt->fetch()['remaining'];

        $lowThreshold = (int)(getConfig('key_pool_low_threshold') ?: 10);
        $critThreshold = (int)(getConfig('key_pool_critical_threshold') ?: 3);
        $autoNotify = getConfig('key_pool_auto_notify') ?: '1';

        if ($autoNotify === '1' && ($remaining <= $critThreshold || $remaining <= $lowThreshold)) {
            require_once __DIR__ . '/../functions/email-helpers.php';
            $level = $remaining <= $critThreshold ? 'critical' : 'low';
            sendKeyPoolAlert($edition, $remaining, $level);
        }
    } catch (Exception $e) {
        error_log("Key pool alert check failed: " . $e->getMessage());
    }

    // Dispatch integration event (non-blocking — errors are logged, not thrown)
    try {
        require_once __DIR__ . '/../functions/integration-helpers.php';
        dispatchEventToAll('key_assigned', [
            'order_number'     => $order_number,
            'technician_id'    => $technician_id,
            'technician_name'  => $technician_id,
            'product_key'      => $key['product_key'],
            'oem_identifier'   => $key['oem_identifier'],
        ]);
    } catch (Exception $intgErr) {
        error_log("Integration dispatch error (non-fatal): " . $intgErr->getMessage());
    }

    jsonResponse([
        'success' => true,
        'session_token' => $session_token,
        'product_key' => $key['product_key'],
        'oem_identifier' => $key['oem_identifier'],
        'key_status' => $key['key_status'],
        'fail_counter' => (int)$key['fail_counter'],
        'expires_at' => $expires_at
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollback();
    error_log("Database error in get-key.php: " . $e->getMessage());
    jsonResponse(['error' => 'Database service temporarily unavailable', 'error_code' => 'DB_UNAVAILABLE'], 503);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollback();
    error_log("Get-key API error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error', 'error_code' => 'INTERNAL_ERROR'], 500);
}
?>