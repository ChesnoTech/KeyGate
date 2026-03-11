<?php
/**
 * TOTP Setup API Endpoint
 *
 * Generates TOTP secret and QR code for admin to scan with authenticator app
 * Also generates backup codes for recovery
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/acl.php';
require_once __DIR__ . '/../functions/totp-helpers.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('totp-setup', [], [
    'rate_limit' => RATE_LIMIT_TOTP_SETUP,
    'require_powershell' => false,
    'require_json' => false,
]);

// Verify admin session
$session = validateAdminApiSession();
$adminId = $session['admin_id'];
$sessionId = $session['session_id'];

// Verify session is valid in DB
$stmt = $pdo->prepare("
    SELECT admin_id FROM admin_sessions
    WHERE id = ? AND admin_id = ? AND is_active = 1
");
$stmt->execute([$sessionId, $adminId]);
if (!$stmt->fetch()) {
    jsonResponse(['error' => 'Invalid session'], 401);
}

// Get admin info
$stmt = $pdo->prepare("SELECT username, email FROM admin_users WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    jsonResponse(['error' => 'Admin not found'], 404);
}

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;

try {
    // Check if admin already has 2FA setup
    $existing = fetchTotpData($pdo, $adminId);

    if ($existing && $existing['totp_enabled'] == 1) {
        jsonResponse([
            'error' => '2FA already enabled',
            'message' => 'You must disable 2FA before setting up a new secret'
        ], 400);
    }

    // Generate TOTP secret
    $totp = TOTP::generate();
    $secret = $totp->getSecret();

    // Get issuer name from config
    $issuer = getConfigWithDefault('totp_issuer_name', 'OEM Activation System');

    // Set TOTP parameters
    $totp->setLabel($admin['username']);
    $totp->setIssuer($issuer);

    // Generate backup codes
    $backupCodes = generateBackupCodes($pdo);

    // Store in database (not yet enabled)
    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE admin_totp_secrets
            SET totp_secret = ?, backup_codes = ?, totp_enabled = 0, verified_at = NULL, created_at = NOW()
            WHERE admin_id = ?
        ");
        $stmt->execute([
            $secret,
            json_encode($backupCodes['hashed']),
            $adminId
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO admin_totp_secrets (admin_id, totp_secret, backup_codes, totp_enabled)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([
            $adminId,
            $secret,
            json_encode($backupCodes['hashed'])
        ]);
    }

    // Generate QR code as SVG
    $provisioningUri = $totp->getProvisioningUri();

    $renderer = new ImageRenderer(
        new RendererStyle(300),
        new SvgImageBackEnd()
    );
    $writer = new Writer($renderer);
    $qrCodeSvg = $writer->writeString($provisioningUri);

    // Log activity
    $stmt = $pdo->prepare("
        INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent)
        VALUES (?, ?, 'TOTP_SETUP', 'Started 2FA setup', ?, ?)
    ");
    $stmt->execute([
        $adminId,
        $sessionId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // Return success with QR code and backup codes
    echo json_encode([
        'success' => true,
        'message' => '2FA setup initiated. Scan QR code with your authenticator app.',
        'qr_code_svg' => $qrCodeSvg,
        'secret' => $secret,
        'backup_codes' => $backupCodes['plain'],
        'issuer' => $issuer,
        'account' => $admin['username'],
        'next_step' => 'Scan QR code and verify with first TOTP code'
    ]);

} catch (Exception $e) {
    error_log("TOTP setup error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    jsonResponse([
        'error' => 'Failed to setup 2FA',
        'message' => 'An error occurred. Please try again.'
    ], 500);
}
