<?php
/**
 * Compliance Controller - QC Motherboard Registry & Compliance Rules
 * Handles all admin-side QC compliance management actions.
 */

require_once dirname(__DIR__, 2) . '/functions/qc-compliance.php';

define('PAGINATION_COMPLIANCE', 20);

// ── Global Settings ──────────────────────────────────────────

function handle_qc_get_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);
    $settings = qcGetGlobalSettings($pdo);
    echo json_encode(['success' => true, 'settings' => $settings]);
}

function handle_qc_save_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $allowedKeys = ['qc_enabled', 'default_bios_enforcement', 'default_secure_boot_enforcement', 'default_hackbgrt_enforcement', 'blocking_prevents_key'];

    foreach ($allowedKeys as $key) {
        if (isset($json_input[$key])) {
            $stmt = $pdo->prepare("UPDATE qc_global_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
            $stmt->execute([$json_input[$key], $admin_session['admin_id'], $key]);
        }
    }

    logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'QC_SETTINGS_UPDATE', 'Updated QC global settings');
    echo json_encode(['success' => true]);
}

// ── Motherboard Registry ─────────────────────────────────────

function handle_qc_list_motherboards(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $search = trim($_GET['search'] ?? '');
    $mfrFilter = trim($_GET['manufacturer'] ?? '');
    $limit = PAGINATION_COMPLIANCE;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "(r.manufacturer LIKE ? OR r.product LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($mfrFilter !== '') {
        $where[] = "r.manufacturer = ?";
        $params[] = $mfrFilter;
    }
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM qc_motherboard_registry r $whereClause");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Fetch with LEFT JOIN on manufacturer defaults for effective display
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt = $pdo->prepare("
        SELECT r.*, md.secure_boot_required AS mfr_sb_required, md.secure_boot_enforcement AS mfr_sb_enforcement,
               md.min_bios_version AS mfr_min_bios, md.recommended_bios_version AS mfr_rec_bios,
               md.bios_enforcement AS mfr_bios_enforcement, md.hackbgrt_enforcement AS mfr_hb_enforcement,
               md.missing_drivers_enforcement AS mfr_drivers_enforcement
        FROM qc_motherboard_registry r
        LEFT JOIN qc_manufacturer_defaults md ON r.manufacturer = md.manufacturer
        $whereClause
        ORDER BY r.last_seen_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute effective values
    $globalSettings = qcGetGlobalSettings($pdo);
    foreach ($rows as &$row) {
        $row['known_bios_versions'] = json_decode($row['known_bios_versions'] ?: '[]', true);
        // Effective: model override > manufacturer default > global
        $row['effective_secure_boot_enforcement'] = $row['secure_boot_enforcement'] ?? $row['mfr_sb_enforcement'] ?? (int) ($globalSettings['default_secure_boot_enforcement'] ?? 1);
        $row['effective_bios_enforcement'] = $row['bios_enforcement'] ?? $row['mfr_bios_enforcement'] ?? (int) ($globalSettings['default_bios_enforcement'] ?? 1);
        $row['effective_hackbgrt_enforcement'] = $row['hackbgrt_enforcement'] ?? $row['mfr_hb_enforcement'] ?? (int) ($globalSettings['default_hackbgrt_enforcement'] ?? 1);
        $row['effective_partition_enforcement'] = (int) ($globalSettings['default_partition_enforcement'] ?? 2);
        $row['effective_missing_drivers_enforcement'] = $row['missing_drivers_enforcement'] ?? $row['mfr_drivers_enforcement'] ?? (int) ($globalSettings['default_missing_drivers_enforcement'] ?? 2);
        $row['effective_secure_boot_required'] = $row['secure_boot_required'] ?? $row['mfr_sb_required'] ?? 1;
        $row['effective_min_bios'] = $row['min_bios_version'] ?? $row['mfr_min_bios'] ?? null;
        $row['effective_rec_bios'] = $row['recommended_bios_version'] ?? $row['mfr_rec_bios'] ?? null;
    }
    unset($row);

    // Distinct manufacturers for filter dropdown
    $mfrs = $pdo->query("SELECT DISTINCT manufacturer FROM qc_motherboard_registry ORDER BY manufacturer")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'motherboards' => $rows,
        'total' => $total,
        'page' => $page,
        'total_pages' => max(1, (int) ceil($total / $limit)),
        'manufacturers' => $mfrs,
    ]);
}

