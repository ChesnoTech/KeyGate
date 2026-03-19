<?php
/**
 * History Controller - Activation History & Hardware Info
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_list_history(PDO $pdo, array $admin_session): void {
    requirePermission('view_activations', $admin_session);

    $page = max(1, intval($_GET['page'] ?? 1));
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $limit = PAGINATION_HISTORY;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if ($filter !== 'all') {
        $where[] = "aa.attempt_result = ?";
        $params[] = $filter;
    }

    if (!empty($search)) {
        $where[] = "(aa.order_number LIKE ? OR k.product_key LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM activation_attempts aa
        LEFT JOIN oem_keys k ON aa.key_id = k.id
        $whereClause
    ");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Get history
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt = $pdo->prepare("
        SELECT
            aa.id, aa.attempted_date, aa.attempted_time, aa.technician_id,
            aa.order_number, k.product_key, aa.attempt_result, aa.notes,
            aa.hardware_collected, aa.activation_server, aa.activation_unique_id
        FROM activation_attempts aa
        LEFT JOIN oem_keys k ON aa.key_id = k.id
        $whereClause
        ORDER BY aa.attempted_date DESC, aa.attempted_time DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'success' => true,
        'history' => $history,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

function handle_get_hardware(PDO $pdo, array $admin_session): void {
    $activationId = $_GET['activation_id'] ?? 0;

    $stmt = $pdo->prepare("
        SELECT h.*, aa.order_number, aa.attempted_at, aa.technician_id,
               t.full_name as technician_name, k.product_key
        FROM hardware_info h
        INNER JOIN activation_attempts aa ON h.activation_id = aa.id
        LEFT JOIN technicians t ON aa.technician_id = t.technician_id
        LEFT JOIN oem_keys k ON aa.key_id = k.id
        WHERE h.activation_id = ?
    ");
    $stmt->execute([$activationId]);
    $hardware = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($hardware) {
        jsonResponse(['success' => true, 'hardware' => $hardware]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Hardware information not found']);
    }
}

function handle_get_hardware_by_order(PDO $pdo, array $admin_session): void {
    $orderNumber = $_GET['order_number'] ?? '';

    if (empty($orderNumber)) {
        jsonResponse(['success' => false, 'error' => 'Order number is required']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT h.*,
               t.full_name as technician_name,
               aa.attempt_result as activation_result,
               aa.attempted_at as activation_time,
               k.product_key
        FROM hardware_info h
        LEFT JOIN technicians t ON h.technician_id = t.technician_id
        LEFT JOIN activation_attempts aa ON h.activation_id = aa.id
        LEFT JOIN oem_keys k ON aa.key_id = k.id
        WHERE h.order_number = ?
        ORDER BY h.collection_timestamp DESC
        LIMIT 1
    ");
    $stmt->execute([$orderNumber]);
    $hardware = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($hardware) {
        jsonResponse(['success' => true, 'hardware' => $hardware]);
    } else {
        jsonResponse(['success' => false, 'error' => 'No hardware information found for this order']);
    }
}
