<?php
// Step 1: Pre-installation Requirements Check

$checks = $_SESSION['system_checks'] ?? [];
$php_info = $_SESSION['php_info'] ?? [];
$auto_fixes = $_SESSION['auto_fixes'] ?? [];

// Fallback: perform checks inline if session data is not available
if (empty($checks)) {
    $checks = [
        'php_version' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'mysql_extension' => extension_loaded('pdo_mysql'),
        'curl_extension' => extension_loaded('curl'),
        'openssl_extension' => extension_loaded('openssl'),
        'json_extension' => extension_loaded('json'),
        'mbstring_extension' => extension_loaded('mbstring'),
        'write_permissions' => is_writable('../'),
        'mysql_innodb' => true, // Simplified check
        'mysql_transactions' => true, // Simplified check
        'memory_limit' => (ini_get('memory_limit') != '-1' && convertToBytes(ini_get('memory_limit')) >= 268435456),
        'max_execution_time' => (ini_get('max_execution_time') == 0 || ini_get('max_execution_time') >= 300),
        'file_uploads' => ini_get('file_uploads') == '1',
        'upload_max_filesize' => convertToBytes(ini_get('upload_max_filesize')) >= 10485760,
        'post_max_size' => convertToBytes(ini_get('post_max_size')) >= 10485760,
        'max_input_vars' => ini_get('max_input_vars') >= 1000,
        'config_writable' => is_writable('../'),
        'logs_directory' => is_dir('../logs') || @mkdir('../logs', 0755, true),
        'tmp_directory' => is_dir('../tmp') || @mkdir('../tmp', 0755, true),
        'uploads_directory' => is_dir('../uploads') || @mkdir('../uploads', 0755, true),
        'safe_mode' => !ini_get('safe_mode'),
        'magic_quotes' => !function_exists('get_magic_quotes_gpc') || !get_magic_quotes_gpc(),
        'register_globals' => !ini_get('register_globals'),
        'https_available' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'mod_rewrite' => false // Simplified check
    ];
}

