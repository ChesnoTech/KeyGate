<?php
/**
 * KeyGate — License Validation & Enforcement (P0: RS256 + DB row HMAC)
 *
 * Provides JWT-based license validation, tier checking, and feature gating.
 * As of v2.3.0 the license system uses **RS256 (asymmetric)** signing:
 *   - Private key lives ONLY on the Cloudflare Worker (LICENSE_PRIVATE_KEY).
 *   - Public key is embedded in this source file. Verification is local;
 *     forging a license requires the private key, which never ships.
 *
 * Backward-compatibility: a legacy HS256 secret is accepted for 90 days
 * after a v2.2.x → v2.3.x upgrade so existing customers can re-issue via
 * the Worker /api/migrate endpoint without losing access.
 *
 * License tiers:
 *   community  — 1 technician, 50 keys, basic features (free)
 *   pro        — unlimited technicians & keys, all features
 *   enterprise — pro + multi-site, SSO, custom integrations
 */

// ── License Server Configuration ────────────────────────────
define('KEYGATE_LICENSE_SERVER', 'https://keygate-license-server.msamirvip.workers.dev');
define('KEYGATE_SPONSORS_URL', 'https://github.com/sponsors/ChesnoTech');

// P1: server-side hardware fingerprint + rebind grace window.
// 7-day grace lets admins click "Rebind hardware" before tier degrades.
require_once __DIR__ . '/hardware-fingerprint.php';
define('KEYGATE_REBIND_GRACE_SECONDS', 7 * 86400);

// ── License Verification Public Key (RS256, PKCS#8 SPKI) ─────
// Generated 2026-05-08 alongside Worker secret LICENSE_PRIVATE_KEY.
// Safe to commit — public key is only useful for verification.
define('KEYGATE_LICENSE_PUBLIC_KEY', "-----BEGIN PUBLIC KEY-----\n"
    . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA108D/sn25MJwnRtSpl56\n"
    . "Vr/z8X8dzywMueB7gwDr1gcsgjYSFsqiPQLwxwptap8dl2iifkTVv0wGlSf6/1Sc\n"
    . "GHQbzISZWO8W4SsADiOZhlV3KLdlLp0A4ttY5OHYQVa52BnANJdBKHqPH1s7/D2k\n"
    . "t+aaMWYEzaAEjkZzwuxgtyhdDa9Mkk2J7y0TCbo+uGP1cLPwfHcSCuO9LRY14R2f\n"
    . "OAK3rwrOSoUIw0/xAPaSkx7tW6aOzRAbRMcE++Ppq+GpYg6ZOaROVyrKX2zuNjh8\n"
    . "8NGYB0IDggRDspi0MAjELUZ/XBm20oXzWLE5T3O+8hBjaeQJ1q5Xk2Rbe080SjxU\n"
    . "LwIDAQAB\n"
    . "-----END PUBLIC KEY-----\n");

// ── Legacy HS256 secret (90-day migration window) ────────────
// REMOVE on 2026-08-08. After that date, only RS256 JWTs verify.
// Customers with a pre-v2.3 JWT must visit /license and click
// "Re-register license" — the UI calls /api/migrate which re-issues
// an RS256 token bound to the same email + new instance_id.
define('KEYGATE_LEGACY_HS256_SECRET', 'keygate-community-verification-key-2026');
define('KEYGATE_LEGACY_HS256_DEADLINE', '2026-08-08');

// ── Tier Definitions ────────────────────────────────────────

