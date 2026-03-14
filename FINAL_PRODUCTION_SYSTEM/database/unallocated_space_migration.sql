-- Unallocated Space QC Check Migration
-- Adds configurable max unallocated space limit for partition QC

-- 1. Add per-variant unallocated space limit
ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS max_unallocated_mb INT DEFAULT NULL
    COMMENT 'Max allowed unallocated disk space in MB (NULL = use global setting)';

-- 2. Add global default setting
INSERT IGNORE INTO qc_global_settings (setting_key, setting_value, description)
VALUES ('max_unallocated_mb', '1024', 'Maximum allowed unallocated disk space in MB (0 = disabled)');