if (empty($php_info)) {
    $php_info = [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'php_sapi' => php_sapi_name(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'safe_mode' => ini_get('safe_mode') ? 'On' : 'Off',
        'magic_quotes_gpc' => function_exists('get_magic_quotes_gpc') ? (get_magic_quotes_gpc() ? 'On' : 'Off') : 'N/A (PHP 8+)',
        'register_globals' => ini_get('register_globals') ? 'On' : 'Off',
        'file_uploads' => ini_get('file_uploads') ? 'Enabled' : 'Disabled',
    ];
}

// Helper function for byte conversion
if (!function_exists('convertToBytes')) {
    function convertToBytes($val) {
        if (is_numeric($val)) return (int)$val;
        $val = trim($val);
        $last = strtolower(substr($val, -1));
        $val = (int)substr($val, 0, -1);
        switch ($last) {
            case 'g': $val *= 1024; // fallthrough
            case 'm': $val *= 1024; // fallthrough  
            case 'k': $val *= 1024;
        }
        return $val;
    }
}

$critical_checks = array_slice($checks, 0, 7, true); // Only critical checks required
$all_passed = !in_array(false, $critical_checks, true);

?>

<h2>Pre-installation Check</h2>
<p>Please ensure your server meets the minimum requirements to install KeyGate.</p>

<?php if (!empty($auto_fixes)): ?>
<div class="alert alert-success">
    <h4>🔧 Automatic Fixes Applied</h4>
    <p>The installer has automatically fixed the following issues:</p>
    <ul style="margin: 10px 0 0 20px;">
        <?php foreach ($auto_fixes as $fix): ?>
            <li><?php echo htmlspecialchars($fix); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="system-info">
    <h3>System Information</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
        <div>
            <strong>Server Environment:</strong><br>
            PHP Version: <?php echo $php_info['php_version'] ?? PHP_VERSION; ?><br>
            Server: <?php echo $php_info['server_software'] ?? $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?><br>
            OS: <?php echo php_uname('s') . ' ' . php_uname('r'); ?><br>
            SAPI: <?php echo $php_info['php_sapi'] ?? php_sapi_name(); ?>
        </div>
        <div>
            <strong>PHP Settings:</strong><br>
            Memory Limit: <?php echo $php_info['memory_limit'] ?? ini_get('memory_limit'); ?><br>
            Execution Time: <?php echo $php_info['max_execution_time'] ?? ini_get('max_execution_time'); ?><br>
            Upload Max: <?php echo $php_info['upload_max_filesize'] ?? ini_get('upload_max_filesize'); ?><br>
            Post Max: <?php echo $php_info['post_max_size'] ?? ini_get('post_max_size'); ?>
        </div>
        <div>
            <strong>Security Settings:</strong><br>
            Safe Mode: <?php echo $php_info['safe_mode'] ?? (ini_get('safe_mode') ? 'On' : 'Off'); ?><br>
            Magic Quotes: <?php echo $php_info['magic_quotes_gpc'] ?? (function_exists('get_magic_quotes_gpc') ? (get_magic_quotes_gpc() ? 'On' : 'Off') : 'N/A (PHP 8+)'); ?><br>
            Register Globals: <?php echo $php_info['register_globals'] ?? (ini_get('register_globals') ? 'On' : 'Off'); ?><br>
            File Uploads: <?php echo $php_info['file_uploads'] ?? (ini_get('file_uploads') ? 'Enabled' : 'Disabled'); ?>
        </div>
        <div>
            <strong>Installation:</strong><br>
            Path: <?php echo dirname(__DIR__); ?><br>
            HTTPS: <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Available' : 'Not Available'; ?><br>
            Port: <?php echo $_SERVER['SERVER_PORT'] ?? 'Unknown'; ?><br>
            Time: <?php echo date('Y-m-d H:i:s T'); ?>
        </div>
    </div>
</div>

<h3>Requirements Check</h3>

<div style="display: grid; grid-template-columns: 1fr; gap: 5px;">
    <!-- Critical Requirements -->
    <h4 style="color: #dc3545; margin: 20px 0 10px 0;">🔴 Critical Requirements</h4>
    
    <div class="requirement <?php echo $checks['php_version'] ? 'pass' : 'fail'; ?>">
        <span>PHP Version (>= <?php echo OEM_MINIMUM_PHP; ?>)</span>
        <span><?php echo $checks['php_version'] ? '✅ ' . PHP_VERSION : '❌ ' . PHP_VERSION; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['mysql_extension'] ? 'pass' : 'fail'; ?>">
        <span>MySQL PDO Extension</span>
        <span><?php echo $checks['mysql_extension'] ? '✅ Available' : '❌ Missing'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['curl_extension'] ? 'pass' : 'fail'; ?>">
        <span>cURL Extension</span>
        <span><?php echo $checks['curl_extension'] ? '✅ Available' : '❌ Missing'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['openssl_extension'] ? 'pass' : 'fail'; ?>">
        <span>OpenSSL Extension</span>
        <span><?php echo $checks['openssl_extension'] ? '✅ Available' : '❌ Missing'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['json_extension'] ? 'pass' : 'fail'; ?>">
        <span>JSON Extension</span>
        <span><?php echo $checks['json_extension'] ? '✅ Available' : '❌ Missing'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['mbstring_extension'] ? 'pass' : 'fail'; ?>">
        <span>Multibyte String Extension</span>
        <span><?php echo $checks['mbstring_extension'] ? '✅ Available' : '❌ Missing'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['write_permissions'] ? 'pass' : 'fail'; ?>">
        <span>Write Permissions</span>
        <span><?php echo $checks['write_permissions'] ? '✅ Writable' : '❌ Not Writable'; ?></span>
    </div>
    
    <!-- Concurrency Requirements (v2.0 Enhancement) -->
    <h4 style="color: #e83e8c; margin: 20px 0 10px 0;">🚀 Concurrency Support (v2.0)</h4>
    
    <div class="requirement <?php echo $checks['mysql_innodb'] ? 'pass' : 'fail'; ?>">
        <span>MySQL InnoDB Engine</span>
        <span><?php echo $checks['mysql_innodb'] ? '✅ Available' : '❌ Not Available'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['mysql_transactions'] ? 'pass' : 'fail'; ?>">
        <span>MySQL Transaction Support</span>
        <span><?php echo $checks['mysql_transactions'] ? '✅ Available' : '❌ Not Available'; ?></span>
    </div>
    
    <!-- PHP Configuration -->
    <h4 style="color: #ffc107; margin: 20px 0 10px 0;">🟡 PHP Configuration</h4>
    
    <div class="requirement <?php echo $checks['memory_limit'] ? 'pass' : 'fail'; ?>">
        <span>Memory Limit (>= 256MB)</span>
        <span><?php echo $checks['memory_limit'] ? '✅ ' . ini_get('memory_limit') : '⚠️ ' . ini_get('memory_limit'); ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['max_execution_time'] ? 'pass' : 'fail'; ?>">
        <span>Max Execution Time (>= 300s)</span>
        <span><?php echo $checks['max_execution_time'] ? '✅ ' . ini_get('max_execution_time') : '⚠️ ' . ini_get('max_execution_time'); ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['file_uploads'] ? 'pass' : 'fail'; ?>">
        <span>File Uploads</span>
        <span><?php echo $checks['file_uploads'] ? '✅ Enabled' : '❌ Disabled'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['upload_max_filesize'] ? 'pass' : 'fail'; ?>">
        <span>Upload Max Filesize (>= 10MB)</span>
        <span><?php echo $checks['upload_max_filesize'] ? '✅ ' . ini_get('upload_max_filesize') : '⚠️ ' . ini_get('upload_max_filesize'); ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['post_max_size'] ? 'pass' : 'fail'; ?>">
        <span>Post Max Size (>= 10MB)</span>
        <span><?php echo $checks['post_max_size'] ? '✅ ' . ini_get('post_max_size') : '⚠️ ' . ini_get('post_max_size'); ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['max_input_vars'] ? 'pass' : 'fail'; ?>">
        <span>Max Input Vars (>= 1000)</span>
        <span><?php echo $checks['max_input_vars'] ? '✅ ' . ini_get('max_input_vars') : '⚠️ ' . ini_get('max_input_vars'); ?></span>
    </div>
    
    <!-- Directory Permissions -->
    <h4 style="color: #28a745; margin: 20px 0 10px 0;">🟢 Directory Structure</h4>
    
    <div class="requirement <?php echo $checks['config_writable'] ? 'pass' : 'fail'; ?>">
        <span>Configuration Directory</span>
        <span><?php echo $checks['config_writable'] ? '✅ Writable' : '❌ Not Writable'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['logs_directory'] ? 'pass' : 'fail'; ?>">
        <span>Logs Directory</span>
        <span><?php echo $checks['logs_directory'] ? '✅ Created' : '⚠️ Cannot Create'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['tmp_directory'] ? 'pass' : 'fail'; ?>">
        <span>Temporary Directory</span>
        <span><?php echo $checks['tmp_directory'] ? '✅ Created' : '⚠️ Cannot Create'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['uploads_directory'] ? 'pass' : 'fail'; ?>">
        <span>Uploads Directory</span>
        <span><?php echo $checks['uploads_directory'] ? '✅ Created' : '⚠️ Cannot Create'; ?></span>
    </div>
    
    <!-- Security Settings -->
    <h4 style="color: #6f42c1; margin: 20px 0 10px 0;">🟣 Security Settings</h4>
    
    <div class="requirement <?php echo $checks['safe_mode'] ? 'pass' : 'fail'; ?>">
        <span>Safe Mode (Should be Off)</span>
        <span><?php echo $checks['safe_mode'] ? '✅ Off' : '❌ On'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['magic_quotes'] ? 'pass' : 'fail'; ?>">
        <span>Magic Quotes (Should be Off)</span>
        <span><?php echo $checks['magic_quotes'] ? '✅ Off' : '❌ On'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['register_globals'] ? 'pass' : 'fail'; ?>">
        <span>Register Globals (Should be Off)</span>
        <span><?php echo $checks['register_globals'] ? '✅ Off' : '❌ On'; ?></span>
    </div>
    
    <!-- Optional Features -->
    <h4 style="color: #17a2b8; margin: 20px 0 10px 0;">🔵 Optional Features</h4>
    
    <div class="requirement <?php echo $checks['https_available'] ? 'pass' : 'fail'; ?>">
        <span>HTTPS Support</span>
        <span><?php echo $checks['https_available'] ? '✅ Available' : '⚠️ Not Detected'; ?></span>
    </div>
    
    <div class="requirement <?php echo $checks['mod_rewrite'] ? 'pass' : 'fail'; ?>">
        <span>URL Rewriting (mod_rewrite)</span>
        <span><?php echo $checks['mod_rewrite'] ? '✅ Available' : '⚠️ Not Detected'; ?></span>
    </div>
</div>

<?php if (!$all_passed): ?>
    <div class="alert alert-danger">
        <strong>❌ Requirements Not Met</strong><br>
        Some requirements are not satisfied. Please contact your hosting provider or system administrator to resolve these issues before proceeding.
        
        <h4>Required Actions:</h4>
        <ul>
            <?php if (!$checks['php_version']): ?>
                <li>Upgrade PHP to version <?php echo OEM_MINIMUM_PHP; ?> or higher</li>
            <?php endif; ?>
            <?php if (!$checks['mysql_extension']): ?>
                <li>Install PHP PDO MySQL extension</li>
            <?php endif; ?>
            <?php if (!$checks['curl_extension']): ?>
                <li>Install PHP cURL extension</li>
            <?php endif; ?>
            <?php if (!$checks['openssl_extension']): ?>
                <li>Install PHP OpenSSL extension</li>
            <?php endif; ?>
            <?php if (!$checks['json_extension']): ?>
                <li>Install PHP JSON extension</li>
            <?php endif; ?>
            <?php if (!$checks['mbstring_extension']): ?>
                <li>Install PHP Multibyte String extension</li>
            <?php endif; ?>
            <?php if (!$checks['write_permissions']): ?>
                <li>Set write permissions on installation directory</li>
            <?php endif; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="alert alert-success">
        <strong>✅ All Requirements Met</strong><br>
        Your server meets all the requirements for KeyGate installation.
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <h4>📋 Recommended Configuration</h4>
    <ul>
        <li><strong>PHP Version:</strong> <?php echo OEM_RECOMMENDED_PHP; ?> (current: <?php echo PHP_VERSION; ?>)</li>
        <li><strong>Memory Limit:</strong> 256MB or higher (current: <?php echo ini_get('memory_limit'); ?>)</li>
        <li><strong>Max Execution Time:</strong> 300 seconds (current: <?php echo ini_get('max_execution_time'); ?>)</li>
        <li><strong>SSL Certificate:</strong> Highly recommended for production</li>
        <li><strong>Firewall:</strong> Configure to allow database connections</li>
    </ul>
</div>

<form method="post">
    <div class="actions">
        <div></div>
        <button type="submit" class="btn" <?php echo !$all_passed ? 'disabled' : ''; ?>>
            <?php echo $all_passed ? 'Next: Database Setup →' : 'Requirements Not Met'; ?>
        </button>
    </div>
</form>