define('LICENSE_TIERS', [
    'community' => [
        'label'            => 'Community',
        'max_technicians'  => 1,
        'max_keys'         => 50,
        'features'         => [
            'activation'   => true,
            'dashboard'    => true,
            'hardware'     => true,
            'backups'      => true,
            'integrations' => false,
            'compliance'   => false,
            'branding'     => false,
            'upgrade'      => false,
            'multi_admin'  => false,
        ],
    ],
    'pro' => [
        'label'            => 'Pro',
        'max_technicians'  => 9999,
        'max_keys'         => 999999,
        'features'         => [
            'activation'   => true,
            'dashboard'    => true,
            'hardware'     => true,
            'backups'      => true,
            'integrations' => true,
            'compliance'   => true,
            'branding'     => true,
            'upgrade'      => true,
            'multi_admin'  => true,
        ],
    ],
    'enterprise' => [
        'label'            => 'Enterprise',
        'max_technicians'  => 99999,
        'max_keys'         => 9999999,
        'features'         => [
            'activation'   => true,
            'dashboard'    => true,
            'hardware'     => true,
            'backups'      => true,
            'integrations' => true,
            'compliance'   => true,
            'branding'     => true,
            'upgrade'      => true,
            'multi_admin'  => true,
            'multi_site'   => true,
            'sso'          => true,
            'api_access'   => true,
        ],
    ],
]);

// ── Instance Fingerprint ────────────────────────────────────

/**
 * Generate a unique instance ID based on server characteristics.
 * This ID is tied to the installation and cannot be transferred.
 */
function generateInstanceId(PDO $pdo): string {
    // Combine: hostname + DB creation date + DB name + document root
    $hostname = gethostname() ?: 'unknown';
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html';

    // Get database creation timestamp (stable across restarts)
    try {
        $stmt = $pdo->query("SELECT MIN(created_at) AS first_record FROM `" . t('admin_users') . "`");
        $row = $stmt->fetch();
        $dbSeed = $row['first_record'] ?? date('Y-m-d');
    } catch (Exception $e) {
        $dbSeed = date('Y-m-d');
    }

    $raw = implode('|', [$hostname, $dbSeed, $docRoot]);
    return hash('sha256', $raw);
}

/**
 * Get or create the instance ID (cached in system_config).
 */
function getInstanceId(PDO $pdo): string {
    $cached = getConfig('license_instance_id');
    if (!empty($cached)) {
        return $cached;
    }

    $instanceId = generateInstanceId($pdo);
    saveConfigBatch($pdo, ['license_instance_id' => $instanceId]);
    return $instanceId;
}

// ── JWT Helpers (RS256 verify-only) ─────────────────────────

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Decode and verify a KeyGate license JWT.
 *
 * Verification path:
 *   1. RS256 with KEYGATE_LICENSE_PUBLIC_KEY (production tokens)
 *   2. HS256 with KEYGATE_LEGACY_HS256_SECRET (legacy tokens, 90-day window)
 *      — only attempted if today's date is on/before KEYGATE_LEGACY_HS256_DEADLINE.
 *
 * Returns the payload array on success, or null on failure.
 * The `_alg` key in the returned payload tells the caller which path verified.
 *
 * Note: production code never signs JWTs locally. The Worker is the only
 * issuer. createLicenseJwt() was removed in v2.3.0.
 */
function decodeLicenseJwt(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;
    $headerJson = base64UrlDecode($headerB64);
    $header = json_decode($headerJson, true);
    if (!is_array($header) || empty($header['alg'])) {
        return null;
    }

    $signingInput = "$headerB64.$payloadB64";
    $signature    = base64UrlDecode($signatureB64);

    // ── 1. RS256 (production) ────────────────────────────────
    if ($header['alg'] === 'RS256') {
        $verified = openssl_verify(
            $signingInput,
            $signature,
            KEYGATE_LICENSE_PUBLIC_KEY,
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1) {
            return null;
        }
        $payload = json_decode(base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) return null;
        $payload['_alg'] = 'RS256';
        return $payload;
    }

    // ── 2. HS256 legacy (migration window only) ──────────────
    if ($header['alg'] === 'HS256') {
        if (!defined('KEYGATE_LEGACY_HS256_DEADLINE') || date('Y-m-d') > KEYGATE_LEGACY_HS256_DEADLINE) {
            return null;
        }
        $expected = hash_hmac(
            'sha256',
            $signingInput,
            KEYGATE_LEGACY_HS256_SECRET,
            true
        );
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $payload = json_decode(base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) return null;
        $payload['_alg'] = 'HS256-legacy';
        return $payload;
    }

    // Unknown alg
    return null;
}

