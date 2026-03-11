<?php
/**
 * Security Controller - 2FA Status & Trusted Networks
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_get_2fa_status(PDO $pdo, array $admin_session): void {
    // Check if admin_totp_secrets table exists
    try {
        $stmt = $pdo->prepare("
            SELECT totp_enabled, verified_at, backup_codes
            FROM admin_totp_secrets
            WHERE admin_id = ?
        ");
        $stmt->execute([$admin_session['admin_id']]);
        $totp = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table may not exist yet
        $totp = null;
    }

    if ($totp) {
        // Count backup codes from JSON array (MariaDB-compatible)
        $backupCount = 0;
        if (!empty($totp['backup_codes'])) {
            $codes = json_decode($totp['backup_codes'], true);
            $backupCount = is_array($codes) ? count($codes) : 0;
        }

        echo json_encode([
            'success' => true,
            'enabled' => (bool)$totp['totp_enabled'],
            'verified_at' => $totp['verified_at'],
            'backup_codes_remaining' => $backupCount
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'enabled' => false,
            'verified_at' => null,
            'backup_codes_remaining' => 0
        ]);
    }
}

function handle_list_trusted_networks(PDO $pdo, array $admin_session): void {
    requirePermission('manage_trusted_networks', $admin_session);

    $stmt = $pdo->query("
        SELECT tn.*, au.username as created_by_username
        FROM trusted_networks tn
        LEFT JOIN admin_users au ON tn.created_by_admin_id = au.id
        ORDER BY tn.created_at DESC
    ");
    $networks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'networks' => $networks]);
}

function handle_add_trusted_network(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_trusted_networks', $admin_session);

    $networkName = $json_input['network_name'] ?? '';
    $ipRange = $json_input['ip_range'] ?? '';
    $bypass2FA = intval($json_input['bypass_2fa'] ?? 1);
    $allowUSBAuth = intval($json_input['allow_usb_auth'] ?? 1);
    $description = $json_input['description'] ?? null;

    if (empty($networkName) || empty($ipRange)) {
        echo json_encode(['success' => false, 'error' => 'Network name and IP range required']);
        return;
    }

    // Validate CIDR format
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(\/\d{1,2})?$/', $ipRange)) {
        echo json_encode(['success' => false, 'error' => 'Invalid CIDR format (use 192.168.1.0/24)']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO trusted_networks (
            network_name, ip_range, bypass_2fa, allow_usb_auth, description, created_by_admin_id
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$networkName, $ipRange, $bypass2FA, $allowUSBAuth, $description, $admin_session['admin_id']]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'ADD_TRUSTED_NETWORK',
        "Added trusted network: $networkName ($ipRange)"
    );

    echo json_encode(['success' => true, 'network_id' => $pdo->lastInsertId()]);
}

function handle_delete_trusted_network(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_trusted_networks', $admin_session);

    $networkId = intval($json_input['network_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT network_name FROM trusted_networks WHERE id = ?");
    $stmt->execute([$networkId]);
    $network = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$network) {
        echo json_encode(['success' => false, 'error' => 'Network not found']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM trusted_networks WHERE id = ?");
    $stmt->execute([$networkId]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DELETE_TRUSTED_NETWORK',
        "Deleted trusted network: {$network['network_name']} (ID: $networkId)"
    );

    echo json_encode(['success' => true]);
}
