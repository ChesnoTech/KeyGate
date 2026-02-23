<?php
/**
 * TOTP Regenerate Backup Codes API Endpoint
 *
 * Generates new backup codes for admin
 * Requires current TOTP code or existing backup code for verification
 */

require_once '../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('totp-regenerate-backup', [], [
    'rate_limit' => RATE_LIMIT_TOTP_REGENERATE_BACKUP,
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
        'error' => 'Missing verification code',
        'message' => 'Please provide TOTP code or backup code to regenerate backup codes'
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
            'message' => 'You must have 2FA enabled to regenerate backup codes'
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
            'message' => 'Verification failed. Cannot regenerate backup codes.'
        ]);
        exit;
    }

    // Generate new backup codes
    $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_backup_codes_count'");
    $backupCountResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $backupCodeCount = $backupCountResult ? (int)$backupCountResult['config_value'] : 10;

    $newBackupCodes = [];
    $hashedBackupCodes = [];

    for ($i = 0; $i < $backupCodeCount; $i++) {
        $code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $newBackupCodes[] = $code;
        $hashedBackupCodes[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // Update database
    $stmt = $pdo->prepare("
        UPDATE admin_totp_secrets
        SET backup_codes = ?
        WHERE admin_id = ?
    ");
    $stmt->execute([
        json_encode($hashedBackupCodes),
        $adminId
    ]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent)
        VALUES (?, ?, 'TOTP_BACKUP_REGEN', 'Regenerated 2FA backup codes', ?, ?)
    ");
    $stmt->execute([
        $adminId,
        $sessionId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Backup codes regenerated successfully',
        'backup_codes' => $newBackupCodes,
        'count' => count($newBackupCodes),
        'warning' => 'Save these codes securely. Old backup codes are now invalid.'
    ]);

} catch (Exception $e) {
    error_log("TOTP backup regeneration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to regenerate backup codes',
        'message' => 'An error occurred. Please try again.'
    ]);
}