// ── DB Row Integrity HMAC (P0.2) ────────────────────────────

/**
 * Get or generate the per-instance secret used to HMAC license_info rows.
 * Stored in system_config('license_row_secret'). Rotated on every successful
 * license registration and (in P2) every successful phone-home validate.
 */
function getLicenseRowSecret(PDO $pdo): string {
    $cached = getConfig('license_row_secret');
    if (!empty($cached)) return $cached;
    $secret = bin2hex(random_bytes(32));
    saveConfigBatch($pdo, ['license_row_secret' => $secret]);
    return $secret;
}

/**
 * Force-rotate the per-instance row secret. Returns the new value.
 * Caller is responsible for re-stamping any active license_info row.
 */
function rotateLicenseRowSecret(PDO $pdo): string {
    $secret = bin2hex(random_bytes(32));
    saveConfigBatch($pdo, ['license_row_secret' => $secret]);
    return $secret;
}

/**
 * Compute the integrity HMAC for a license_info row.
 *
 *   HMAC_SHA256(
 *     license_key | tier | max_techs | max_keys | exp_unix | instance_id | hardware_fingerprint,
 *     row_secret
 *   )
 *
 * P1 added the trailing hardware_fingerprint field. P0 rows (no hwfp
 * column or NULL value) hash with empty-string for the field; after P1
 * migration the field is always present so empty-string only means an
 * intentionally unbound row (claim-pending).
 */
function computeLicenseRowHmac(string $secret, array $row): string {
    $material = implode('|', [
        (string)($row['license_key']     ?? ''),
        (string)($row['tier']            ?? ''),
        (string)((int)($row['max_technicians'] ?? 0)),
        (string)((int)($row['max_keys']        ?? 0)),
        // expires_at is stored as DATETIME — convert to unix epoch for stable hashing.
        (string)(empty($row['expires_at']) ? 0 : strtotime($row['expires_at'])),
        (string)($row['instance_id']     ?? ''),
        // P1: hardware_fingerprint (composite SHA256 from hardware-fingerprint.php)
        (string)($row['hardware_fingerprint'] ?? ''),
    ]);
    return hash_hmac('sha256', $material, $secret);
}

/**
 * Verify a license_info row's integrity HMAC. Returns true if valid.
 * Rows missing integrity_hmac (legacy or directly-INSERTed) fail.
 */
function verifyLicenseRow(PDO $pdo, array $row): bool {
    if (empty($row['integrity_hmac'])) return false;
    $expected = computeLicenseRowHmac(getLicenseRowSecret($pdo), $row);
    return hash_equals($expected, $row['integrity_hmac']);
}

// ── License Validation ──────────────────────────────────────

/**
 * Get the current license info from the database.
 * Returns the license row or null if no license is registered.
 */
