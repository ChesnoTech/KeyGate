<?php
/**
 * Admin Helper Functions
 * Extracted from admin_v2.php (Phase 3 refactoring)
 *
 * Provides: validateAdminSession(), logAdminActivity(),
 *           isActorSuperAdmin(), filterDangerousPermissions()
 */

/**
 * Validate admin session token against database.
 * Checks: token exists, session not expired, inactivity timeout, user active.
 * Updates last_activity on success.
 *
 * @return array|false Session data array or false
 */
function validateAdminSession() {
    global $pdo;

    if (!isset($_SESSION['admin_token'])) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                s.id, s.admin_id, s.expires_at, s.last_activity,
                u.username, u.full_name, u.role, u.is_active, u.preferred_language
            FROM admin_sessions s
            JOIN admin_users u ON s.admin_id = u.id
            WHERE s.session_token = ? AND s.is_active = 1
        ");
        $stmt->execute([$_SESSION['admin_token']]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return false;
        }

        // Check if session expired
        if (strtotime($session['expires_at']) < time()) {
            return false;
        }

        // Check inactivity timeout
        if (strtotime($session['last_activity']) < (time() - SESSION_INACTIVITY_SECONDS)) {
            return false;
        }

        // Check if user is active
        if (!$session['is_active']) {
            return false;
        }

        // Update last activity
        $stmt = $pdo->prepare("UPDATE admin_sessions SET last_activity = NOW() WHERE id = ?");
        $stmt->execute([$session['id']]);

        return $session;
    } catch (Exception $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log admin activity to audit trail.
 *
 * @param int    $admin_id   Admin user ID
 * @param int    $session_id Session record ID
 * @param string $action     Action name
 * @param string $description Optional description
 */
function logAdminActivity($admin_id, $session_id, $action, $description = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $admin_id, $session_id, $action, $description,
            getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        // Dispatch push notification (if push-helpers.php is loaded)
        if (function_exists('dispatchNotification')) {
            dispatchNotification($action, $description, $admin_id);
        }
    } catch (Exception $e) {
        error_log("Failed to log admin activity: " . $e->getMessage());
    }
}

/**
 * Check if the actor is a super_admin (needed for dangerous permission operations).
 *
 * @param int $adminId Admin user ID
 * @return bool
 */
function isActorSuperAdmin($adminId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT r.role_name FROM acl_roles r
            INNER JOIN admin_users u ON u.custom_role_id = r.id
            WHERE u.id = ? AND r.role_name = 'super_admin'
        ");
        $stmt->execute([$adminId]);
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Filter out dangerous permission IDs — only super_admin can assign them.
 *
 * @param array $permissionIds Array of permission IDs
 * @param int   $actorId       Admin user performing the action
 * @return array Filtered permission IDs (dangerous ones removed for non-super-admins)
 */
function filterDangerousPermissions($permissionIds, $actorId) {
    global $pdo;
    if (isActorSuperAdmin($actorId)) {
        return $permissionIds; // Super admin can assign anything
    }
    try {
        $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM acl_permissions WHERE id IN ($placeholders) AND is_dangerous = 1");
        $stmt->execute($permissionIds);
        $dangerousIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($permissionIds, $dangerousIds));
    } catch (Exception $e) {
        return $permissionIds;
    }
}