function handle_qc_get_motherboard(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM qc_motherboard_registry WHERE id = ?");
    $stmt->execute([$id]);
    $board = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$board) {
        echo json_encode(['success' => false, 'error' => 'Motherboard not found']);
        return;
    }

    $board['known_bios_versions'] = json_decode($board['known_bios_versions'] ?: '[]', true);
    $rules = qcGetEffectiveRules($pdo, $board['manufacturer'], $board['product']);

    echo json_encode(['success' => true, 'motherboard' => $board, 'effective_rules' => $rules]);
}

function handle_qc_update_motherboard(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance_rules', $admin_session);

    $id = (int) ($json_input['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing motherboard ID']);
        return;
    }

    $fields = [];
    $params = [];
    $allowedFields = ['secure_boot_required', 'secure_boot_enforcement', 'min_bios_version', 'recommended_bios_version', 'bios_enforcement', 'hackbgrt_enforcement', 'missing_drivers_enforcement', 'notes', 'is_active'];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $json_input)) {
            $value = $json_input[$field];
            // Allow setting back to NULL (inherit from manufacturer)
            if ($value === '' || $value === null) {
                $fields[] = "$field = NULL";
            } else {
                $fields[] = "$field = ?";
                $params[] = $value;
            }
        }
    }

    if (empty($fields)) {
        echo json_encode(['success' => false, 'error' => 'No fields to update']);
        return;
    }

    $fields[] = "updated_by = ?";
    $params[] = $admin_session['admin_id'];
    $params[] = $id;

    $stmt = $pdo->prepare("UPDATE qc_motherboard_registry SET " . implode(", ", $fields) . " WHERE id = ?");
    $stmt->execute($params);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'QC_MOTHERBOARD_UPDATE', "Updated motherboard registry #$id");
    echo json_encode(['success' => true]);
}

// ── Manufacturer Defaults ────────────────────────────────────

function handle_qc_list_manufacturers(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    // Configured manufacturers
    $stmt = $pdo->query("SELECT * FROM qc_manufacturer_defaults ORDER BY manufacturer");
    $configured = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Unconfigured: manufacturers seen in registry but no defaults entry
    $stmt = $pdo->query("
        SELECT DISTINCT r.manufacturer
        FROM qc_motherboard_registry r
        LEFT JOIN qc_manufacturer_defaults md ON r.manufacturer = md.manufacturer
        WHERE md.id IS NULL
        ORDER BY r.manufacturer
    ");
    $unconfigured = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['success' => true, 'manufacturers' => $configured, 'unconfigured' => $unconfigured]);
}

function handle_qc_update_manufacturer(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance_rules', $admin_session);

    $manufacturer = trim($json_input['manufacturer'] ?? '');
    if (empty($manufacturer)) {
        echo json_encode(['success' => false, 'error' => 'Manufacturer name required']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO qc_manufacturer_defaults (manufacturer, secure_boot_required, secure_boot_enforcement, min_bios_version, recommended_bios_version, bios_enforcement, hackbgrt_enforcement, notes, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            secure_boot_required = VALUES(secure_boot_required),
            secure_boot_enforcement = VALUES(secure_boot_enforcement),
            min_bios_version = VALUES(min_bios_version),
            recommended_bios_version = VALUES(recommended_bios_version),
            bios_enforcement = VALUES(bios_enforcement),
            hackbgrt_enforcement = VALUES(hackbgrt_enforcement),
            notes = VALUES(notes),
            updated_by = VALUES(updated_by)
    ");
    $stmt->execute([
        $manufacturer,
        (int) ($json_input['secure_boot_required'] ?? 1),
        (int) ($json_input['secure_boot_enforcement'] ?? 1),
        $json_input['min_bios_version'] ?? null,
        $json_input['recommended_bios_version'] ?? null,
        (int) ($json_input['bios_enforcement'] ?? 1),
        (int) ($json_input['hackbgrt_enforcement'] ?? 1),
        $json_input['notes'] ?? null,
        $admin_session['admin_id'],
    ]);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'QC_MANUFACTURER_UPDATE', "Updated manufacturer defaults: $manufacturer");
    echo json_encode(['success' => true]);
}

// ── Compliance Results ───────────────────────────────────────

function handle_qc_list_compliance_results(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $search = trim($_GET['search'] ?? '');
    $checkType = trim($_GET['check_type'] ?? '');
    $checkResult = trim($_GET['check_result'] ?? '');
    $limit = PAGINATION_COMPLIANCE;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "cr.order_number LIKE ?";
        $params[] = "%$search%";
    }
    if ($checkType !== '') {
        $where[] = "cr.check_type = ?";
        $params[] = $checkType;
    }
    if ($checkResult !== '') {
        $where[] = "cr.check_result = ?";
        $params[] = $checkResult;
    }
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM qc_compliance_results cr $whereClause");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt = $pdo->prepare("
        SELECT cr.*, hi.motherboard_manufacturer, hi.motherboard_product, hi.bios_version AS hw_bios_version
        FROM qc_compliance_results cr
        LEFT JOIN hardware_info hi ON cr.hardware_info_id = hi.id
        $whereClause
        ORDER BY cr.checked_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'results' => $results,
        'total' => $total,
        'page' => $page,
        'total_pages' => max(1, (int) ceil($total / $limit)),
    ]);
}

