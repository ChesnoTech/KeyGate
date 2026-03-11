<?php
/**
 * Authenticate technician using USB device serial number
 * Returns session token if USB is authorized
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/network-utils.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

// bootstrap handles: PowerShell check, POST validation, rate limiting, JSON parsing
$input = ApiMiddleware::bootstrap('authenticate-usb', [], [
    'rate_limit' => RATE_LIMIT_AUTHENTICATE_USB,
]);

$usbSerialNumber = $input['usb_serial_number'] ?? '';
$computerName = $input['computer_name'] ?? null;
$clientInfo = $input['client_info'] ?? [];

// ========================================================================
// CRITICAL SECURITY CHECK: USB authentication only allowed from trusted networks
// This prevents stolen USB sticks from being used remotely
// ========================================================================
$clientIP = getClientIP();
$trustedNetwork = checkTrustedNetwork($clientIP, true); // true = check USB auth permission

if (!$trustedNetwork) {
    // IP not in trusted network - reject USB authentication
    error_log("USB authentication blocked: IP {$clientIP} not in trusted network");

    // Log the blocked attempt
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usb_auth_attempts (
                device_serial_number, technician_id, device_id,
                attempt_result, client_ip, computer_name, error_message
            ) VALUES (?, NULL, NULL, ?, ?, ?, ?)
        ");

        $stmt->execute([
            !empty($usbSerialNumber) ? $usbSerialNumber : 'unknown',
            'network_restricted',
            $clientIP,
            $computerName,
            'USB authentication only allowed from trusted networks'
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log blocked USB auth attempt: " . $e->getMessage());
    }

    jsonResponse([
        'success' => true,
        'authenticated' => false,
        'reason' => 'USB authentication only allowed from trusted networks',
        'security_info' => 'Please use password authentication from this location',
        'client_ip' => $clientIP
    ]);
}

// Log that USB auth was attempted from trusted network
error_log("USB authentication attempt from trusted network: {$trustedNetwork['network_name']} (IP: {$clientIP})");

// Validate input
if (empty($usbSerialNumber)) {
    jsonResponse(['success' => false, 'error' => 'Missing usb_serial_number'], 400);
}

try {
    // Check if USB auth is enabled
    $usbAuthEnabled = (bool)getConfig('usb_auth_enabled');

    if (!$usbAuthEnabled) {
        // Log attempt when system is disabled
        logUSBAuthAttempt(
            $pdo,
            $usbSerialNumber,
            null,
            null,
            'system_disabled',
            'USB authentication is disabled globally',
            $computerName
        );

        jsonResponse([
            'success' => true,
            'authenticated' => false,
            'reason' => 'USB authentication disabled'
        ]);
    }

    // Find USB device by serial number
    $stmt = $pdo->prepare("
        SELECT d.*, t.full_name, t.is_active
        FROM usb_devices d
        INNER JOIN technicians t ON d.technician_id = t.technician_id
        WHERE d.device_serial_number = ?
    ");
    $stmt->execute([$usbSerialNumber]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        // USB not registered
        logUSBAuthAttempt(
            $pdo,
            $usbSerialNumber,
            null,
            null,
            'no_match',
            'USB serial number not registered in system',
            $computerName
        );

        jsonResponse([
            'success' => true,
            'authenticated' => false,
            'reason' => 'USB device not registered'
        ]);
    }

    // Check device status
    if ($device['device_status'] !== 'active') {
        logUSBAuthAttempt(
            $pdo,
            $usbSerialNumber,
            $device['technician_id'],
            $device['device_id'],
            'disabled_device',
            "Device status: {$device['device_status']}",
            $computerName
        );

        jsonResponse([
            'success' => true,
            'authenticated' => false,
            'reason' => "USB device is {$device['device_status']}"
        ]);
    }

    // Check if technician is active
    $requireActiveTech = (bool)getConfig('usb_auth_require_active_technician');
    if ($requireActiveTech && !$device['is_active']) {
        logUSBAuthAttempt(
            $pdo,
            $usbSerialNumber,
            $device['technician_id'],
            $device['device_id'],
            'inactive_technician',
            'Technician account is inactive',
            $computerName
        );

        jsonResponse([
            'success' => true,
            'authenticated' => false,
            'reason' => 'Technician account is inactive'
        ]);
    }

    // === AUTHENTICATION SUCCESSFUL ===

    // Create session token
    $sessionToken = bin2hex(random_bytes(32));
    $sessionHours = (int) getConfigWithDefault('usb_session_timeout_hours', 8);
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$sessionHours} hours"));
    $clientIP = getClientIP();

    // Insert session
    $stmt = $pdo->prepare("
        INSERT INTO active_sessions (
            technician_id, session_token, created_at, expires_at,
            is_active, auth_method, usb_device_id, computer_name
        ) VALUES (?, ?, NOW(), ?, 1, 'usb', ?, ?)
    ");
    $stmt->execute([
        $device['technician_id'],
        $sessionToken,
        $expiresAt,
        $device['device_id'],
        $computerName
    ]);

    // Update USB device last used info
    $stmt = $pdo->prepare("
        UPDATE usb_devices
        SET last_used_date = NOW(),
            last_used_ip = ?,
            last_used_computer_name = ?,
            usage_count = usage_count + 1
        WHERE device_id = ?
    ");
    $stmt->execute([$clientIP, $computerName, $device['device_id']]);

    // Update technician last login
    $stmt = $pdo->prepare("
        UPDATE technicians
        SET last_login = NOW(),
            failed_login_attempts = 0,
            locked_until = NULL
        WHERE technician_id = ?
    ");
    $stmt->execute([$device['technician_id']]);

    // Log successful auth attempt
    logUSBAuthAttempt(
        $pdo,
        $usbSerialNumber,
        $device['technician_id'],
        $device['device_id'],
        'success',
        null,
        $computerName
    );

    // Return success with session token
    jsonResponse([
        'success' => true,
        'authenticated' => true,
        'session_token' => $sessionToken,
        'technician_id' => $device['technician_id'],
        'full_name' => $device['full_name'],
        'device_id' => $device['device_id'],
        'device_name' => $device['device_name'],
        'expires_at' => $expiresAt
    ]);

} catch (PDOException $e) {
    error_log("USB authentication error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Database error'], 503);
} catch (Exception $e) {
    error_log("USB auth API error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error'], 500);
}

/**
 * Log USB authentication attempt
 */
function logUSBAuthAttempt($pdo, $serialNumber, $technicianId, $deviceId, $result, $errorMessage, $computerName = null) {
    $logEnabled = (bool)getConfig('usb_auth_log_failures');
    if (!$logEnabled && $result !== 'success') {
        return; // Don't log failures if disabled
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO usb_auth_attempts (
                device_serial_number, technician_id, device_id,
                attempt_result, client_ip, computer_name, error_message
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $serialNumber,
            $technicianId,
            $deviceId,
            $result,
            getClientIP(),
            $computerName,
            $errorMessage
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log USB auth attempt: " . $e->getMessage());
    }
}
?>
