<?php
/**
 * TOTP Verification API Endpoint
 *
 * Verifies TOTP code during:
 * 1. Initial setup (enables 2FA after first successful verification)
 * 2. Login authentication (validates code during login)
 */

require_once '../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$data = ApiMiddleware::bootstrap('totp-verify', [], [
    'rate_limit' => RATE_LIMIT_TOTP_VERIFY,
    'require_powershell' => false,
]);

$totpCode = $data['totp_code'] ?? '';
$adminId = $data['admin_id'] ?? null;
$isSetup = $data['is_setup'] ?? false; // True if this is initial setup verification

// Validate input
if (empty($totpCode) || !$adminId) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required fields',
        'required' => ['totp_code', 'admin_id']
    ]);
    exit;
}

// Remove spaces and dashes from code
$totpCode = preg_replace('/[\s\-]/', '', $totpCode);

// Validate code format (6 digits or 8 digit backup code)
if (!preg_match('/^\d{6}$/', $totpCode) && !preg_match('/^\d{8}$/', $totpCode)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid code format',
        'message' => 'Code must be 6 digits (TOTP) or 8 digits (backup code)'
    ]);
    exit;
}

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use OTPHP\TOTP;

try {
    // Get admin's TOTP secret
    $stmt = $pdo->prepare("
        SELECT id, totp_secret, totp_enabled, backup_codes
        FROM admin_totp_secrets
        WHERE admin_id = ?
    ");
    $stmt->execute([$adminId]);
    $totpData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$totpData) {
        http_response_code(404);
        echo json_encode([
            'error' => '2FA not setup',
            'message' => 'Please setup 2FA first'
        ]);
        exit;
    }

    $isBackupCode = strlen($totpCode) === 8;
    $verified = false;
    $usedBackupCode = false;

    if ($isBackupCode) {
        // Verify backup code
        $backupCodes = json_decode($totpData['backup_codes'], true);

        if (!is_array($backupCodes)) {
            http_response_code(500);
            echo json_encode(['error' => 'Invalid backup codes data']);
            exit;
        }

        foreach ($backupCodes as $index => $hashedCode) {
            if (password_verify($totpCode, $hashedCode)) {
                // Backup code is valid - remove it so it can't be reused
                unset($backupCodes[$index]);
                $backupCodes = array_values($backupCodes); // Re-index array

                $stmt = $pdo->prepare("
                    UPDATE admin_totp_secrets
                    SET backup_codes = ?, last_used_at = NOW()
                    WHERE admin_id = ?
                ");
                $stmt->execute([json_encode($backupCodes), $adminId]);

                $verified = true;
                $usedBackupCode = true;
                break;
            }
        }

        if (!$verified) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid backup code',
                'message' => 'Backup code is invalid or already used'
            ]);
            exit;
        }

    } else {
        // Verify TOTP code
        $totp = TOTP::createFromSecret($totpData['totp_secret']);

        // Get time window from config (default ±1 = 90 seconds)
        $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_window'");
        $windowResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $window = $windowResult ? (int)$windowResult['config_value'] : 1;

        $verified = $totp->verify($totpCode, null, $window);

        if (!$verified) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid TOTP code',
                'message' => 'The code you entered is incorrect or expired'
            ]);
            exit;
        }

        // Update last used timestamp
        $stmt = $pdo->prepare("
            UPDATE admin_totp_secrets
            SET last_used_at = NOW()
            WHERE admin_id = ?
        ");
        $stmt->execute([$adminId]);
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
        $usedBackupCode ? '2FA verified with backup code' : '2FA verified with TOTP',
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // Return success
    echo json_encode([
        'success' => true,
        'verified' => true,
        'message' => $isSetup ? '2FA enabled successfully!' : 'Verification successful',
        'used_backup_code' => $usedBackupCode,
        'remaining_backup_codes' => $usedBackupCode ? count(json_decode($totpData['backup_codes'], true)) - 1 : null
    ]);

} catch (Exception $e) {
    error_log("TOTP verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Verification failed',
        'message' => 'An error occurred. Please try again.'
    ]);
}