function getCurrentLicense(PDO $pdo): ?array {
    try {
        $stmt = $pdo->query("SELECT * FROM `" . t('license_info') . "` WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        // Table may not exist yet (pre-migration)
        return null;
    }
}

/**
 * Get the effective license tier and limits.
 *
 * Decision tree (highest priority first):
 *   1. No row OR row HMAC fail              → community (mark row invalid).
 *   2. validation_status='rebinding_required' → previous tier inside 7d grace,
 *                                              else community.
 *   3. validation_status!='valid'            → community.
 *   4. hwfp drift detected (3-of-5 fail)     → set rebinding_required, recurse.
 *   5. Otherwise                             → row's tier.
 *
 * Defeats direct INSERT/UPDATE bypass (HMAC) AND VM-clone bypass (hwfp).
 */
function getEffectiveLicense(PDO $pdo): array {
    $license = getCurrentLicense($pdo);

    // ── 1. Missing row OR HMAC mismatch → community ──────────
    if (!$license || !verifyLicenseRow($pdo, $license)) {
        // If row exists but HMAC failed, mark it invalid so admin sees the issue.
        // Best-effort; ignore failures (column missing pre-migration, etc.).
        if ($license && $license['validation_status'] === 'valid') {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE `" . t('license_info') . "` SET validation_status = 'invalid' WHERE id = ?"
                );
                $stmt->execute([$license['id']]);
                error_log("KeyGate: license row HMAC mismatch on id=" . $license['id'] . ", forced to community");
            } catch (Exception $e) { /* legacy installs */ }
        }
        return _communityLicense($license !== null);
    }

    $tier    = $license['tier'] ?? 'community';
    $tierDef = LICENSE_TIERS[$tier] ?? LICENSE_TIERS['community'];

    // ── 2. rebinding_required → grace window of previous tier ──
    if (($license['validation_status'] ?? '') === 'rebinding_required') {
        $boundAt   = !empty($license['hwfp_last_rebind_at'])
                     ? strtotime($license['hwfp_last_rebind_at'])
                     : (int)strtotime($license['updated_at'] ?? 'now');
        $graceEnds = $boundAt + KEYGATE_REBIND_GRACE_SECONDS;
        if (time() < $graceEnds) {
            $prevTier = (string)getConfig('license_prev_tier');
            if ($prevTier && isset(LICENSE_TIERS[$prevTier])) {
                $prevDef = LICENSE_TIERS[$prevTier];
                return [
                    'tier'             => $prevTier,
                    'label'            => $prevDef['label'],
                    'max_technicians'  => (int)($prevDef['max_technicians']),
                    'max_keys'         => (int)($prevDef['max_keys']),
                    'features'         => $prevDef['features'],
                    'licensed_to'      => $license['licensed_to_email'] ?? '',
                    'expires_at'       => $license['expires_at'],
                    'is_registered'    => true,
                    'instance_id'      => $license['instance_id'],
                    'rebind_required'  => true,
                    'rebind_grace_ends'=> date('c', $graceEnds),
                ];
            }
        }
        // Grace exhausted → community.
        return _communityLicense(true, ['rebind_required' => true]);
    }

    // ── 3. Anything other than 'valid' → community ────────────
    if (($license['validation_status'] ?? '') !== 'valid') {
        return _communityLicense(true);
    }

    // ── 4. hwfp drift detection (P1) ──────────────────────────
    // Only enforce if the row has a bound fingerprint (post-P1 install).
    // Pre-P1 rows have NULL hardware_fingerprint and skip this gate until
    // the customer re-registers (auto-binds current host hwfp).
    if (!empty($license['hardware_fingerprint'])) {
        try {
            $current = getServerHardwareFingerprint($pdo, false);
            $bound   = ['composite' => $license['hardware_fingerprint'], 'components' => []];
            $cmp     = compareHwfp($current, $bound);
            if (!$cmp['accepted']) {
                _markRebindingRequired($pdo, $license, $tier);
                error_log("KeyGate: hwfp drift on license id=" . $license['id']
                    . ", matches=" . $cmp['match_count'] . "/" . $cmp['total']
                    . ", composite_eq=" . ($cmp['composite_eq'] ? '1' : '0'));
                // Recurse — caller now sees rebinding_required path.
                return getEffectiveLicense($pdo);
            }
        } catch (Exception $e) {
            // Hardware-fingerprint helper unavailable (PHP module missing,
            // unsupported OS) — fail open, log, continue with bound tier.
            error_log("KeyGate: hwfp comparison threw: " . $e->getMessage());
        }
    }

    return [
        'tier'             => $tier,
        'label'            => $tierDef['label'],
        'max_technicians'  => (int)$license['max_technicians'],
        'max_keys'         => (int)$license['max_keys'],
        'features'         => $tierDef['features'],
        'licensed_to'      => $license['licensed_to_email'] ?? '',
        'expires_at'       => $license['expires_at'],
        'is_registered'    => true,
        'instance_id'      => $license['instance_id'],
    ];
}

/**
 * Build the canonical community-tier response. Shared by all the
 * fallback paths in getEffectiveLicense().
 */
