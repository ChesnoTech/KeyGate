<?php
/**
 * TOTP Regenerate Backup Codes API Endpoint
 *
 * Generates new backup codes for admin
 * Requires current TOTP code or existing backup code for verification
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/totp-helpers.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('totp-regenerate-backup', [], [
    'rate_limit' => RATE_LIMIT_TOTP_REGENERATE_BACKUP,
    'require_powershell' => false,
    'require_json' => false,
]);

// Verify admin session
$session = validateAdminApiSession();
$adminId = $session['admin_id'];
$sessionId = $session['session_id'];

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$totpCode = $data['totp_code'] ?? '';

// Validate input
if (empty($totpCode)) {
    jsonResponse([
        'error' => 'Missing verification code',
        'message' => 'Please provide TOTP code or backup code to regenerate backup codes'
    ], 400);
}

// Remove spaces and dashes
$totpCode = preg_replace('/[\s\-]/', '', $totpCode);

try {
    // Get admin's TOTP data
    $totpData = fetchTotpData($pdo, $adminId);

    if (!$totpData || $totpData['totp_enabled'] == 0) {
        jsonResponse([
            'error' => '2FA not enabled',
            'message' => 'You must have 2FA enabled to regenerate backup codes'
        ], 400);
    }

    // Verify the provided code
    $result = verifyTotpCode($pdo, $adminId, $totpCode, $totpData);

    if (!$result['verified']) {
        jsonResponse([
            'success' => false,
            'error' => 'Invalid code',
            'message' => 'Verification failed. Cannot regenerate backup codes.'
        ], 401);
    }

    // Generate new backup codes
    $newCodes = generateBackupCodes($pdo);

    // Update database
    $stmt = $pdo->prepare("
        UPDATE `" . t('admin_totp_secrets') . "`
        SET backup_codes = ?
        WHERE admin_id = ?
    ");
    $stmt->execute([
        json_encode($newCodes['hashed']),
        $adminId
    ]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO `" . t('admin_activity_log') . "` (admin_id, session_id, action, description, ip_address, user_agent)
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
        'backup_codes' => $newCodes['plain'],
        'count' => count($newCodes['plain']),
        'warning' => 'Save these codes securely. Old backup codes are now invalid.'
    ]);

} catch (Exception $e) {
    error_log("TOTP backup regeneration error: " . $e->getMessage());
    jsonResponse([
        'error' => 'Failed to regenerate backup codes',
        'message' => 'An error occurred. Please try again.'
    ], 500);
}
