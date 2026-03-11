<?php
/**
 * Deep ACL System v2 - Database-Driven Access Control
 *
 * Database-driven permission system with flexible, granular access control.
 * Features:
 *   - Custom roles (admin + technician types)
 *   - Granular permissions grouped by category
 *   - Per-user permission overrides (grant/deny) with optional expiry
 *   - Full audit trail of all ACL changes
 *
 * Usage:
 *   require_once __DIR__ . '/acl.php';
 *   if (aclCheck('view_keys', 'admin', $adminId)) { ... }
 *   aclRequire('delete_key', $adminSession); // 403 if denied
 */

// ============================================================
// PERMISSION CHECKING
// ============================================================

/**
 * Check if a user has a specific permission.
 * Checks: role permissions + user overrides (with expiry).
 *
 * @param string $permissionKey Permission key (e.g., 'view_keys')
 * @param string $userType 'admin' or 'technician'
 * @param int $userId User's database ID (admin_users.id or technicians.id)
 * @return bool
 */
function aclCheck($permissionKey, $userType, $userId) {
    if (!$permissionKey || !$userType || !$userId) {
        return false;
    }

    // Per-request cache: load all effective permissions once per user,
    // then answer every subsequent check from memory (O(4) queries → O(1)).
    static $cache = [];
    $cacheKey = "{$userType}:{$userId}";

    if (!isset($cache[$cacheKey])) {
        $cache[$cacheKey] = aclGetEffectivePermissions($userType, $userId);
    }

    return !empty($cache[$cacheKey][$permissionKey]['granted']);
}

/**
 * Require a permission or return 403 JSON and exit.
 *
 * @param string $permissionKey Permission to check
 * @param array $session Session data (must have 'admin_id' or 'role' key)
 */
function aclRequire($permissionKey, $session) {
    $userId = $session['admin_id'] ?? null;
    $userType = 'admin';

    if (!$userId) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Permission denied: No user session',
            'required_permission' => $permissionKey
        ]);
        exit;
    }

    if (!aclCheck($permissionKey, $userType, $userId)) {
        // Log denial
        aclLogDenial($userId, $userType, $permissionKey, $session);

        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Permission denied',
            'required_permission' => $permissionKey,
            'your_role' => $session['role'] ?? 'unknown'
        ]);
        exit;
    }
}

/**
 * Require a permission or exit with 403.
 * Convenience alias for aclRequire() — used by all controllers.
 *
 * @param string $action Required permission key
 * @param array  $adminSession Admin session data
 */
function requirePermission($action, $adminSession) {
    aclRequire($action, $adminSession);
}

/**
 * Log a permission denial to the existing rbac_permission_denials table.
 */