function _communityLicense(bool $isRegistered, array $extra = []): array {
    $communityDef = LICENSE_TIERS['community'];
    return array_merge([
        'tier'             => 'community',
        'label'            => 'Community',
        'max_technicians'  => $communityDef['max_technicians'],
        'max_keys'         => $communityDef['max_keys'],
        'features'         => $communityDef['features'],
        'licensed_to'      => '',
        'expires_at'       => null,
        'is_registered'    => $isRegistered,
        'instance_id'      => '',
    ], $extra);
}

/**
 * Set validation_status='rebinding_required' and persist the previous
 * tier so the 7-day grace window can serve it. Called by the hwfp-drift
 * branch of getEffectiveLicense() and (in P2) by phone-home responses
 * carrying must_rebind:true.
 */
function _markRebindingRequired(PDO $pdo, array $license, string $prevTier): void {
    try {
        // Persist prev tier for the grace path.
        if (function_exists('saveConfigBatch')) {
            saveConfigBatch($pdo, ['license_prev_tier' => $prevTier]);
        }
        $stmt = $pdo->prepare(
            "UPDATE `" . t('license_info') . "`
             SET validation_status = 'rebinding_required'
             WHERE id = ?"
        );
        $stmt->execute([$license['id']]);
    } catch (Exception $e) {
        error_log("KeyGate: failed to mark rebinding_required: " . $e->getMessage());
    }
}

/**
 * Register or update a license key.
 */
