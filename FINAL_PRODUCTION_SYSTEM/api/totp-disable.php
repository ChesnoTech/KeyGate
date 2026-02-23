<?php
/**
 * TOTP Disable API Endpoint
 *
 * Allows admin to disable their own 2FA
 * Requires current TOTP code for confirmation
 */

require_once '../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('totp-disable', [], [
    'rate_limit' => RATE_LIMIT_TOTP_DISABLE,
    'require_powershell' => false,
    'require_json' => false,
]);

// Verify admin session
session_start();
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['session_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Please login']);
    exit;
}

$adminId = $_SESSION['admin_id'];
$sessionId = $_SESSION['session_id'];

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$totpCode = $data['totp_code'] ?? '';

// Validate input
if (empty($totpCode)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing TOTP code',
        'message' => 'Please provide your current TOTP code to disable 2FA'
    ]);
    exit;
}

// Remove spaces and dashes
$totpCode = preg_replace('/[\s\-]/', '', $totpCode);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use OTPHP\TOTP;

try {
    // Get admin's TOTP data
    $stmt = $pdo->prepare("
        SELECT id, totp_secret, totp_enabled, backup_codes
        FROM admin_totp_secrets
        WHERE admin_id = ?
    ");
    $stmt->execute([$adminId]);
    $totpData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$totpData || $totpData['totp_enabled'] == 0) {
        http_response_code(400);
        echo json_encode([
            'error' => '2FA not enabled',
            'message' => '2FA is not currently enabled on your account'
        ]);
        exit;
    }

    // Verify the provided code
    $verified = false;
    $isBackupCode = strlen($totpCode) === 8;

    if ($isBackupCode) {
        // Check backup code
        $backupCodes = json_decode($totpData['backup_codes'], true);

        foreach ($backupCodes as $hashedCode) {
            if (password_verify($totpCode, $hashedCode)) {
                $verified = true;
                break;
            }
        }
    } else {
        // Verify TOTP code
        $totp = TOTP::createFromSecret($totpData['totp_secret']);

        // Get time window from config
        $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_window'");
        $windowResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $window = $windowResult ? (int)$windowResult['config_value'] : 1;

        $verified = $totp->verify($totpCode, null, $window);
    }

    if (!$verified) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid code',
            'message' => 'The code you entered is incorrect. Cannot disable 2FA without valid verification.'
        ]);
        exit;
    }

    // Code verified - disable 2FA
    $stmt = $pdo->prepare("
        UPDATE admin_totp_secrets
        SET totp_enabled = 0
        WHERE admin_id = ?
    ");
    $stmt->execute([$adminId]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent)
        VALUES (?, ?, 'TOTP_DISABLED', '2FA disabled by user', ?, ?)
    ");
    $stmt->execute([
        $adminId,
        $sessionId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    echo json_encode([
        'success' => true,
        'message' => '2FA has been disabled successfully',
        'warning' => 'Your account is now less secure. Consider re-enabling 2FA.'
    ]);

} catch (Exception $e) {
    error_log("TOTP disable error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to disable 2FA',
        'message' => 'An error occurred. Please try again.'
    ]);
}
