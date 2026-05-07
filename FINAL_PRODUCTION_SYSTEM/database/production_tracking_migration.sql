-- =============================================================
-- KeyGate — Production Tracking & Enterprise Key Management
-- =============================================================
-- CBR reports, key pool alerts, hardware binding, DPK import,
-- production line tracking with work orders and batch numbers.
-- =============================================================

-- ── 1. Computer Build Reports (CBR) ────────────────────────
-- Structured per-machine reports for auditing and compliance.
CREATE TABLE IF NOT EXISTS `#__computer_build_reports` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID v4 for external reference',
    activation_attempt_id INT DEFAULT NULL,
    order_number VARCHAR(100) DEFAULT NULL,
    batch_number VARCHAR(100) DEFAULT NULL,
    work_order_id INT DEFAULT NULL,

    -- Machine identity
    device_fingerprint VARCHAR(500) DEFAULT NULL,
    system_uuid VARCHAR(100) DEFAULT NULL,
    motherboard_manufacturer VARCHAR(255) DEFAULT NULL,
    motherboard_model VARCHAR(255) DEFAULT NULL,
    motherboard_serial VARCHAR(255) DEFAULT NULL,
    bios_version VARCHAR(255) DEFAULT NULL,
    chassis_serial VARCHAR(255) DEFAULT NULL,

    -- Key & Activation
    product_key_masked VARCHAR(29) DEFAULT NULL COMMENT 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX with middle groups masked',
    product_key_id INT DEFAULT NULL COMMENT 'FK to oem_keys',
    product_edition VARCHAR(100) DEFAULT NULL,
    activation_status ENUM('activated', 'failed', 'pending', 'not_attempted') NOT NULL DEFAULT 'pending',
    activation_method VARCHAR(50) DEFAULT NULL COMMENT 'slmgr, firmware, alt_server',
    activation_timestamp TIMESTAMP NULL DEFAULT NULL,
    activation_error_code VARCHAR(20) DEFAULT NULL,

    -- Hardware summary
    cpu_model VARCHAR(255) DEFAULT NULL,
    ram_total_gb DECIMAL(10,2) DEFAULT NULL,
    gpu_model VARCHAR(255) DEFAULT NULL,
    storage_total_gb DECIMAL(10,2) DEFAULT NULL,
    os_version VARCHAR(100) DEFAULT NULL,
    os_build VARCHAR(50) DEFAULT NULL,

    -- QC compliance
    qc_passed TINYINT(1) DEFAULT NULL,
    qc_secure_boot TINYINT(1) DEFAULT NULL,
    qc_tpm_present TINYINT(1) DEFAULT NULL,
    qc_hackbgrt_clean TINYINT(1) DEFAULT NULL,

    -- Production tracking
    product_line_id INT DEFAULT NULL,
    product_line_name VARCHAR(100) DEFAULT NULL,
    technician_id INT DEFAULT NULL,
    technician_name VARCHAR(100) DEFAULT NULL,
    station_name VARCHAR(100) DEFAULT NULL COMMENT 'Workstation hostname',

    -- Shipping
    shipping_status ENUM('building', 'testing', 'ready', 'shipped', 'returned') NOT NULL DEFAULT 'building',
    shipped_at TIMESTAMP NULL DEFAULT NULL,
    shipping_tracking VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    customer_order_ref VARCHAR(255) DEFAULT NULL,

    -- Report metadata
    report_format_version INT NOT NULL DEFAULT 1,
    full_report_json JSON DEFAULT NULL COMMENT 'Complete hardware + task pipeline + QC data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_cbr_uuid (report_uuid),
    INDEX idx_cbr_order (order_number),
    INDEX idx_cbr_batch (batch_number),
    INDEX idx_cbr_fingerprint (device_fingerprint(255)),
    INDEX idx_cbr_key (product_key_id),
    INDEX idx_cbr_status (activation_status),
    INDEX idx_cbr_shipping (shipping_status),
    INDEX idx_cbr_created (created_at),
    INDEX idx_cbr_work_order (work_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Key Pool Management ─────────────────────────────────
-- Alert thresholds and replenishment tracking per product edition.
CREATE TABLE IF NOT EXISTS `#__key_pool_config` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_edition VARCHAR(100) NOT NULL UNIQUE COMMENT 'e.g. Windows 11 Pro, Windows 11 Home',
    low_threshold INT NOT NULL DEFAULT 10 COMMENT 'Alert when unused keys drop below this',
    critical_threshold INT NOT NULL DEFAULT 3 COMMENT 'Critical alert level',
    auto_notify TINYINT(1) NOT NULL DEFAULT 1,
    notify_email VARCHAR(255) DEFAULT NULL COMMENT 'Override default notification email',
    last_alert_sent_at TIMESTAMP NULL DEFAULT NULL,
    last_replenished_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Hardware Binding Registry ───────────────────────────
-- Tracks which keys have been used on which hardware.
CREATE TABLE IF NOT EXISTS `#__hardware_key_bindings` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_key_id INT NOT NULL COMMENT 'FK to oem_keys',
    device_fingerprint VARCHAR(500) NOT NULL,
    motherboard_serial VARCHAR(255) DEFAULT NULL,
    system_uuid VARCHAR(100) DEFAULT NULL,
    primary_mac_address VARCHAR(50) DEFAULT NULL,
    bound_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    activation_attempt_id INT DEFAULT NULL,
    status ENUM('active', 'released', 'conflict') NOT NULL DEFAULT 'active',
    conflict_details TEXT DEFAULT NULL COMMENT 'If same key used on different hardware',
    released_at TIMESTAMP NULL DEFAULT NULL,
    released_by_admin_id INT DEFAULT NULL,

    INDEX idx_binding_key (product_key_id),
    INDEX idx_binding_fingerprint (device_fingerprint(255)),
    INDEX idx_binding_status (status),
    UNIQUE KEY uk_key_fingerprint (product_key_id, device_fingerprint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. DPK Import Batches ──────────────────────────────────
-- Tracks bulk key imports from Microsoft OEM deliveries.
CREATE TABLE IF NOT EXISTS `#__dpk_import_batches` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_name VARCHAR(255) NOT NULL COMMENT 'e.g. "Microsoft Order #12345"',
    import_source VARCHAR(100) NOT NULL DEFAULT 'manual' COMMENT 'manual, microsoft_csv, microsoft_xml',
    product_edition VARCHAR(100) DEFAULT NULL,
    total_keys INT NOT NULL DEFAULT 0,
    imported_keys INT NOT NULL DEFAULT 0,
    duplicate_keys INT NOT NULL DEFAULT 0,
    failed_keys INT NOT NULL DEFAULT 0,
    import_status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    source_filename VARCHAR(255) DEFAULT NULL,
    source_checksum VARCHAR(64) DEFAULT NULL,
    error_log TEXT DEFAULT NULL,
    imported_by_admin_id INT NOT NULL,
    imported_by_username VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    INDEX idx_dpk_status (import_status),
    INDEX idx_dpk_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Work Orders (Production Line Tracking) ──────────────
-- Links builds to customer orders, batch production runs.
CREATE TABLE IF NOT EXISTS `#__work_orders` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    work_order_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'Auto-generated or manual',
    batch_number VARCHAR(100) DEFAULT NULL COMMENT 'Production batch grouping',
    customer_name VARCHAR(255) DEFAULT NULL,
    customer_email VARCHAR(255) DEFAULT NULL,
    customer_phone VARCHAR(50) DEFAULT NULL,
    customer_order_ref VARCHAR(255) DEFAULT NULL COMMENT 'Customer PO number',

    -- What to build
    product_line_id INT DEFAULT NULL,
    product_line_name VARCHAR(100) DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    completed_quantity INT NOT NULL DEFAULT 0,

    -- Status
    status ENUM('draft', 'queued', 'in_progress', 'completed', 'shipped', 'cancelled') NOT NULL DEFAULT 'draft',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    assigned_technician_id INT DEFAULT NULL,

    -- Dates
    due_date DATE DEFAULT NULL,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    shipped_at TIMESTAMP NULL DEFAULT NULL,

    -- Shipping
    shipping_method VARCHAR(100) DEFAULT NULL,
    shipping_tracking VARCHAR(255) DEFAULT NULL,
    shipping_address TEXT DEFAULT NULL,

    -- Notes
    internal_notes TEXT DEFAULT NULL,
    customer_notes TEXT DEFAULT NULL,

    -- Metadata
    created_by_admin_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_wo_status (status),
    INDEX idx_wo_batch (batch_number),
    INDEX idx_wo_customer (customer_name(100)),
    INDEX idx_wo_due (due_date),
    INDEX idx_wo_product_line (product_line_id),
    INDEX idx_wo_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link table: which CBRs belong to which work order
-- (already handled by work_order_id FK in computer_build_reports)
