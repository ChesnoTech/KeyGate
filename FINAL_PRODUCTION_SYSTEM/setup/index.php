<?php
/**
 * OEM Activation System - Professional Installation Wizard
 * Version: 2.0.0
 * Similar to Joomla 5 installation process
 */

session_start();

// System Configuration
define('OEM_VERSION', '2.0.0');
define('OEM_RELEASE_DATE', '2025-08-24');
define('OEM_MINIMUM_PHP', '8.0.0');
define('OEM_RECOMMENDED_PHP', '8.3.22');

// Installation steps
$steps = [
    1 => 'Pre-installation Check',
    2 => 'Database Configuration', 
    3 => 'System Configuration',
    4 => 'Admin Account Setup',
    5 => 'Installation Complete'
];

$current_step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$current_step = max(1, min(5, $current_step));

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($current_step) {
        case 1:
            if (checkSystemRequirements()) {
                header('Location: index.php?step=2');
                exit;
            }
            break;
        case 2:
            if (handleDatabaseSetup()) {
                header('Location: index.php?step=3');
                exit;
            }
            break;
        case 3:
            if (handleSystemConfiguration()) {
                header('Location: index.php?step=4');
                exit;
            }
            break;
        case 4:
            if (handleAdminSetup()) {
                header('Location: index.php?step=5');
                exit;
            }
            break;
    }
}

function checkSystemRequirements() {
    $checks = [
        // PHP Version
        'php_version' => version_compare(PHP_VERSION, OEM_MINIMUM_PHP, '>='),
        
        // Required Extensions
        'mysql_extension' => extension_loaded('pdo_mysql'),
        'curl_extension' => extension_loaded('curl'),
        'openssl_extension' => extension_loaded('openssl'),
        'json_extension' => extension_loaded('json'),
        'mbstring_extension' => extension_loaded('mbstring'),
        
        // Concurrency Requirements (v2.0 enhancement)
        'mysql_innodb' => checkInnoDBSupport(),
        'mysql_transactions' => checkTransactionSupport(),
        
        // PHP Settings
        'memory_limit' => checkMemoryLimit(),
        'max_execution_time' => checkExecutionTime(),
        'file_uploads' => ini_get('file_uploads') == '1',
        'upload_max_filesize' => checkUploadSize(),
        'post_max_size' => checkPostSize(),
        'max_input_vars' => checkMaxInputVars(),
        
        // File Permissions
        'write_permissions' => checkWritePermissions(),
        'config_writable' => is_writable('../') || createDirectory('../'),
        'logs_directory' => createDirectory('../logs'),
        'tmp_directory' => createDirectory('../tmp'),
        'uploads_directory' => createDirectory('../uploads'),
        
        // Security Settings
        'safe_mode' => !ini_get('safe_mode'),
        'magic_quotes' => !function_exists('get_magic_quotes_gpc') || !get_magic_quotes_gpc(),
        'register_globals' => !ini_get('register_globals'),
        
        // Optional but Recommended
        'https_available' => isHTTPS() || canForceHTTPS(),
        'mod_rewrite' => checkModRewrite(),
    ];
    
    // Attempt to fix issues automatically
    $fixes_attempted = attemptAutoFixes();
    $_SESSION['auto_fixes'] = $fixes_attempted;
    
    $_SESSION['system_checks'] = $checks;
    $_SESSION['php_info'] = getPHPInfo();
    
    return !in_array(false, array_slice($checks, 0, 7), true); // Only require critical checks
}

function checkMemoryLimit() {
    $memory_limit = ini_get('memory_limit');
    if ($memory_limit == -1) return true; // Unlimited
    
    $memory_bytes = convertToBytes($memory_limit);
    $required_bytes = 256 * 1024 * 1024; // 256MB
    
    return $memory_bytes >= $required_bytes;
}

function checkExecutionTime() {
    $max_execution_time = ini_get('max_execution_time');
    if ($max_execution_time == 0) return true; // Unlimited
    
    return $max_execution_time >= 300; // 5 minutes
}

function checkUploadSize() {
    $upload_max_filesize = ini_get('upload_max_filesize');
    $upload_bytes = convertToBytes($upload_max_filesize);
    $required_bytes = 10 * 1024 * 1024; // 10MB
    
    return $upload_bytes >= $required_bytes;
}

