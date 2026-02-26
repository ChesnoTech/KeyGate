<?php
/**
 * Shared TOTP Helper Functions
 *
 * Eliminates duplication across totp-setup.php, totp-verify.php,
 * totp-disable.php, and totp-regenerate-backup-codes.php.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use OTPHP\TOTP;

/**
 * Validate admin API session from $_SESSION.
 * Starts session, checks admin_id + session_id exist.
 *
 * @return array ['admin_id' => int, 'session_id' => string]
 */
function validateAdminApiSession(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_id']) || !isset($_SESSION['session_id'])) {
        jsonResponse(['error' => 'Unauthorized - Please login'], 401);
    }

    return [
        'admin_id' => $_SESSION['admin_id'],
        'session_id' => $_SESSION['session_id'],
    ];
}

/**
 * Fetch TOTP data for an admin user.
 *
 * @return array|null The TOTP record or null if not found
 */
function fetchTotpData(PDO $pdo, int $adminId): ?array {
    $stmt = $pdo->prepare("
        SELECT id, totp_secret, totp_enabled, backup_codes
        FROM admin_totp_secrets
        WHERE admin_id = ?
    ");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Verify a TOTP code or backup code against an admin's stored secret.
 *
 * @param bool $consumeBackup If true, removes a used backup code from the DB (for login verification)
 * @return array ['verified' => bool, 'is_backup_code' => bool, 'remaining_backup_count' => int|null]
 */
function verifyTotpCode(PDO $pdo, int $adminId, string $code, array $totpData, bool $consumeBackup = false): array {
    $result = [
        'verified' => false,
        'is_backup_code' => false,
        'remaining_backup_count' => null,
    ];

    $isBackupCode = strlen($code) === 8;
    $result['is_backup_code'] = $isBackupCode;

    if ($isBackupCode) {
        $backupCodes = json_decode($totpData['backup_codes'], true);
        if (!is_array($backupCodes)) {
            return $result;
        }

        foreach ($backupCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                $result['verified'] = true;

                if ($consumeBackup) {
                    unset($backupCodes[$index]);
                    $backupCodes = array_values($backupCodes);

                    $stmt = $pdo->prepare("
                        UPDATE admin_totp_secrets
                        SET backup_codes = ?, last_used_at = NOW()
                        WHERE admin_id = ?
                    ");
                    $stmt->execute([json_encode($backupCodes), $adminId]);

                    $result['remaining_backup_count'] = count($backupCodes);
                }
                break;
            }
        }
    } else {
        $totp = TOTP::createFromSecret($totpData['totp_secret']);

        $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_window'");
        $windowResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $window = $windowResult ? (int)$windowResult['config_value'] : 1;

        $result['verified'] = $totp->verify($code, null, $window);

        if ($result['verified']) {
            $stmt = $pdo->prepare("
                UPDATE admin_totp_secrets
                SET last_used_at = NOW()
                WHERE admin_id = ?
            ");
            $stmt->execute([$adminId]);
        }
    }

    return $result;
}

/**
 * Generate new backup codes using system config count and BCRYPT_COST.
 *
 * @return array ['plain' => string[], 'hashed' => string[]]
 */
function generateBackupCodes(PDO $pdo): array {
    $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'totp_backup_codes_count'");
    $backupCountResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $backupCodeCount = $backupCountResult ? (int)$backupCountResult['config_value'] : 10;

    $plain = [];
    $hashed = [];

    for ($i = 0; $i < $backupCodeCount; $i++) {
        $code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    return ['plain' => $plain, 'hashed' => $hashed];
}
