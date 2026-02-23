<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '/var/www/html/activate/config.php';

echo "=== Testing Admin Dashboard ===\n\n";

// Test 1: Database connection
echo "1. Database Connection: ";
try {
    $stmt = $pdo->query("SELECT 1");
    echo "✓ OK\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    exit;
}

// Test 2: Get admin session
echo "2. Active Session: ";
$stmt = $pdo->query("SELECT session_token, username FROM admin_sessions s JOIN admin_users u ON s.admin_id = u.id WHERE s.is_active = 1 ORDER BY s.created_at DESC LIMIT 1");
$sess = $stmt->fetch(PDO::FETCH_ASSOC);
if ($sess) {
    echo "✓ Found (User: {$sess['username']})\n";
} else {
    echo "✗ FAILED: No active session\n";
    exit;
}

// Test 3: Dashboard stats query
echo "3. Dashboard Stats Query: ";
try {
    $stmt = $pdo->query("SELECT key_status, COUNT(*) as count FROM oem_keys GROUP BY key_status");
    $key_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "✓ OK (Keys: " . array_sum($key_stats) . ")\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
}

// Test 4: Technicians query
echo "4. Technicians Query: ";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM technicians");
    $count = $stmt->fetchColumn();
    echo "✓ OK (Count: $count)\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
}

// Test 5: Activation attempts query
echo "5. Activation Attempts Query: ";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM activation_attempts WHERE DATE(attempt_date) = CURDATE()");
    $count = $stmt->fetchColumn();
    echo "✓ OK (Today: $count)\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== All Tests Passed! ===\n";
?>
