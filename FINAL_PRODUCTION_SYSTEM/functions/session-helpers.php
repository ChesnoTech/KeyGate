<?php
/**
 * Session Management Helpers
 * OEM Activation System v2.0
 *
 * Extracted from config.php — contains validateSession(),
 * getActiveSession(), cleanupExpiredSessions(), generateToken().
 */

require_once __DIR__ . '/../constants.php';

/**
 * Generate a cryptographically secure random token.
 */
function generateToken($length = SESSION_TOKEN_BYTES) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    }
    return bin2hex(openssl_random_pseudo_bytes($length));
}

/**
 * Validate a session token and return session data (or false).
 */
function validateSession($token) {
    global $pdo;

    if (empty($token) || !is_string($token)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT s.*, k.product_key, k.key_status, t.is_active as tech_active
            FROM active_sessions s
            LEFT JOIN oem_keys k ON s.key_id = k.id
            LEFT JOIN technicians t ON s.technician_id = t.technician_id
            WHERE s.session_token = ?
            AND s.is_active = 1
            AND s.expires_at > NOW()
            AND t.is_active = 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Deactivate expired sessions in batches.
 */
function cleanupExpiredSessions($pdo) {
    try {
        $stmt = $pdo->prepare("
            UPDATE active_sessions
            SET is_active = 0
            WHERE expires_at < NOW() AND is_active = 1
            LIMIT " . SESSION_CLEANUP_BATCH . "
        ");
        $stmt->execute();
        $cleaned = $stmt->rowCount();

        if ($cleaned > 0) {
            error_log("Cleaned up {$cleaned} expired sessions");
        }

        return $cleaned;
    } catch (Exception $e) {
        error_log("Session cleanup failed: " . $e->getMessage());
        return 0;
    }
}

/**
 * Retrieve the most recent active session for a technician (with FOR UPDATE lock).
 */
function getActiveSession($pdo, $technician_id) {
    try {
        cleanupExpiredSessions($pdo);

        $stmt = $pdo->prepare("
            SELECT s.*, k.product_key, k.oem_identifier, k.key_status, k.fail_counter
            FROM active_sessions s
            LEFT JOIN oem_keys k ON s.key_id = k.id
            WHERE s.technician_id = ?
            AND s.is_active = 1
            AND s.expires_at > NOW()
            ORDER BY s.created_at DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$technician_id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get active session failed: " . $e->getMessage());
        return null;
    }
}
