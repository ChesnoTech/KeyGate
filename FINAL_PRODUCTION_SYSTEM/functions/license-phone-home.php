<?php
/**
 * KeyGate — License phone-home (P2 anti-piracy)
 *
 * Without phone-home, a JWT registered once was good forever; pirates
 * could register a single legit license, export the JWT, and seed an
 * unlimited number of installs. Phone-home turns the Cloudflare Worker
 * into the authoritative tier source and enables revocation.
 *
 * Cadence: at most every 24h on PHP boot OR daily cron (whichever fires
 * first). Non-blocking — request runs with a tight timeout so admin
 * pages never wait on it. Cached response serves the tier between calls.
 *
 * Grace bands (enforced by checkPhoneHomeGrace):
 *    0–14d  → cached tier, no banner.
 *   14–30d  → cached tier, banner ("validation failed for N days").
 *   >30d    → community fallback (validation_status='expired').
 *   revoked → community immediately, regardless of grace.
 *   must_rebind → 'rebinding_required' (P1 grace path takes over).
 *   clock drift > 5min × 3 consecutive checks → 'clock_drift'.
 */

require_once __DIR__ . '/license-helpers.php';

define('KEYGATE_PHONEHOME_TIMEOUT_SEC', 6);
define('KEYGATE_PHONEHOME_GRACE_BANNER_S', 14 * 86400);
define('KEYGATE_PHONEHOME_GRACE_HARD_S',   30 * 86400);
define('KEYGATE_CLOCK_DRIFT_THRESHOLD_S',  5 * 60);
define('KEYGATE_CLOCK_DRIFT_STRIKE_LIMIT', 3);

/**
 * Validate license against the Cloudflare Worker. Returns the parsed
 * response array on success or null on network/parse error. Does NOT
 * mutate the DB; caller is responsible for that via applyValidateResponse.
 *
 * Caller usually passes $force=false so the call is throttled by
 * `license_phonehome_interval` (default 86400s).
 */
