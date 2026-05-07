<?php
/**
 * Task Pipeline Controller — Configurable per-product-line task pipelines
 *
 * Manages:
 *   - Task template library (CRUD)
 *   - Per-product-line task assignments (assign, reorder, override)
 *   - Task execution log viewing
 *   - Pipeline API for PS1 client to fetch tasks at runtime
 */

// ── Task Templates (Library) ────────────────────────────────

function handle_list_task_templates(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $stmt = $pdo->query("
        SELECT * FROM `" . t('task_templates') . "`
        ORDER BY is_system DESC, task_key ASC
    ");

    jsonResponse(['success' => true, 'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_save_task_template(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $id = (int)($json_input['id'] ?? 0);
    $taskKey = trim($json_input['task_key'] ?? '');
    $taskName = trim($json_input['task_name'] ?? '');
    $taskType = $json_input['task_type'] ?? 'custom';
    $description = trim($json_input['description'] ?? '');
    $defaultCode = $json_input['default_code'] ?? '';
    $defaultTimeout = (int)($json_input['default_timeout_seconds'] ?? 60);
    $defaultOnFailure = $json_input['default_on_failure'] ?? 'stop';
    $icon = trim($json_input['icon'] ?? '');

    if (empty($taskKey) || !preg_match('/^[a-z0-9_]+$/', $taskKey)) {
        jsonResponse(['success' => false, 'error' => 'Task key must be lowercase alphanumeric with underscores']);
        return;
    }
    if (empty($taskName)) {
        jsonResponse(['success' => false, 'error' => 'Task name is required']);
        return;
    }
    if (!in_array($taskType, ['built_in', 'custom'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid task type']);
        return;
    }
    if (!in_array($defaultOnFailure, ['stop', 'skip', 'warn'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid on_failure value']);
        return;
    }

    if ($id > 0) {
        // Check not editing a system task's key/type
        $existing = $pdo->prepare("SELECT is_system FROM `" . t('task_templates') . "` WHERE id = ?");
        $existing->execute([$id]);
        $row = $existing->fetch();
        if ($row && $row['is_system']) {
            // System tasks: only allow editing name, description, timeout, on_failure, icon
            $stmt = $pdo->prepare("
                UPDATE `" . t('task_templates') . "`
                SET task_name = ?, description = ?, default_timeout_seconds = ?,
                    default_on_failure = ?, icon = ?
                WHERE id = ?
            ");
            $stmt->execute([$taskName, $description, $defaultTimeout, $defaultOnFailure, $icon, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE `" . t('task_templates') . "`
                SET task_key = ?, task_name = ?, task_type = ?, description = ?,
                    default_code = ?, default_timeout_seconds = ?, default_on_failure = ?, icon = ?
                WHERE id = ? AND is_system = 0
            ");
            $stmt->execute([$taskKey, $taskName, $taskType, $description, $defaultCode, $defaultTimeout, $defaultOnFailure, $icon, $id]);
        }
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO `" . t('task_templates') . "`
                (task_key, task_name, task_type, description, default_code,
                 default_timeout_seconds, default_on_failure, is_system, icon)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
        ");
        $stmt->execute([$taskKey, $taskName, $taskType, $description, $defaultCode, $defaultTimeout, $defaultOnFailure, $icon]);
        $id = (int)$pdo->lastInsertId();
    }

    logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'TASK_TEMPLATE_SAVED', "Saved task template: {$taskKey} (#{$id})");

    jsonResponse(['success' => true, 'id' => $id]);
}

function handle_delete_task_template(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $id = (int)($json_input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Invalid template ID']);
        return;
    }

    // Cannot delete system tasks
    $check = $pdo->prepare("SELECT is_system, task_key FROM `" . t('task_templates') . "` WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Template not found']);
        return;
    }
    if ($row['is_system']) {
        jsonResponse(['success' => false, 'error' => 'System tasks cannot be deleted']);
        return;
    }

    // Remove from all product line assignments first
    $pdo->prepare("DELETE FROM `" . t('product_line_tasks') . "` WHERE task_template_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM `" . t('task_templates') . "` WHERE id = ? AND is_system = 0")->execute([$id]);

    logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'TASK_TEMPLATE_DELETED', "Deleted task template: {$row['task_key']} (#{$id})");

    jsonResponse(['success' => true]);
}

// ── Product Line Tasks (Pipeline) ───────────────────────────

function handle_get_product_line_tasks(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $productLineId = (int)($json_input['product_line_id'] ?? $_GET['product_line_id'] ?? 0);
    if ($productLineId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Product line ID required']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT plt.*, tt.task_key, tt.task_name AS template_name, tt.task_type,
               tt.description AS template_description, tt.default_code,
               tt.default_timeout_seconds, tt.default_on_failure, tt.is_system, tt.icon
        FROM `" . t('product_line_tasks') . "` plt
        JOIN `" . t('task_templates') . "` tt ON tt.id = plt.task_template_id
        WHERE plt.product_line_id = ?
        ORDER BY plt.sort_order ASC
    ");
    $stmt->execute([$productLineId]);

    jsonResponse(['success' => true, 'tasks' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function handle_save_product_line_tasks(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $productLineId = (int)($json_input['product_line_id'] ?? 0);
    $tasks = $json_input['tasks'] ?? [];

    if ($productLineId <= 0) {
        jsonResponse(['success' => false, 'error' => 'Product line ID required']);
        return;
    }
    if (!is_array($tasks)) {
        jsonResponse(['success' => false, 'error' => 'Tasks must be an array']);
        return;
    }

    $pdo->beginTransaction();
    try {
        // Remove existing assignments
        $pdo->prepare("DELETE FROM `" . t('product_line_tasks') . "` WHERE product_line_id = ?")->execute([$productLineId]);

        // Insert new assignments in order
        $insertStmt = $pdo->prepare("
            INSERT INTO `" . t('product_line_tasks') . "`
                (product_line_id, task_template_id, sort_order, enabled,
                 custom_name, custom_code, custom_timeout_seconds, custom_on_failure)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($tasks as $i => $task) {
            $templateId = (int)($task['task_template_id'] ?? 0);
            if ($templateId <= 0) continue;

            $insertStmt->execute([
                $productLineId,
                $templateId,
                $i,
                (int)($task['enabled'] ?? 1),
                !empty($task['custom_name']) ? trim($task['custom_name']) : null,
                !empty($task['custom_code']) ? $task['custom_code'] : null,
                !empty($task['custom_timeout_seconds']) ? (int)$task['custom_timeout_seconds'] : null,
                !empty($task['custom_on_failure']) ? $task['custom_on_failure'] : null,
            ]);
        }

        $pdo->commit();

        logAdminActivity($admin_session['admin_id'], $admin_session['id'] ?? 0, 'PIPELINE_SAVED', "Saved task pipeline for product line #{$productLineId}: " . count($tasks) . " tasks");

        jsonResponse(['success' => true, 'count' => count($tasks)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("save_product_line_tasks error: " . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to save pipeline']);
    }
}

// ── Task Execution Log ──────────────────────────────────────

function handle_list_task_executions(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('view_activations', $admin_session);

    $limit = min(100, max(1, (int)($json_input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = max(0, (int)($json_input['offset'] ?? $_GET['offset'] ?? 0));
    $productLineId = (int)($json_input['product_line_id'] ?? $_GET['product_line_id'] ?? 0);

    $where = '';
    $params = [];
    if ($productLineId > 0) {
        $where = 'WHERE tel.product_line_id = ?';
        $params[] = $productLineId;
    }

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT tel.*
        FROM task_execution_log tel
        {$where}
        ORDER BY tel.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    jsonResponse(['success' => true, 'executions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── Client API: Get Pipeline for Activation ─────────────────

function handle_get_activation_pipeline(PDO $pdo, array $admin_session, $json_input): void {
    // This can be called by the PS1 client via technician auth
    $productLineId = (int)($json_input['product_line_id'] ?? $_GET['product_line_id'] ?? 0);

    if ($productLineId <= 0) {
        // Return default pipeline (all system built-in tasks in default order)
        $stmt = $pdo->query("
            SELECT id AS task_template_id, task_key, task_name, task_type,
                   default_code AS code, default_timeout_seconds AS timeout_seconds,
                   default_on_failure AS on_failure, 1 AS enabled
            FROM `" . t('task_templates') . "`
            WHERE is_system = 1
            ORDER BY id ASC
        ");
        jsonResponse(['success' => true, 'pipeline' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'product_line_id' => 0]);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT plt.task_template_id,
               tt.task_key,
               COALESCE(plt.custom_name, tt.task_name) AS task_name,
               tt.task_type,
               COALESCE(plt.custom_code, tt.default_code) AS code,
               COALESCE(plt.custom_timeout_seconds, tt.default_timeout_seconds) AS timeout_seconds,
               COALESCE(plt.custom_on_failure, tt.default_on_failure) AS on_failure,
               plt.enabled
        FROM `" . t('product_line_tasks') . "` plt
        JOIN `" . t('task_templates') . "` tt ON tt.id = plt.task_template_id
        WHERE plt.product_line_id = ? AND plt.enabled = 1
        ORDER BY plt.sort_order ASC
    ");
    $stmt->execute([$productLineId]);
    $pipeline = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If product line has no tasks assigned, fall back to defaults
    if (empty($pipeline)) {
        $stmt = $pdo->query("
            SELECT id AS task_template_id, task_key, task_name, task_type,
                   default_code AS code, default_timeout_seconds AS timeout_seconds,
                   default_on_failure AS on_failure, 1 AS enabled
            FROM `" . t('task_templates') . "`
            WHERE is_system = 1
            ORDER BY id ASC
        ");
        $pipeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    jsonResponse(['success' => true, 'pipeline' => $pipeline, 'product_line_id' => $productLineId]);
}

// ── Log Task Execution Result ───────────────────────────────

function handle_log_task_execution(PDO $pdo, array $admin_session, $json_input): void {
    $entries = $json_input['entries'] ?? [];
    if (!is_array($entries) || empty($entries)) {
        jsonResponse(['success' => false, 'error' => 'Entries array required']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO `" . t('task_execution_log') . "`
            (activation_attempt_id, product_line_id, task_template_id, task_key,
             task_name, status, started_at, completed_at, duration_ms,
             output, error_message, technician_id, order_number)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $count = 0;
    foreach ($entries as $entry) {
        $stmt->execute([
            $entry['activation_attempt_id'] ?? null,
            $entry['product_line_id'] ?? null,
            (int)($entry['task_template_id'] ?? 0),
            $entry['task_key'] ?? '',
            $entry['task_name'] ?? '',
            $entry['status'] ?? 'pending',
            $entry['started_at'] ?? null,
            $entry['completed_at'] ?? null,
            $entry['duration_ms'] ?? null,
            $entry['output'] ?? null,
            $entry['error_message'] ?? null,
            $entry['technician_id'] ?? null,
            $entry['order_number'] ?? null,
        ]);
        $count++;
    }

    jsonResponse(['success' => true, 'logged' => $count]);
}
