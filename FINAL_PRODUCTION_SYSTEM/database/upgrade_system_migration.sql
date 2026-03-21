-- =============================================================
-- OEM Activation System — System Upgrade Tracking
-- =============================================================
-- Tracks all system upgrades: upload, preflight, backup, apply,
-- verify, rollback. Provides full audit trail.
-- =============================================================

CREATE TABLE IF NOT EXISTS upgrade_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_version VARCHAR(20) NOT NULL,
    to_version VARCHAR(20) NOT NULL,
    from_version_code INT NOT NULL DEFAULT 0,
    to_version_code INT NOT NULL DEFAULT 0,
    status ENUM(
        'pending',
        'preflight',
        'backing_up',
        'upgrading',
        'verifying',
        'completed',
        'failed',
        'rolled_back'
    ) NOT NULL DEFAULT 'pending',
    step_details JSON DEFAULT NULL COMMENT 'Per-step status tracking for the wizard',
    manifest_json JSON DEFAULT NULL COMMENT 'Full manifest stored for audit',
    package_filename VARCHAR(255) DEFAULT NULL,
    package_checksum VARCHAR(64) DEFAULT NULL,
    backup_db_filename VARCHAR(512) DEFAULT NULL COMMENT 'Pre-upgrade DB backup file path',
    backup_files_path VARCHAR(512) DEFAULT NULL COMMENT 'Pre-upgrade file archive path',
    migrations_applied JSON DEFAULT NULL COMMENT 'List of migration files applied',
    files_changed JSON DEFAULT NULL COMMENT 'List of files replaced/added/deleted',
    error_message TEXT DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    rolled_back_at TIMESTAMP NULL DEFAULT NULL,
    admin_id INT NOT NULL,
    admin_username VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_upgrade_status (status),
    INDEX idx_upgrade_version (to_version_code),
    INDEX idx_upgrade_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
