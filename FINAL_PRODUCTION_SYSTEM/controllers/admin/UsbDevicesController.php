<?php
/**
 * USB Devices Controller - USB Device Registration & Management
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_list_usb_devices(PDO $pdo, array $admin_session): void {
    $filterTech = $_GET['technician_id'] ?? '';
    $filterStatus = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');

    $where = [];
    $params = [];

    if (!empty($filterTech)) {
        $where[] = "d.technician_id = ?";
        $params[] = $filterTech;
    }

    if (!empty($filterStatus)) {
        $where[] = "d.device_status = ?";
        $params[] = $filterStatus;
    }

    if (!empty($search)) {
        $where[] = "(d.device_name LIKE ? OR d.device_serial_number LIKE ? OR d.device_manufacturer LIKE ? OR d.device_model LIKE ? OR t.full_name LIKE ? OR d.technician_id LIKE ?)";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT d.*, t.full_name
        FROM usb_devices d
        INNER JOIN technicians t ON d.technician_id = t.technician_id
        $whereClause
        ORDER BY d.registered_date DESC
    ");
    $stmt->execute($params);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get USB device statistics
    $stmt = $pdo->query("
        SELECT device_status, COUNT(*) as count
        FROM usb_devices
        GROUP BY device_status
    ");
    $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'success' => true,
        'devices' => $devices,
        'stats' => [
            'active' => $statusCounts['active'] ?? 0,
            'disabled' => $statusCounts['disabled'] ?? 0,
            'lost' => $statusCounts['lost'] ?? 0,
            'stolen' => $statusCounts['stolen'] ?? 0,
            'total' => array_sum($statusCounts)
        ]
    ]);
}

function handle_register_usb_device(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('register_usb_device', $admin_session);

    $technicianId = $json_input['technician_id'] ?? '';
    $deviceName = $json_input['device_name'] ?? '';
    $deviceSerial = $json_input['device_serial_number'] ?? '';
    $deviceManufacturer = $json_input['device_manufacturer'] ?? null;
    $deviceModel = $json_input['device_model'] ?? null;
    $deviceCapacityGB = $json_input['device_capacity_gb'] ?? null;
    $deviceDescription = $json_input['device_description'] ?? null;

    // Validate required fields
    if (empty($technicianId) || empty($deviceName) || empty($deviceSerial)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        return;
    }

    // Check if technician exists and is active
    $stmt = $pdo->prepare("SELECT technician_id, is_active FROM technicians WHERE technician_id = ?");
    $stmt->execute([$technicianId]);
    $technician = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$technician) {
        echo json_encode(['success' => false, 'error' => 'Technician not found']);
        return;
    }

    if (!$technician['is_active']) {
        echo json_encode(['success' => false, 'error' => 'Cannot register USB device for inactive technician']);
        return;
    }

    // Check if serial number already exists
    $stmt = $pdo->prepare("SELECT device_id, device_name FROM usb_devices WHERE device_serial_number = ?");
    $stmt->execute([$deviceSerial]);
    $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingDevice) {
        echo json_encode([
            'success' => false,
            'error' => "USB device with this serial number already registered: {$existingDevice['device_name']}"
        ]);
        return;
    }

    // Check max devices per technician limit
    $maxDevices = (int)getConfig('usb_auth_max_devices_per_tech');
    if ($maxDevices > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usb_devices WHERE technician_id = ? AND device_status = 'active'");
        $stmt->execute([$technicianId]);
        $currentCount = $stmt->fetchColumn();

        if ($currentCount >= $maxDevices) {
            echo json_encode([
                'success' => false,
                'error' => "Technician has reached maximum allowed USB devices ($maxDevices)"
            ]);
            return;
        }
    }

    // Insert new USB device
    $stmt = $pdo->prepare("
        INSERT INTO usb_devices (
            device_serial_number, device_name, technician_id,
            device_manufacturer, device_model, device_capacity_gb,
            device_description, registered_by_admin_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $deviceSerial, $deviceName, $technicianId,
        $deviceManufacturer, $deviceModel, $deviceCapacityGB,
        $deviceDescription, $admin_session['admin_id']
    ]);

    $deviceId = $pdo->lastInsertId();

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'REGISTER_USB_DEVICE',
        "Registered USB device '$deviceName' (ID: $deviceId) for technician $technicianId"
    );

    echo json_encode([
        'success' => true,
        'device_id' => $deviceId,
        'message' => 'USB device registered successfully'
    ]);
}

function handle_update_usb_device_status(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('disable_usb_device', $admin_session);

    $deviceId = intval($json_input['device_id'] ?? 0);
    $newStatus = $json_input['status'] ?? '';

    // Validate status
    $validStatuses = ['active', 'disabled', 'lost', 'stolen'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        return;
    }

    // Get device info before update
    $stmt = $pdo->prepare("SELECT device_name, technician_id FROM usb_devices WHERE device_id = ?");
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        echo json_encode(['success' => false, 'error' => 'USB device not found']);
        return;
    }

    // Update device status
    if ($newStatus === 'active') {
        $stmt = $pdo->prepare("
            UPDATE usb_devices
            SET device_status = 'active',
                disabled_date = NULL,
                disabled_by_admin_id = NULL,
                disabled_reason = NULL
            WHERE device_id = ?
        ");
        $stmt->execute([$deviceId]);
    } else {
        $disableReason = $json_input['reason'] ?? null;

        $stmt = $pdo->prepare("
            UPDATE usb_devices
            SET device_status = ?,
                disabled_date = NOW(),
                disabled_by_admin_id = ?,
                disabled_reason = ?
            WHERE device_id = ?
        ");
        $stmt->execute([$newStatus, $admin_session['admin_id'], $disableReason, $deviceId]);
    }

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_USB_DEVICE_STATUS',
        "Changed USB device '{$device['device_name']}' (ID: $deviceId) status to '$newStatus'"
    );

    echo json_encode([
        'success' => true,
        'message' => "USB device status updated to '$newStatus'"
    ]);
}

function handle_delete_usb_device(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('delete_usb_device', $admin_session);

    $deviceId = intval($json_input['device_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT device_name, technician_id FROM usb_devices WHERE device_id = ?");
    $stmt->execute([$deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        echo json_encode(['success' => false, 'error' => 'USB device not found']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM usb_devices WHERE device_id = ?");
    $stmt->execute([$deviceId]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DELETE_USB_DEVICE',
        "Deleted USB device '{$device['device_name']}' (ID: $deviceId) from technician {$device['technician_id']}"
    );

    echo json_encode([
        'success' => true,
        'message' => 'USB device deleted successfully'
    ]);
}
