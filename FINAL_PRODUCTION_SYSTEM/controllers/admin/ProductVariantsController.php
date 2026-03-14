<?php
/**
 * Product Variants Controller — Product Lines, Variants & Partition Templates
 * Manages the hierarchy: Product Line (order pattern) → Variants (disk sizes) → Partitions
 */

require_once dirname(__DIR__, 2) . '/config.php';

// ── Product Lines ────────────────────────────────────────────

function handle_get_product_lines(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    $stmt = $pdo->query("
        SELECT pl.*,
               COUNT(DISTINCT pv.id) AS variant_count
        FROM product_lines pl
        LEFT JOIN product_variants pv ON pv.line_id = pl.id AND pv.is_active = 1
        GROUP BY pl.id
        ORDER BY pl.name
    ");
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(['success' => true, 'lines' => $lines]);
}

function handle_get_product_line(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('view_compliance', $admin_session);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid line ID'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM product_lines WHERE id = ?");
    $stmt->execute([$id]);
    $line = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        jsonResponse(['success' => false, 'error' => 'Product line not found'], 404);
    }

    // Fetch variants
    $stmt = $pdo->prepare("
        SELECT pv.*
        FROM product_variants pv
        WHERE pv.line_id = ? AND pv.is_active = 1
        ORDER BY pv.disk_size_min_mb
    ");
    $stmt->execute([$id]);
    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all partitions for these variants
    $variantIds = array_column($variants, 'id');
    $partitions = [];
    if (!empty($variantIds)) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmt = $pdo->prepare("
            SELECT * FROM product_variant_partitions
            WHERE variant_id IN ($placeholders)
            ORDER BY variant_id, partition_order
        ");
        $stmt->execute($variantIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $partitions[$p['variant_id']][] = $p;
        }
    }

    // Attach partitions to variants
    foreach ($variants as &$v) {
        $v['partitions'] = $partitions[$v['id']] ?? [];
    }
    unset($v);

    $line['variants'] = $variants;
    jsonResponse(['success' => true, 'line' => $line]);
}

function handle_save_product_line(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $id = (int) ($json_input['id'] ?? 0);
    $name = trim($json_input['name'] ?? '');
    $orderPattern = trim($json_input['order_pattern'] ?? '');
    $description = trim($json_input['description'] ?? '');
    $enforcement = (int) ($json_input['enforcement_level'] ?? 2);
    $isActive = (int) ($json_input['is_active'] ?? 1);

    if ($name === '' || $orderPattern === '') {
        jsonResponse(['success' => false, 'error' => 'Name and order pattern are required'], 400);
    }

    if ($enforcement < 0 || $enforcement > 3) {
        jsonResponse(['success' => false, 'error' => 'Invalid enforcement level'], 400);
    }

    try {
        if ($id > 0) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE product_lines
                SET name = ?, order_pattern = ?, description = ?, enforcement_level = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $orderPattern, $description, $enforcement, $isActive, $id]);
            $action = 'PRODUCT_LINE_UPDATE';
        } else {
            // Insert
            $stmt = $pdo->prepare("
                INSERT INTO product_lines (name, order_pattern, description, enforcement_level, is_active)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $orderPattern, $description, $enforcement, $isActive]);
            $id = (int) $pdo->lastInsertId();
            $action = 'PRODUCT_LINE_CREATE';
        }

        logAdminActivity($admin_session['admin_id'], $admin_session['id'], $action, "Product line: $name (pattern: $orderPattern)");
        jsonResponse(['success' => true, 'id' => $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            jsonResponse(['success' => false, 'error' => 'A product line with this name or pattern already exists'], 409);
        }
        error_log("save_product_line error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

function handle_delete_product_line(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $id = (int) ($json_input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid line ID'], 400);
    }

    $stmt = $pdo->prepare("UPDATE product_lines SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'PRODUCT_LINE_DELETE', "Deactivated product line ID: $id");
    jsonResponse(['success' => true]);
}

// ── Product Variants ─────────────────────────────────────────

function handle_save_product_variant(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $id = (int) ($json_input['id'] ?? 0);
    $lineId = (int) ($json_input['line_id'] ?? 0);
    $name = trim($json_input['name'] ?? '');
    $diskSizeMin = (int) ($json_input['disk_size_min_mb'] ?? 0);
    $diskSizeMax = (int) ($json_input['disk_size_max_mb'] ?? 0);
    $isActive = (int) ($json_input['is_active'] ?? 1);
    $partitions = $json_input['partitions'] ?? [];

    if ($lineId <= 0 || $name === '') {
        jsonResponse(['success' => false, 'error' => 'Line ID and name are required'], 400);
    }
    if ($diskSizeMin <= 0 || $diskSizeMax <= 0 || $diskSizeMin >= $diskSizeMax) {
        jsonResponse(['success' => false, 'error' => 'Invalid disk size range'], 400);
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE product_variants
                SET line_id = ?, name = ?, disk_size_min_mb = ?, disk_size_max_mb = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$lineId, $name, $diskSizeMin, $diskSizeMax, $isActive, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO product_variants (line_id, name, disk_size_min_mb, disk_size_max_mb, is_active)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$lineId, $name, $diskSizeMin, $diskSizeMax, $isActive]);
            $id = (int) $pdo->lastInsertId();
        }

        // Replace partitions: delete existing, insert new
        $stmt = $pdo->prepare("DELETE FROM product_variant_partitions WHERE variant_id = ?");
        $stmt->execute([$id]);

        if (!empty($partitions)) {
            $stmt = $pdo->prepare("
                INSERT INTO product_variant_partitions
                    (variant_id, partition_order, partition_name, partition_type, expected_size_mb, tolerance_percent, is_flexible)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($partitions as $i => $p) {
                $partName = trim($p['partition_name'] ?? '');
                $partType = trim($p['partition_type'] ?? '') ?: null;
                $tolerance = (float) ($p['tolerance_percent'] ?? 1.0);
                $sizeMb = (int) ($p['expected_size_mb'] ?? 0);

                if (mb_strlen($partName) > 50) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'error' => "Partition name too long (max 50 chars): $partName"], 400);
                }
                if ($partType !== null && mb_strlen($partType) > 20) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'error' => "Partition type too long (max 20 chars): $partType"], 400);
                }
                if ($tolerance < 0 || $tolerance > 100) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'error' => "Tolerance must be 0-100%, got: $tolerance"], 400);
                }
                if ($sizeMb < 0) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'error' => "Partition size cannot be negative"], 400);
                }

                $stmt->execute([
                    $id,
                    (int) ($p['partition_order'] ?? $i + 1),
                    $partName,
                    $partType,
                    $sizeMb,
                    $tolerance,
                    (int) ($p['is_flexible'] ?? 0),
                ]);
            }
        }

        $pdo->commit();
        logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'PRODUCT_VARIANT_SAVE', "Variant: $name (ID: $id) with " . count($partitions) . " partitions");
        jsonResponse(['success' => true, 'id' => $id]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (str_contains($e->getMessage(), 'Duplicate entry')) {
            jsonResponse(['success' => false, 'error' => 'A variant with this name already exists in this product line'], 409);
        }
        error_log("save_product_variant error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Database error'], 500);
    }
}

function handle_delete_product_variant(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_compliance', $admin_session);

    $id = (int) ($json_input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid variant ID'], 400);
    }

    $stmt = $pdo->prepare("UPDATE product_variants SET is_active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'PRODUCT_VARIANT_DELETE', "Deactivated variant ID: $id");
    jsonResponse(['success' => true]);
}