// ── Compliance Results — Grouped by Order ────────────────────

function handle_qc_list_compliance_grouped(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $search = trim($_GET['search'] ?? '');
    $resultFilter = trim($_GET['check_result'] ?? ''); // pass|warning|fail — filters orders that HAVE this result
    $limit = PAGINATION_COMPLIANCE;
    $offset = ($page - 1) * $limit;

    // Sub-query: distinct (order_number, hardware_info_id) with worst result per order
    // Result severity ordering: fail > warning > info > pass
    $havingClause = '';
    $havingParams = [];
    if ($resultFilter !== '') {
        // Filter to orders that have at least one check matching this result
        $havingClause = "HAVING MAX(CASE WHEN cr.check_result = ? THEN 1 ELSE 0 END) = 1";
        $havingParams[] = $resultFilter;
    }

    $searchClause = '';
    $searchParams = [];
    if ($search !== '') {
        $searchClause = "AND cr.order_number LIKE ?";
        $searchParams[] = "%$search%";
    }

    // Count distinct orders
    $countSql = "
        SELECT COUNT(*) FROM (
            SELECT cr.order_number, cr.hardware_info_id
            FROM qc_compliance_results cr
            WHERE 1=1 $searchClause
            GROUP BY cr.order_number, cr.hardware_info_id
            $havingClause
        ) sub
    ";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute(array_merge($searchParams, $havingParams));
    $total = (int) $stmt->fetchColumn();

    // Fetch grouped orders with worst result, ordered by latest check
    $sql = "
        SELECT
            cr.order_number,
            cr.hardware_info_id,
            hi.motherboard_manufacturer,
            hi.motherboard_product,
            hi.bios_version AS hw_bios_version,
            hi.detected_variant_name,
            hi.detected_line_name,
            MAX(cr.checked_at) AS checked_at,
            MAX(CASE WHEN cr.check_result = 'fail' THEN 3
                     WHEN cr.check_result = 'warning' THEN 2
                     WHEN cr.check_result = 'info' THEN 1
                     ELSE 0 END) AS worst_severity
        FROM qc_compliance_results cr
        LEFT JOIN hardware_info hi ON cr.hardware_info_id = hi.id
        WHERE 1=1 $searchClause
        GROUP BY cr.order_number, cr.hardware_info_id,
                 hi.motherboard_manufacturer, hi.motherboard_product, hi.bios_version,
                 hi.detected_variant_name, hi.detected_line_name
        $havingClause
        ORDER BY MAX(cr.checked_at) DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($searchParams, $havingParams, [(int) $limit, (int) $offset]));
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each order, fetch all individual check results
    if (!empty($orders)) {
        $orderKeys = [];
        foreach ($orders as $o) {
            $orderKeys[] = $o['order_number'] . '|' . $o['hardware_info_id'];
        }

        // Fetch all checks for these orders in one query
        $placeholders = implode(',', array_fill(0, count($orders), '(?, ?)'));
        $checkParams = [];
        foreach ($orders as $o) {
            $checkParams[] = $o['order_number'];
            $checkParams[] = $o['hardware_info_id'];
        }

        $checkSql = "
            SELECT cr.order_number, cr.hardware_info_id, cr.check_type, cr.check_result,
                   cr.enforcement_level, cr.expected_value, cr.actual_value, cr.message,
                   cr.rule_source
            FROM qc_compliance_results cr
            WHERE (cr.order_number, cr.hardware_info_id) IN ($placeholders)
            ORDER BY cr.check_type
        ";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute($checkParams);
        $allChecks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group checks by order+hw key
        $checksMap = [];
        foreach ($allChecks as $c) {
            $key = $c['order_number'] . '|' . $c['hardware_info_id'];
            $checksMap[$key][] = $c;
        }

        // Attach checks to each order row
        foreach ($orders as &$o) {
            $key = $o['order_number'] . '|' . $o['hardware_info_id'];
            $checks = $checksMap[$key] ?? [];

            // Build per-check-type summary
            $o['checks'] = [];
            foreach ($checks as $c) {
                $o['checks'][$c['check_type']] = [
                    'result'           => $c['check_result'],
                    'enforcement_level' => (int) $c['enforcement_level'],
                    'expected_value'    => $c['expected_value'],
                    'actual_value'      => $c['actual_value'],
                    'message'           => $c['message'],
                    'rule_source'       => $c['rule_source'],
                ];
            }

            // Map worst_severity back to string
            $severityMap = [0 => 'pass', 1 => 'info', 2 => 'warning', 3 => 'fail'];
            $o['worst_result'] = $severityMap[(int) $o['worst_severity']] ?? 'pass';
            unset($o['worst_severity']);
        }
        unset($o);
    }

    echo json_encode([
        'success' => true,
        'results' => $orders,
        'total' => $total,
        'page' => $page,
        'total_pages' => max(1, (int) ceil($total / $limit)),
    ]);
}

