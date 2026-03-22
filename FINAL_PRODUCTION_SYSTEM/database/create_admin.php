<?php
require __DIR__ . '/../config.php';
$hash = password_hash("Admin2024!", PASSWORD_BCRYPT, ["cost" => 10]);
$stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), failed_login_attempts = 0, locked_until = NULL");
$stmt->execute(["admin", $hash, "Administrator", "admin@localhost", "super_admin"]);
echo "Admin user created/reset\n";
