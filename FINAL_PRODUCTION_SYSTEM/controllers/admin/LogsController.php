<?php
/**
 * Logs Controller - Admin Activity Logs
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_list_logs(PDO $pdo, array $admin_session): void {
    requirePermission('view_logs', $admin_session);

    $page = max(1, intval($_GET['page'] ?? 1));
    $search = $_GET['search'] ?? '';
    $limit = PAGINATION_LOGS;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if (!empty($search)) {
        $where[] = "(aal.action LIKE ? OR aal.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM admin_activity_log aal
        $whereClause
    ");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Get logs
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt = $pdo->prepare("
        SELECT
            aal.created_at, au.username, aal.action, aal.description, aal.ip_address
        FROM admin_activity_log aal
        LEFT JOIN admin_users au ON aal.admin_id = au.id
        $whereClause
        ORDER BY aal.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'success' => true,
        'logs' => $logs,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}
