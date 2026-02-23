-- ============================================================
-- Deep ACL System Migration
-- Date: 2026-02-09
-- Purpose: Database-driven roles, granular permissions,
--          per-user overrides, organizational roles
-- ============================================================

-- ============================================================
-- TABLES
-- ============================================================

-- Permission Categories (groups permissions for UI accordion)
CREATE TABLE IF NOT EXISTS acl_permission_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    icon VARCHAR(10) DEFAULT NULL,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Granular Permissions
CREATE TABLE IF NOT EXISTS acl_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    category_id INT NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    action_type ENUM('view','create','edit','delete','manage','execute') NOT NULL,
    is_dangerous TINYINT(1) DEFAULT 0 COMMENT 'Requires confirmation / shown with warning',
    FOREIGN KEY (category_id) REFERENCES acl_permission_categories(id),
    INDEX idx_resource (resource_type),
    INDEX idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom Roles
CREATE TABLE IF NOT EXISTS acl_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    role_type ENUM('admin','technician') NOT NULL,
    is_system_role TINYINT(1) DEFAULT 0 COMMENT 'Cannot be deleted',
    priority INT DEFAULT 0 COMMENT 'Higher = more privileged',
    color VARCHAR(7) DEFAULT '#6c757d' COMMENT 'Badge color hex',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    INDEX idx_role_type (role_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Role <-> Permission Junction
CREATE TABLE IF NOT EXISTS acl_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    granted_by INT NULL,
    UNIQUE KEY unique_role_perm (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES acl_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES acl_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-User Permission Overrides
CREATE TABLE IF NOT EXISTS acl_user_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin','technician') NOT NULL,
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    is_granted TINYINT(1) NOT NULL COMMENT '1=grant beyond role, 0=deny from role',
    reason TEXT,
    expires_at TIMESTAMP NULL COMMENT 'NULL=permanent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    UNIQUE KEY unique_user_perm (user_type, user_id, permission_id),
    FOREIGN KEY (permission_id) REFERENCES acl_permissions(id) ON DELETE CASCADE,
    INDEX idx_user (user_type, user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ACL Change Audit Log
CREATE TABLE IF NOT EXISTS acl_change_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    actor_type ENUM('admin','system') DEFAULT 'admin',
    action VARCHAR(50) NOT NULL COMMENT 'create_role, update_role, delete_role, assign_permissions, set_override, etc.',
    target_type VARCHAR(50) NOT NULL COMMENT 'role, permission, user_override',
    target_id INT NULL,
    target_name VARCHAR(100) NULL,
    old_value TEXT NULL COMMENT 'JSON of previous state',
    new_value TEXT NULL COMMENT 'JSON of new state',
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor (actor_id),
    INDEX idx_target (target_type, target_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- MODIFY EXISTING TABLES
-- ============================================================

-- Add custom_role_id to admin_users (links to acl_roles for new ACL)
ALTER TABLE admin_users ADD COLUMN custom_role_id INT NULL AFTER role;
ALTER TABLE admin_users ADD CONSTRAINT fk_admin_acl_role FOREIGN KEY (custom_role_id) REFERENCES acl_roles(id) ON DELETE SET NULL;

-- Add role_id to technicians (links to acl_roles for technician roles)
ALTER TABLE technicians ADD COLUMN role_id INT NULL AFTER is_active;
ALTER TABLE technicians ADD CONSTRAINT fk_tech_acl_role FOREIGN KEY (role_id) REFERENCES acl_roles(id) ON DELETE SET NULL;

-- Add acl_v2_enabled config flag (disabled by default for safety)
INSERT INTO system_config (config_key, config_value, description)
VALUES ('acl_v2_enabled', '0', 'Enable database-driven ACL system (0=legacy hardcoded, 1=new ACL)')
ON DUPLICATE KEY UPDATE config_key = config_key;

-- ============================================================
-- SEED: Permission Categories
-- ============================================================

INSERT INTO acl_permission_categories (category_key, display_name, icon, sort_order) VALUES
('dashboard',    'Dashboard & Reports',       NULL, 10),
('keys',         'OEM Key Management',        NULL, 20),
('technicians',  'Technician Management',     NULL, 30),
('activations',  'Activation Records',        NULL, 40),
('hardware',     'Hardware Information',       NULL, 45),
('usb_devices',  'USB Device Management',     NULL, 50),
('admin_users',  'Admin User Management',     NULL, 60),
('system',       'System & Configuration',    NULL, 70),
('logs',         'Logs & Audit Trail',        NULL, 80),
('roles',        'Roles & Permissions',       NULL, 90);

-- ============================================================
-- SEED: Granular Permissions (~38)
-- ============================================================

INSERT INTO acl_permissions (permission_key, display_name, description, category_id, resource_type, action_type, is_dangerous) VALUES
-- Dashboard (cat 1)
('view_dashboard',     'View Dashboard',          'Access the main dashboard with statistics',     1, 'dashboard', 'view', 0),
('view_reports',       'View Reports',            'Access activation and usage reports',           1, 'dashboard', 'view', 0),
('export_data',        'Export Data',             'Export data to CSV/Excel files',                1, 'dashboard', 'execute', 0),

-- Keys (cat 2)
('view_keys',          'View OEM Keys',           'View list of OEM license keys',                2, 'keys', 'view', 0),
('view_key_full',      'View Full Key Value',     'See unmasked product key (not just last 5)',   2, 'keys', 'view', 1),
('add_key',            'Add OEM Key',             'Manually add a new OEM key',                   2, 'keys', 'create', 0),
('import_keys',        'Import Keys (CSV)',       'Bulk import keys from CSV file',               2, 'keys', 'create', 0),
('edit_key',           'Edit OEM Key',            'Modify OEM key details and status',            2, 'keys', 'edit', 0),
('recycle_key',        'Recycle Key',             'Reset a used key back to unused status',       2, 'keys', 'edit', 0),
('delete_key',         'Delete OEM Key',          'Permanently delete an OEM key',                2, 'keys', 'delete', 1),

-- Technicians (cat 3)
('view_technicians',   'View Technicians',        'View list of technician accounts',             3, 'technicians', 'view', 0),
('add_technician',     'Add Technician',          'Create new technician account',                3, 'technicians', 'create', 0),
('edit_technician',    'Edit Technician',         'Modify technician account details',            3, 'technicians', 'edit', 0),
('delete_technician',  'Delete Technician',       'Remove technician account',                    3, 'technicians', 'delete', 1),
('reset_tech_password','Reset Technician Password','Reset a technician password',                 3, 'technicians', 'manage', 0),
('assign_tech_role',   'Assign Technician Role',  'Change a technician assigned role',            3, 'technicians', 'manage', 0),

-- Activations (cat 4)
('view_activations',   'View Activations',        'View activation history and attempt records',  4, 'activations', 'view', 0),
('add_activation_note','Add Activation Note',     'Add notes to activation records',              4, 'activations', 'edit', 0),
('delete_activation',  'Delete Activation Record','Remove activation history entries',             4, 'activations', 'delete', 1),

-- Hardware (cat 5)
('view_hardware',      'View Hardware Info',       'View hardware info reports of activated PCs',  5, 'hardware', 'view', 0),
('export_hardware',    'Export Hardware Reports',  'Export hardware information to CSV/PDF',        5, 'hardware', 'execute', 0),

-- USB Devices (cat 6)
('view_usb_devices',   'View USB Devices',        'View registered USB authentication devices',   6, 'usb_devices', 'view', 0),
('register_usb_device','Register USB Device',     'Register a new USB authentication device',     6, 'usb_devices', 'create', 0),
('disable_usb_device', 'Disable USB Device',      'Disable a USB device (revoke access)',         6, 'usb_devices', 'edit', 0),
('enable_usb_device',  'Enable USB Device',       'Re-enable a disabled USB device',              6, 'usb_devices', 'edit', 0),
('delete_usb_device',  'Delete USB Device',       'Permanently remove USB device registration',   6, 'usb_devices', 'delete', 1),

-- Admin Users (cat 7)
('view_admins',        'View Admin Users',        'View list of admin user accounts',             7, 'admin_users', 'view', 0),
('manage_admins',      'Manage Admin Users',      'Create, edit, and delete admin accounts',      7, 'admin_users', 'manage', 1),
('assign_admin_role',  'Assign Admin Role',       'Change an admin user assigned role',           7, 'admin_users', 'manage', 1),

-- System (cat 8)
('view_system_info',   'View System Info',        'View system configuration and status',         8, 'system', 'view', 0),
('system_settings',    'Modify System Settings',  'Change system configuration values',           8, 'system', 'manage', 1),
('manual_backup',      'Trigger Manual Backup',   'Execute a manual database backup',             8, 'system', 'execute', 1),
('manage_trusted_nets','Manage Trusted Networks',  'Add/edit/remove trusted network ranges',      8, 'system', 'manage', 1),
('manage_smtp',        'Manage SMTP Settings',    'Configure email delivery settings',            8, 'system', 'manage', 0),
('view_backups',       'View Backup History',     'View database backup history and status',      8, 'system', 'view', 0),

-- Logs (cat 9)
('view_logs',          'View System Logs',        'Access system and error log entries',          9, 'logs', 'view', 0),
('view_audit_trail',   'View Audit Trail',        'View detailed admin activity audit log',       9, 'logs', 'view', 0),
('delete_logs',        'Delete Log Entries',      'Remove log entries from the system',           9, 'logs', 'delete', 1),

-- Roles (cat 10)
('manage_roles',       'Manage Roles & Permissions','Create, edit, delete roles and assign permissions', 10, 'roles', 'manage', 1),
('view_acl_changelog', 'View ACL Change Log',     'View audit log of role/permission changes',   10, 'roles', 'view', 0);

-- ============================================================
-- SEED: Roles (7 admin + 2 technician)
-- ============================================================

INSERT INTO acl_roles (role_name, display_name, description, role_type, is_system_role, priority, color) VALUES
-- Admin roles
('super_admin',       'Super Administrator',  'Full system access including admin management, system settings, backups, and role management', 'admin', 1, 100, '#dc3545'),
('admin',             'Administrator',        'All data operations except delete, admin management, and system settings',                     'admin', 1, 80,  '#007bff'),
('billing_manager',   'Billing Manager',      'View dashboard, view keys (masked), view activation reports, export data, view logs',          'admin', 1, 40,  '#28a745'),
('hr_manager',        'HR / Account Manager', 'Manage technician accounts (create, edit, disable, reset passwords), view dashboard',          'admin', 1, 40,  '#17a2b8'),
('qc_inspector',      'QC Inspector',         'View activations, view hardware info, add activation notes, export reports',                   'admin', 1, 30,  '#fd7e14'),
('dept_manager',      'Department Manager',   'View technicians, view activations, view hardware, view dashboard, add notes',                 'admin', 1, 50,  '#6f42c1'),
('viewer',            'Viewer (Read-Only)',   'Read-only access to dashboard, reports, and logs for auditors and guests',                     'admin', 1, 10,  '#6c757d'),
-- Technician roles
('technician_full',   'Full Technician',      'Standard technician with full activation access and hardware submission',                      'technician', 1, 50, '#007bff'),
('technician_limited','Limited Technician',   'Trainee or restricted technician — can view keys but cannot activate independently',           'technician', 1, 10, '#6c757d');

-- ============================================================
-- SEED: Role Permission Assignments
-- ============================================================

-- Helper: Get role and permission IDs for assignment
-- super_admin: ALL permissions
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r CROSS JOIN acl_permissions p
WHERE r.role_name = 'super_admin';

-- admin: All view + edit/create operations, NO delete, NO admin mgmt, NO system settings, NO role mgmt
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'admin' AND p.permission_key IN (
    'view_dashboard', 'view_reports', 'export_data',
    'view_keys', 'add_key', 'import_keys', 'edit_key', 'recycle_key',
    'view_technicians', 'add_technician', 'edit_technician', 'reset_tech_password', 'assign_tech_role',
    'view_activations', 'add_activation_note',
    'view_hardware', 'export_hardware',
    'view_usb_devices', 'register_usb_device', 'disable_usb_device', 'enable_usb_device',
    'view_system_info', 'view_backups', 'manage_smtp',
    'view_logs', 'view_audit_trail'
);

-- billing_manager: Dashboard, keys (view only), activations (view), reports, export, logs
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'billing_manager' AND p.permission_key IN (
    'view_dashboard', 'view_reports', 'export_data',
    'view_keys',
    'view_activations',
    'view_logs', 'view_audit_trail'
);

-- hr_manager: Dashboard, technician CRUD, password reset, role assignment
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'hr_manager' AND p.permission_key IN (
    'view_dashboard',
    'view_technicians', 'add_technician', 'edit_technician', 'reset_tech_password', 'assign_tech_role',
    'view_usb_devices', 'register_usb_device', 'disable_usb_device', 'enable_usb_device'
);

-- qc_inspector: View activations, hardware, add notes, export
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'qc_inspector' AND p.permission_key IN (
    'view_dashboard', 'view_reports', 'export_data',
    'view_activations', 'add_activation_note',
    'view_hardware', 'export_hardware'
);

-- dept_manager: View technicians, activations, hardware, dashboard, add notes
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'dept_manager' AND p.permission_key IN (
    'view_dashboard', 'view_reports', 'export_data',
    'view_technicians',
    'view_activations', 'add_activation_note',
    'view_hardware', 'export_hardware',
    'view_logs'
);

-- viewer: Read-only across the board
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'viewer' AND p.permission_key IN (
    'view_dashboard', 'view_reports', 'export_data',
    'view_keys',
    'view_technicians',
    'view_activations',
    'view_hardware',
    'view_usb_devices',
    'view_system_info',
    'view_logs', 'view_audit_trail'
);

-- technician_full: Can activate, submit hardware
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'technician_full' AND p.permission_key IN (
    'view_keys', 'view_activations', 'view_hardware'
);

-- technician_limited: View only
INSERT INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM acl_roles r, acl_permissions p
WHERE r.role_name = 'technician_limited' AND p.permission_key IN (
    'view_keys'
);

-- ============================================================
-- ASSIGN EXISTING ADMIN USERS TO ACL ROLES
-- Map legacy role ENUM to new custom_role_id
-- ============================================================

UPDATE admin_users au
INNER JOIN acl_roles ar ON ar.role_name COLLATE utf8mb4_general_ci = au.role COLLATE utf8mb4_general_ci AND ar.role_type = 'admin'
SET au.custom_role_id = ar.id;
