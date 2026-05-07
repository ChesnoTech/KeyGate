<?php
/**
 * One-time migration script: Hash plaintext temp_passwords with bcrypt.
 * Run via CLI: php hash_temp_passwords.php
 */

require_once __DIR__ . '/../config.php';

echo "Hashing plaintext temp_passwords...\n";

$stmt = $pdo->query("SELECT id, temp_password FROM `" . t('technicians') . "` WHERE temp_password IS NOT NULL AND temp_password != ''");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($rows as $row) {
    // Skip if already a bcrypt hash
    if (str_starts_with($row['temp_password'], '$2y$') || str_starts_with($row['temp_password'], '$2a$')) {
        echo "  Skipping ID {$row['id']} (already hashed)\n";
        continue;
    }

    $hashed = password_hash($row['temp_password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $update = $pdo->prepare("UPDATE `" . t('technicians') . "` SET temp_password = ? WHERE id = ?");
    $update->execute([$hashed, $row['id']]);
    $updated++;
    echo "  Hashed temp_password for ID {$row['id']}\n";
}

echo "Done. Updated {$updated} row(s).\n";
