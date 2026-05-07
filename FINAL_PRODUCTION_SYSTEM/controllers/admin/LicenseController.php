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
        ],
        'usage' => [
            'technicians' => $techCount,
            'keys'        => $keyCount,
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

    $body = json_encode([
        'tier'        => $tier,
        'email'       => 'dev@localhost',
        'instance_id' => $instanceId,
        'dev_token'   => $devToken,
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
    $body = json_encode([
        'email'         => $email,
        'instance_id'   => $instanceId,
        'sponsor_login' => $sponsorLogin ?: null,
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
    $body = json_encode([
        'license_key' => $legacyKey,
        'instance_id' => $instanceId,
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
