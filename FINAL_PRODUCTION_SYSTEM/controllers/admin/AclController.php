<?php
/**
 * ACL Controller - Roles & Permissions Management
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

$webRoot = dirname(__DIR__, 2);
require_once $webRoot . '/functions/acl.php';

function handle_acl_list_roles(PDO $pdo, array $admin_session): void {
    requirePermission('view_system_info', $admin_session);
    $roleType = $_GET['role_type'] ?? null;
    $roles = aclListRoles($roleType);
    jsonResponse(['success' => true, 'roles' => $roles]);
}

function handle_acl_get_role(PDO $pdo, array $admin_session): void {
    requirePermission('view_system_info', $admin_session);
    $roleId = (int)($_GET['role_id'] ?? 0);
    $role = aclGetRoleById($roleId);
    if ($role) {
        jsonResponse(['success' => true, 'role' => $role]);
    } else {
        jsonResponse(['success' => false, 'error' => 'Role not found']);
    }
}

function handle_acl_list_permissions(PDO $pdo, array $admin_session): void {
    requirePermission('manage_roles', $admin_session);
    $categories = aclListPermissions();
    // Hide is_dangerous flag for non-super-admins
    if (!isActorSuperAdmin($admin_session['admin_id'])) {
        foreach ($categories as &$cat) {
            foreach ($cat['permissions'] as &$p) {
                unset($p['is_dangerous']);
            }
        }
        unset($cat, $p);
    }
    jsonResponse(['success' => true, 'categories' => $categories]);
}

function handle_acl_create_role(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_admins', $admin_session);

    $data = $json_input;
    if (!$data) $data = $_POST;

    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($data['role_name'] ?? ''));
    $displayName = htmlspecialchars(strip_tags($data['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars(strip_tags($data['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    $roleType = in_array($data['role_type'] ?? '', ['admin', 'technician']) ? $data['role_type'] : 'admin';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'] ?? '') ? $data['color'] : '#6c757d';
    $rawPermIds = $data['permission_ids'] ?? [];
    $permIds = is_string($rawPermIds) ? array_map('intval', array_filter(explode(',', $rawPermIds), 'strlen')) : array_map('intval', (array)$rawPermIds);
    // Only super_admin can assign dangerous permissions
    $permIds = filterDangerousPermissions($permIds, $admin_session['admin_id']);

    if (empty($name) || empty($displayName)) {
        jsonResponse(['success' => false, 'error' => 'Role name and display name are required']);
        return;
    }

    // Rate limit: max 20 custom roles
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . t('acl_roles') . "` WHERE is_system_role = 0");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() >= 20) {
        jsonResponse(['success' => false, 'error' => 'Maximum custom roles limit reached (20)']);
        return;
    }

    $newId = aclCreateRole($name, $displayName, $description, $roleType, $color, $permIds, $admin_session['admin_id']);
    if ($newId) {
        jsonResponse(['success' => true, 'role_id' => $newId, 'message' => 'Role created successfully']);
    } else {
        jsonResponse(['success' => false, 'error' => 'Failed to create role (name may already exist)']);
    }
}

function handle_acl_update_role(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_admins', $admin_session);

    $data = $json_input;
    if (!$data) $data = $_POST;
    $roleId = (int)($data['role_id'] ?? 0);

    if (!$roleId) {
        jsonResponse(['success' => false, 'error' => 'Role ID required']);
        return;
    }

    // Sanitize input fields
    if (isset($data['role_name'])) {
        $data['role_name'] = preg_replace('/[^a-z0-9_]/', '', strtolower($data['role_name']));
    }
    if (isset($data['display_name'])) {
        $data['display_name'] = htmlspecialchars(strip_tags($data['display_name']), ENT_QUOTES, 'UTF-8');
    }
    if (isset($data['description'])) {
        $data['description'] = htmlspecialchars(strip_tags($data['description']), ENT_QUOTES, 'UTF-8');
    }
    if (isset($data['role_type']) && !in_array($data['role_type'], ['admin', 'technician'])) {
        $data['role_type'] = 'admin';
    }
    if (isset($data['color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $data['color'])) {
        $data['color'] = '#6c757d';
    }
    if (isset($data['permission_ids'])) {
        $rawPermIds = $data['permission_ids'];
        $data['permission_ids'] = is_string($rawPermIds) ? array_map('intval', array_filter(explode(',', $rawPermIds), 'strlen')) : array_map('intval', (array)$rawPermIds);
        // Only super_admin can assign dangerous permissions
        $data['permission_ids'] = filterDangerousPermissions($data['permission_ids'], $admin_session['admin_id']);
    }

    $result = aclUpdateRole($roleId, $data, $admin_session['admin_id']);
    if (is_array($result) && isset($result['error']) && $result['error'] === 'conflict') {
        jsonResponse(['success' => false, 'error' => $result['message']]);
    } else {
        jsonResponse(['success' => (bool)$result, 'message' => $result ? 'Role updated' : 'Update failed']);
    }
}

function handle_acl_delete_role(PDO $pdo, array $admin_session): void {
    requirePermission('manage_admins', $admin_session);
    $roleId = (int)($_GET['role_id'] ?? $_POST['role_id'] ?? 0);
    $result = aclDeleteRole($roleId, $admin_session['admin_id']);
    jsonResponse($result);
}

function handle_acl_clone_role(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_admins', $admin_session);

    $data = $json_input;
    if (!$data) $data = $_POST;

    $sourceId = (int)($data['source_role_id'] ?? 0);
    $newName = preg_replace('/[^a-z0-9_]/', '', strtolower($data['new_name'] ?? ''));
    $newDisplayName = htmlspecialchars(strip_tags($data['new_display_name'] ?? ''), ENT_QUOTES, 'UTF-8');

    if (!$sourceId || !$newName || !$newDisplayName) {
        jsonResponse(['success' => false, 'error' => 'Source role, new name, and display name required']);
        return;
    }

    $newId = aclCloneRole($sourceId, $newName, $newDisplayName, $admin_session['admin_id']);
    jsonResponse(['success' => (bool)$newId, 'role_id' => $newId, 'message' => $newId ? 'Role cloned' : 'Clone failed']);
}

function handle_acl_get_user_effective(PDO $pdo, array $admin_session): void {
    requirePermission('view_system_info', $admin_session);
    $userType = in_array($_GET['user_type'] ?? '', ['admin', 'technician']) ? $_GET['user_type'] : 'admin';
    $userId = (int)($_GET['user_id'] ?? 0);

    // Non-super-admins can only query their own permissions or technician permissions they manage
    if (!isActorSuperAdmin($admin_session['admin_id'])) {
        if ($userType === 'admin' && $userId !== (int)$admin_session['admin_id']) {
            jsonResponse(['success' => false, 'error' => 'You can only view your own permissions']);
            return;
        }
    }

    $permissions = aclGetEffectivePermissions($userType, $userId);
    $overrides = aclGetUserOverrides($userType, $userId);
    jsonResponse(['success' => true, 'permissions' => $permissions, 'overrides' => $overrides]);
}

function handle_acl_set_user_override(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_admins', $admin_session);

    $data = $json_input;
    if (!$data) $data = $_POST;

    // Prevent self-modification of permission overrides
    $targetUserType = $data['user_type'] ?? 'admin';
    $targetUserId = (int)($data['user_id'] ?? 0);
    if ($targetUserType === 'admin' && $targetUserId === (int)$admin_session['admin_id']) {
        jsonResponse(['success' => false, 'error' => 'Cannot modify your own permission overrides']);
        return;
    }

    $safeUserType = in_array($data['user_type'] ?? '', ['admin', 'technician']) ? $data['user_type'] : 'admin';
    $safeReason = isset($data['reason']) ? htmlspecialchars(strip_tags($data['reason']), ENT_QUOTES, 'UTF-8') : null;
    $safeExpiry = isset($data['expires_at']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $data['expires_at']) ? $data['expires_at'] : null;

    $result = aclSetUserOverride(
        $safeUserType,
        (int)($data['user_id'] ?? 0),
        (int)($data['permission_id'] ?? 0),
        (bool)($data['is_granted'] ?? true),
        $safeReason,
        $safeExpiry,
        $admin_session['admin_id']
    );
    jsonResponse(['success' => $result]);
}

function handle_acl_remove_user_override(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_admins', $admin_session);

    $data = $json_input;
    if (!$data) $data = $_POST;

    // Prevent self-modification of permission overrides
    $targetUserType = $data['user_type'] ?? 'admin';
    $targetUserId = (int)($data['user_id'] ?? 0);
    if ($targetUserType === 'admin' && $targetUserId === (int)$admin_session['admin_id']) {
        jsonResponse(['success' => false, 'error' => 'Cannot modify your own permission overrides']);
        return;
    }

    $result = aclRemoveUserOverride(
        $data['user_type'] ?? 'admin',
        (int)($data['user_id'] ?? 0),
        (int)($data['permission_id'] ?? 0),
        $admin_session['admin_id']
    );
    jsonResponse(['success' => $result]);
}

function handle_acl_get_changelog(PDO $pdo, array $admin_session): void {
    requirePermission('view_logs', $admin_session);
    $page = (int)($_GET['page'] ?? 1);
    $result = aclGetChangelog([], $page, 30);
    jsonResponse(['success' => true, 'data' => $result]);
}
