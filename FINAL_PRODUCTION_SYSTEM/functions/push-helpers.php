<?php
/**
 * Web Push Notification Helpers
 * Phase 8: Push notification dispatch, VAPID key management, category mapping
 */

// Load composer autoloader for minishlink/web-push
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;

/**
 * Map admin activity action strings to notification categories.
 * Returns null for actions that should NOT trigger notifications (too noisy).
 */
function getNotificationCategory(string $action): ?string {
    $map = [
        // Security events
        'LOGIN_FAILED'              => 'security',
        'LOGIN_BLOCKED'             => 'security',
        'ACCESS_DENIED'             => 'security',

        // Key management
        'RECYCLE_KEY'               => 'keys',
        'DELETE_KEY'                => 'keys',
        'IMPORT_KEYS'              => 'keys',
        'EXPORT_KEYS'              => 'keys',

        // Technician management
        'CREATE_TECHNICIAN'         => 'technicians',
        'UPDATE_TECHNICIAN'         => 'technicians',
        'DELETE_TECHNICIAN'         => 'technicians',
        'TOGGLE_TECHNICIAN'         => 'technicians',
        'RESET_PASSWORD'            => 'technicians',

        // System events
        'UPDATE_ALT_SERVER_SETTINGS' => 'system',
        'MANUAL_BACKUP'             => 'system',
        'ADD_TRUSTED_NETWORK'       => 'system',
        'DELETE_TRUSTED_NETWORK'    => 'system',

        // USB device events
        'REGISTER_USB_DEVICE'       => 'devices',
        'UPDATE_USB_DEVICE_STATUS'  => 'devices',
        'DELETE_USB_DEVICE'         => 'devices',
    ];

    return $map[$action] ?? null;
}

/**
 * Get the admin panel hash URL for a notification category.
 */
function getNotificationActionUrl(string $category): string {
    $urls = [
        'security'    => 'admin_v2.php#logs',
        'keys'        => 'admin_v2.php#keys',
        'technicians' => 'admin_v2.php#technicians',
        'system'      => 'admin_v2.php#settings',
        'devices'     => 'admin_v2.php#usb-devices',
        'activation'  => 'admin_v2.php#history',
    ];
    return $urls[$category] ?? 'admin_v2.php#dashboard';
}

/**
 * Get VAPID keys from system_config, auto-generating on first use.
 * Returns ['publicKey' => ..., 'privateKey' => ...] or null on failure.
 */
function getVapidKeys(): ?array {
    global $pdo;

    try {
        $publicKey = getConfig('vapid_public_key');
        $privateKey = getConfig('vapid_private_key');

        if (!empty($publicKey) && !empty($privateKey)) {
            return ['publicKey' => $publicKey, 'privateKey' => $privateKey];
        }

        // Auto-generate VAPID keys
        $keys = VAPID::createVapidKeys();

        $stmt = $pdo->prepare("
            INSERT INTO system_config (config_key, config_value, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute(['vapid_public_key', $keys['publicKey'], 'VAPID public key for Web Push (auto-generated)']);
        $stmt->execute(['vapid_private_key', $keys['privateKey'], 'VAPID private key for Web Push (auto-generated)']);

        return $keys;
    } catch (Exception $e) {
        error_log("VAPID key generation failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Dispatch a push notification and create bell entries for all subscribed admins.
 * Called from logAdminActivity() after the DB insert.
 *
 * @param string $action      The action string (e.g. LOGIN_FAILED)
 * @param string $description The human-readable description
 * @param int    $actorAdminId The admin who performed the action (excluded from notifications)
 */
function dispatchNotification(string $action, string $description, int $actorAdminId): void {
    global $pdo;

    // Check if push is globally enabled
    if (getConfig('push_notifications_enabled') !== '1') {
        return;
    }

    $category = getNotificationCategory($action);
    if ($category === null) {
        return; // Non-notifiable action
    }

    $titleKey = 'notif.title.' . $category;
    $actionUrl = getNotificationActionUrl($category);

    try {
        // Get all admin IDs except the actor, who have this category enabled (or no preference row = default ON)
        $stmt = $pdo->prepare("
            SELECT DISTINCT au.id, au.preferred_language
            FROM admin_users au
            WHERE au.id != ?
              AND au.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM push_preferences pp
                  WHERE pp.admin_id = au.id AND pp.category = ? AND pp.enabled = 0
              )
        ");
        $stmt->execute([$actorAdminId, $category]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recipients)) {
            return;
        }

        $recipientIds = array_column($recipients, 'id');

        // Insert bell notifications for all recipients
        $insertStmt = $pdo->prepare("
            INSERT INTO notifications (admin_id, category, title_key, body, action_url)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($recipientIds as $adminId) {
            $insertStmt->execute([$adminId, $category, $titleKey, $description, $actionUrl]);
        }

        // Get active push subscriptions for these recipients
        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $subStmt = $pdo->prepare("
            SELECT ps.id, ps.admin_id, ps.endpoint, ps.p256dh_key, ps.auth_key, au.preferred_language
            FROM push_subscriptions ps
            JOIN admin_users au ON ps.admin_id = au.id
            WHERE ps.admin_id IN ($placeholders) AND ps.is_active = 1
        ");
        $subStmt->execute($recipientIds);
        $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($subscriptions)) {
            return;
        }

        // Get VAPID keys
        $vapidKeys = getVapidKeys();
        if (!$vapidKeys) {
            return;
        }

        $vapidSubject = getConfigWithDefault('vapid_subject', 'mailto:admin@oem-activation.local');

        $auth = [
            'VAPID' => [
                'subject' => $vapidSubject,
                'publicKey' => $vapidKeys['publicKey'],
                'privateKey' => $vapidKeys['privateKey'],
            ],
        ];

        $webPush = new WebPush($auth);

        // Queue push messages
        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh_key'],
                'authToken' => $sub['auth_key'],
            ]);

            $payload = json_encode([
                'titleKey'  => $titleKey,
                'body'      => $description,
                'category'  => $category,
                'actionUrl' => $actionUrl,
                'lang'      => $sub['preferred_language'] ?? 'en',
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        // Flush all queued notifications
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                // Deactivate expired subscriptions
                $expiredEndpoint = $report->getRequest()->getUri()->__toString();
                $deactivateStmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?");
                $deactivateStmt->execute([$expiredEndpoint]);
            }
        }

        // Update last_used_at for all sent subscriptions
        $subIds = array_column($subscriptions, 'id');
        if (!empty($subIds)) {
            $updatePlaceholders = implode(',', array_fill(0, count($subIds), '?'));
            $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id IN ($updatePlaceholders)")
                ->execute($subIds);
        }

    } catch (Exception $e) {
        // Never break admin operations due to push failures
        error_log("Push notification dispatch failed: " . $e->getMessage());
    }
}
