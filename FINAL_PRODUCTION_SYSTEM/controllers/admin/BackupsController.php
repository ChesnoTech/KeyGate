<?php
/**
 * Backups Controller - Database Backup Management
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

function handle_list_backups(PDO $pdo, array $admin_session): void {
    requirePermission('view_backups', $admin_session);

    $stmt = $pdo->query("
        SELECT * FROM backup_history
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'backups' => $backups]);
}

function handle_trigger_manual_backup(PDO $pdo, array $admin_session): void {
    requirePermission('manual_backup', $admin_session);

    $webRoot = dirname(__DIR__, 2);
    $scriptPath = $webRoot . '/scripts/backup-database.sh';

    if (!file_exists($scriptPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup script not found']);
        return;
    }

    $output = [];
    $returnCode = 0;
    exec("BACKUP_TYPE=manual bash $scriptPath 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'],
            'MANUAL_BACKUP',
            'Triggered manual database backup'
        );

        echo json_encode([
            'success' => true,
            'message' => 'Backup completed successfully',
            'output' => implode("\n", $output)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Backup failed',
            'output' => implode("\n", $output)
        ]);
    }
}
