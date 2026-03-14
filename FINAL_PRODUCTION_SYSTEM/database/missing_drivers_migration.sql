-- Missing Drivers QC Check Migration
-- Adds driver status tracking to hardware_info and QC enforcement rules

-- 1. Add driver columns to hardware_info
ALTER TABLE hardware_info
    ADD COLUMN missing_drivers JSON NULL COMMENT 'Array of devices with missing/problematic drivers',
    ADD COLUMN missing_drivers_count INT NULL DEFAULT NULL COMMENT 'Number of missing/error drivers detected';

CREATE INDEX idx_hw_missing_drivers_count ON hardware_info (missing_drivers_count);

-- 2. Add enforcement columns to QC tables
ALTER TABLE qc_motherboard_registry
    ADD COLUMN missing_drivers_enforcement TINYINT(1) NULL COMMENT 'Override: 0=disabled, 1=info, 2=warning, 3=blocking';

ALTER TABLE qc_manufacturer_defaults
    ADD COLUMN missing_drivers_enforcement TINYINT(1) DEFAULT NULL COMMENT 'Override: 0=disabled, 1=info, 2=warning, 3=blocking';

-- 3. Add global setting
INSERT IGNORE INTO qc_global_settings (setting_key, setting_value, description)
VALUES ('default_missing_drivers_enforcement', '2', 'Default enforcement for missing drivers check (0=disabled, 1=info, 2=warning, 3=blocking)');

-- 4. Update check_type ENUM to include missing_drivers
ALTER TABLE qc_compliance_results
    MODIFY COLUMN check_type VARCHAR(50) NOT NULL COMMENT 'Check type identifier';
