<?php
/**
 * Production Controller — Enterprise Key Management & Tracking
 *
 * Features:
 *   1. CBR (Computer Build Reports) — structured per-machine reports
 *   2. Key Pool Management — inventory alerts & replenishment
 *   3. Hardware Binding — detect key reuse on different hardware
 *   4. DPK Batch Import — bulk key import from Microsoft deliveries
 *   5. Work Orders — production line tracking with shipping
 */

// ═══════════════════════════════════════════════════════════
// 1. COMPUTER BUILD REPORTS (CBR)
// ═══════════════════════════════════════════════════════════

function handle_list_build_reports(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_activations', $admin_session);

    $limit = min(100, max(1, (int)($json_input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = max(0, (int)($json_input['offset'] ?? $_GET['offset'] ?? 0));
    $status = $json_input['status'] ?? $_GET['status'] ?? '';
    $search = trim($json_input['search'] ?? $_GET['search'] ?? '');
    $workOrderId = (int)($json_input['work_order_id'] ?? $_GET['work_order_id'] ?? 0);

    $where = [];
    $params = [];

    if ($status && in_array($status, ['activated', 'failed', 'pending', 'not_attempted'])) {
        $where[] = 'activation_status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where[] = '(order_number LIKE ? OR motherboard_serial LIKE ? OR customer_name LIKE ? OR report_uuid LIKE ?)';
        $s = "%{$search}%";
        $params = array_merge($params, [$s, $s, $s, $s]);
    }
    if ($workOrderId > 0) {
        $where[] = 'work_order_id = ?';
        $params[] = $workOrderId;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT id, report_uuid, order_number, batch_number, device_fingerprint,
               motherboard_manufacturer, motherboard_model, motherboard_serial,
               product_key_masked, product_edition, activation_status, activation_timestamp,
               cpu_model, ram_total_gb, gpu_model, os_version,
               qc_passed, product_line_name, technician_name,
               shipping_status, customer_name, customer_order_ref,
               created_at
        FROM computer_build_reports
        {$whereClause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    // Total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM computer_build_reports {$whereClause}");
    $countStmt->execute(array_slice($params, 0, -2));
    $total = (int)$countStmt->fetchColumn();

    jsonResponse(['success' => true, 'reports' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total]);
}

function handle_get_build_report(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_activations', $admin_session);

    $id = (int)($json_input['id'] ?? $_GET['id'] ?? 0);
    $uuid = $json_input['uuid'] ?? $_GET['uuid'] ?? '';

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM computer_build_reports WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($uuid) {
        $stmt = $pdo->prepare("SELECT * FROM computer_build_reports WHERE report_uuid = ?");
        $stmt->execute([$uuid]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Report ID or UUID required']);
        return;
    }

    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        jsonResponse(['success' => false, 'error' => 'Report not found']);
        return;
    }

    jsonResponse(['success' => true, 'report' => $report]);
}

function handle_export_build_report(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_activations', $admin_session);

    $id = (int)($json_input['id'] ?? $_GET['id'] ?? 0);
    $format = $json_input['format'] ?? $_GET['format'] ?? 'json';

    $stmt = $pdo->prepare("SELECT * FROM computer_build_reports WHERE id = ?");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        jsonResponse(['success' => false, 'error' => 'Report not found']);
        return;
    }

    if ($format === 'xml') {
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="CBR_' . $report['report_uuid'] . '.xml"');
        echo generateCBRXml($report);
        exit;
    }

    // JSON export
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="CBR_' . $report['report_uuid'] . '.json"');
    echo json_encode([
        'cbr_version' => '1.0',
        'generated_at' => gmdate('c'),
        'generator' => 'KeyGate v' . (defined('APP_VERSION') ? APP_VERSION : '2.0'),
        'report' => $report,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function generateCBRXml(array $report): string {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ComputerBuildReport/>');
    $xml->addAttribute('version', '1.0');
    $xml->addAttribute('generator', 'KeyGate');

    $meta = $xml->addChild('Metadata');
    $meta->addChild('ReportUUID', htmlspecialchars($report['report_uuid']));
    $meta->addChild('GeneratedAt', gmdate('c'));
    $meta->addChild('OrderNumber', htmlspecialchars($report['order_number'] ?? ''));
    $meta->addChild('BatchNumber', htmlspecialchars($report['batch_number'] ?? ''));

    $hw = $xml->addChild('Hardware');
    $hw->addChild('DeviceFingerprint', htmlspecialchars($report['device_fingerprint'] ?? ''));
    $hw->addChild('SystemUUID', htmlspecialchars($report['system_uuid'] ?? ''));
    $mb = $hw->addChild('Motherboard');
    $mb->addChild('Manufacturer', htmlspecialchars($report['motherboard_manufacturer'] ?? ''));
    $mb->addChild('Model', htmlspecialchars($report['motherboard_model'] ?? ''));
    $mb->addChild('Serial', htmlspecialchars($report['motherboard_serial'] ?? ''));
    $hw->addChild('CPU', htmlspecialchars($report['cpu_model'] ?? ''));
    $hw->addChild('RAM_GB', $report['ram_total_gb'] ?? '0');
    $hw->addChild('GPU', htmlspecialchars($report['gpu_model'] ?? ''));
    $hw->addChild('Storage_GB', $report['storage_total_gb'] ?? '0');

    $act = $xml->addChild('Activation');
    $act->addChild('ProductKey', htmlspecialchars($report['product_key_masked'] ?? ''));
    $act->addChild('Edition', htmlspecialchars($report['product_edition'] ?? ''));
    $act->addChild('Status', $report['activation_status']);
    $act->addChild('Method', htmlspecialchars($report['activation_method'] ?? ''));
    $act->addChild('Timestamp', $report['activation_timestamp'] ?? '');

    $qc = $xml->addChild('QualityControl');
    $qc->addChild('Passed', $report['qc_passed'] ? 'true' : 'false');
    $qc->addChild('SecureBoot', $report['qc_secure_boot'] ? 'true' : 'false');
    $qc->addChild('TPMPresent', $report['qc_tpm_present'] ? 'true' : 'false');
    $qc->addChild('HackBGRTClean', $report['qc_hackbgrt_clean'] ? 'true' : 'false');

    $prod = $xml->addChild('Production');
    $prod->addChild('ProductLine', htmlspecialchars($report['product_line_name'] ?? ''));
    $prod->addChild('Technician', htmlspecialchars($report['technician_name'] ?? ''));
    $prod->addChild('Station', htmlspecialchars($report['station_name'] ?? ''));

    $ship = $xml->addChild('Shipping');
    $ship->addChild('Status', $report['shipping_status']);
    $ship->addChild('Tracking', htmlspecialchars($report['shipping_tracking'] ?? ''));
    $ship->addChild('Customer', htmlspecialchars($report['customer_name'] ?? ''));

    return $xml->asXML();
}

function handle_update_build_report_shipping(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $id = (int)($json_input['id'] ?? 0);
    $shippingStatus = $json_input['shipping_status'] ?? '';
    $tracking = trim($json_input['shipping_tracking'] ?? '');
    $customerName = trim($json_input['customer_name'] ?? '');
    $customerOrderRef = trim($json_input['customer_order_ref'] ?? '');

    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Report ID required']);
        return;
    }

    $validStatuses = ['building', 'testing', 'ready', 'shipped', 'returned'];
    if (!in_array($shippingStatus, $validStatuses)) {
        jsonResponse(['success' => false, 'error' => 'Invalid shipping status']);
        return;
    }

    $sets = ['shipping_status = ?', 'shipping_tracking = ?', 'customer_name = ?', 'customer_order_ref = ?'];
    $params = [$shippingStatus, $tracking, $customerName, $customerOrderRef];

    if ($shippingStatus === 'shipped') {
        $sets[] = 'shipped_at = NOW()';
    }

    $params[] = $id;
    $stmt = $pdo->prepare("UPDATE computer_build_reports SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->execute($params);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'CBR_SHIPPING_UPDATED', "Updated CBR #{$id} shipping: {$shippingStatus}");

    jsonResponse(['success' => true]);
}

// ═══════════════════════════════════════════════════════════
// 2. KEY POOL MANAGEMENT
// ═══════════════════════════════════════════════════════════

function handle_get_key_pool_status(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_keys', $admin_session);

    // Get key counts grouped by product type
    $stmt = $pdo->query("
        SELECT
            COALESCE(product_type, 'Unknown') AS product_edition,
            COUNT(*) AS total_keys,
            SUM(CASE WHEN status = 'unused' THEN 1 ELSE 0 END) AS unused_keys,
            SUM(CASE WHEN status = 'allocated' THEN 1 ELSE 0 END) AS allocated_keys,
            SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) AS used_keys,
            SUM(CASE WHEN status = 'bad' THEN 1 ELSE 0 END) AS bad_keys
        FROM oem_keys
        GROUP BY product_type
    ");
    $pools = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get pool config
    $configStmt = $pdo->query("SELECT * FROM key_pool_config ORDER BY product_edition");
    $configs = [];
    foreach ($configStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $configs[$c['product_edition']] = $c;
    }

    // Merge pool data with config
    $result = [];
    foreach ($pools as $pool) {
        $edition = $pool['product_edition'];
        $config = $configs[$edition] ?? null;
        $unused = (int)$pool['unused_keys'];

        $alertLevel = 'ok';
        if ($config) {
            if ($unused <= $config['critical_threshold']) {
                $alertLevel = 'critical';
            } elseif ($unused <= $config['low_threshold']) {
                $alertLevel = 'low';
            }
        } elseif ($unused <= 3) {
            $alertLevel = 'critical';
        } elseif ($unused <= 10) {
            $alertLevel = 'low';
        }

        $result[] = array_merge($pool, [
            'alert_level' => $alertLevel,
            'low_threshold' => $config['low_threshold'] ?? 10,
            'critical_threshold' => $config['critical_threshold'] ?? 3,
            'auto_notify' => $config['auto_notify'] ?? 1,
            'last_replenished_at' => $config['last_replenished_at'] ?? null,
        ]);
    }

    jsonResponse(['success' => true, 'pools' => $result]);
}

function handle_save_key_pool_config(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('manage_keys', $admin_session);

    $edition = trim($json_input['product_edition'] ?? '');
    $lowThreshold = (int)($json_input['low_threshold'] ?? 10);
    $criticalThreshold = (int)($json_input['critical_threshold'] ?? 3);
    $autoNotify = (int)($json_input['auto_notify'] ?? 1);
    $notifyEmail = trim($json_input['notify_email'] ?? '');

    if (empty($edition)) {
        jsonResponse(['success' => false, 'error' => 'Product edition required']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO key_pool_config (product_edition, low_threshold, critical_threshold, auto_notify, notify_email)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            low_threshold = VALUES(low_threshold),
            critical_threshold = VALUES(critical_threshold),
            auto_notify = VALUES(auto_notify),
            notify_email = VALUES(notify_email)
    ");
    $stmt->execute([$edition, $lowThreshold, $criticalThreshold, $autoNotify, $notifyEmail ?: null]);

    jsonResponse(['success' => true]);
}

// ═══════════════════════════════════════════════════════════
// 3. HARDWARE BINDING VERIFICATION
// ═══════════════════════════════════════════════════════════

function handle_check_hardware_binding(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_keys', $admin_session);

    $keyId = (int)($json_input['key_id'] ?? $_GET['key_id'] ?? 0);
    $fingerprint = $json_input['device_fingerprint'] ?? $_GET['device_fingerprint'] ?? '';

    $where = [];
    $params = [];

    if ($keyId > 0) {
        $where[] = 'hkb.product_key_id = ?';
        $params[] = $keyId;
    }
    if ($fingerprint) {
        $where[] = 'hkb.device_fingerprint LIKE ?';
        $params[] = "%{$fingerprint}%";
    }

    if (empty($where)) {
        // Return recent bindings
        $stmt = $pdo->query("
            SELECT hkb.*, ok.product_key, ok.product_type
            FROM hardware_key_bindings hkb
            LEFT JOIN oem_keys ok ON ok.id = hkb.product_key_id
            ORDER BY hkb.bound_at DESC LIMIT 50
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT hkb.*, ok.product_key, ok.product_type
            FROM hardware_key_bindings hkb
            LEFT JOIN oem_keys ok ON ok.id = hkb.product_key_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY hkb.bound_at DESC LIMIT 50
        ");
        $stmt->execute($params);
    }

    $bindings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check for conflicts (same key on multiple hardware)
    $conflicts = [];
    if ($keyId > 0) {
        $conflictStmt = $pdo->prepare("
            SELECT device_fingerprint, motherboard_serial, bound_at
            FROM hardware_key_bindings
            WHERE product_key_id = ? AND status = 'active'
        ");
        $conflictStmt->execute([$keyId]);
        $activeBindings = $conflictStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($activeBindings) > 1) {
            $conflicts = $activeBindings;
        }
    }

    jsonResponse(['success' => true, 'bindings' => $bindings, 'conflicts' => $conflicts]);
}

function handle_release_hardware_binding(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('manage_keys', $admin_session);

    $id = (int)($json_input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Binding ID required']);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE hardware_key_bindings
        SET status = 'released', released_at = NOW(), released_by_admin_id = ?
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([$admin_session['admin_id'], $id]);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'BINDING_RELEASED', "Released hardware binding #{$id}");

    jsonResponse(['success' => true]);
}

// ═══════════════════════════════════════════════════════════
// 4. DPK BATCH IMPORT
// ═══════════════════════════════════════════════════════════

function handle_import_dpk_batch(PDO $pdo, array $admin_session): void {
    requirePermission('manage_keys', $admin_session);
    set_time_limit(300);

    $batchName = $_POST['batch_name'] ?? 'Import ' . date('Y-m-d H:i');
    $productEdition = $_POST['product_edition'] ?? '';
    $importSource = $_POST['import_source'] ?? 'manual';

    if (!isset($_FILES['key_file']) || $_FILES['key_file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'Key file upload required']);
        return;
    }

    $file = $_FILES['key_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'xml'])) {
        jsonResponse(['success' => false, 'error' => 'Supported formats: CSV, TXT, XML']);
        return;
    }

    $content = file_get_contents($file['tmp_name']);
    $checksum = hash('sha256', $content);

    // Create batch record
    $batchStmt = $pdo->prepare("
        INSERT INTO dpk_import_batches
            (batch_name, import_source, product_edition, source_filename, source_checksum,
             imported_by_admin_id, imported_by_username, import_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'processing')
    ");
    $batchStmt->execute([
        $batchName, $importSource, $productEdition,
        $file['name'], $checksum,
        (int)$admin_session['admin_id'], $admin_session['username'],
    ]);
    $batchId = (int)$pdo->lastInsertId();

    // Parse keys
    $keys = [];
    if ($ext === 'xml') {
        $keys = parseDPKXml($content);
    } else {
        // CSV/TXT: one key per line, or comma-separated
        $lines = preg_split('/[\r\n]+/', $content);
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip headers and empty lines
            if (empty($line) || stripos($line, 'key') === 0 || $line[0] === '#') continue;
            // Extract product key pattern (XXXXX-XXXXX-XXXXX-XXXXX-XXXXX)
            if (preg_match('/([A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5})/', strtoupper($line), $m)) {
                $keys[] = $m[1];
            }
        }
    }

    // Import keys
    $imported = 0;
    $duplicates = 0;
    $failed = 0;

    $checkExisting = $pdo->prepare("SELECT COUNT(*) FROM oem_keys WHERE product_key = ?");
    $insertKey = $pdo->prepare("
        INSERT INTO oem_keys (product_key, product_type, status, added_by, import_batch_id)
        VALUES (?, ?, 'unused', ?, ?)
    ");

    foreach ($keys as $key) {
        try {
            $checkExisting->execute([$key]);
            if ((int)$checkExisting->fetchColumn() > 0) {
                $duplicates++;
                continue;
            }
            $insertKey->execute([$key, $productEdition, $admin_session['username'], $batchId]);
            $imported++;
        } catch (Exception $e) {
            $failed++;
            error_log("DPK import error for key: " . $e->getMessage());
        }
    }

    // Update batch record
    $pdo->prepare("
        UPDATE dpk_import_batches
        SET total_keys = ?, imported_keys = ?, duplicate_keys = ?, failed_keys = ?,
            import_status = 'completed', completed_at = NOW()
        WHERE id = ?
    ")->execute([count($keys), $imported, $duplicates, $failed, $batchId]);

    logAdminActivity(
        $admin_session['admin_id'], $admin_session['id'] ?? 0, 'DPK_BATCH_IMPORTED',
        "Imported batch '{$batchName}': {$imported} new, {$duplicates} duplicates, {$failed} failed"
    );

    // Update key pool replenishment timestamp
    if ($productEdition && $imported > 0) {
        $pdo->prepare("
            UPDATE key_pool_config SET last_replenished_at = NOW() WHERE product_edition = ?
        ")->execute([$productEdition]);
    }

    jsonResponse([
        'success' => true,
        'batch_id' => $batchId,
        'total_in_file' => count($keys),
        'imported' => $imported,
        'duplicates' => $duplicates,
        'failed' => $failed,
    ]);
}

function parseDPKXml(string $content): array {
    $keys = [];
    try {
        $xml = new SimpleXMLElement($content);
        // Microsoft OA3 XML format
        foreach ($xml->xpath('//ProductKey') as $node) {
            $key = trim((string)$node);
            if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $key)) {
                $keys[] = $key;
            }
        }
        // Generic Key element
        if (empty($keys)) {
            foreach ($xml->xpath('//Key') as $node) {
                $key = trim((string)$node);
                if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', strtoupper($key))) {
                    $keys[] = strtoupper($key);
                }
            }
        }
    } catch (Exception $e) {
        error_log("DPK XML parse error: " . $e->getMessage());
    }
    return $keys;
}

function handle_list_dpk_batches(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_keys', $admin_session);

    $stmt = $pdo->query("SELECT * FROM dpk_import_batches ORDER BY created_at DESC LIMIT 50");

    jsonResponse(['success' => true, 'batches' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ═══════════════════════════════════════════════════════════
// 5. WORK ORDERS
// ═══════════════════════════════════════════════════════════

function handle_list_work_orders(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_activations', $admin_session);

    $limit = min(100, max(1, (int)($json_input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = max(0, (int)($json_input['offset'] ?? $_GET['offset'] ?? 0));
    $status = $json_input['status'] ?? $_GET['status'] ?? '';
    $search = trim($json_input['search'] ?? $_GET['search'] ?? '');

    $where = [];
    $params = [];

    if ($status && in_array($status, ['draft', 'queued', 'in_progress', 'completed', 'shipped', 'cancelled'])) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where[] = '(work_order_number LIKE ? OR customer_name LIKE ? OR batch_number LIKE ? OR customer_order_ref LIKE ?)';
        $s = "%{$search}%";
        $params = array_merge($params, [$s, $s, $s, $s]);
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("SELECT * FROM work_orders {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute($params);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM work_orders {$whereClause}");
    $countStmt->execute(array_slice($params, 0, -2));
    $total = (int)$countStmt->fetchColumn();

    jsonResponse(['success' => true, 'work_orders' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total]);
}

function handle_save_work_order(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $id = (int)($json_input['id'] ?? 0);
    $data = [
        'work_order_number' => trim($json_input['work_order_number'] ?? ''),
        'batch_number' => trim($json_input['batch_number'] ?? '') ?: null,
        'customer_name' => trim($json_input['customer_name'] ?? '') ?: null,
        'customer_email' => trim($json_input['customer_email'] ?? '') ?: null,
        'customer_phone' => trim($json_input['customer_phone'] ?? '') ?: null,
        'customer_order_ref' => trim($json_input['customer_order_ref'] ?? '') ?: null,
        'product_line_id' => (int)($json_input['product_line_id'] ?? 0) ?: null,
        'product_line_name' => trim($json_input['product_line_name'] ?? '') ?: null,
        'quantity' => max(1, (int)($json_input['quantity'] ?? 1)),
        'status' => $json_input['status'] ?? 'draft',
        'priority' => $json_input['priority'] ?? 'normal',
        'assigned_technician_id' => (int)($json_input['assigned_technician_id'] ?? 0) ?: null,
        'due_date' => $json_input['due_date'] ?? null,
        'shipping_method' => trim($json_input['shipping_method'] ?? '') ?: null,
        'shipping_tracking' => trim($json_input['shipping_tracking'] ?? '') ?: null,
        'shipping_address' => trim($json_input['shipping_address'] ?? '') ?: null,
        'internal_notes' => trim($json_input['internal_notes'] ?? '') ?: null,
        'customer_notes' => trim($json_input['customer_notes'] ?? '') ?: null,
    ];

    if (empty($data['work_order_number'])) {
        // Auto-generate work order number
        $data['work_order_number'] = 'WO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    if ($id > 0) {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }

        // Handle status transitions
        $status = $data['status'];
        if ($status === 'in_progress' && empty($json_input['started_at'])) {
            $sets[] = 'started_at = COALESCE(started_at, NOW())';
        }
        if ($status === 'completed') {
            $sets[] = 'completed_at = COALESCE(completed_at, NOW())';
        }
        if ($status === 'shipped') {
            $sets[] = 'shipped_at = COALESCE(shipped_at, NOW())';
        }

        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE work_orders SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);
    } else {
        $data['created_by_admin_id'] = (int)$admin_session['admin_id'];
        $cols = array_keys($data);
        $placeholders = array_fill(0, count($cols), '?');
        $stmt = $pdo->prepare("INSERT INTO work_orders (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")");
        $stmt->execute(array_values($data));
        $id = (int)$pdo->lastInsertId();
    }

    logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'WORK_ORDER_SAVED', "Saved work order #{$id}: {$data['work_order_number']}");

    jsonResponse(['success' => true, 'id' => $id, 'work_order_number' => $data['work_order_number']]);
}

function handle_get_work_order(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_activations', $admin_session);

    $id = (int)($json_input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Work order ID required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM work_orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        jsonResponse(['success' => false, 'error' => 'Work order not found']);
        return;
    }

    // Get associated CBRs
    $cbrStmt = $pdo->prepare("
        SELECT id, report_uuid, order_number, activation_status, shipping_status,
               motherboard_model, cpu_model, created_at
        FROM computer_build_reports
        WHERE work_order_id = ?
        ORDER BY created_at ASC
    ");
    $cbrStmt->execute([$id]);
    $order['build_reports'] = $cbrStmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(['success' => true, 'work_order' => $order]);
}

function handle_delete_work_order(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $id = (int)($json_input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Work order ID required']);
        return;
    }

    // Only allow deleting draft/cancelled orders
    $check = $pdo->prepare("SELECT status, work_order_number FROM work_orders WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Work order not found']);
        return;
    }
    if (!in_array($row['status'], ['draft', 'cancelled'])) {
        jsonResponse(['success' => false, 'error' => 'Can only delete draft or cancelled work orders']);
        return;
    }

    $pdo->prepare("DELETE FROM work_orders WHERE id = ?")->execute([$id]);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'WORK_ORDER_DELETED', "Deleted work order: {$row['work_order_number']}");

    jsonResponse(['success' => true]);
}