function checkPostSize() {
    $post_max_size = ini_get('post_max_size');
    $post_bytes = convertToBytes($post_max_size);
    $required_bytes = 10 * 1024 * 1024; // 10MB
    
    return $post_bytes >= $required_bytes;
}

function checkMaxInputVars() {
    $max_input_vars = ini_get('max_input_vars');
    return $max_input_vars >= 1000;
}

function checkWritePermissions() {
    $test_file = '../test_write_' . uniqid() . '.tmp';
    
    if (@file_put_contents($test_file, 'test') !== false) {
        @unlink($test_file);
        return true;
    }
    
    return false;
}

function createDirectory($path) {
    if (is_dir($path)) {
        return is_writable($path);
    }
    
    if (@mkdir($path, 0755, true)) {
        return is_writable($path);
    }
    
    return false;
}

function isHTTPS() {
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function canForceHTTPS() {
    // Check if we can detect HTTPS capability
    return !empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;
}

function checkModRewrite() {
    // Check if mod_rewrite is available
    if (function_exists('apache_get_modules')) {
        return in_array('mod_rewrite', apache_get_modules());
    }
    
    // Alternative check for mod_rewrite
    if (isset($_SERVER['HTTP_MOD_REWRITE'])) {
        return $_SERVER['HTTP_MOD_REWRITE'] == 'On';
    }
    
    // Test .htaccess support
    $htaccess_test = '../.htaccess_test';
    $test_content = "RewriteEngine On\nRewriteRule ^test$ index.php [L]\n";
    
    if (@file_put_contents($htaccess_test, $test_content)) {
        $works = !strpos(file_get_contents('http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/test'), 'Not Found');
        @unlink($htaccess_test);
        return $works;
    }
    
    return false; // Cannot determine
}

function checkInnoDBSupport() {
    if (!extension_loaded('pdo_mysql')) {
        return false;
    }
    
    try {
        // Create a temporary connection to check InnoDB support
        $host = $_SESSION['db_config']['host'] ?? 'localhost';
        $username = $_SESSION['db_config']['username'] ?? 'root';
        $password = $_SESSION['db_config']['password'] ?? '';
        
        $pdo = new PDO("mysql:host=$host", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        $stmt = $pdo->query("SHOW ENGINES");
        $engines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($engines as $engine) {
            if (strtoupper($engine['Engine']) === 'INNODB' && 
                in_array(strtoupper($engine['Support']), ['YES', 'DEFAULT'])) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        // If we can't check yet (no DB config), assume it's supported
        return !isset($_SESSION['db_config']);
    }
}

function checkTransactionSupport() {
    if (!extension_loaded('pdo_mysql')) {
        return false;
    }
    
    try {
        // Create a temporary connection to test transactions
        $host = $_SESSION['db_config']['host'] ?? 'localhost';
        $username = $_SESSION['db_config']['username'] ?? 'root';
        $password = $_SESSION['db_config']['password'] ?? '';
        
        $pdo = new PDO("mysql:host=$host", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Test transaction support
        $pdo->beginTransaction();
        $pdo->rollback();
        
        return true;
    } catch (Exception $e) {
        // If we can't check yet (no DB config), assume it's supported
        return !isset($_SESSION['db_config']);
    }
}

function convertToBytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = intval($val);
    
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    
    return $val;
}

function attemptAutoFixes() {
    $fixes = [];
    
    // Try to set better PHP settings if possible
    if (function_exists('ini_set')) {
        // Increase memory limit if possible
        if (checkMemoryLimit() === false) {
            if (@ini_set('memory_limit', '256M')) {
                $fixes[] = 'Increased memory limit to 256M';
            }
        }
        
        // Increase execution time if possible
        if (checkExecutionTime() === false) {
            if (@ini_set('max_execution_time', 300)) {
                $fixes[] = 'Increased execution time to 300 seconds';
            }
        }
    }
    
    // Try to create directories with correct permissions
    $directories = ['../logs', '../tmp', '../uploads', '../backups'];
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0755, true)) {
                $fixes[] = "Created directory: " . basename($dir);
            }
        }
        
        // Try to fix permissions
        if (is_dir($dir) && !is_writable($dir)) {
            if (@chmod($dir, 0755)) {
                $fixes[] = "Fixed permissions for: " . basename($dir);
            }
        }
    }
    
    // Try to create .htaccess if it doesn't exist
    $htaccess_path = '../.htaccess';
    if (!file_exists($htaccess_path)) {
        $htaccess_content = generateBasicHtaccess();
        if (@file_put_contents($htaccess_path, $htaccess_content)) {
            $fixes[] = 'Created basic .htaccess file';
        }
    }
    
    return $fixes;
}

