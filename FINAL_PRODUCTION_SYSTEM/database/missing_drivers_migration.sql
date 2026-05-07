-- Missing Drivers QC Check + Per-Product-Line Enforcement Migration
-- Adds driver status tracking and per-check enforcement to product lines

-- 1. Add driver columns to hardware_info
ALTER TABLE `#__hardware_info`
    ADD COLUMN IF NOT EXISTS missing_drivers JSON NULL COMMENT 'Array of devices with missing/problematic drivers',
    ADD COLUMN IF NOT EXISTS missing_drivers_count INT NULL DEFAULT NULL COMMENT 'Number of missing/error drivers detected';

CREATE INDEX IF NOT EXISTS idx_hw_missing_drivers_count ON hardware_info (missing_drivers_count);

-- 2. Add enforcement columns to QC tables
ALTER TABLE `#__qc_motherboard_registry`
    ADD COLUMN IF NOT EXISTS missing_drivers_enforcement TINYINT(1) NULL COMMENT 'Override: 0=disabled, 1=info, 2=warning, 3=blocking';

ALTER TABLE `#__qc_manufacturer_defaults`
    ADD COLUMN IF NOT EXISTS missing_drivers_enforcement TINYINT(1) DEFAULT NULL COMMENT 'Override: 0=disabled, 1=info, 2=warning, 3=blocking';

-- 3. Add per-check enforcement columns to product_lines
ALTER TABLE `#__product_lines`
    ADD COLUMN IF NOT EXISTS secure_boot_enforcement TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS bios_enforcement TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS hackbgrt_enforcement TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS partition_enforcement TINYINT(1) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS missing_drivers_enforcement TINYINT(1) DEFAULT NULL;

-- 4. Add global settings for new check types
INSERT IGNORE INTO qc_global_settings (setting_key, setting_value, description)
VALUES ('default_missing_drivers_enforcement', '2', 'Default enforcement for missing drivers check (0=disabled, 1=info, 2=warning, 3=blocking)');

INSERT IGNORE INTO qc_global_settings (setting_key, setting_value, description)
VALUES ('default_partition_enforcement', '2', 'Default enforcement for partition layout check (0=disabled, 1=info, 2=warning, 3=blocking)');

-- 5. Widen check_type from ENUM to VARCHAR for extensibility
ALTER TABLE `#__qc_compliance_results`
    MODIFY COLUMN check_type VARCHAR(50) NOT NULL COMMENT 'Check type identifier';