function registerLicense(PDO $pdo, string $licenseKey): array {
    $payload = decodeLicenseJwt($licenseKey);

    if (!$payload) {
        return ['success' => false, 'error' => 'Invalid license key — signature verification failed'];
    }

    // Validate required fields
    $required = ['tier', 'instance_id', 'iat', 'exp'];
    foreach ($required as $field) {
        if (empty($payload[$field])) {
            return ['success' => false, 'error' => "License missing required field: {$field}"];
        }
    }

    // Reject wildcard binding — every license MUST be bound to a specific instance.
    // Customers paste their instance ID into the checkout form (LemonSqueezy custom
    // field, T-Bank OrderId encoding, GitHub Sponsors /api/claim flow).
    if ($payload['instance_id'] === '*') {
        return [
            'success' => false,
            'error' => 'This license uses a deprecated wildcard binding. Re-issue from your purchase email or run /api/claim.',
        ];
    }

    // Validate instance ID matches this install
    $instanceId = getInstanceId($pdo);
    if ($payload['instance_id'] !== $instanceId) {
        return [
            'success' => false,
            'error' => 'License is bound to a different installation. Your instance ID: ' . substr($instanceId, 0, 12) . '...',
        ];
    }

    // Check expiration
    if ($payload['exp'] < time()) {
        return ['success' => false, 'error' => 'License has expired'];
    }

    $tier = $payload['tier'] ?? 'community';
    if (!isset(LICENSE_TIERS[$tier])) {
        return ['success' => false, 'error' => 'Unknown license tier: ' . $tier];
    }

    $tierDef = LICENSE_TIERS[$tier];

    // Build the row payload up-front so we can HMAC it.
    $maxTechs = (int)($payload['max_technicians'] ?? $tierDef['max_technicians']);
    $maxKeys  = (int)($payload['max_keys']        ?? $tierDef['max_keys']);
    $expIso   = date('Y-m-d H:i:s', (int)$payload['exp']);

    // ── P1: hardware fingerprint binding ─────────────────────
    // Compute current host fingerprint. If the JWT carries an `hwfp`
    // claim, validate against current with the 3-of-5 soft threshold.
    // If absent (legacy or pre-P1 mint), auto-bind to current.
    $serverHwfp = ['composite' => '', 'components' => []];
    try {
        $serverHwfp = getServerHardwareFingerprint($pdo, false);
    } catch (Exception $e) {
        error_log("KeyGate: hwfp helper unavailable on register: " . $e->getMessage());
    }
    $hostHwfpComposite = (string)($serverHwfp['composite'] ?? '');

    if (!empty($payload['hwfp']) && $hostHwfpComposite !== '') {
        $bound = ['composite' => $payload['hwfp'], 'components' => []];
        $cmp   = compareHwfp($serverHwfp, $bound);
        if (!$cmp['accepted']) {
            return [
                'success' => false,
                'error'   => 'License is bound to different hardware. '
                    . 'Match: ' . $cmp['match_count'] . '/' . $cmp['total']
                    . ' components. Run /api/rebind from your previous host '
                    . 'or contact support if the previous host is unrecoverable.',
            ];
        }
    }

    // ── P0.3: rotate per-instance row secret on every register ──
    $rowSecret = rotateLicenseRowSecret($pdo);

    // Compute integrity HMAC for the row (P1 formula includes hwfp).
    $rowForHmac = [
        'license_key'          => $licenseKey,
        'tier'                 => $tier,
        'max_technicians'      => $maxTechs,
        'max_keys'             => $maxKeys,
        'expires_at'           => $expIso,
        'instance_id'          => $instanceId,
        'hardware_fingerprint' => $hostHwfpComposite,
    ];
    $integrityHmac = computeLicenseRowHmac($rowSecret, $rowForHmac);

    // Deactivate any existing license
    $pdo->exec("UPDATE `" . t('license_info') . "` SET is_active = 0");

    // Insert new license (P1 columns included).
    $stmt = $pdo->prepare("
        INSERT INTO `" . t('license_info') . "`
            (license_key, instance_id, hardware_fingerprint, hwfp_bound_at,
             tier, licensed_to_email, licensed_to_name,
             max_technicians, max_keys, features,
             issued_at, expires_at, last_validated_at, validation_status, is_active,
             integrity_hmac)
        VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), NOW(),
                'valid', 1, ?)
    ");
    $stmt->execute([
        $licenseKey,
        $instanceId,
        $hostHwfpComposite ?: null,
        $tier,
        $payload['email'] ?? null,
        $payload['name'] ?? null,
        $maxTechs,
        $maxKeys,
        json_encode($tierDef['features']),
        $payload['iat'],
        $payload['exp'],
        $integrityHmac,
    ]);

    // Update system_config — store both current tier and the prev-tier
    // anchor used by the rebinding_required grace window.
    saveConfigBatch($pdo, [
        'license_tier'      => $tier,
        'license_prev_tier' => $tier,
    ]);

    return [
        'success'   => true,
        'tier'      => $tier,
        'label'     => $tierDef['label'],
        'algorithm' => $payload['_alg'] ?? 'unknown',
        'hwfp'      => $hostHwfpComposite,
        'message'   => "License registered successfully — {$tierDef['label']} tier activated"
            . (($payload['_alg'] ?? '') === 'HS256-legacy'
                ? ' (legacy algorithm; please re-issue via Re-register button before ' . KEYGATE_LEGACY_HS256_DEADLINE . ')'
                : ''),
    ];
}

// ── Rebind support (P1) ─────────────────────────────────────

/**
 * Apply a successful /api/rebind response to the active license row.
 * Caller hands in the fresh JWT (RS256, hwfp bound to current host) and
 * the new rebind quota counters.
 *
 * Returns ['success'=>true, 'tier'=>...] on apply or ['success'=>false]
 * on failure. The new JWT must validate via decodeLicenseJwt() and bind
 * to the same instance_id as the existing row.
 */
