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

    // Support X-Admin-Token header as alternative to session (for CI/API testing)
    if (!isset($_SESSION['admin_token']) && !empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
        $_SESSION['admin_token'] = $_SERVER['HTTP_X_ADMIN_TOKEN'];
    }

    if (!isset($_SESSION['admin_token'])) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                s.id, s.admin_id, s.expires_at, s.last_activity,
                u.username, u.full_name, u.role, u.is_active, u.preferred_language,
                u.password_changed_at, u.must_change_password
            FROM admin_sessions s
            JOIN admin_users u ON s.admin_id = u.id
            WHERE s.session_token = ? AND s.is_active = 1
        ");
        $stmt->execute([$_SESSION['admin_token']]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return false;
        }

        // Check if session expired (hard expiry set at creation time)
        if (strtotime($session['expires_at']) < time()) {
            $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE id = ?");
            $stmt->execute([$session['id']]);
            return false;
        }

        // Check inactivity timeout (configurable via system_config, fallback to constant)
        $timeoutMinutes = (int) getConfigWithDefault('admin_session_timeout_minutes', DEFAULT_ADMIN_SESSION_TIMEOUT_MINUTES);
        $timeoutSeconds = $timeoutMinutes * 60;
        if (strtotime($session['last_activity']) < (time() - $timeoutSeconds)) {
            $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE id = ?");
            $stmt->execute([$session['id']]);
            return false;
        }

        // Check if user is active
        if (!$session['is_active']) {
            return false;
        }

        // Check password age if force-change is configured
        $forceChangeDays = (int) getConfigWithDefault('admin_force_password_change_days', DEFAULT_ADMIN_PASSWORD_CHANGE_DAYS);
        if (!empty($session['password_changed_at'])) {
            $passwordAge = time() - strtotime($session['password_changed_at']);
            $maxAge = $forceChangeDays * 24 * 3600;
            if ($passwordAge > $maxAge) {
                $session['must_change_password'] = true;
            }
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
 * Authenticate an admin user by username and password.
 * Handles lockout checking, failed-attempt tracking, session creation.
 *
 * @param string $username
 * @param string $password
 * @return array|false|array{error:string} Admin user row on success, false on invalid credentials, ['error'=>...] on lockout
 */
function authenticateAdmin($username, $password) {
    global $pdo;

    // Load configurable lockout settings from system_config, with constant fallbacks
    $maxFailedLogins = (int) getConfigWithDefault('admin_max_failed_logins', DEFAULT_ADMIN_MAX_FAILED_LOGINS);
    $lockoutMinutes  = (int) getConfigWithDefault('admin_lockout_duration_minutes', DEFAULT_ADMIN_LOCKOUT_MINUTES);
    $sessionTimeout  = (int) getConfigWithDefault('admin_session_timeout_minutes', DEFAULT_ADMIN_SESSION_TIMEOUT_MINUTES);

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin) {
        appLog('warning', 'Admin login failed: invalid username', [
            'event' => 'auth_failure', 'username' => $username,
        ]);
        logAdminActivity(null, null, 'LOGIN_FAILED', "Invalid username: $username");
        return false;
    }

    // Check lockout
    if ($admin['locked_until'] && $admin['locked_until'] > date('Y-m-d H:i:s')) {
        logAdminActivity($admin['id'], null, 'LOGIN_BLOCKED', 'Account locked');
        return ['error' => 'Account locked until ' . $admin['locked_until']];
    }

    // Verify password
    if (!password_verify($password, $admin['password_hash'])) {
        $failed_attempts = $admin['failed_login_attempts'] + 1;
        $locked_until = null;

        if ($failed_attempts >= $maxFailedLogins) {
            $locked_until = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
        }

        $stmt = $pdo->prepare("
            UPDATE admin_users
            SET failed_login_attempts = ?, locked_until = ?
            WHERE id = ?
        ");
        $stmt->execute([$failed_attempts, $locked_until, $admin['id']]);

        appLog('warning', 'Admin login failed: wrong password', [
            'event' => 'auth_failure', 'username' => $username,
            'attempt' => $failed_attempts, 'locked' => $locked_until !== null,
        ]);
        logAdminActivity($admin['id'], null, 'LOGIN_FAILED', "Failed password attempt #$failed_attempts");
        return false;
    }

    // Create session
    $session_token = bin2hex(random_bytes(SESSION_TOKEN_BYTES));
    $expires_at = date('Y-m-d H:i:s', time() + ($sessionTimeout * 60));

    $stmt = $pdo->prepare("
        INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $admin['id'], $session_token, getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expires_at
    ]);
    $session_id = $pdo->lastInsertId();

    // Reset failed attempts
    $stmt = $pdo->prepare("
        UPDATE admin_users
        SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW(), last_login_ip = ?
        WHERE id = ?
    ");
    $stmt->execute([getClientIP(), $admin['id']]);

    $_SESSION['admin_token'] = $session_token;
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['session_id'] = $session_id;

    logAdminActivity($admin['id'], $session_id, 'LOGIN_SUCCESS', 'Successful login');

    return $admin;
}

// ── Shared Upload / Config / Download Helpers ────────────────────────

/**
 * Get a human-readable error message for a PHP file upload error code.
 */
function getUploadErrorMessage(int $errorCode): string {
    $errMap = [
        UPLOAD_ERR_INI_SIZE  => 'File exceeds server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
        UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE   => 'No file was uploaded',
    ];
    return $errMap[$errorCode] ?? 'Upload failed (code: ' . $errorCode . ')';
}

/**
 * Batch-save key-value pairs to system_config using UPSERT.
 */
function saveConfigBatch(PDO $pdo, array $configs, array $descriptions = []): void {
    $stmt = $pdo->prepare("
        INSERT INTO system_config (config_key, config_value, description, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()
    ");
    foreach ($configs as $key => $value) {
        $desc = $descriptions[$key] ?? '';
        $stmt->execute([$key, $value, $desc, $value]);
    }
}

/**
 * Stream a file download with proper headers and exit.
 */
function streamFileDownload(string $filePath, string $filename, string $mimeType, int $fileSize, string $checksum): void {
    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
    header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . $fileSize);
    header('X-Checksum-SHA256: ' . $checksum);
    header('Cache-Control: no-store');
    readfile($filePath);
    exit;
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
