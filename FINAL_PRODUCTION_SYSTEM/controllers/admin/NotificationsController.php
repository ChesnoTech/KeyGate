<?php
/**
 * Notifications Controller
 * Handles push subscription management, notification preferences, and bell dropdown data.
 */

/**
 * Return the VAPID public key for client-side push subscription.
 */
function handle_push_get_vapid_key(PDO $pdo, array $admin_session): void {
    $keys = getVapidKeys();
    if (!$keys) {
        echo json_encode(['success' => false, 'error' => 'VAPID keys unavailable']);
        return;
    }
    $enabled = getConfig('push_notifications_enabled') === '1';
    echo json_encode([
        'success' => true,
        'vapidPublicKey' => $keys['publicKey'],
        'pushEnabled' => $enabled,
    ]);
}

/**
 * Subscribe a browser push endpoint for the current admin.
 */
function handle_push_subscribe(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $endpoint = $json_input['endpoint'] ?? '';
    $p256dh = $json_input['p256dh'] ?? '';
    $auth = $json_input['auth'] ?? '';

    if (empty($endpoint) || empty($p256dh) || empty($auth)) {
        echo json_encode(['success' => false, 'error' => 'Missing subscription data']);
        return;
    }

    $adminId = (int)$admin_session['admin_id'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Upsert: insert or re-activate existing subscription
    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions (admin_id, endpoint, p256dh_key, auth_key, user_agent, is_active)
        VALUES (?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key),
            user_agent = VALUES(user_agent), is_active = 1, last_used_at = NOW()
    ");
    $stmt->execute([$adminId, $endpoint, $p256dh, $auth, $userAgent]);

    echo json_encode(['success' => true]);
}

/**
 * Unsubscribe (deactivate) a push endpoint for the current admin.
 */
function handle_push_unsubscribe(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $endpoint = $json_input['endpoint'] ?? '';
    $adminId = (int)$admin_session['admin_id'];

    if (empty($endpoint)) {
        // Deactivate all subscriptions for this admin
        $stmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE admin_id = ?");
        $stmt->execute([$adminId]);
    } else {
        $stmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE admin_id = ? AND endpoint = ?");
        $stmt->execute([$adminId, $endpoint]);
    }

    echo json_encode(['success' => true]);
}

/**
 * Get push preferences (category toggles) and subscription status for the current admin.
 */
function handle_get_push_preferences(PDO $pdo, array $admin_session): void {
    $adminId = (int)$admin_session['admin_id'];

    // Get saved preferences
    $stmt = $pdo->prepare("SELECT category, enabled FROM push_preferences WHERE admin_id = ?");
    $stmt->execute([$adminId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = ['security', 'keys', 'technicians', 'system', 'devices', 'activation'];
    $prefs = [];
    $savedPrefs = [];
    foreach ($rows as $row) {
        $savedPrefs[$row['category']] = (bool)$row['enabled'];
    }
    foreach ($categories as $cat) {
        $prefs[$cat] = $savedPrefs[$cat] ?? true; // Default ON
    }

    // Check if admin has any active subscriptions
    $subStmt = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE admin_id = ? AND is_active = 1");
    $subStmt->execute([$adminId]);
    $hasSubscription = (int)$subStmt->fetchColumn() > 0;

    echo json_encode([
        'success' => true,
        'preferences' => $prefs,
        'subscribed' => $hasSubscription,
        'pushEnabled' => getConfig('push_notifications_enabled') === '1',
    ]);
}

/**
 * Save push preferences (category toggles) for the current admin.
 */
function handle_save_push_preferences(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $adminId = (int)$admin_session['admin_id'];
    $preferences = $json_input['preferences'] ?? [];

    if (!is_array($preferences)) {
        echo json_encode(['success' => false, 'error' => 'Invalid preferences format']);
        return;
    }

    $validCategories = ['security', 'keys', 'technicians', 'system', 'devices', 'activation'];

    $stmt = $pdo->prepare("
        INSERT INTO push_preferences (admin_id, category, enabled)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)
    ");

    foreach ($validCategories as $cat) {
        if (isset($preferences[$cat])) {
            $enabled = $preferences[$cat] ? 1 : 0;
            $stmt->execute([$adminId, $cat, $enabled]);
        }
    }

    echo json_encode(['success' => true]);
}

/**
 * Get recent notifications for the bell dropdown.
 */
function handle_get_notifications(PDO $pdo, array $admin_session): void {
    $adminId = (int)$admin_session['admin_id'];

    // Get 50 most recent notifications
    $stmt = $pdo->prepare("
        SELECT id, category, title_key, body, action_url, is_read, created_at
        FROM notifications
        WHERE admin_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$adminId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unread count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE admin_id = ? AND is_read = 0");
    $countStmt->execute([$adminId]);
    $unreadCount = (int)$countStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
    ]);
}

/**
 * Send a test push notification and bell entry to the current admin.
 */
function handle_send_test_notification(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $adminId = (int)$admin_session['admin_id'];
    $type = $json_input['type'] ?? 'push'; // 'push' or 'sound'

    // Insert a bell notification for this admin
    $stmt = $pdo->prepare("
        INSERT INTO notifications (admin_id, category, title_key, body, action_url)
        VALUES (?, 'system', 'notif.title.system', ?, 'admin_v2.php#notifications')
    ");
    $testBody = $type === 'sound'
        ? 'Sound notification test'
        : 'This is a test push notification';
    $stmt->execute([$adminId, $testBody]);

    // If type=push, also send an actual Web Push to this admin's subscriptions
    if ($type === 'push') {
        $subStmt = $pdo->prepare("
            SELECT ps.endpoint, ps.p256dh_key, ps.auth_key, au.preferred_language
            FROM push_subscriptions ps
            JOIN admin_users au ON ps.admin_id = au.id
            WHERE ps.admin_id = ? AND ps.is_active = 1
        ");
        $subStmt->execute([$adminId]);
        $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($subs)) {
            $vapidKeys = getVapidKeys();
            if ($vapidKeys) {
                $vapidSubject = getConfig('vapid_subject') ?: 'mailto:admin@oem-activation.local';
                $webPush = new \Minishlink\WebPush\WebPush([
                    'VAPID' => [
                        'subject' => $vapidSubject,
                        'publicKey' => $vapidKeys['publicKey'],
                        'privateKey' => $vapidKeys['privateKey'],
                    ],
                ]);

                foreach ($subs as $sub) {
                    $subscription = \Minishlink\WebPush\Subscription::create([
                        'endpoint' => $sub['endpoint'],
                        'publicKey' => $sub['p256dh_key'],
                        'authToken' => $sub['auth_key'],
                    ]);
                    $payload = json_encode([
                        'titleKey' => 'notif.title.system',
                        'body' => $testBody,
                        'category' => 'system',
                        'actionUrl' => 'admin_v2.php#notifications',
                        'lang' => $sub['preferred_language'] ?? 'en',
                    ]);
                    $webPush->queueNotification($subscription, $payload);
                }

                foreach ($webPush->flush() as $report) {
                    if ($report->isSubscriptionExpired()) {
                        $endpoint = $report->getRequest()->getUri()->__toString();
                        $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?")
                            ->execute([$endpoint]);
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true, 'type' => $type]);
}

/**
 * Mark notifications as read (specific IDs or all).
 */
function handle_mark_notifications_read(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $adminId = (int)$admin_session['admin_id'];
    $ids = $json_input['ids'] ?? null;

    if ($ids === null || (is_array($ids) && empty($ids))) {
        // Mark all as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE admin_id = ? AND is_read = 0");
        $stmt->execute([$adminId]);
    } elseif (is_array($ids)) {
        $intIds = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE admin_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$adminId], $intIds));
    }

    echo json_encode(['success' => true]);
}
