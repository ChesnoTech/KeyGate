<?php
/**
 * TOTP Disable API Endpoint
 *
 * Allows admin to disable their own 2FA
 * Requires current TOTP code for confirmation
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/totp-helpers.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('totp-disable', [], [
    'rate_limit' => RATE_LIMIT_TOTP_DISABLE,
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
        'error' => 'Missing TOTP code',
        'message' => 'Please provide your current TOTP code to disable 2FA'
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
            'message' => '2FA is not currently enabled on your account'
        ], 400);
    }

    // Verify the provided code
    $result = verifyTotpCode($pdo, $adminId, $totpCode, $totpData);

    if (!$result['verified']) {
        jsonResponse([
            'success' => false,
            'error' => 'Invalid code',
            'message' => 'The code you entered is incorrect. Cannot disable 2FA without valid verification.'
        ], 401);
    }

    // Code verified - disable 2FA
    $stmt = $pdo->prepare("
        UPDATE `" . t('admin_totp_secrets') . "`
        SET totp_enabled = 0
        WHERE admin_id = ?
    ");
    $stmt->execute([$adminId]);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO `" . t('admin_activity_log') . "` (admin_id, session_id, action, description, ip_address, user_agent)
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
    jsonResponse([
        'error' => 'Failed to disable 2FA',
        'message' => 'An error occurred. Please try again.'
    ], 500);
}
