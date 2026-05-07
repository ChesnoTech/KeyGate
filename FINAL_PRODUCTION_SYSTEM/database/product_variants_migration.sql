-- Product Lines & Variants Migration
-- Adds partition layout QC checking via product lines → variants → partition templates

-- ── Product Lines (linked to order number patterns) ──────────────
CREATE TABLE IF NOT EXISTS `#__product_lines` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    order_pattern VARCHAR(50) NOT NULL COMMENT 'Order number prefix to match, e.g. ЭЛ00-',
    description TEXT,
    enforcement_level INT NOT NULL DEFAULT 2 COMMENT '0=off, 1=info, 2=warning, 3=blocking',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_name (name),
    UNIQUE KEY uk_pattern (order_pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Product Variants (disk size ranges within a product line) ────
CREATE TABLE IF NOT EXISTS `#__product_variants` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'e.g. RTX 1TB, RTX 512GB',
    disk_size_min_mb INT NOT NULL COMMENT 'Min boot disk size in MB for auto-detect',
    disk_size_max_mb INT NOT NULL COMMENT 'Max boot disk size in MB for auto-detect',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (line_id) REFERENCES `#__product_lines`(id) ON DELETE CASCADE,
    UNIQUE KEY uk_line_name (line_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Partition Templates (expected layout per variant) ────────────
CREATE TABLE IF NOT EXISTS `#__product_variant_partitions` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    variant_id INT NOT NULL,
    partition_order INT NOT NULL COMMENT 'Expected position on disk: 1, 2, 3...',
    partition_name VARCHAR(50) NOT NULL COMMENT 'EFI, MSR, OS, Recovery Image, Data, BIOS',
    partition_type VARCHAR(50) DEFAULT NULL COMMENT 'EFI System, Microsoft Reserved, Basic Data, etc.',
    expected_size_mb INT NOT NULL,
    tolerance_percent DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT 'Allowed deviation % per partition',
    is_flexible TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = absorbs remaining space (e.g. Data partition)',
    FOREIGN KEY (variant_id) REFERENCES `#__product_variants`(id) ON DELETE CASCADE,
    UNIQUE KEY uk_variant_order (variant_id, partition_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Extend QC compliance results to include partition_layout ─────
ALTER TABLE `#__qc_compliance_results`
    MODIFY COLUMN check_type ENUM('bios_version','secure_boot','hackbgrt_boot_priority','partition_layout') NOT NULL;

-- ── Add detected variant tracking to hardware_info ───────────────
ALTER TABLE `#__hardware_info`
    ADD COLUMN IF NOT EXISTS detected_variant_id INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS detected_variant_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS detected_line_name VARCHAR(100) DEFAULT NULL;

-- ── Add default partition enforcement to QC global settings ──────
INSERT IGNORE INTO qc_global_settings (setting_key, setting_value)
    VALUES ('default_partition_enforcement', '2');

-- ── Seed default product lines (order type prefixes) ─────────────
INSERT IGNORE INTO product_lines (name, order_pattern, description, enforcement_level, is_active)
VALUES
    ('Marketplace 1', 'ЭЛ00-######', 'Marketplace orders (ЭЛ00)', 2, 1),
    ('Marketplace 2', 'ЛЕ00-######', 'Marketplace orders (ЛЕ00)', 2, 1),
    ('In person',     'ИП00-######', 'In-person computer orders',  2, 1);
