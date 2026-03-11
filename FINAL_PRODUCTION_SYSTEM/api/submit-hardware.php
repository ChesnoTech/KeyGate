<?php
/**
 * Hardware Information Submission API
 *
 * Endpoint: api/submit-hardware.php
 * Method: POST
 * Content-Type: application/json
 *
 * Purpose: Receives hardware information collected from activated PCs
 *
 * Request Body:
 * {
 *   "session_token": "...",
 *   "order_number": "...",
 *   "motherboard_manufacturer": "...",
 *   "motherboard_product": "...",
 *   // ... all other hardware fields
 * }
 *
 * Response:
 * {
 *   "success": true/false,
 *   "message": "...",
 *   "error": "..." (if failed)
 * }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$data = ApiMiddleware::bootstrap('submit-hardware', ['session_token'], [
    'rate_limit' => RATE_LIMIT_SUBMIT_HARDWARE,
    'require_powershell' => false,
]);

$sessionToken = $data['session_token'];
$orderNumber = $data['order_number'] ?? '';

if (empty($sessionToken) || empty($orderNumber)) {
    jsonResponse(['success' => false, 'error' => 'Missing required fields: session_token and order_number'], 400);
}

// Validate order number format against admin-configured rules
ApiMiddleware::validateOrderNumber($orderNumber);

try {
    // Validate session token and get activation_id
    $stmt = $pdo->prepare("
        SELECT aa.id as activation_id, aa.technician_id, aa.key_id
        FROM activation_attempts aa
        INNER JOIN active_sessions s ON s.technician_id = aa.technician_id
        WHERE s.session_token = ?
          AND aa.order_number = ?
          AND aa.attempt_result = 'success'
          AND s.expires_at > NOW()
        ORDER BY aa.attempted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$sessionToken, $orderNumber]);
    $activation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activation) {
        jsonResponse(['success' => false, 'error' => 'Invalid session or no matching activation found'], 401);
    }

    $activationId = $activation['activation_id'];

    // Check if hardware info already exists for this activation
    $stmt = $pdo->prepare("SELECT id FROM hardware_info WHERE activation_id = ?");
    $stmt->execute([$activationId]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => true, 'message' => 'Hardware information already recorded', 'duplicate' => true]);
    }

    // Wrap multi-step DB operations in a transaction for atomicity
    $pdo->beginTransaction();

    // Insert hardware information
    $stmt = $pdo->prepare("
        INSERT INTO hardware_info (
            activation_id,
            order_number,
            motherboard_manufacturer,
            motherboard_product,
            motherboard_serial,
            motherboard_version,
            bios_manufacturer,
            bios_version,
            bios_release_date,
            bios_serial_number,
            cpu_name,
            cpu_manufacturer,
            cpu_cores,
            cpu_logical_processors,
            cpu_max_clock_speed,
            cpu_serial,
            ram_total_capacity_gb,
            ram_slots_used,
            ram_slots_total,
            ram_modules,
            video_cards,
            storage_devices,
            disk_partitions,
            os_name,
            os_version,
            os_architecture,
            os_build_number,
            os_install_date,
            os_serial_number,
            secure_boot_enabled,
            computer_name,
            chassis_manufacturer,
            chassis_serial,
            chassis_type,
            system_manufacturer,
            system_product_name,
            system_serial,
            system_uuid,
            tpm_present,
            tpm_version,
            tpm_manufacturer,
            primary_mac_address,
            local_ip,
            public_ip,
            network_adapters,
            audio_devices,
            monitors,
            device_fingerprint,
            collected_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $activationId,
        $orderNumber,
        $data['motherboard_manufacturer'] ?? null,
        $data['motherboard_product'] ?? null,
        $data['motherboard_serial'] ?? null,
        $data['motherboard_version'] ?? null,
        $data['bios_manufacturer'] ?? null,
        $data['bios_version'] ?? null,
        $data['bios_release_date'] ?? null,
        $data['bios_serial_number'] ?? null,
        $data['cpu_name'] ?? null,
        $data['cpu_manufacturer'] ?? null,
        $data['cpu_cores'] ?? null,
        $data['cpu_logical_processors'] ?? null,
        $data['cpu_max_clock_speed'] ?? null,
        $data['cpu_serial'] ?? null,
        $data['ram_total_capacity_gb'] ?? null,
        $data['ram_slots_used'] ?? null,
        $data['ram_slots_total'] ?? null,
        $data['ram_modules'] ?? null,
        $data['video_cards'] ?? null,
        $data['storage_devices'] ?? null,
        $data['disk_partitions'] ?? null,
        $data['os_name'] ?? null,
        $data['os_version'] ?? null,
        $data['os_architecture'] ?? null,
        $data['os_build_number'] ?? null,
        $data['os_install_date'] ?? null,
        $data['os_serial_number'] ?? null,
        $data['secure_boot_enabled'] ?? null,
        $data['computer_name'] ?? null,
        $data['chassis_manufacturer'] ?? null,
        $data['chassis_serial'] ?? null,
        $data['chassis_type'] ?? null,
        $data['system_manufacturer'] ?? null,
        $data['system_product_name'] ?? null,
        $data['system_serial'] ?? null,
        $data['system_uuid'] ?? null,
        $data['tpm_present'] ?? null,
        $data['tpm_version'] ?? null,
        $data['tpm_manufacturer'] ?? null,
        $data['primary_mac_address'] ?? null,
        $data['local_ip'] ?? null,
        $data['public_ip'] ?? null,
        $data['network_adapters'] ?? null,
        $data['audio_devices'] ?? null,
        $data['monitors'] ?? null,
        $data['device_fingerprint'] ?? null
    ]);

    // Update activation_attempts to mark hardware as collected
    $stmt = $pdo->prepare("UPDATE activation_attempts SET hardware_collected = 1 WHERE id = ?");
    $stmt->execute([$activationId]);

    $hardwareId = $pdo->lastInsertId();
    $pdo->commit();

    // Dispatch integration event (non-blocking — errors are logged, not thrown)
    try {
        require_once __DIR__ . '/../functions/integration-helpers.php';
        dispatchEventToAll('activation_complete', [
            'order_number'      => $orderNumber,
            'technician_id'     => $activation['technician_id'],
            'technician_name'   => $activation['technician_id'],
            'activation_result' => 'success',
            'hardware'          => [
                'motherboard_manufacturer' => $data['motherboard_manufacturer'] ?? '',
                'motherboard_product'      => $data['motherboard_product'] ?? '',
                'bios_version'             => $data['bios_version'] ?? '',
                'cpu_name'                 => $data['cpu_name'] ?? '',
                'total_ram_gb'             => $data['ram_total_capacity_gb'] ?? '',
            ],
        ]);
    } catch (Exception $intgErr) {
        error_log("Integration dispatch error (non-fatal): " . $intgErr->getMessage());
    }

    jsonResponse([
        'success' => true,
        'message' => 'Hardware information recorded successfully',
        'hardware_id' => $hardwareId
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Hardware submission error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Database error occurred'], 500);
}
