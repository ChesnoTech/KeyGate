<?php
/**
 * TOTP Setup API Endpoint
 *
 * Generates TOTP secret and QR code for admin to scan with authenticator app
 * Also generates backup codes for recovery
 */

require_once '../config.php';
require_once '../functions/rbac.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('totp-setup', [], [
    'rate_limit' => RATE_LIMIT_TOTP_SETUP,
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

// Verify session is valid
$stmt = $pdo->prepare("
    SELECT admin_id FROM admin_sessions
    WHERE id = ? AND admin_id = ? AND is_active = 1
");
$stmt->execute([$sessionId, $adminId]);
if (!$stmt->fetch()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid session']);
    exit;
}

// Get admin info
$stmt = $pdo->prepare("SELECT username, email FROM admin_users WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    http_response_code(404);
    echo json_encode(['error' => 'Admin not found']);
    exit;
}

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

try {
    // Check if admin already has 2FA setup
    $stmt = $pdo->prepare("SELECT id, totp_enabled FROM admin_totp_secrets WHERE admin_id = ?");
    $stmt->execute([$adminId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['totp_enabled'] == 1) {
        http_response_code(400);
        echo json_encode([
            'error' => '2FA already enabled',
            'message' => 'You must disable 2FA before setting up a new secret'
        ]);
        exit;
    }

    // Generate TOTP secret
    $totp = TOTP::generate();
    $secret = $totp->getSecret();

    // Get issuer name from config
    $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_issuer_name'");
    $issuerResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $issuer = $issuerResult ? $issuerResult['config_value'] : 'OEM Activation System';

    // Set TOTP parameters
    $totp->setLabel($admin['username']);
    $totp->setIssuer($issuer);

    // Generate backup codes (10 codes, 8 digits each)
    $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_backup_codes_count'");
    $backupCountResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $backupCodeCount = $backupCountResult ? (int)$backupCountResult['config_value'] : 10;

    $backupCodes = [];
    $hashedBackupCodes = [];

    for ($i = 0; $i < $backupCodeCount; $i++) {
        $code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $backupCodes[] = $code;
        $hashedBackupCodes[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // Store in database (not yet enabled)
    if ($existing) {
        // Update existing record
        $stmt = $pdo->prepare("
            UPDATE admin_totp_secrets
            SET totp_secret = ?, backup_codes = ?, totp_enabled = 0, verified_at = NULL, created_at = NOW()
            WHERE admin_id = ?
        ");
        $stmt->execute([
            $secret,
            json_encode($hashedBackupCodes),
            $adminId
        ]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("
            INSERT INTO admin_totp_secrets (admin_id, totp_secret, backup_codes, totp_enabled)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([
            $adminId,
            $secret,
            json_encode($hashedBackupCodes)
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
        'secret' => $secret, // Show secret for manual entry
        'backup_codes' => $backupCodes, // Show plaintext codes ONCE
        'issuer' => $issuer,
        'account' => $admin['username'],
        'next_step' => 'Scan QR code and verify with first TOTP code'
    ]);

} catch (Exception $e) {
    error_log("TOTP setup error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to setup 2FA',
        'message' => 'An error occurred. Please try again.'
    ]);
}
