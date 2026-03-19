<?php
/**
 * Technicians Controller - Technician Account Management
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_list_techs(PDO $pdo, array $admin_session): void {
    requirePermission('view_technicians', $admin_session);

    $page = max(1, intval($_GET['page'] ?? 1));
    $search = $_GET['search'] ?? '';
    $limit = PAGINATION_TECHNICIANS;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if (!empty($search)) {
        $where[] = "(technician_id LIKE ? OR full_name LIKE ? OR email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM technicians $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt = $pdo->prepare("
        SELECT id, technician_id, full_name, email, is_active, last_login, created_at, preferred_server
        FROM technicians
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $techs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'technicians' => $techs,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit)
    ]);
}

function handle_list_technicians(PDO $pdo, array $admin_session): void {
    requirePermission('view_technicians', $admin_session);

    $stmt = $pdo->query("
        SELECT id, technician_id, full_name, email, is_active
        FROM technicians
        ORDER BY full_name ASC
    ");
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'technicians' => $technicians
    ]);
}

function handle_add_tech(PDO $pdo, array $admin_session): void {
    requirePermission('add_technician', $admin_session);

    $tech_id = trim($_POST['technician_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $preferred_server = $_POST['preferred_server'] ?? 'oem';
    $preferred_language = preg_replace('/[^a-z]/', '', strtolower($_POST['preferred_language'] ?? 'en'));
    if (empty($preferred_language)) $preferred_language = 'en';

    if (strlen($tech_id) !== TECH_ID_LENGTH || !preg_match(TECH_ID_PATTERN, $tech_id)) {
        echo json_encode(['success' => false, 'error' => 'Technician ID must be exactly 5 alphanumeric characters']);
        return;
    }

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters']);
        return;
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    try {
        $pdo->beginTransaction();

        // Check + insert inside transaction to prevent TOCTOU race condition
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM technicians WHERE technician_id = ?");
        $stmt->execute([$tech_id]);
        if ($stmt->fetchColumn() > 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Technician ID already exists']);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO technicians (technician_id, password_hash, full_name, email, is_active, preferred_server, preferred_language)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tech_id, $password_hash, $full_name, $email, $is_active, $preferred_server, $preferred_language]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Handle duplicate key constraint violation gracefully
        if ($e->getCode() == '23000') {
            echo json_encode(['success' => false, 'error' => 'Technician ID already exists']);
            return;
        }
        throw $e;
    }

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'CREATE_TECHNICIAN',
        "Created technician: $tech_id"
    );

    echo json_encode(['success' => true, 'message' => 'Technician created successfully']);
}

function handle_edit_tech(PDO $pdo, array $admin_session): void {
    requirePermission('edit_technician', $admin_session);

    $id = intval($_POST['id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $stmt = $pdo->prepare("
        UPDATE technicians
        SET full_name = ?, email = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$full_name, $email, $is_active, $id]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_TECHNICIAN',
        "Updated technician ID: $id"
    );

    echo json_encode(['success' => true, 'message' => 'Technician updated successfully']);
}

function handle_get_tech(PDO $pdo, array $admin_session): void {
    $techId = intval($_GET['id'] ?? 0);
    if ($techId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid technician ID']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, technician_id, full_name, email, is_active, preferred_server, preferred_language
        FROM technicians
        WHERE id = ?
    ");
    $stmt->execute([$techId]);
    $tech = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tech) {
        echo json_encode(['success' => false, 'error' => 'Technician not found']);
        return;
    }

    echo json_encode(['success' => true, 'technician' => $tech]);
}

function handle_update_tech(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $input = $json_input;
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    $techId = intval($input['tech_id'] ?? 0);
    $fullName = trim($input['full_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $preferredServer = $input['preferred_server'] ?? 'oem';
    $preferredLang = preg_replace('/[^a-z]/', '', strtolower($input['preferred_language'] ?? 'en'));
    if (empty($preferredLang)) $preferredLang = 'en';
    $isActive = intval($input['is_active'] ?? 0);

    if ($techId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid technician ID']);
        return;
    }

    if (empty($fullName)) {
        echo json_encode(['success' => false, 'error' => 'Full name is required']);
        return;
    }

    if (!in_array($preferredServer, ['oem', 'alternative'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid preferred server']);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE technicians
        SET full_name = ?, email = ?, preferred_server = ?, preferred_language = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$fullName, $email, $preferredServer, $preferredLang, $isActive, $techId]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_TECHNICIAN',
        "Updated technician ID {$techId}"
    );

    echo json_encode(['success' => true]);
}

function handle_reset_password(PDO $pdo, array $admin_session): void {
    requirePermission('reset_tech_password', $admin_session);

    $id = intval($_POST['id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';

    if (strlen($new_password) < PASSWORD_MIN_LENGTH) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters']);
        return;
    }

    $password_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $stmt = $pdo->prepare("
        UPDATE technicians
        SET password_hash = ?, must_change_password = 1
        WHERE id = ?
    ");
    $stmt->execute([$password_hash, $id]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'RESET_PASSWORD',
        "Reset password for technician ID: $id"
    );

    echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
}

function handle_toggle_tech(PDO $pdo, array $admin_session): void {
    requirePermission('edit_technician', $admin_session);

    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("
        UPDATE technicians
        SET is_active = NOT is_active
        WHERE id = ?
    ");
    $stmt->execute([$id]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'TOGGLE_TECHNICIAN',
        "Toggled active status for technician ID: $id"
    );

    echo json_encode(['success' => true, 'message' => 'Technician status updated']);
}

function handle_delete_tech(PDO $pdo, array $admin_session): void {
    requirePermission('delete_technician', $admin_session);

    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
    $stmt->execute([$id]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DELETE_TECHNICIAN',
        "Deleted technician ID: $id"
    );

    echo json_encode(['success' => true, 'message' => 'Technician deleted successfully']);
}
