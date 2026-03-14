<?php
// Secure Admin Panel with Enhanced Security
require_once 'security-headers.php';
require_once 'config.php';
require_once 'functions/network-utils.php';
require_once 'functions/push-helpers.php';
require_once 'functions/admin-helpers.php';

// Security Configuration (used by enforceHTTPS and checkIPWhitelist below)
$ADMIN_CONFIG = [
    'REQUIRE_HTTPS' => (bool)getConfig('admin_require_https'),
    'IP_WHITELIST_ENABLED' => (bool)getConfig('admin_ip_whitelist_enabled'),
];

// Start secure session
session_start();
session_regenerate_id(true);

// Security Functions
function enforceHTTPS() {
    global $ADMIN_CONFIG;
    if ($ADMIN_CONFIG['REQUIRE_HTTPS'] && !isset($_SERVER['HTTPS'])) {
        // Validate host header to prevent open redirect attacks
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $allowedHost = getConfig('admin_allowed_host') ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        if ($host !== $allowedHost) {
            $host = $allowedHost;
        }
        header('Location: https://' . $host . $_SERVER['REQUEST_URI']);
        exit;
    }
}

function checkIPWhitelist() {
    global $ADMIN_CONFIG, $pdo;
    if (!$ADMIN_CONFIG['IP_WHITELIST_ENABLED']) return true;
    
    $client_ip = getClientIP();
    
    // Get all active whitelist entries
    $stmt = $pdo->prepare("
        SELECT ip_address, ip_range FROM admin_ip_whitelist 
        WHERE is_active = 1
    ");
    $stmt->execute();
    $whitelist_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($whitelist_entries as $entry) {
        // Check exact IP match
        if ($entry['ip_address'] === $client_ip) {
            return true;
        }
        
        // Check IP range match (safely)
        if (!empty($entry['ip_range'])) {
            if (isIPInRange($client_ip, $entry['ip_range'])) {
                return true;
            }
        }
    }
    
    return false;
}

// isIPInRange() is provided by functions/network-utils.php
// authenticateAdmin(), validateAdminSession(), logAdminActivity() are provided by functions/admin-helpers.php

// Enforce security measures
enforceHTTPS();

if (!checkIPWhitelist()) {
    logAdminActivity(null, null, 'ACCESS_DENIED', 'IP not in whitelist: ' . getClientIP());
    http_response_code(403);
    die('Access denied from this IP address.');
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['admin_token'])) {
        $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE session_token = ?");
        $stmt->execute([$_SESSION['admin_token']]);
        logAdminActivity($_SESSION['admin_id'], $_SESSION['session_id'], 'LOGOUT', 'User logout');
    }
    session_destroy();
    // Return JSON for API calls, redirect for browser
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Logged out']);
    } else {
        header('Location: /');
    }
    exit;
}

// Legacy login form removed — React frontend is the admin panel.
// This file is still included by admin_v2.php for session/security infrastructure.
// If accessed directly in a browser, redirect to the React frontend.

// Handle legacy POST login (in case something still hits it)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $result = authenticateAdmin($_POST['username'], $_POST['password']);
    if ($result === false) {
        header('Location: /?error=invalid_credentials');
    } elseif (is_array($result) && isset($result['error'])) {
        header('Location: /?error=auth_failed');
    } else {
        header('Location: /');
    }
    exit;
}

// Check session — redirect to React frontend either way
$admin_session = validateAdminSession();
header('Location: /');
exit;