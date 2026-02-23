<?php
/**
 * Keys Controller - OEM Key Management
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_list_keys(PDO $pdo, array $admin_session): void {
    requirePermission('view_keys', $admin_session);

    $page = max(1, intval($_GET['page'] ?? 1));
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $limit = PAGINATION_KEYS;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if ($filter !== 'all') {
        $where[] = "key_status = ?";
        $params[] = $filter;
    }

    if (!empty($search)) {
        $where[] = "(product_key LIKE ? OR oem_identifier LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Advanced search parameters
    if (!empty($_GET['key_pattern'])) {
        $pattern = str_replace('*', '%', $_GET['key_pattern']);
        $where[] = "product_key LIKE ?";
        $params[] = $pattern;
    }

    if (!empty($_GET['oem_pattern'])) {
        $pattern = str_replace('*', '%', $_GET['oem_pattern']);
        $where[] = "oem_identifier LIKE ?";
        $params[] = $pattern;
    }

    if (!empty($_GET['roll_pattern'])) {
        $pattern = str_replace('*', '%', $_GET['roll_pattern']);
        $where[] = "roll_serial LIKE ?";
        $params[] = $pattern;
    }

    if (!empty($_GET['adv_status'])) {
        $where[] = "key_status = ?";
        $params[] = $_GET['adv_status'];
    }

    if (!empty($_GET['date_from'])) {
        $where[] = "last_use_date >= ?";
        $params[] = $_GET['date_from'];
    }

    if (!empty($_GET['date_to'])) {
        $where[] = "last_use_date <= ?";
        $params[] = $_GET['date_to'];
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oem_keys $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Get keys with latest order number
    $stmt = $pdo->prepare("
        SELECT k.id, k.product_key, k.oem_identifier, k.roll_serial, k.key_status,
               k.last_use_date, k.last_use_time, k.created_at,
               (SELECT order_number FROM activation_attempts
                WHERE key_id = k.id
                ORDER BY attempted_at DESC LIMIT 1) as order_number
        FROM oem_keys k
        $whereClause
        ORDER BY k.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mask keys if configured
    $show_full = (bool)getConfig('show_full_keys_in_admin');
    if (!$show_full) {
        foreach ($keys as &$key) {
            $key['product_key'] = substr($key['product_key'], 0, 10) . '...' . substr($key['product_key'], -5);
        }
    }

    echo json_encode([
        'success' => true,
        'keys' => $keys,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

function handle_recycle_key(PDO $pdo, array $admin_session): void {
    requirePermission('recycle_key', $admin_session);

    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("
        UPDATE oem_keys
        SET key_status = 'unused', last_use_date = NULL, last_use_time = NULL
        WHERE id = ?
    ");
    $stmt->execute([$id]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'RECYCLE_KEY',
        "Recycled key ID: $id"
    );

    echo json_encode(['success' => true, 'message' => 'Key recycled successfully']);
}

function handle_delete_key(PDO $pdo, array $admin_session): void {
    requirePermission('delete_key', $admin_session);

    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("DELETE FROM oem_keys WHERE id = ?");
    $stmt->execute([$id]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DELETE_KEY',
        "Deleted key ID: $id"
    );

    echo json_encode(['success' => true, 'message' => 'Key deleted successfully']);
}

function handle_import_keys(PDO $pdo, array $admin_session): void {
    requirePermission('import_keys', $admin_session);

    if (!isset($_FILES['csv_file'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        return;
    }

    $file = $_FILES['csv_file'];
    $update_existing = isset($_POST['update_existing']);

    // Include CSV import functions from secure-admin.php
    $webRoot = dirname(__DIR__, 2);
    require_once $webRoot . '/secure-admin.php';

    $result = handleCSVImport($file);

    if (isset($result['error'])) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    } else {
        echo json_encode([
            'success' => true,
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'] ?? []
        ]);

        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'],
            'IMPORT_KEYS',
            "Imported {$result['imported']} keys from CSV"
        );
    }
}

function handle_export_keys(PDO $pdo, array $admin_session): void {
    requirePermission('export_data', $admin_session);

    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';

    $where = [];
    $params = [];

    if ($filter !== 'all') {
        $where[] = "key_status = ?";
        $params[] = $filter;
    }

    if (!empty($search)) {
        $where[] = "(product_key LIKE ? OR oem_identifier LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT k.product_key, k.oem_identifier, k.roll_serial, k.key_status,
               k.last_use_date, k.last_use_time, k.created_at,
               (SELECT order_number FROM activation_attempts
                WHERE key_id = k.id
                ORDER BY attempted_at DESC LIMIT 1) as order_number
        FROM oem_keys k
        $whereClause
        ORDER BY k.id DESC
    ");
    $stmt->execute($params);
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="oem_keys_export_' . date('Y-m-d_His') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Product Key', 'Order Number', 'OEM ID', 'Roll Serial', 'Status', 'Last Use Date', 'Last Use Time', 'Created At']);

    foreach ($keys as $key) {
        fputcsv($output, [
            $key['product_key'],
            $key['order_number'] ?? '',
            $key['oem_identifier'] ?? '',
            $key['roll_serial'] ?? '',
            $key['key_status'],
            $key['last_use_date'] ?? '',
            $key['last_use_time'] ?? '',
            $key['created_at']
        ]);
    }

    fclose($output);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'EXPORT_KEYS',
        "Exported " . count($keys) . " keys to CSV"
    );

    exit;
}
