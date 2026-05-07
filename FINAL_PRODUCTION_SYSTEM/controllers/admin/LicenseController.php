<?php
/**
 * KeyGate — License Management Controller
 *
 * Handles license registration, status checks, and tier enforcement.
 */

require_once __DIR__ . '/../../functions/license-helpers.php';

// ── Get License Status (no auth required — needed for registration wall) ──

function handle_license_status(PDO $pdo, array $admin_session, $json_input): void {
    $instanceId = getInstanceId($pdo);
    $license = getEffectiveLicense($pdo);

    // Count current usage
    $techCount = 0;
    $keyCount = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `" . t('technicians') . "` WHERE status = 'active'");
        $techCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* table may not exist */ }

    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `" . t('oem_keys') . "`");
        $keyCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* table may not exist */ }

    // P1: hardware fingerprint info — current host, bound row, drift state.
    $hwfp = ['composite' => '', 'components' => []];
    try { $hwfp = getServerHardwareFingerprint($pdo, false); } catch (Exception $e) { /* fail open */ }

    $rebindCount = 0;
    $boundFingerprint = '';
    try {
        $stmt = $pdo->query("SELECT hardware_fingerprint, hwfp_rebind_count, hwfp_bound_at, hwfp_last_rebind_at
                             FROM `" . t('license_info') . "` WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $boundFingerprint = (string)($row['hardware_fingerprint'] ?? '');
        $rebindCount = (int)($row['hwfp_rebind_count'] ?? 0);
    } catch (Exception $e) { /* pre-P1 install, columns absent */ }

    jsonResponse([
        'success' => true,
        'license' => [
            'tier'            => $license['tier'],
            'label'           => $license['label'],
            'is_registered'   => $license['is_registered'],
            'licensed_to'     => $license['licensed_to'],
            'expires_at'      => $license['expires_at'],
            'max_technicians' => $license['max_technicians'],
            'max_keys'        => $license['max_keys'],
            'features'        => $license['features'],
            'instance_id'     => $instanceId,
            'rebind_required' => !empty($license['rebind_required']),
            'rebind_grace_ends' => $license['rebind_grace_ends'] ?? null,
        ],
        'usage' => [
            'technicians' => $techCount,
            'keys'        => $keyCount,
        ],
        'hardware' => [
            'current_fingerprint' => (string)($hwfp['composite'] ?? ''),
            'components'          => $hwfp['components'] ?? [],
            'bound_fingerprint'   => $boundFingerprint,
            'rebind_count'        => $rebindCount,
            'rebind_quota_limit'  => 3,
            'rebind_window_days'  => 365,
        ],
    ]);
}

// ── Register License Key ──

function handle_license_register(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $licenseKey = trim($json_input['license_key'] ?? '');
    if (empty($licenseKey)) {
        jsonResponse(['success' => false, 'error' => 'License key is required']);
        return;
    }

    $result = registerLicense($pdo, $licenseKey);

    if ($result['success']) {
        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'] ?? 0,
            'LICENSE_REGISTERED',
            "Registered {$result['label']} license"
        );
    }

    jsonResponse($result);
}

// ── Deactivate License (revert to community) ──

function handle_license_deactivate(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $pdo->exec("UPDATE `" . t('license_info') . "` SET is_active = 0");
    saveConfigBatch($pdo, ['license_tier' => 'community']);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'LICENSE_DEACTIVATED',
        'License deactivated — reverted to Community tier'
    );

    jsonResponse(['success' => true, 'message' => 'License deactivated']);
}