function phoneHomeValidate(PDO $pdo, bool $force = false): ?array {
    $row = getCurrentLicense($pdo);
    if (!$row || empty($row['license_key'])) return null;

    if (!$force) {
        $intervalCfg = (int)getConfig('license_phonehome_interval');
        $interval = $intervalCfg > 0 ? $intervalCfg : 86400;
        if (!empty($row['last_validated_at'])) {
            $age = time() - (int)strtotime($row['last_validated_at']);
            if ($age < $interval) return null; // throttled
        }
    }

    $instanceId = (string)($row['instance_id'] ?? '');
    $hwfp       = (string)($row['hardware_fingerprint'] ?? '');
    $body = json_encode([
        'license_key'          => $row['license_key'],
        'instance_id'          => $instanceId,
        'hardware_fingerprint' => $hwfp,
        'version'              => defined('KEYGATE_VERSION') ? KEYGATE_VERSION : '',
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => KEYGATE_PHONEHOME_TIMEOUT_SEC,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents(KEYGATE_LICENSE_SERVER . '/api/validate', false, $ctx);
    if ($resp === false) {
        recordPhoneHomeFailure($pdo, $row, 'network_unreachable');
        return null;
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        recordPhoneHomeFailure($pdo, $row, 'parse_error');
        return null;
    }

    applyValidateResponse($pdo, $row, $decoded);
    return $decoded;
}

/**
 * Persist a successful validate response to the DB + system_config cache.
 * Drives all the grace-band / revocation / clock-drift logic.
 */
function applyValidateResponse(PDO $pdo, array $row, array $resp): void {
    $now = time();

    // ── Server time drift ────────────────────────────────────
    $drift = 0;
    $strike = (int)($row['clock_drift_strikes'] ?? 0);
    if (!empty($resp['server_time'])) {
        $serverTs = (int)strtotime($resp['server_time']);
        if ($serverTs > 0) {
            $drift = $now - $serverTs; // local clock vs server, signed
            $absDrift = abs($drift);
            if ($absDrift > KEYGATE_CLOCK_DRIFT_THRESHOLD_S) {
                $strike++;
            } else {
                $strike = 0;
            }
        }
    }

    // ── Decide validation_status ─────────────────────────────
    $newStatus = $row['validation_status'] ?? 'valid';
    $msg       = null;

    if (!empty($resp['revoked'])) {
        $newStatus = 'revoked';
        $msg = 'License revoked by issuer';
    } elseif (!empty($resp['must_rebind'])) {
        $newStatus = 'rebinding_required';
        $msg = 'Hardware fingerprint mismatch — rebind required';
        // Persist prev_tier so rebind grace path can serve it.
        if (function_exists('saveConfigBatch')) {
            saveConfigBatch($pdo, ['license_prev_tier' => $row['tier'] ?? 'community']);
        }
    } elseif (!empty($resp['valid'])) {
        $newStatus = 'valid';
        $msg = null;
    } else {
        // valid:false with reason other than revoked/must_rebind.
        $newStatus = $resp['reason'] === 'expired' ? 'expired' : 'invalid';
        $msg = 'Worker validate: ' . ($resp['reason'] ?? 'unknown');
    }

    // Clock drift override — three strikes wins.
    if ($strike >= KEYGATE_CLOCK_DRIFT_STRIKE_LIMIT && $newStatus === 'valid') {
        $newStatus = 'clock_drift';
        $msg = 'Local clock drifted from server time on ' . $strike . ' consecutive checks';
    }

    // ── Update license_info row ──────────────────────────────
    try {
        $stmt = $pdo->prepare("
            UPDATE `" . t('license_info') . "`
            SET last_validated_at         = NOW(),
                validation_status         = ?,
                validation_failure_count  = 0,
                last_validation_error     = ?,
                server_time_drift_seconds = ?,
                clock_drift_strikes       = ?,
                current_jti               = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $newStatus,
            $msg,
            $drift,
            $strike,
            $resp['jti'] ?? null,
            $row['id'],
        ]);
    } catch (Exception $e) {
        error_log('KeyGate phonehome: update failed: ' . $e->getMessage());
    }

    // ── HMAC-anchored cache (defeats UPDATE-the-cache forgery) ──
    try {
        $secret = getLicenseRowSecret($pdo);
        $cacheBlob = json_encode($resp, JSON_UNESCAPED_SLASHES);
        $hash = hash_hmac('sha256', $cacheBlob, $secret);
        if (function_exists('saveConfigBatch')) {
            saveConfigBatch($pdo, [
                'license_validation_cache' => json_encode([
                    'response'   => $resp,
                    'fetched_at' => $now,
                    'jti'        => $resp['jti'] ?? null,
                    'hmac'       => $hash,
                ], JSON_UNESCAPED_SLASHES),
            ]);
        }
    } catch (Exception $e) {
        error_log('KeyGate phonehome: cache write failed: ' . $e->getMessage());
    }
}

/**
 * Increment validation_failure_count and persist the error reason.
 * Caller arrives here when network or parse fails — does NOT change the
 * tier (cached tier serves until grace exhausted).
 */
function recordPhoneHomeFailure(PDO $pdo, array $row, string $reason): void {
    try {
        $stmt = $pdo->prepare("
            UPDATE `" . t('license_info') . "`
            SET validation_failure_count = validation_failure_count + 1,
                last_validation_error    = ?
            WHERE id = ?
        ");
        $stmt->execute([$reason, $row['id']]);
    } catch (Exception $e) { /* legacy install */ }
}

/**
 * Compute the current grace band based on last_validated_at age.
 * Pure read; getEffectiveLicense() consults this and degrades the tier
 * once the >30d band is reached.
 *
 * Returns:
 *   ['band' => 'ok'|'banner'|'expired', 'days_since' => N, 'banner' => string|null]
 */
function checkPhoneHomeGrace(?array $row): array {
    if (!$row || empty($row['last_validated_at'])) {
        return ['band' => 'ok', 'days_since' => 0, 'banner' => null];
    }
    $age = time() - (int)strtotime($row['last_validated_at']);
    $days = (int)floor($age / 86400);

    if ($age >= KEYGATE_PHONEHOME_GRACE_HARD_S) {
        return [
            'band'        => 'expired',
            'days_since'  => $days,
            'banner'      => 'License has not validated against the issuer for ' . $days . ' days. Reverted to community tier.',
        ];
    }
    if ($age >= KEYGATE_PHONEHOME_GRACE_BANNER_S) {
        return [
            'band'        => 'banner',
            'days_since'  => $days,
            'banner'      => 'License validation failed for ' . $days . ' days. Re-connect to the network within '
                            . (int)floor((KEYGATE_PHONEHOME_GRACE_HARD_S - $age) / 86400)
                            . ' days to keep your tier.',
        ];
    }
    return ['band' => 'ok', 'days_since' => $days, 'banner' => null];
}

/**
 * Fire phoneHomeValidate() in the background so admin page renders don't
 * block on it. On Linux/macOS this uses popen(); on Windows it just calls
 * synchronously inside the request — the timeout is short enough that it's
 * acceptable.
 *
 * Caller is typically `getEffectiveLicense()` once per request, throttled
 * via the last_validated_at check inside phoneHomeValidate().
 */
function firePhoneHomeAsync(PDO $pdo): void {
    // Cheap throttle before forking.
    $row = getCurrentLicense($pdo);
    if (!$row || empty($row['license_key'])) return;
    $intervalCfg = (int)getConfig('license_phonehome_interval');
    $interval = $intervalCfg > 0 ? $intervalCfg : 86400;
    if (!empty($row['last_validated_at'])
        && (time() - strtotime($row['last_validated_at'])) < $interval) {
        return;
    }

    if (PHP_OS_FAMILY !== 'Windows' && function_exists('proc_open')) {
        // Spawn a one-shot CLI worker. The shim is small + idempotent.
        $cli = realpath(__DIR__ . '/../cli/license-validate.php');
        if ($cli && is_file($cli)) {
            $cmd = '/usr/bin/env php ' . escapeshellarg($cli) . ' >/dev/null 2>&1 &';
            @exec($cmd);
            return;
        }
    }
    // Fallback: synchronous (Windows or no CLI shim found).
    @phoneHomeValidate($pdo, false);
}