function generateBasicHtaccess() {
    return "# OEM Activation System - Basic Security
# Deny access to sensitive files
<Files \"config.php\">
    Require all denied
</Files>

<Files \"*.sql\">
    Require all denied
</Files>

# Security headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options \"nosniff\"
    Header always set X-Frame-Options \"SAMEORIGIN\"
    Header always set X-XSS-Protection \"1; mode=block\"
</IfModule>

# Error pages
ErrorDocument 403 /activate/403.html
ErrorDocument 404 /activate/404.html
";
}

function getPHPInfo() {
    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => php_sapi_name(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_input_vars' => ini_get('max_input_vars'),
        'safe_mode' => ini_get('safe_mode') ? 'On' : 'Off',
        'magic_quotes_gpc' => function_exists('get_magic_quotes_gpc') ? (get_magic_quotes_gpc() ? 'On' : 'Off') : 'N/A (PHP 8+)',
        'register_globals' => ini_get('register_globals') ? 'On' : 'Off',
        'file_uploads' => ini_get('file_uploads') ? 'Enabled' : 'Disabled',
    ];
}

function handleDatabaseSetup() {
    $host = $_POST['db_host'] ?? '';
    $username = $_POST['db_username'] ?? '';
    $password = $_POST['db_password'] ?? '';
    $database = $_POST['db_database'] ?? '';
    
    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$database`");
        
        // Run installation SQL
        $sql = file_get_contents('../database/install.sql');
        $pdo->exec($sql);
        
        $_SESSION['db_config'] = [
            'host' => $host,
            'username' => $username,
            'password' => $password,
            'database' => $database
        ];
        
        return true;
    } catch (Exception $e) {
        $_SESSION['db_error'] = $e->getMessage();
        return false;
    }
}

function handleSystemConfiguration() {
    $_SESSION['system_config'] = [
        'site_name' => $_POST['site_name'] ?? 'OEM Activation System',
        'site_url' => $_POST['site_url'] ?? '',
        'smtp_server' => $_POST['smtp_server'] ?? 'smtp.zoho.com',
        'smtp_port' => $_POST['smtp_port'] ?? '587',
        'smtp_username' => $_POST['smtp_username'] ?? '',
        'smtp_password' => $_POST['smtp_password'] ?? '',
        'email_from' => $_POST['email_from'] ?? '',
        'email_to' => $_POST['email_to'] ?? '',
        'enable_https' => isset($_POST['enable_https']),
        'enable_ip_whitelist' => isset($_POST['enable_ip_whitelist']),
    ];
    
    return true;
}

function handleAdminSetup() {
    $username = $_POST['admin_username'] ?? '';
    $password = $_POST['admin_password'] ?? '';
    $email = $_POST['admin_email'] ?? '';
    $full_name = $_POST['admin_full_name'] ?? '';
    
    if (strlen($password) < 8) {
        $_SESSION['admin_error'] = 'Password must be at least 8 characters long';
        return false;
    }
    
    // Save admin details for final installation
    $_SESSION['admin_config'] = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'email' => $email,
        'full_name' => $full_name
    ];
    
    // Perform final installation
    return performFinalInstallation();
}

function performFinalInstallation() {
    try {
        // Create configuration file
        createConfigurationFile();
        
        // Setup admin account
        setupAdminAccount();
        
        // Apply system configuration
        applySystemConfiguration();
        
        // Create directory structure
        createDirectoryStructure();
        
        // Set permissions
        setFilePermissions();
        
        return true;
    } catch (Exception $e) {
        $_SESSION['install_error'] = $e->getMessage();
        return false;
    }
}

function createConfigurationFile() {
    $db = $_SESSION['db_config'];
    
    // Read the enhanced config template
    $config_template = file_get_contents('../config/config-template-enhanced.php');
    
    // Replace placeholders with actual values
    $replacements = [
        '{INSTALL_DATE}' => date('Y-m-d H:i:s'),
        '{OEM_VERSION}' => OEM_VERSION,
        '{DB_HOST}' => $db['host'],
        '{DB_NAME}' => $db['database'],
        '{DB_USERNAME}' => $db['username'],
        '{DB_PASSWORD}' => $db['password']
    ];
    
    $config = str_replace(array_keys($replacements), array_values($replacements), $config_template);
    
    file_put_contents('../config.php', $config);
}

function setupAdminAccount() {
    $db = $_SESSION['db_config'];
    $admin = $_SESSION['admin_config'];
    
    $pdo = new PDO("mysql:host={$db['host']};dbname={$db['database']};charset=utf8mb4", 
                   $db['username'], $db['password']);
    
    $stmt = $pdo->prepare("
        INSERT INTO admin_users (username, full_name, email, password_hash, role, must_change_password)
        VALUES (?, ?, ?, ?, 'super_admin', 0)
    ");
    $stmt->execute([$admin['username'], $admin['full_name'], $admin['email'], $admin['password']]);
}

function applySystemConfiguration() {
    $db = $_SESSION['db_config'];
    $config = $_SESSION['system_config'];
    
    $pdo = new PDO("mysql:host={$db['host']};dbname={$db['database']};charset=utf8mb4", 
                   $db['username'], $db['password']);
    
    $configs = [
        ['smtp_server', $config['smtp_server']],
        ['smtp_port', $config['smtp_port']],
        ['smtp_username', $config['smtp_username']],
        ['smtp_password', $config['smtp_password']],
        ['email_from', $config['email_from']],
        ['email_to', $config['email_to']],
        ['admin_require_https', $config['enable_https'] ? '1' : '0'],
        ['admin_ip_whitelist_enabled', $config['enable_ip_whitelist'] ? '1' : '0'],
    ];
    
    $stmt = $pdo->prepare("UPDATE system_config SET config_value = ? WHERE config_key = ?");
    foreach ($configs as $cfg) {
        $stmt->execute([$cfg[1], $cfg[0]]);
    }
}

function createDirectoryStructure() {
    $dirs = [
        '../logs',
        '../tmp',
        '../uploads',
        '../backups'
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

function setFilePermissions() {
    chmod('../config.php', 0644);
    chmod('../logs', 0755);
    chmod('../tmp', 0755);
    chmod('../uploads', 0755);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OEM Activation System - Installation Wizard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .installer {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 800px;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .logo { font-size: 28px; font-weight: bold; margin-bottom: 10px; }
        .version { opacity: 0.9; font-size: 14px; }
        .steps {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            overflow-x: auto;
        }
        .step {
            flex: 1;
            padding: 15px 10px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }
        .step.active {
            color: #667eea;
            border-bottom-color: #667eea;
            background: white;
        }
        .step.completed {
            color: #28a745;
            border-bottom-color: #28a745;
        }
        .content {
            padding: 40px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        input[type="text"], input[type="password"], input[type="email"], input[type="number"], select, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #6c757d;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .requirements {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 15px;
            margin: 20px 0;
        }
        .requirement {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-radius: 5px;
            background: #f8f9fa;
        }
        .requirement.pass {
            background: #d4edda;
            color: #155724;
        }
        .requirement.fail {
            background: #f8d7da;
            color: #721c24;
        }
        .actions {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }
        .system-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="installer">
        <div class="header">
            <div class="logo">🔐 OEM Activation System</div>
            <div class="version">Installation Wizard - Version <?php echo OEM_VERSION; ?></div>
        </div>
        
        <div class="steps">
            <?php foreach ($steps as $step_num => $step_name): ?>
                <div class="step <?php 
                    echo $step_num == $current_step ? 'active' : '';
                    echo $step_num < $current_step ? 'completed' : '';
                ?>">
                    <?php echo $step_num; ?>. <?php echo $step_name; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="content">
            <?php
            // Include the appropriate step
            switch ($current_step) {
                case 1:
                    // Perform system checks for step 1
                    if (!isset($_SESSION['system_checks'])) {
                        checkSystemRequirements(); // This function sets session variables internally
                    }
                    include 'steps/step1_requirements.php';
                    break;
                case 2:
                    include 'steps/step2_database.php';
                    break;
                case 3:
                    include 'steps/step3_configuration.php';
                    break;
                case 4:
                    include 'steps/step4_admin.php';
                    break;
                case 5:
                    include 'steps/step5_complete.php';
                    break;
            }
            ?>
        </div>
    </div>
</body>
</html>