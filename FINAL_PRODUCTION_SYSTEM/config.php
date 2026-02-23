<?php
/**
 * Production Database Configuration
 * OEM Activation System v2.0
 * 
 * IMPORTANT: Update these settings for your production server
 * Then rename this file to config.php to use it
 */

require_once __DIR__ . '/constants.php';

// Environment detection
$isProduction = !in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'activate.local']);

// Database Configuration with Environment Variable Support
$db_config = [
    'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost',
    'dbname' => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'oem_activation_prod',
    'username' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'oem_user',
    'password' => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? 'CHANGE_THIS_PASSWORD',
    'charset' => 'utf8mb4',
    'port' => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? DB_DEFAULT_PORT
];

// Validate critical configuration
if ($isProduction) {
    if ($db_config['password'] === 'CHANGE_THIS_PASSWORD' || empty($db_config['password'])) {
        error_log("SECURITY WARNING: Default database password detected in production!");
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            die(json_encode(['error' => 'Database configuration required']));
        }
    }
}

// Enhanced PDO connection with retry logic
function createDatabaseConnection($config, $maxRetries = DB_MAX_RETRIES) {
    $attempts = 0;
    $lastException = null;
    
    while ($attempts < $maxRetries) {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, // Disable persistent connections for better reliability
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']} COLLATE {$config['charset']}_unicode_ci"
            ]);
            
            // Test connection with a simple query
            $pdo->query("SELECT 1");
            return $pdo;
            
        } catch (PDOException $e) {
            $lastException = $e;
            $attempts++;
            
            if ($attempts < $maxRetries) {
                error_log("Database connection attempt $attempts failed, retrying... Error: " . $e->getMessage());
                usleep(DB_RETRY_DELAY_US);
            }
        }
    }
    
    // All attempts failed
    error_log("Database connection failed after $maxRetries attempts. Last error: " . $lastException->getMessage());
    
    if (php_sapi_name() !== 'cli') {
        http_response_code(503);
        die(json_encode([
            'error' => 'Database service temporarily unavailable',
            'support' => 'Please check server configuration and try again'
        ]));
    } else {
        throw $lastException;
    }
}

// Create database connection
$pdo = createDatabaseConnection($db_config);

// Helper function to get configuration values with caching
$configCache = [];
function getConfig($key, $useCache = true) {
    global $pdo, $configCache;
    
    if ($useCache && isset($configCache[$key])) {
        return $configCache[$key];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $value = $result ? $result['config_value'] : null;
        
        if ($useCache) {
            $configCache[$key] = $value;
        }
        
        return $value;
    } catch (PDOException $e) {
        error_log("Failed to get config for key '$key': " . $e->getMessage());
        return null;
    }
}

// Helper function to generate secure random token
function generateToken($length = SESSION_TOKEN_BYTES) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    } else {
        // Fallback for older PHP versions
        return bin2hex(openssl_random_pseudo_bytes($length));
    }
}

