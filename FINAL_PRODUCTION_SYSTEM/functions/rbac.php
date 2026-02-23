<?php
/**
 * RBAC (Role-Based Access Control) Functions
 *
 * Thin wrapper that delegates all permission checks to the database-driven
 * ACL engine (acl.php). Kept for backward compatibility with existing code
 * that calls checkAdminPermission() and requirePermission().
 */

require_once __DIR__ . '/acl.php';

/**
 * Check if an admin role has permission to perform an action
 *
 * @param string $action Action to check (e.g., 'delete_key', 'add_technician')
 * @param string $adminRole Admin's role (unused - kept for signature compatibility)
 * @return bool True if allowed, false if denied
 */
function checkAdminPermission($action, $adminRole) {
    try {
        $adminId = $_SESSION['admin_id'] ?? null;
        if ($adminId) {
            return aclCheck($action, 'admin', $adminId);
        }
    } catch (Exception $e) {
        error_log("ACL permission check failed: " . $e->getMessage());
    }

    return false;
}

/**
 * Require specific permission or deny access with 403 error
 *
 * @param string $action Required action permission
 * @param array $adminSession Admin session data (must include 'admin_id' key)
 * @throws void Exits with 403 JSON response if permission denied
 */
function requirePermission($action, $adminSession) {
    try {
        aclRequire($action, $adminSession);
        return; // Permission granted
    } catch (Exception $e) {
        // aclRequire exits on denial, so this only catches unexpected errors
        error_log("ACL requirePermission failed: " . $e->getMessage());
    }

    // If we get here, something went wrong — deny access
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Permission denied: ACL check failed',
        'required_permission' => $action
    ]);
    exit;
}

/**
 * Log permission denial to database
 *
 * @param int|null $adminId Admin ID
 * @param string|null $sessionId Session ID
 * @param string $adminRole Admin's role
 * @param string $action Action that was denied
 */
function logPermissionDenial($adminId, $sessionId, $adminRole, $action) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO rbac_permission_denials (
                admin_id, session_id, admin_role, requested_action,
                endpoint, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $adminId,
            $sessionId,
            $adminRole,
            $action,
            $_SERVER['REQUEST_URI'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("Failed to log permission denial: " . $e->getMessage());
    }
}

/**
 * Get all permissions for the current admin user
 *
 * @param string $role Role name (unused - kept for signature compatibility)
 * @return array List of allowed actions
 */
function getRolePermissions($role) {
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!$adminId) {
        return [];
    }

    try {
        $effective = aclGetEffectivePermissions('admin', $adminId);
        $allowed = [];
        foreach ($effective as $key => $perm) {
            if ($perm['granted']) {
                $allowed[] = $key;
            }
        }
        return $allowed;
    } catch (Exception $e) {
        error_log("getRolePermissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if admin has any of the specified permissions (OR logic)
 *
 * @param array $actions List of actions to check
 * @param array $adminSession Admin session data
 * @return bool True if admin has at least one of the permissions
 */
function hasAnyPermission(array $actions, $adminSession) {
    if (!isset($adminSession['role'])) {
        return false;
    }

    foreach ($actions as $action) {
        if (checkAdminPermission($action, $adminSession['role'])) {
            return true;
        }
    }

    return false;
}

/**
 * Check if admin has all of the specified permissions (AND logic)
 *
 * @param array $actions List of actions to check
 * @param array $adminSession Admin session data
 * @return bool True if admin has all permissions
 */
function hasAllPermissions(array $actions, $adminSession) {
    if (!isset($adminSession['role'])) {
        return false;
    }

    foreach ($actions as $action) {
        if (!checkAdminPermission($action, $adminSession['role'])) {
            return false;
        }
    }

    return true;
}
