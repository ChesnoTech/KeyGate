<?php
/**
 * OEM Key Helpers
 * KeyGate v2.0
 *
 * Extracted from config.php — contains allocateKeyAtomically()
 * and formatProductKeySecure().
 */

require_once __DIR__ . '/../constants.php';

/**
 * Atomic key allocation with pessimistic locking.
 *
 * Selects the best available key (unused first, then lowest fail_counter),
 * marks it as 'allocated', and returns the key row — all under a named lock
 * to prevent race conditions.
 *
 * @return array|null  The allocated key row, or null on failure.
 */
function allocateKeyAtomically($pdo, $technician_id, $order_number) {
    $lockName = "key_allocation_" . md5($technician_id . $order_number);
    $needsCommit = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $needsCommit = true;
        }

        $stmt = $pdo->prepare("SELECT GET_LOCK(?, " . DB_LOCK_TIMEOUT . ") as lock_acquired");
        $stmt->execute([$lockName]);
        $lockResult = $stmt->fetch();

        if ($lockResult['lock_acquired'] != 1) {
            $pdo->rollback();
            error_log("Could not acquire lock for key allocation: $technician_id");
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT * FROM oem_keys
            WHERE key_status IN ('unused', 'retry')
            AND (fail_counter < " . MAX_KEY_FAIL_COUNTER . " OR key_status = 'unused')
            ORDER BY
                CASE WHEN key_status = 'unused' THEN 0 ELSE 1 END,
                fail_counter ASC,
                COALESCE(last_use_date, '1970-01-01') ASC,
                id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $key = $stmt->fetch();

        if ($key) {
            $stmt = $pdo->prepare("
                UPDATE oem_keys
                SET key_status = 'allocated',
                    last_use_date = CURDATE(),
                    last_use_time = CURTIME(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$key['id']]);

            error_log("Key allocated atomically: Key ID {$key['id']} to {$technician_id} for order {$order_number}");
        }

        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);

        if ($needsCommit) {
            $pdo->commit();
        }
        return $key;

    } catch (Exception $e) {
        if ($needsCommit && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Atomic key allocation failed: " . $e->getMessage());

        try {
            $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockName]);
        } catch (Exception $lockError) {
            error_log("Failed to release lock: " . $lockError->getMessage());
        }

        throw $e;
    }
}

/**
 * Display a product key securely, masking the middle groups.
 *
 * @param string $product_key  Full 25-character product key.
 * @param string $context      'email' | 'admin' — controls masking behavior.
 * @return string  The masked (or full) key string.
 */
function formatProductKeySecure($product_key, $context = 'email') {
    if ($context === 'email') {
        $hide_keys = (bool) getConfig('hide_product_keys_in_emails');
        if ($hide_keys) {
            return "***" . substr($product_key, -KEY_MASK_SUFFIX_LEN);
        }
    } elseif ($context === 'admin') {
        $show_keys = (bool) getConfig('show_full_keys_in_admin');
        if (!$show_keys) {
            return substr($product_key, 0, KEY_MASK_PREFIX_LEN)
                . "-*****-*****-*****-"
                . substr($product_key, -KEY_MASK_SUFFIX_LEN);
        }
    }

    return $product_key;
}
