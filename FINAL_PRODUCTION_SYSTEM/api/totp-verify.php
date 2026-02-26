<?php
/**
 * TOTP Verification API Endpoint
 *
 * Verifies TOTP code during:
 * 1. Initial setup (enables 2FA after first successful verification)
 * 2. Login authentication (validates code during login)
 */

require_once '../config.php';
require_once __DIR__ . '/../functions/totp-helpers.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$data = ApiMiddleware::bootstrap('totp-verify', [], [
    'rate_limit' => RATE_LIMIT_TOTP_VERIFY,
    'require_powershell' => false,
]);

$totpCode = $data['totp_code'] ?? '';
$adminId = $data['admin_id'] ?? null;
$isSetup = $data['is_setup'] ?? false;

// Validate input
if (empty($totpCode) || !$adminId) {
    jsonResponse([
        'error' => 'Missing required fields',
        'required' => ['totp_code', 'admin_id']
    ], 400);
}

// Remove spaces and dashes from code
$totpCode = preg_replace('/[\s\-]/', '', $totpCode);

// Validate code format (6 digits or 8 digit backup code)
if (!preg_match('/^\d{6}$/', $totpCode) && !preg_match('/^\d{8}$/', $totpCode)) {
    jsonResponse([
        'error' => 'Invalid code format',
        'message' => 'Code must be 6 digits (TOTP) or 8 digits (backup code)'
    ], 400);
}

try {
    // Get admin's TOTP secret
    $totpData = fetchTotpData($pdo, $adminId);

    if (!$totpData) {
        jsonResponse([
            'error' => '2FA not setup',
            'message' => 'Please setup 2FA first'
        ], 404);
    }

    // Verify code — consume backup codes on use (login flow)
    $result = verifyTotpCode($pdo, $adminId, $totpCode, $totpData, true);

    if (!$result['verified']) {
        $errorMsg = $result['is_backup_code']
            ? 'Backup code is invalid or already used'
            : 'The code you entered is incorrect or expired';
        $errorKey = $result['is_backup_code'] ? 'Invalid backup code' : 'Invalid TOTP code';

        jsonResponse([
            'success' => false,
            'error' => $errorKey,
            'message' => $errorMsg
        ], 401);
    }

    // If this is initial setup verification, enable 2FA
    if ($isSetup && $totpData['totp_enabled'] == 0) {
        $stmt = $pdo->prepare("
            UPDATE admin_totp_secrets
            SET totp_enabled = 1, verified_at = NOW()
            WHERE admin_id = ?
        ");
        $stmt->execute([$adminId]);

        // Log activity
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent)
            VALUES (?, NULL, 'TOTP_ENABLED', '2FA successfully enabled', ?, ?)
        ");
        $stmt->execute([
            $adminId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    // Log successful verification
    $stmt = $pdo->prepare("
        INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent, totp_verified)
        VALUES (?, NULL, 'TOTP_VERIFIED', ?, ?, ?, 1)
    ");
    $stmt->execute([
        $adminId,
        $result['is_backup_code'] ? '2FA verified with backup code' : '2FA verified with TOTP',
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // Return success
    echo json_encode([
        'success' => true,
        'verified' => true,
        'message' => $isSetup ? '2FA enabled successfully!' : 'Verification successful',
        'used_backup_code' => $result['is_backup_code'],
        'remaining_backup_codes' => $result['remaining_backup_count']
    ]);

} catch (Exception $e) {
    error_log("TOTP verification error: " . $e->getMessage());
    jsonResponse([
        'error' => 'Verification failed',
        'message' => 'An error occurred. Please try again.'
    ], 500);
}
