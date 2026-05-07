-- ============================================================
-- QC Hardware Compliance System Migration
-- Date: 2026-03-09
-- Purpose: Auto-learning motherboard registry, per-model/manufacturer
--          compliance rules, enforcement levels, retroactive checking
-- ============================================================

-- ============================================================
-- 1. New columns on hardware_info
-- ============================================================

ALTER TABLE `#__hardware_info` ADD COLUMN boot_order JSON NULL COMMENT 'Ordered list of UEFI boot entries from bcdedit' AFTER device_fingerprint;
ALTER TABLE `#__hardware_info` ADD COLUMN hackbgrt_installed TINYINT(1) NULL COMMENT '1=HackBGRT traces found on EFI partition' AFTER boot_order;
ALTER TABLE `#__hardware_info` ADD COLUMN hackbgrt_first_boot TINYINT(1) NULL COMMENT '1=HackBGRT is first boot entry' AFTER hackbgrt_installed;

-- ============================================================
-- 2. QC Global Settings (key-value)
-- ============================================================

CREATE TABLE IF NOT EXISTS `#__qc_global_settings` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `#__qc_global_settings` (setting_key, setting_value, description) VALUES
('qc_enabled',                      '0', 'Enable hardware compliance checking during activation (0=off, 1=on)'),
('default_bios_enforcement',        '1', 'Default BIOS version check enforcement (0=disabled, 1=info, 2=warning, 3=blocking)'),
('default_secure_boot_enforcement', '1', 'Default Secure Boot check enforcement (0=disabled, 1=info, 2=warning, 3=blocking)'),
('default_hackbgrt_enforcement',    '1', 'Default HackBGRT boot priority enforcement (0=disabled, 1=info, 2=warning, 3=blocking)'),
('blocking_prevents_key',           '1', 'Prevent key distribution when blocking compliance issues exist (0=no, 1=yes)');

-- ============================================================
-- 3. Manufacturer Defaults
-- ============================================================

CREATE TABLE IF NOT EXISTS `#__qc_manufacturer_defaults` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturer VARCHAR(100) NOT NULL UNIQUE,
    secure_boot_required TINYINT(1) DEFAULT 1 COMMENT '1=Secure Boot must be ON',
    secure_boot_enforcement TINYINT(1) DEFAULT 1 COMMENT '0=disabled, 1=info, 2=warning, 3=blocking',
    min_bios_version VARCHAR(100) NULL COMMENT 'Minimum acceptable BIOS version string',
    recommended_bios_version VARCHAR(100) NULL COMMENT 'Recommended BIOS version string',
    bios_enforcement TINYINT(1) DEFAULT 1 COMMENT '0=disabled, 1=info, 2=warning, 3=blocking',
    hackbgrt_enforcement TINYINT(1) DEFAULT 1 COMMENT '0=disabled, 1=info, 2=warning, 3=blocking',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL,
    INDEX idx_manufacturer (manufacturer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. Motherboard Registry (auto-populated from `#__hardware_info`)
-- ============================================================

CREATE TABLE IF NOT EXISTS `#__qc_motherboard_registry` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturer VARCHAR(100) NOT NULL,
    product VARCHAR(100) NOT NULL,
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    times_seen INT DEFAULT 1,
    -- Per-model overrides (NULL = inherit from manufacturer default)
    secure_boot_required TINYINT(1) NULL,
    secure_boot_enforcement TINYINT(1) NULL,
    min_bios_version VARCHAR(100) NULL,
    recommended_bios_version VARCHAR(100) NULL,
    bios_enforcement TINYINT(1) NULL,
    hackbgrt_enforcement TINYINT(1) NULL,
    -- Self-learning: auto-populated BIOS versions seen
    known_bios_versions JSON NULL COMMENT 'Array of BIOS versions observed for this model',
    notes TEXT NULL,
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Admin can deactivate obsolete boards',
    updated_by INT NULL,
    UNIQUE KEY unique_board (manufacturer, product),
    INDEX idx_manufacturer (manufacturer),
    INDEX idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. Compliance Results (per check, per hardware submission)
-- ============================================================

CREATE TABLE IF NOT EXISTS `#__qc_compliance_results` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hardware_info_id INT NOT NULL,
    order_number VARCHAR(10) NOT NULL,
    check_type ENUM('bios_version','secure_boot','hackbgrt_boot_priority') NOT NULL,
    check_result ENUM('pass','info','warning','fail') NOT NULL,
    enforcement_level TINYINT(1) NOT NULL COMMENT '0=disabled, 1=info, 2=warning, 3=blocking',
    expected_value VARCHAR(200) NULL,
    actual_value VARCHAR(200) NULL,
    message TEXT NULL COMMENT 'Human-readable result description',
    rule_source ENUM('global','manufacturer','model') NOT NULL COMMENT 'Where the rule came from',
    motherboard_registry_id INT NULL,
    is_retroactive TINYINT(1) DEFAULT 0 COMMENT '1=created by retroactive recheck',
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hardware_info_id) REFERENCES `#__hardware_info`(id) ON DELETE CASCADE,
    INDEX idx_hardware_id (hardware_info_id),
    INDEX idx_order_number (order_number),
    INDEX idx_check_result (check_result),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. ACL: Quality Control permission category + permissions
-- ============================================================

INSERT INTO `#__acl_permission_categories` (category_key, display_name, icon, sort_order) VALUES
('quality_control', 'Quality Control', NULL, 46);

SET @qc_cat_id = (SELECT id FROM `#__acl_permission_categories` WHERE category_key = 'quality_control');

INSERT INTO `#__acl_permissions` (permission_key, display_name, description, category_id, resource_type, action_type, is_dangerous) VALUES
('view_compliance',          'View Compliance Results',    'View QC compliance check results and statistics',                   @qc_cat_id, 'compliance', 'view', 0),
('manage_compliance_rules',  'Manage Compliance Rules',    'Configure motherboard registry and compliance enforcement rules',   @qc_cat_id, 'compliance', 'manage', 0),
('manage_compliance',        'Manage QC System',           'Toggle QC feature, trigger retroactive checks, modify global settings', @qc_cat_id, 'compliance', 'manage', 1);

-- Grant to super_admin (already gets everything via CROSS JOIN, but explicit for clarity on new installs)
INSERT IGNORE INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM `#__acl_roles` r CROSS JOIN `#__acl_permissions` p
WHERE r.role_name = 'super_admin' AND p.permission_key IN ('view_compliance', 'manage_compliance_rules', 'manage_compliance');

-- Grant to admin: view + manage rules
INSERT IGNORE INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM `#__acl_roles` r, acl_permissions p
WHERE r.role_name = 'admin' AND p.permission_key IN ('view_compliance', 'manage_compliance_rules');

-- Grant to qc_inspector: view only
INSERT IGNORE INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM `#__acl_roles` r, acl_permissions p
WHERE r.role_name = 'qc_inspector' AND p.permission_key IN ('view_compliance');

-- Grant to dept_manager: view only
INSERT IGNORE INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM `#__acl_roles` r, acl_permissions p
WHERE r.role_name = 'dept_manager' AND p.permission_key IN ('view_compliance');
