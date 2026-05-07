-- ============================================================
-- Downloads ACL Migration
-- Date: 2026-03-16
-- Purpose: Add ACL permissions for client downloads management
-- ============================================================

-- Add "Downloads" permission category
INSERT INTO `#__acl_permission_categories` (category_key, display_name, icon, sort_order)
VALUES ('downloads', 'Client Downloads', NULL, 55)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Add download permissions
INSERT INTO `#__acl_permissions` (permission_key, display_name, description, category_id, resource_type, action_type, is_dangerous)
SELECT 'view_downloads', 'View Downloads', 'View and download client tools (launcher, PS7 installer, extensions)',
       c.id, 'downloads', 'view', 0
FROM `#__acl_permission_categories` c WHERE c.category_key = 'downloads'
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

INSERT INTO `#__acl_permissions` (permission_key, display_name, description, category_id, resource_type, action_type, is_dangerous)
SELECT 'manage_downloads', 'Manage Downloads', 'Upload, replace, and delete client resources',
       c.id, 'downloads', 'manage', 1
FROM `#__acl_permission_categories` c WHERE c.category_key = 'downloads'
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Grant both permissions to super_admin role
INSERT IGNORE INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM `#__acl_roles` r CROSS JOIN `#__acl_permissions` p
WHERE r.role_name = 'super_admin'
  AND p.permission_key IN ('view_downloads', 'manage_downloads');

-- Grant view_downloads to admin role
INSERT IGNORE INTO acl_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM `#__acl_roles` r, acl_permissions p
WHERE r.role_name = 'admin'
  AND p.permission_key = 'view_downloads';
