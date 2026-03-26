<?php
require __DIR__ . '/../config.php';
$hash = password_hash("Admin2024!", PASSWORD_BCRYPT, ["cost" => 10]);
// Get super_admin role ID from acl_roles
$roleId = $pdo->query("SELECT id FROM acl_roles WHERE role_name = 'super_admin' LIMIT 1")->fetchColumn() ?: null;

$stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, full_name, email, role, custom_role_id) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), custom_role_id = VALUES(custom_role_id), failed_login_attempts = 0, locked_until = NULL");
$stmt->execute(["admin", $hash, "Administrator", "admin@localhost", "super_admin", $roleId]);
echo "Admin user created/reset\n";
