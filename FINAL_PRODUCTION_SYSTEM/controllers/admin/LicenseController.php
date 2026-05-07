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

// ── Generate Development License (dev/testing only) ──

function handle_license_generate_dev(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    // Only allow in non-production environments
    $isProduction = !in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'activate.local']);
    if ($isProduction) {
        jsonResponse(['success' => false, 'error' => 'Dev license generation is only available in development environments']);
        return;
    }

    $tier = $json_input['tier'] ?? 'pro';
    if (!isset(LICENSE_TIERS[$tier])) {
        jsonResponse(['success' => false, 'error' => 'Invalid tier']);
        return;
    }

    $instanceId = getInstanceId($pdo);
    $tierDef = LICENSE_TIERS[$tier];

    $payload = [
        'iss'              => 'keygate-dev',
        'tier'             => $tier,
        'instance_id'      => $instanceId,
        'email'            => 'dev@localhost',
        'name'             => 'Development License',
        'max_technicians'  => $tierDef['max_technicians'],
        'max_keys'         => $tierDef['max_keys'],
        'iat'              => time(),
        'exp'              => time() + (365 * 86400), // 1 year
    ];

    $jwt = createLicenseJwt($payload);

    jsonResponse([
        'success'     => true,
        'license_key' => $jwt,
        'tier'        => $tier,
        'instance_id' => $instanceId,
        'message'     => "Development {$tierDef['label']} license generated. Paste it into the registration field.",
    ]);
}
