<?php
/**
 * KeyGate — License phone-home CLI shim (P2)
 *
 * Daily cron entry runs this script to call /api/validate even when no
 * admin pages have been hit. Idempotent — phoneHomeValidate() throttles
 * itself via license_info.last_validated_at, so running this hourly or
 * every minute is safe (only the first call inside the interval fires).
 *
 * Suggested cron (Linux):
 *   0 3 * * * cd /var/www/keygate && /usr/bin/php FINAL_PRODUCTION_SYSTEM/cli/license-validate.php >> /var/log/keygate-phonehome.log 2>&1
 *
 * The Windows/IIS path goes through firePhoneHomeAsync()'s synchronous
 * fallback — the 6-second Worker timeout is tolerable as a once-per-day
 * blocking call.
 */

// Run only from CLI — refuse to expose this over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/admin-helpers.php';
require_once __DIR__ . '/../functions/license-helpers.php';
require_once __DIR__ . '/../functions/license-phone-home.php';

$force = in_array('--force', $argv ?? [], true);
echo '[' . date('c') . "] phone-home start (force=" . ($force ? '1' : '0') . ")\n";

try {
    $resp = phoneHomeValidate($pdo, $force);
    if ($resp === null) {
        echo "[" . date('c') . "] no-op (throttled or no license)\n";
        exit(0);
    }
    echo '[' . date('c') . '] OK valid=' . (!empty($resp['valid']) ? '1' : '0')
        . ' tier=' . ($resp['tier'] ?? '-')
        . ' must_rebind=' . (!empty($resp['must_rebind']) ? '1' : '0')
        . ' revoked=' . (!empty($resp['revoked']) ? '1' : '0')
        . ' jti=' . substr((string)($resp['jti'] ?? ''), 0, 8)
        . "\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, '[' . date('c') . '] ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}