function applyRebindResponse(PDO $pdo, string $newJwt, int $rebindCount): array {
    $payload = decodeLicenseJwt($newJwt);
    if (!$payload) {
        return ['success' => false, 'error' => 'Rebind response carried invalid JWT'];
    }
    $current = getCurrentLicense($pdo);
    if (!$current) {
        return ['success' => false, 'error' => 'No active license to rebind'];
    }
    if (($payload['instance_id'] ?? '') !== $current['instance_id']) {
        return ['success' => false, 'error' => 'Rebind response mismatched instance_id'];
    }

    $serverHwfp = getServerHardwareFingerprint($pdo, true); // force re-detect post-rebind
    $hostHwfpComposite = (string)($serverHwfp['composite'] ?? '');

    $tier    = $payload['tier'] ?? $current['tier'];
    $tierDef = LICENSE_TIERS[$tier] ?? LICENSE_TIERS['community'];
    $maxT    = (int)($payload['max_technicians'] ?? $tierDef['max_technicians']);
    $maxK    = (int)($payload['max_keys']        ?? $tierDef['max_keys']);
    $expIso  = date('Y-m-d H:i:s', (int)$payload['exp']);

    $rowSecret = rotateLicenseRowSecret($pdo);
    $hmac = computeLicenseRowHmac($rowSecret, [
        'license_key'          => $newJwt,
        'tier'                 => $tier,
        'max_technicians'      => $maxT,
        'max_keys'             => $maxK,
        'expires_at'           => $expIso,
        'instance_id'          => $current['instance_id'],
        'hardware_fingerprint' => $hostHwfpComposite,
    ]);

    $stmt = $pdo->prepare("
        UPDATE `" . t('license_info') . "`
        SET license_key          = ?,
            hardware_fingerprint = ?,
            hwfp_bound_at        = NOW(),
            hwfp_rebind_count    = ?,
            hwfp_last_rebind_at  = NOW(),
            tier                 = ?,
            max_technicians      = ?,
            max_keys             = ?,
            expires_at           = FROM_UNIXTIME(?),
            validation_status    = 'valid',
            integrity_hmac       = ?,
            last_validated_at    = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $newJwt,
        $hostHwfpComposite ?: null,
        $rebindCount,
        $tier,
        $maxT,
        $maxK,
        (int)$payload['exp'],
        $hmac,
        $current['id'],
    ]);

    saveConfigBatch($pdo, ['license_tier' => $tier, 'license_prev_tier' => $tier]);

    return ['success' => true, 'tier' => $tier, 'rebind_count' => $rebindCount];
}

// ── Enforcement ─────────────────────────────────────────────

/**
 * Check if a specific feature is available in the current license tier.
 */
function isFeatureAvailable(PDO $pdo, string $feature): bool {
    $license = getEffectiveLicense($pdo);
    return !empty($license['features'][$feature]);
}

/**
 * Check if adding another technician would exceed the license limit.
 */
function canAddTechnician(PDO $pdo): array {
    $license = getEffectiveLicense($pdo);
    $stmt = $pdo->query("SELECT COUNT(*) FROM `" . t('technicians') . "` WHERE status = 'active'");
    $currentCount = (int)$stmt->fetchColumn();

    if ($currentCount >= $license['max_technicians']) {
        return [
            'allowed' => false,
            'current' => $currentCount,
            'limit'   => $license['max_technicians'],
            'tier'    => $license['tier'],
            'message' => "Technician limit reached ({$currentCount}/{$license['max_technicians']}). Upgrade to Pro for unlimited technicians.",
        ];
    }

    return ['allowed' => true, 'current' => $currentCount, 'limit' => $license['max_technicians']];
}

/**
 * Check if adding more keys would exceed the license limit.
 */
function canAddKeys(PDO $pdo, int $count = 1): array {
    $license = getEffectiveLicense($pdo);
    $stmt = $pdo->query("SELECT COUNT(*) FROM `" . t('oem_keys') . "`");
    $currentCount = (int)$stmt->fetchColumn();

    if (($currentCount + $count) > $license['max_keys']) {
        return [
            'allowed'   => false,
            'current'   => $currentCount,
            'adding'    => $count,
            'limit'     => $license['max_keys'],
            'tier'      => $license['tier'],
            'message'   => "Key limit reached ({$currentCount}/{$license['max_keys']}). Upgrade to Pro for unlimited keys.",
        ];
    }

    return ['allowed' => true, 'current' => $currentCount, 'limit' => $license['max_keys']];
}