function aclLogDenial($userId, $userType, $permissionKey, $session) {
    global $pdo;

    // Structured log for aggregation
    if (function_exists('appLog')) {
        appLog('warning', 'ACL permission denied', [
            'event'      => 'acl_denied',
            'user_id'    => $userId,
            'user_type'  => $userType,
            'permission' => $permissionKey,
            'role'       => $session['role'] ?? 'unknown',
        ]);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO rbac_permission_denials (
                admin_id, session_id, admin_role, requested_action,
                endpoint, ip_address, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $session['id'] ?? null,
            $session['role'] ?? 'acl_v2',
            $permissionKey,
            $_SERVER['REQUEST_URI'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("ACL denial log error: " . $e->getMessage());
    }
}

// ============================================================
// EFFECTIVE PERMISSIONS
// ============================================================

/**
 * Get all effective permissions for a user (role + overrides).
 *
 * @param string $userType 'admin' or 'technician'
 * @param int $userId
 * @return array ['permission_key' => ['granted' => bool, 'source' => string, ...], ...]
 */
function aclGetEffectivePermissions($userType, $userId) {
    global $pdo;
    $result = [];

    try {
        $roleId = aclGetUserRoleId($userType, $userId);

        // Check if super_admin
        $isSuperAdmin = false;
        if ($roleId) {
            $stmt = $pdo->prepare("SELECT role_name FROM acl_roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($role && $role['role_name'] === 'super_admin') {
                $isSuperAdmin = true;
            }
        }

        // Get all permissions
        $stmt = $pdo->query("SELECT id, permission_key, display_name, category_id, is_dangerous FROM acl_permissions ORDER BY id");
        $allPerms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get role permissions
        $rolePermKeys = [];
        if ($roleId) {
            $stmt = $pdo->prepare("
                SELECT p.permission_key
                FROM acl_role_permissions rp
                INNER JOIN acl_permissions p ON rp.permission_id = p.id
                WHERE rp.role_id = ?
            ");
            $stmt->execute([$roleId]);
            $rolePermKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Get user overrides
        $overrides = [];
        $stmt = $pdo->prepare("
            SELECT p.permission_key, uo.is_granted, uo.reason, uo.expires_at
            FROM acl_user_overrides uo
            INNER JOIN acl_permissions p ON uo.permission_id = p.id
            WHERE uo.user_type = ? AND uo.user_id = ?
              AND (uo.expires_at IS NULL OR uo.expires_at > NOW())
        ");
        $stmt->execute([$userType, $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $overrides[$ov['permission_key']] = $ov;
        }

        // Build effective permissions
        foreach ($allPerms as $perm) {
            $key = $perm['permission_key'];
            $entry = [
                'permission_key' => $key,
                'display_name' => $perm['display_name'],
                'is_dangerous' => (bool)$perm['is_dangerous'],
                'granted' => false,
                'source' => 'none'
            ];

            if ($isSuperAdmin) {
                $entry['granted'] = true;
                $entry['source'] = 'super_admin';
            } elseif (isset($overrides[$key])) {
                $entry['granted'] = (bool)$overrides[$key]['is_granted'];
                $entry['source'] = $overrides[$key]['is_granted'] ? 'override_grant' : 'override_deny';
                $entry['override_reason'] = $overrides[$key]['reason'];
                $entry['override_expires'] = $overrides[$key]['expires_at'];
            } elseif (in_array($key, $rolePermKeys)) {
                $entry['granted'] = true;
                $entry['source'] = 'role';
            }

            $result[$key] = $entry;
        }
    } catch (PDOException $e) {
        error_log("ACL effective permissions error: " . $e->getMessage());
    }

    return $result;
}

// ============================================================
// ROLE MANAGEMENT
// ============================================================

/**
 * Get a role by ID with its assigned permission IDs.
 */
function aclGetRoleById($roleId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM acl_roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$role) return null;

        $stmt = $pdo->prepare("SELECT permission_id FROM acl_role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);
        $role['permission_ids'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $role;
    } catch (PDOException $e) {
        error_log("ACL get role error: " . $e->getMessage());
        return null;
    }
}

/**
 * List all roles, optionally filtered by type.
 */
function aclListRoles($roleType = null) {
    global $pdo;
    try {
        $sql = "SELECT r.*,
                (SELECT COUNT(*) FROM acl_role_permissions WHERE role_id = r.id) as permission_count
                FROM acl_roles r WHERE 1=1";
        $params = [];
        if ($roleType) {
            $sql .= " AND r.role_type = ?";
            $params[] = $roleType;
        }
        $sql .= " ORDER BY r.role_type, r.priority DESC, r.display_name";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add user counts
        foreach ($roles as &$role) {
            $role['user_count'] = aclGetRoleUserCount($role['id']);
        }

        return $roles;
    } catch (PDOException $e) {
        error_log("ACL list roles error: " . $e->getMessage());
        return [];
    }
}

/**
 * Create a new custom role.
 *
 * @return int|false New role ID or false on failure
 */
function aclCreateRole($name, $displayName, $description, $roleType, $color, $permissionIds, $actorId) {
    global $pdo;
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO acl_roles (role_name, display_name, description, role_type, color, is_system_role, priority, created_by)
            VALUES (?, ?, ?, ?, ?, 0, 0, ?)
        ");
        $stmt->execute([$name, $displayName, $description, $roleType, $color, $actorId]);
        $roleId = $pdo->lastInsertId();

        // Assign permissions
        if (!empty($permissionIds)) {
            $stmt = $pdo->prepare("INSERT INTO acl_role_permissions (role_id, permission_id, granted_by) VALUES (?, ?, ?)");
            foreach ($permissionIds as $permId) {
                $stmt->execute([$roleId, $permId, $actorId]);
            }
        }

        // Log
        aclLogChange($actorId, 'create_role', 'role', $roleId, $displayName, null,
            json_encode(['name' => $name, 'type' => $roleType, 'permissions' => count($permissionIds)]));

        $pdo->commit();
        return $roleId;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("ACL create role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update an existing role (not system roles' name/type).
 */
function aclUpdateRole($roleId, $data, $actorId) {
    global $pdo;
    try {
        $role = aclGetRoleById($roleId);
        if (!$role) return false;

        // Optimistic locking: reject if role was modified since the client loaded it
        if (!empty($data['expected_updated_at'])) {
            $stmt = $pdo->prepare("SELECT updated_at FROM acl_roles WHERE id = ?");
            $stmt->execute([$roleId]);
            $currentUpdatedAt = $stmt->fetchColumn();
            if ($currentUpdatedAt && $currentUpdatedAt !== $data['expected_updated_at']) {
                return ['error' => 'conflict', 'message' => 'Role was modified by another user. Please reload and try again.'];
            }
        }

        $pdo->beginTransaction();

        // Update role fields (don't allow renaming system roles)
        $fields = [];
        $params = [];

        if (isset($data['display_name'])) {
            $fields[] = 'display_name = ?';
            $params[] = $data['display_name'];
        }
        if (isset($data['description'])) {
            $fields[] = 'description = ?';
            $params[] = $data['description'];
        }
        if (isset($data['color'])) {
            $fields[] = 'color = ?';
            $params[] = $data['color'];
        }
        if (!$role['is_system_role']) {
            if (isset($data['role_name'])) {
                $fields[] = 'role_name = ?';
                $params[] = $data['role_name'];
            }
            if (isset($data['role_type'])) {
                $fields[] = 'role_type = ?';
                $params[] = $data['role_type'];
            }
            if (isset($data['is_active'])) {
                $fields[] = 'is_active = ?';
                $params[] = $data['is_active'];
            }
        }

        if (!empty($fields)) {
            $params[] = $roleId;
            $stmt = $pdo->prepare("UPDATE acl_roles SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
        }

        // Update permissions if provided
        if (isset($data['permission_ids']) && is_array($data['permission_ids'])) {
            $oldPermIds = $role['permission_ids'];

            // Clear existing
            $stmt = $pdo->prepare("DELETE FROM acl_role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);

            // Insert new
            $stmt = $pdo->prepare("INSERT INTO acl_role_permissions (role_id, permission_id, granted_by) VALUES (?, ?, ?)");
            foreach ($data['permission_ids'] as $permId) {
                $stmt->execute([$roleId, $permId, $actorId]);
            }

            // Log permission changes
            aclLogChange($actorId, 'update_role_permissions', 'role', $roleId, $role['display_name'],
                json_encode($oldPermIds), json_encode($data['permission_ids']));
        }

        // Log field changes
        aclLogChange($actorId, 'update_role', 'role', $roleId, $role['display_name'],
            json_encode(['display_name' => $role['display_name'], 'color' => $role['color']]),
            json_encode($data));

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("ACL update role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete a custom role. Refuses if is_system_role=1.
 */
function aclDeleteRole($roleId, $actorId) {
    global $pdo;
    try {
        $role = aclGetRoleById($roleId);
        if (!$role) return ['success' => false, 'error' => 'Role not found'];
        if ($role['is_system_role']) return ['success' => false, 'error' => 'Cannot delete system role'];

        // Check if users are assigned
        $userCount = aclGetRoleUserCount($roleId);
        if ($userCount > 0) {
            return ['success' => false, 'error' => "Cannot delete role: $userCount users are still assigned to it"];
        }

        $pdo->beginTransaction();

        // role_permissions will cascade delete
        $stmt = $pdo->prepare("DELETE FROM acl_roles WHERE id = ? AND is_system_role = 0");
        $stmt->execute([$roleId]);

        aclLogChange($actorId, 'delete_role', 'role', $roleId, $role['display_name'],
            json_encode($role), null);

        $pdo->commit();
        return ['success' => true];
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("ACL delete role error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error'];
    }
}

/**
 * Clone a role with all its permissions.
 */
function aclCloneRole($roleId, $newName, $newDisplayName, $actorId) {
    global $pdo;
    try {
        $role = aclGetRoleById($roleId);
        if (!$role) return false;

        return aclCreateRole(
            $newName,
            $newDisplayName,
            'Cloned from: ' . $role['display_name'],
            $role['role_type'],
            $role['color'],
            $role['permission_ids'],
            $actorId
        );
    } catch (PDOException $e) {
        error_log("ACL clone role error: " . $e->getMessage());
        return false;
    }
}

/**
 * Replace all permissions for a role.
 */
function aclAssignPermissions($roleId, $permissionIds, $actorId) {
    global $pdo;
    try {
        $role = aclGetRoleById($roleId);
        if (!$role) return false;

        $pdo->beginTransaction();

        $oldPermIds = $role['permission_ids'];

        $stmt = $pdo->prepare("DELETE FROM acl_role_permissions WHERE role_id = ?");
        $stmt->execute([$roleId]);

        $stmt = $pdo->prepare("INSERT INTO acl_role_permissions (role_id, permission_id, granted_by) VALUES (?, ?, ?)");
        foreach ($permissionIds as $permId) {
            $stmt->execute([$roleId, $permId, $actorId]);
        }

        aclLogChange($actorId, 'assign_permissions', 'role', $roleId, $role['display_name'],
            json_encode($oldPermIds), json_encode($permissionIds));

        $pdo->commit();
        return true;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("ACL assign permissions error: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// USER OVERRIDES
// ============================================================

/**
 * Set a per-user permission override.
 */
function aclSetUserOverride($userType, $userId, $permissionId, $isGranted, $reason, $expiresAt, $actorId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO acl_user_overrides (user_type, user_id, permission_id, is_granted, reason, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted), reason = VALUES(reason),
                                    expires_at = VALUES(expires_at), created_by = VALUES(created_by),
                                    created_at = NOW()
        ");
        $stmt->execute([$userType, $userId, $permissionId, $isGranted ? 1 : 0, $reason, $expiresAt, $actorId]);

        // Get permission key for logging
        $stmt2 = $pdo->prepare("SELECT permission_key FROM acl_permissions WHERE id = ?");
        $stmt2->execute([$permissionId]);
        $permKey = $stmt2->fetchColumn();

        aclLogChange($actorId, 'set_override', 'user_override', $userId,
            "$userType:$userId:$permKey", null,
            json_encode(['granted' => $isGranted, 'reason' => $reason, 'expires' => $expiresAt]));

        return true;
    } catch (PDOException $e) {
        error_log("ACL set override error: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove a per-user permission override.
 */
function aclRemoveUserOverride($userType, $userId, $permissionId, $actorId) {
    global $pdo;
    try {
        // Get current override for logging
        $stmt = $pdo->prepare("
            SELECT uo.*, p.permission_key
            FROM acl_user_overrides uo
            INNER JOIN acl_permissions p ON uo.permission_id = p.id
            WHERE uo.user_type = ? AND uo.user_id = ? AND uo.permission_id = ?
        ");
        $stmt->execute([$userType, $userId, $permissionId]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM acl_user_overrides WHERE user_type = ? AND user_id = ? AND permission_id = ?");
        $stmt->execute([$userType, $userId, $permissionId]);

        if ($old) {
            aclLogChange($actorId, 'remove_override', 'user_override', $userId,
                "$userType:$userId:" . $old['permission_key'],
                json_encode(['granted' => $old['is_granted'], 'reason' => $old['reason']]), null);
        }

        return true;
    } catch (PDOException $e) {
        error_log("ACL remove override error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all overrides for a user.
 */
function aclGetUserOverrides($userType, $userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT uo.*, p.permission_key, p.display_name as permission_name, p.category_id
            FROM acl_user_overrides uo
            INNER JOIN acl_permissions p ON uo.permission_id = p.id
            WHERE uo.user_type = ? AND uo.user_id = ?
            ORDER BY p.id
        ");
        $stmt->execute([$userType, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("ACL get overrides error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// PERMISSIONS & CATEGORIES
// ============================================================

/**
 * List all permissions grouped by category.
 */
function aclListPermissions($categoryId = null) {
    global $pdo;
    try {
        // Get categories
        $stmt = $pdo->query("SELECT * FROM acl_permission_categories ORDER BY sort_order");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get permissions
        $sql = "SELECT * FROM acl_permissions";
        $params = [];
        if ($categoryId) {
            $sql .= " WHERE category_id = ?";
            $params[] = $categoryId;
        }
        $sql .= " ORDER BY category_id, id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by category
        $grouped = [];
        foreach ($categories as $cat) {
            $cat['permissions'] = [];
            $grouped[$cat['id']] = $cat;
        }
        foreach ($permissions as $perm) {
            $catId = $perm['category_id'];
            if (isset($grouped[$catId])) {
                $grouped[$catId]['permissions'][] = $perm;
            }
        }

        return array_values($grouped);
    } catch (PDOException $e) {
        error_log("ACL list permissions error: " . $e->getMessage());
        return [];
    }
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Get a user's role_id from the appropriate table.
 */
function aclGetUserRoleId($userType, $userId) {
    global $pdo;
    try {
        if ($userType === 'admin') {
            $stmt = $pdo->prepare("SELECT custom_role_id FROM admin_users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn() ?: null;
        } elseif ($userType === 'technician') {
            $stmt = $pdo->prepare("SELECT role_id FROM technicians WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetchColumn() ?: null;
        }
    } catch (PDOException $e) {
        error_log("ACL get user role error: " . $e->getMessage());
    }
    return null;
}

/**
 * Count users assigned to a role.
 */
function aclGetRoleUserCount($roleId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM admin_users WHERE custom_role_id = ?) +
                (SELECT COUNT(*) FROM technicians WHERE role_id = ?) as total
        ");
        $stmt->execute([$roleId, $roleId]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("ACL role user count error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Log an ACL change to the audit trail.
 */
function aclLogChange($actorId, $action, $targetType, $targetId, $targetName, $oldValue, $newValue) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO acl_change_log (actor_id, action, target_type, target_id, target_name, old_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $actorId,
            $action,
            $targetType,
            $targetId,
            $targetName,
            $oldValue,
            $newValue,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("ACL log change error: " . $e->getMessage());
    }
}

/**
 * Get paginated ACL change log.
 */
function aclGetChangelog($filters = [], $page = 1, $perPage = 50) {
    global $pdo;
    try {
        $where = [];
        $params = [];

        if (!empty($filters['actor_id'])) {
            $where[] = 'cl.actor_id = ?';
            $params[] = $filters['actor_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'cl.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['target_type'])) {
            $where[] = 'cl.target_type = ?';
            $params[] = $filters['target_type'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'cl.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'cl.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($page - 1) * $perPage;

        // Count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM acl_change_log cl $whereClause");
        $stmt->execute($params);
        $totalCount = $stmt->fetchColumn();

        // Data
        $stmt = $pdo->prepare("
            SELECT cl.*, au.username as actor_name
            FROM acl_change_log cl
            LEFT JOIN admin_users au ON cl.actor_id = au.id
            $whereClause
            ORDER BY cl.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = (int)$perPage;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'entries' => $entries,
            'total' => (int)$totalCount,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($totalCount / $perPage)
        ];
    } catch (PDOException $e) {
        error_log("ACL changelog error: " . $e->getMessage());
        return ['entries' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
    }
}