// ── Retroactive Recheck ──────────────────────────────────────

function handle_qc_recheck_count(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $manufacturer = $json_input['manufacturer'] ?? null;
    $product = $json_input['product'] ?? null;

    $count = qcRecheckCount($pdo, $manufacturer, $product);
    jsonResponse(['success' => true, 'count' => $count]);
}

function handle_qc_recheck_historical(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $manufacturer = $json_input['manufacturer'] ?? null;
    $product = $json_input['product'] ?? null;
    $batchSize = isset($json_input['batch_size']) ? (int) $json_input['batch_size'] : 50;
    $afterId = isset($json_input['after_id']) ? (int) $json_input['after_id'] : 0;

    $stats = qcRecheckHistorical($pdo, $manufacturer, $product, $batchSize, $afterId);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'QC_RECHECK_HISTORICAL', "Retroactive compliance recheck batch: {$stats['rechecked']} records (after_id={$afterId})");
    jsonResponse(['success' => true, 'stats' => $stats]);
}

// ── QC Statistics ────────────────────────────────────────────

function handle_qc_get_stats(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    // Result counts
    $stmt = $pdo->query("SELECT check_result, COUNT(*) as cnt FROM qc_compliance_results GROUP BY check_result");
    $resultCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $total = array_sum($resultCounts);
    $passCount = (int) ($resultCounts['pass'] ?? 0);

    // Top failing boards
    $stmt = $pdo->query("
        SELECT hi.motherboard_manufacturer, hi.motherboard_product, COUNT(*) as fail_count
        FROM qc_compliance_results cr
        JOIN hardware_info hi ON cr.hardware_info_id = hi.id
        WHERE cr.check_result = 'fail'
        GROUP BY hi.motherboard_manufacturer, hi.motherboard_product
        ORDER BY fail_count DESC
        LIMIT 5
    ");
    $topFailing = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Unresolved blocking
    $stmt = $pdo->query("SELECT COUNT(DISTINCT hardware_info_id) FROM qc_compliance_results WHERE enforcement_level = 3 AND check_result = 'fail'");
    $unresolvedBlocking = (int) $stmt->fetchColumn();

    // Registry stats
    $stmt = $pdo->query("SELECT COUNT(*) FROM qc_motherboard_registry");
    $registeredBoards = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM qc_manufacturer_defaults");
    $mfrsWithDefaults = (int) $stmt->fetchColumn();

    // Check type breakdown
    $stmt = $pdo->query("
        SELECT check_type, check_result, COUNT(*) as cnt
        FROM qc_compliance_results
        GROUP BY check_type, check_result
    ");
    $byType = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byType[$row['check_type']][$row['check_result']] = (int) $row['cnt'];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_checks' => $total,
            'pass_count'   => $passCount,
            'info_count'   => (int) ($resultCounts['info'] ?? 0),
            'warning_count' => (int) ($resultCounts['warning'] ?? 0),
            'fail_count'   => (int) ($resultCounts['fail'] ?? 0),
            'pass_rate'    => $total > 0 ? round($passCount / $total * 100, 1) : 0,
            'top_failing_boards' => $topFailing,
            'unresolved_blocking' => $unresolvedBlocking,
            'registered_motherboards' => $registeredBoards,
            'manufacturers_with_defaults' => $mfrsWithDefaults,
            'by_check_type' => $byType,
        ],
    ]);
}
