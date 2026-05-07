<?php
/**
 * Integration Framework — Core helper functions
 * Provides event dispatching, status management, and integration queries.
 */

$integrationCache = [];

/**
 * Get integration config by key (cached per-request)
 */
function getIntegration(string $key): ?array {
    global $pdo, $integrationCache;

    if (isset($integrationCache[$key])) {
        return $integrationCache[$key];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `" . t('integrations') . "` WHERE integration_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            $integrationCache[$key] = null;
            return null;
        }

        $row['config'] = json_decode($row['config'] ?: '{}', true) ?: [];
        $integrationCache[$key] = $row;
        return $row;
    } catch (PDOException $e) {
        error_log("getIntegration($key) failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Quick check if an integration is enabled and not in error state
 */
function isIntegrationEnabled(string $key): bool {
    $intg = getIntegration($key);
    return $intg && (int)$intg['enabled'] === 1;
}

/**
 * Update integration status and optional error message
 */
function updateIntegrationStatus(int $id, string $status, ?string $error = null): void {
    global $pdo;

    try {
        if ($error !== null) {
            $stmt = $pdo->prepare("UPDATE `" . t('integrations') . "` SET status = ?, last_error = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $error, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE `" . t('integrations') . "` SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);
        }

        // Clear cache
        global $integrationCache;
        $integrationCache = [];
    } catch (PDOException $e) {
        error_log("updateIntegrationStatus failed: " . $e->getMessage());
    }
}

/**
 * Dispatch an event to a specific integration.
 * If the integration is not enabled, returns immediately (no-op).
 * Otherwise, inserts an event record and attempts immediate delivery.
 */
function dispatchIntegrationEvent(string $integrationKey, string $eventType, array $payload): void {
    global $pdo;

    $intg = getIntegration($integrationKey);
    if (!$intg || (int)$intg['enabled'] !== 1) {
        return; // Fast path — integration not active
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `" . t('integration_events') . "` (integration_id, event_type, payload, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $intg['id'],
            $eventType,
            json_encode($payload),
        ]);
        $eventId = $pdo->lastInsertId();

        // Attempt immediate delivery based on integration type
        deliverIntegrationEvent((int)$eventId, $intg);

    } catch (PDOException $e) {
        error_log("dispatchIntegrationEvent($integrationKey, $eventType) failed: " . $e->getMessage());
    }
}

/**
 * Dispatch an event to ALL enabled integrations
 */
function dispatchEventToAll(string $eventType, array $payload): void {
    global $pdo;

    try {
        $stmt = $pdo->query("SELECT integration_key FROM `" . t('integrations') . "` WHERE enabled = 1");
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($keys as $key) {
            dispatchIntegrationEvent($key, $eventType, $payload);
        }
    } catch (PDOException $e) {
        error_log("dispatchEventToAll($eventType) failed: " . $e->getMessage());
    }
}

/**
 * Attempt delivery of a single event
 */
function deliverIntegrationEvent(int $eventId, array $integration): void {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT * FROM `" . t('integration_events') . "` WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        if (!$event) return;

        $config = $integration['config'];
        $key = $integration['integration_key'];
        $payload = json_decode($event['payload'] ?: '{}', true) ?: [];

        // Load integration-specific handler
        $handlerFile = dirname(__DIR__) . "/functions/integrations/{$key}-handler.php";
        if (!file_exists($handlerFile)) {
            // No handler yet — mark as skipped
            $stmt = $pdo->prepare("UPDATE `" . t('integration_events') . "` SET status = 'skipped', processed_at = NOW(), error_message = 'No handler file' WHERE id = ?");
            $stmt->execute([$eventId]);
            return;
        }

        require_once $handlerFile;
        $handlerFunc = 'handle_' . str_replace('-', '_', $key) . '_event';

        if (!function_exists($handlerFunc)) {
            $stmt = $pdo->prepare("UPDATE `" . t('integration_events') . "` SET status = 'skipped', processed_at = NOW(), error_message = 'Handler function not found' WHERE id = ?");
            $stmt->execute([$eventId]);
            return;
        }

        // Call the handler
        $result = $handlerFunc($event['event_type'], $payload, $config);

        // Update event with result
        $status = ($result['success'] ?? false) ? 'sent' : 'failed';
        $stmt = $pdo->prepare("
            UPDATE `" . t('integration_events') . "`
            SET status = ?, response_code = ?, response_body = ?, error_message = ?, processed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $result['http_code'] ?? null,
            $result['response'] ?? null,
            $result['error'] ?? null,
            $eventId
        ]);

        // Update integration status
        if ($status === 'sent') {
            updateIntegrationStatus($integration['id'], 'connected');
            $pdo->prepare("UPDATE `" . t('integrations') . "` SET last_sync_at = NOW() WHERE id = ?")->execute([$integration['id']]);
        } else {
            updateIntegrationStatus($integration['id'], 'error', $result['error'] ?? 'Delivery failed');
        }

    } catch (Exception $e) {
        error_log("deliverIntegrationEvent($eventId) failed: " . $e->getMessage());
        try {
            $stmt = $pdo->prepare("UPDATE `" . t('integration_events') . "` SET status = 'failed', processed_at = NOW(), error_message = ? WHERE id = ?");
            $stmt->execute([$e->getMessage(), $eventId]);
        } catch (PDOException $ex) {
            // Ignore
        }
    }
}

/**
 * Retry failed/pending events for an integration
 */
function retryFailedEvents(string $integrationKey, int $limit = 50): array {
    global $pdo;

    $intg = getIntegration($integrationKey);
    if (!$intg) {
        return ['success' => false, 'error' => 'Integration not found'];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id FROM `" . t('integration_events') . "`
            WHERE integration_id = ? AND status IN ('failed', 'pending')
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$intg['id'], $limit]);
        $eventIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $retried = 0;
        $succeeded = 0;
        foreach ($eventIds as $eid) {
            deliverIntegrationEvent((int)$eid, $intg);
            $retried++;

            // Check if it succeeded
            $check = $pdo->prepare("SELECT status FROM `" . t('integration_events') . "` WHERE id = ?");
            $check->execute([$eid]);
            if ($check->fetchColumn() === 'sent') {
                $succeeded++;
            }
        }

        return ['success' => true, 'retried' => $retried, 'succeeded' => $succeeded];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
