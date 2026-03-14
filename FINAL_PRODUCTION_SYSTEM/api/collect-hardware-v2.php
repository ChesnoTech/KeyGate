<?php
/**
 * Hardware Collection API v2.0
 *
 * Collects hardware information immediately after technician login
 * Works regardless of activation status (success, failure, or already activated)
 *
 * Called at the start of the activation process to gather complete system specs
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$data = ApiMiddleware::bootstrap('collect-hardware', ['session_token', 'order_number'], [
    'rate_limit' => false,
    'require_powershell' => false,
]);

$sessionToken = $data['session_token'];
$orderNumber = $data['order_number'];

// Validate order number format against admin-configured rules
ApiMiddleware::validateOrderNumber($orderNumber);

try {
    // Validate session token and get technician info
    $stmt = $pdo->prepare("
        SELECT s.technician_id, t.full_name, s.expires_at
        FROM active_sessions s
        INNER JOIN technicians t ON s.technician_id = t.technician_id
        WHERE s.session_token = ?
          AND s.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        jsonResponse(['success' => false, 'error' => 'Invalid or expired session token'], 401);
    }

    $technicianId = $session['technician_id'];

    // Check if hardware info already exists for this order number
    $stmt = $pdo->prepare("
        SELECT id, collection_timestamp
        FROM hardware_info
        WHERE order_number = ?
        ORDER BY collection_timestamp DESC
        LIMIT 1
    ");
    $stmt->execute([$orderNumber]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Hardware already collected for this order
        $collectionTime = new DateTime($existing['collection_timestamp']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $collectionTime->getTimestamp();

        // If collected within last 5 minutes, return existing data
        if ($diff < 300) {
            jsonResponse([
                'success' => true,
                'message' => 'Hardware information already collected for this order',
                'duplicate' => true,
                'hardware_id' => $existing['id'],
                'collected_ago_seconds' => $diff
            ]);
        }
    }

    // Extract hardware data from request
    $hardwareData = [
        'activation_id' => null,  // Will be set later if activation occurs
        'order_number' => $orderNumber,
        'technician_id' => $technicianId,
        'session_token' => $sessionToken,

        // Motherboard
        'motherboard_manufacturer' => $data['motherboard_manufacturer'] ?? null,
        'motherboard_product' => $data['motherboard_product'] ?? null,
        'motherboard_serial' => $data['motherboard_serial'] ?? null,
        'motherboard_version' => $data['motherboard_version'] ?? null,

        // BIOS
        'bios_manufacturer' => $data['bios_manufacturer'] ?? null,
        'bios_version' => $data['bios_version'] ?? null,
        'bios_release_date' => $data['bios_release_date'] ?? null,
        'bios_serial_number' => $data['bios_serial_number'] ?? null,

        // CPU
        'cpu_name' => $data['cpu_name'] ?? null,
        'cpu_manufacturer' => $data['cpu_manufacturer'] ?? null,
        'cpu_cores' => $data['cpu_cores'] ?? null,
        'cpu_logical_processors' => $data['cpu_logical_processors'] ?? null,
        'cpu_max_clock_speed' => $data['cpu_max_clock_speed'] ?? null,

        // RAM
        'ram_total_capacity_gb' => $data['ram_total_capacity_gb'] ?? null,
        'ram_slots_used' => $data['ram_slots_used'] ?? null,
        'ram_slots_total' => $data['ram_slots_total'] ?? null,
        'ram_modules' => $data['ram_modules'] ?? null,  // JSON

        // Video
        'video_cards' => $data['video_cards'] ?? null,  // JSON

        // Storage
        'storage_devices' => $data['storage_devices'] ?? null,  // JSON
        'disk_partitions' => $data['disk_partitions'] ?? null,  // JSON (legacy)
        'complete_disk_layout' => $data['complete_disk_layout'] ?? null,  // JSON (new)

        // OS
        'os_name' => $data['os_name'] ?? null,
        'os_version' => $data['os_version'] ?? null,
        'os_architecture' => $data['os_architecture'] ?? null,
        'secure_boot_enabled' => isset($data['secure_boot_enabled']) ? (int)$data['secure_boot_enabled'] : null,
        'computer_name' => $data['computer_name'] ?? null,

        // Boot order & HackBGRT (QC compliance)
        'boot_order' => $data['boot_order'] ?? null,
        'hackbgrt_installed' => isset($data['hackbgrt_installed']) ? (int)$data['hackbgrt_installed'] : null,
        'hackbgrt_first_boot' => isset($data['hackbgrt_first_boot']) ? (int)$data['hackbgrt_first_boot'] : null,

        // Driver status (QC compliance)
        'missing_drivers' => $data['missing_drivers'] ?? null,  // JSON array of problem devices
        'missing_drivers_count' => isset($data['missing_drivers_count']) ? (int)$data['missing_drivers_count'] : null,
    ];

    // Insert hardware information
    $stmt = $pdo->prepare("
        INSERT INTO hardware_info (
            activation_id, order_number, technician_id, session_token,
            motherboard_manufacturer, motherboard_product, motherboard_serial, motherboard_version,
            bios_manufacturer, bios_version, bios_release_date, bios_serial_number,
            cpu_name, cpu_manufacturer, cpu_cores, cpu_logical_processors, cpu_max_clock_speed,
            ram_total_capacity_gb, ram_slots_used, ram_slots_total, ram_modules,
            video_cards, storage_devices, disk_partitions, complete_disk_layout,
            os_name, os_version, os_architecture, secure_boot_enabled, computer_name,
            boot_order, hackbgrt_installed, hackbgrt_first_boot,
            missing_drivers, missing_drivers_count,
            collection_timestamp
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?,
            NOW()
        )
    ");

    $stmt->execute([
        $hardwareData['activation_id'],
        $hardwareData['order_number'],
        $hardwareData['technician_id'],
        $hardwareData['session_token'],

        $hardwareData['motherboard_manufacturer'],
        $hardwareData['motherboard_product'],
        $hardwareData['motherboard_serial'],
        $hardwareData['motherboard_version'],

        $hardwareData['bios_manufacturer'],
        $hardwareData['bios_version'],
        $hardwareData['bios_release_date'],
        $hardwareData['bios_serial_number'],

        $hardwareData['cpu_name'],
        $hardwareData['cpu_manufacturer'],
        $hardwareData['cpu_cores'],
        $hardwareData['cpu_logical_processors'],
        $hardwareData['cpu_max_clock_speed'],

        $hardwareData['ram_total_capacity_gb'],
        $hardwareData['ram_slots_used'],
        $hardwareData['ram_slots_total'],
        $hardwareData['ram_modules'],

        $hardwareData['video_cards'],
        $hardwareData['storage_devices'],
        $hardwareData['disk_partitions'],
        $hardwareData['complete_disk_layout'],

        $hardwareData['os_name'],
        $hardwareData['os_version'],
        $hardwareData['os_architecture'],
        $hardwareData['secure_boot_enabled'],
        $hardwareData['computer_name'],

        $hardwareData['boot_order'],
        $hardwareData['hackbgrt_installed'],
        $hardwareData['hackbgrt_first_boot'],

        $hardwareData['missing_drivers'],
        $hardwareData['missing_drivers_count']
    ]);

    $hardwareId = $pdo->lastInsertId();

    // Log the collection attempt
    $stmt = $pdo->prepare("
        INSERT INTO hardware_collection_log (
            order_number, technician_id, session_token, hardware_info_id, collection_status
        ) VALUES (?, ?, ?, ?, 'success')
    ");
    $stmt->execute([$orderNumber, $technicianId, $sessionToken, $hardwareId]);

    // Run QC compliance checks if enabled
    $complianceResults = null;
    try {
        require_once __DIR__ . '/../functions/qc-compliance.php';
        if (qcIsEnabled($pdo)) {
            $complianceResults = qcRunChecks($pdo, $hardwareId, $hardwareData);
        }
    } catch (Exception $qcError) {
        error_log("QC compliance check error: " . $qcError->getMessage());
    }

    $response = [
        'success' => true,
        'message' => 'Hardware information collected successfully',
        'hardware_id' => $hardwareId,
        'technician' => $session['full_name']
    ];
    if ($complianceResults !== null) {
        $response['compliance'] = $complianceResults;
    }
    jsonResponse($response);

} catch (PDOException $e) {
    // Log error
    error_log("Hardware collection error: " . $e->getMessage());

    // Try to log the failed attempt
    try {
        if (isset($technicianId) && isset($orderNumber) && isset($sessionToken)) {
            $stmt = $pdo->prepare("
                INSERT INTO hardware_collection_log (
                    order_number, technician_id, session_token, hardware_info_id,
                    collection_status, error_message
                ) VALUES (?, ?, ?, NULL, 'failed', ?)
            ");
            $stmt->execute([$orderNumber, $technicianId, $sessionToken, $e->getMessage()]);
        }
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    jsonResponse([
        'success' => false,
        'error' => 'Database error occurred while collecting hardware information'
    ], 500);
}
?>