// ── Generate Development License (calls Worker /api/dev-issue) ──
//
// Local signing was removed in v2.3.0 (P0 hardening). The Worker is the
// only entity that holds the RS256 private key. Founder provides a
// DEV_TOKEN (matching the Cloudflare secret) to authorize.
function handle_license_generate_dev(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    // Only allow in non-production environments
    $isProduction = !in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'activate.local']);
    if ($isProduction) {
        jsonResponse(['success' => false, 'error' => 'Dev license generation is only available in development environments']);
        return;
    }

    $tier      = $json_input['tier'] ?? 'pro';
    $devToken  = $json_input['dev_token'] ?? '';
    if (!isset(LICENSE_TIERS[$tier])) {
        jsonResponse(['success' => false, 'error' => 'Invalid tier']);
        return;
    }
    if (empty($devToken)) {
        jsonResponse([
            'success' => false,
            'error'   => 'DEV_TOKEN required. Set the same value via wrangler secret put DEV_TOKEN on the Worker.',
        ]);
        return;
    }

    $instanceId = getInstanceId($pdo);
    $hwfp       = getServerHardwareFingerprint($pdo, false);
    $hwfpHex    = (string)($hwfp['composite'] ?? '');
    if ($hwfpHex === '') {
        jsonResponse(['success' => false, 'error' => 'Could not compute server hardware fingerprint']);
        return;
    }

    $body = json_encode([
        'tier'                 => $tier,
        'email'                => 'dev@localhost',
        'instance_id'          => $instanceId,
        'hardware_fingerprint' => $hwfpHex,
        'dev_token'            => $devToken,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents(KEYGATE_LICENSE_SERVER . '/api/dev-issue', false, $ctx);
    if ($resp === false) {
        jsonResponse(['success' => false, 'error' => 'Could not reach license server. Check network.']);
        return;
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
        jsonResponse(['success' => false, 'error' => 'License server returned invalid response.']);
        return;
    }

    if (empty($decoded['success'])) {
        jsonResponse(['success' => false, 'error' => $decoded['error'] ?? 'Worker rejected request.']);
        return;
    }

    jsonResponse([
        'success'     => true,
        'license_key' => $decoded['license_key'],
        'tier'        => $decoded['tier'] ?? $tier,
        'instance_id' => $instanceId,
        'expires_at'  => $decoded['expires_at'] ?? null,
        'message'     => 'Development license issued by Worker. Paste into Registration field.',
    ]);
}

// ── Claim license (GitHub Sponsors / pending payments) ──
//
// Body: { email, sponsor_login? }
// Calls Worker /api/claim with the local instance_id. Worker mints an
// RS256 JWT bound to this install.
function handle_license_claim(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $email         = trim($json_input['email'] ?? '');
    $sponsorLogin  = trim($json_input['sponsor_login'] ?? '');
    if (empty($email)) {
        jsonResponse(['success' => false, 'error' => 'Email is required']);
        return;
    }

    $instanceId = getInstanceId($pdo);
    $hwfp       = getServerHardwareFingerprint($pdo, false);
    $hwfpHex    = (string)($hwfp['composite'] ?? '');
    if ($hwfpHex === '') {
        jsonResponse(['success' => false, 'error' => 'Could not compute server hardware fingerprint']);
        return;
    }
    $body = json_encode([
        'email'                => $email,
        'instance_id'          => $instanceId,
        'hardware_fingerprint' => $hwfpHex,
        'sponsor_login'        => $sponsorLogin ?: null,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents(KEYGATE_LICENSE_SERVER . '/api/claim', false, $ctx);
    if ($resp === false) {
        jsonResponse(['success' => false, 'error' => 'Could not reach license server']);
        return;
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        jsonResponse(['success' => false, 'error' => $decoded['error'] ?? 'Claim failed']);
        return;
    }

    // Auto-register the freshly-issued JWT.
    $regResult = registerLicense($pdo, $decoded['license_key']);
    jsonResponse(array_merge($regResult, [
        'license_key' => $decoded['license_key'],
        'expires_at'  => $decoded['expires_at'] ?? null,
    ]));
}

// ── Migrate legacy HS256 license to RS256 ──
//
// Body: { license_key (legacy HS256) }
// Calls Worker /api/migrate. Re-issues an RS256 JWT bound to this
// instance_id. Auto-registers on success.
function handle_license_migrate(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $legacyKey = trim($json_input['license_key'] ?? '');
    if (empty($legacyKey)) {
        jsonResponse(['success' => false, 'error' => 'license_key is required']);
        return;
    }

    $instanceId = getInstanceId($pdo);
    $hwfp       = getServerHardwareFingerprint($pdo, false);
    $hwfpHex    = (string)($hwfp['composite'] ?? '');
    if ($hwfpHex === '') {
        jsonResponse(['success' => false, 'error' => 'Could not compute server hardware fingerprint']);
        return;
    }
    $body = json_encode([
        'license_key'          => $legacyKey,
        'instance_id'          => $instanceId,
        'hardware_fingerprint' => $hwfpHex,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents(KEYGATE_LICENSE_SERVER . '/api/migrate', false, $ctx);
    if ($resp === false) {
        jsonResponse(['success' => false, 'error' => 'Could not reach license server']);
        return;
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        jsonResponse(['success' => false, 'error' => $decoded['error'] ?? 'Migration failed']);
        return;
    }

    // Auto-register the freshly-issued RS256 JWT.
    $regResult = registerLicense($pdo, $decoded['license_key']);
    jsonResponse(array_merge($regResult, [
        'license_key' => $decoded['license_key'],
        'expires_at'  => $decoded['expires_at'] ?? null,
    ]));
}

// ── Re-detect server hardware fingerprint (P1) ──
//
// Body: {} — admin clicks "Re-detect hardware" button. Force-recomputes
// the server hwfp helper (shells out for machine-id / system-uuid / MAC /
// volume UUID), caches the new result, and returns the components.
//
// Does NOT change the license-bound fingerprint — that requires /api/rebind.
function handle_license_redetect_hw(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    try {
        $hwfp = getServerHardwareFingerprint($pdo, true);
    } catch (Exception $e) {
        error_log("KeyGate redetect: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Hardware detection failed']);
        return;
    }

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'LICENSE_HW_REDETECTED',
        'Re-detected server hardware fingerprint'
    );

    jsonResponse([
        'success'    => true,
        'fingerprint'=> (string)($hwfp['composite'] ?? ''),
        'components' => $hwfp['components'] ?? [],
        'computed_at'=> $hwfp['computed_at'] ?? null,
    ]);
}

// ── Rebind license to new hardware (P1) ──
//
// Body: { reason? }
// Calls Worker /api/rebind with current license_key, instance_id, and the
// freshly-detected hardware fingerprint. Worker enforces quota (3 per
// rolling 365 days), mints a new RS256 JWT, returns rebind counters.
// On success applyRebindResponse() updates the local row.
function handle_license_rebind(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $current = getCurrentLicense($pdo);
    if (!$current || empty($current['license_key'])) {
        jsonResponse(['success' => false, 'error' => 'No active license to rebind']);
        return;
    }

    // Always re-detect when rebinding — admin's intent is "the hardware just changed".
    $hwfp = getServerHardwareFingerprint($pdo, true);
    $hwfpHex = (string)($hwfp['composite'] ?? '');
    if ($hwfpHex === '') {
        jsonResponse(['success' => false, 'error' => 'Could not compute new server hardware fingerprint']);
        return;
    }

    $reason = trim((string)($json_input['reason'] ?? ''));
    $body = json_encode([
        'license_key'              => $current['license_key'],
        'instance_id'              => $current['instance_id'],
        'new_hardware_fingerprint' => $hwfpHex,
        'reason'                   => $reason ?: null,
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $body,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents(KEYGATE_LICENSE_SERVER . '/api/rebind', false, $ctx);
    if ($resp === false) {
        jsonResponse(['success' => false, 'error' => 'Could not reach license server']);
        return;
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        jsonResponse([
            'success' => false,
            'error'   => $decoded['error'] ?? 'Rebind failed',
            'quota_window_days'   => $decoded['quota_window_days']   ?? null,
            'quota_limit'         => $decoded['quota_limit']         ?? null,
            'retry_after_iso'     => $decoded['retry_after_iso']     ?? null,
        ]);
        return;
    }

    $apply = applyRebindResponse(
        $pdo,
        $decoded['license_key'],
        (int)($decoded['rebind_count'] ?? 0)
    );
    if (!$apply['success']) {
        jsonResponse(['success' => false, 'error' => $apply['error'] ?? 'Local rebind apply failed']);
        return;
    }

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'LICENSE_REBOUND',
        "Rebound license to new hardware fingerprint (count: {$apply['rebind_count']}, reason: " . ($reason ?: 'unspecified') . ')'
    );

    jsonResponse([
        'success'                => true,
        'tier'                   => $apply['tier'],
        'rebind_count'           => $apply['rebind_count'],
        'rebind_quota_remaining' => $decoded['rebind_quota_remaining'] ?? null,
        'rebind_quota_limit'     => $decoded['rebind_quota_limit']     ?? 3,
        'message'                => 'License rebound successfully',
    ]);
}