// Enhanced JSON response function with security headers
function jsonResponse($data, $status = 200) {
    // Security headers
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // CORS headers (restrict to your domain in production)
    $allowedOrigins = array_filter(array_map('trim', explode(',', getenv('CORS_ORIGINS') ?: '')));

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($allowedOrigins) && in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
    
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Enhanced client IP detection
// In Docker/local network environments, client IPs are private (192.168.x, 172.x, 10.x).
// We first check proxy headers for a public IP, then fall back to accepting private IPs
// from those same headers, since Docker routing uses private addresses.
function getClientIP() {
    $proxyHeaders = [
        'HTTP_X_FORWARDED_FOR',    // Most common proxy header
        'HTTP_X_REAL_IP',          // Nginx proxy
        'HTTP_CLIENT_IP',          // Proxy
        'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
        'HTTP_FORWARDED_FOR',      // RFC 7239
        'HTTP_FORWARDED',          // RFC 7239
    ];

    // Priority 1: Check proxy headers for public IPs
    foreach ($proxyHeaders as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    // Priority 2: Accept private IPs from proxy headers (Docker/local network)
    foreach ($proxyHeaders as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    // Priority 3: Fallback to REMOTE_ADDR (works for direct connections)
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Helper function to display product key securely
function formatProductKeySecure($product_key, $context = 'email') {
    if ($context === 'email') {
        $hide_keys = (bool)getConfig('hide_product_keys_in_emails');
        if ($hide_keys) {
            return "***" . substr($product_key, -KEY_MASK_SUFFIX_LEN);
        }
    } elseif ($context === 'admin') {
        $show_keys = (bool)getConfig('show_full_keys_in_admin');
        if (!$show_keys) {
            return substr($product_key, 0, KEY_MASK_PREFIX_LEN) . "-*****-*****-*****-" . substr($product_key, -KEY_MASK_SUFFIX_LEN);
        }
    }
    
    return $product_key;
}

// Enhanced session validation with additional security checks
function validateSession($token) {
    global $pdo;
    
    if (empty($token) || !is_string($token)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, k.product_key, k.key_status, t.is_active as tech_active
            FROM active_sessions s 
            LEFT JOIN oem_keys k ON s.key_id = k.id
            LEFT JOIN technicians t ON s.technician_id = t.technician_id
            WHERE s.session_token = ? 
            AND s.is_active = 1 
            AND s.expires_at > NOW()
            AND t.is_active = 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
}

// CRITICAL: Atomic key allocation with enhanced concurrency protection
function allocateKeyAtomically($pdo, $technician_id, $order_number) {
    $lockName = "key_allocation_" . md5($technician_id . $order_number);
    $needsCommit = false;

    try {
        // Only start transaction if one isn't already active
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $needsCommit = true;
        }

        // Get lock with timeout to prevent deadlocks
        $stmt = $pdo->prepare("SELECT GET_LOCK(?, " . DB_LOCK_TIMEOUT . ") as lock_acquired");
        $stmt->execute([$lockName]);
        $lockResult = $stmt->fetch();
        
        if ($lockResult['lock_acquired'] != 1) {
            $pdo->rollback();
            error_log("Could not acquire lock for key allocation: $technician_id");
            return null;
        }
        
        // Select and lock the best available key
        $stmt = $pdo->prepare("
            SELECT * FROM oem_keys 
            WHERE key_status IN ('unused', 'retry') 
            AND (fail_counter < " . MAX_KEY_FAIL_COUNTER . " OR key_status = 'unused')
            ORDER BY 
                CASE WHEN key_status = 'unused' THEN 0 ELSE 1 END,
                fail_counter ASC,
                COALESCE(last_use_date, '1970-01-01') ASC,
                id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute();
        $key = $stmt->fetch();
        
        if ($key) {
            // Mark key as in use immediately
            $stmt = $pdo->prepare("
                UPDATE oem_keys
                SET key_status = 'allocated',
                    last_use_date = CURDATE(),
                    last_use_time = CURTIME(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$key['id']]);
            
            error_log("Key allocated atomically: Key ID {$key['id']} to {$technician_id} for order {$order_number}");
        }
        
        // Release lock
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lockName]);

        // Only commit if we started the transaction
        if ($needsCommit) {
            $pdo->commit();
        }
        return $key;

    } catch (Exception $e) {
        // Only rollback if we started the transaction
        if ($needsCommit && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Atomic key allocation failed: " . $e->getMessage());
        
        // Try to release lock even on failure
        try {
            $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
            $stmt->execute([$lockName]);
        } catch (Exception $lockError) {
            error_log("Failed to release lock: " . $lockError->getMessage());
        }
        
        throw $e;
    }
}

// Enhanced session cleanup with performance optimization
function cleanupExpiredSessions($pdo) {
    try {
        $stmt = $pdo->prepare("
            UPDATE active_sessions 
            SET is_active = 0 
            WHERE expires_at < NOW() AND is_active = 1
            LIMIT " . SESSION_CLEANUP_BATCH . "
        ");
        $stmt->execute();
        $cleaned = $stmt->rowCount();
        
        if ($cleaned > 0) {
            error_log("Cleaned up {$cleaned} expired sessions");
        }
        
        return $cleaned;
    } catch (Exception $e) {
        error_log("Session cleanup failed: " . $e->getMessage());
        return 0;
    }
}

// Enhanced active session retrieval with better locking
function getActiveSession($pdo, $technician_id) {
    try {
        cleanupExpiredSessions($pdo);
        
        $stmt = $pdo->prepare("
            SELECT s.*, k.product_key, k.oem_identifier, k.key_status, k.fail_counter
            FROM active_sessions s 
            LEFT JOIN oem_keys k ON s.key_id = k.id
            WHERE s.technician_id = ? 
            AND s.is_active = 1 
            AND s.expires_at > NOW()
            ORDER BY s.created_at DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$technician_id]);
        return $stmt->fetch();
        
    } catch (Exception $e) {
        error_log("Get active session failed: " . $e->getMessage());
        return null;
    }
}

// Set timezone with environment variable support
$timezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? 'UTC';
if (!in_array($timezone, timezone_identifiers_list())) {
    error_log("Invalid timezone '$timezone', falling back to UTC");
    $timezone = 'UTC';
}
date_default_timezone_set($timezone);

// Log successful configuration load
error_log("OEM Activation System configuration loaded successfully. Environment: " . 
         ($isProduction ? "Production" : "Development"));
?>