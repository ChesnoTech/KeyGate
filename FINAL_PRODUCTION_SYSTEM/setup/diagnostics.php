<?php
/**
 * KeyGate - Advanced System Diagnostics
 * Comprehensive system analysis and troubleshooting tool
 */

header('Content-Type: text/html; charset=utf-8');

// Include main installer functions
require_once 'index.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KeyGate - System Diagnostics</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .content {
            padding: 30px;
        }
        .diagnostic-section {
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            overflow: hidden;
        }
        .section-header {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            font-weight: bold;
            color: #495057;
        }
        .section-content {
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .status-pass { color: #28a745; font-weight: bold; }
        .status-fail { color: #dc3545; font-weight: bold; }
        .status-warn { color: #ffc107; font-weight: bold; }
        .status-info { color: #17a2b8; font-weight: bold; }
        .code-block {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            overflow-x: auto;
            margin: 10px 0;
        }
        .alert {
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 5px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .metric-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .metric-value {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 System Diagnostics</h1>
            <p>KeyGate v<?php echo OEM_VERSION; ?></p>
        </div>
        
        <div class="content">
            <?php
            // Run comprehensive diagnostics
            $diagnostics = runComprehensiveDiagnostics();
            $overall_score = calculateOverallScore($diagnostics);
            ?>
            
            <!-- Overall System Health -->
            <div class="diagnostic-section">
                <div class="section-header">🎯 Overall System Health</div>
                <div class="section-content">
                    <div class="grid">
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $overall_score; ?>%</div>
                            <div>System Health Score</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?php echo count(array_filter($diagnostics['requirements'])); ?></div>
                            <div>Requirements Passed</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?php echo count($diagnostics['auto_fixes']); ?></div>
                            <div>Issues Auto-Fixed</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $diagnostics['security_score']; ?>%</div>
                            <div>Security Score</div>
                        </div>
                    </div>
                    
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $overall_score; ?>%"></div>
                    </div>
                    <p style="text-align: center; margin-top: 10px;">
                        <?php if ($overall_score >= 90): ?>
                            <span class="status-pass">✅ Excellent - System ready for production</span>
                        <?php elseif ($overall_score >= 75): ?>
                            <span class="status-warn">⚠️ Good - Minor issues detected</span>
                        <?php elseif ($overall_score >= 60): ?>
                            <span class="status-warn">⚠️ Fair - Some configuration needed</span>
                        <?php else: ?>
                            <span class="status-fail">❌ Poor - Significant issues need attention</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <!-- Server Information -->
            <div class="diagnostic-section">
                <div class="section-header">🖥️ Server Information</div>
                <div class="section-content">
                    <table>
                        <?php foreach ($diagnostics['server_info'] as $key => $value): ?>
                        <tr>
                            <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $key))); ?></th>
                            <td><?php echo htmlspecialchars($value); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            
            <!-- PHP Configuration -->
            <div class="diagnostic-section">
                <div class="section-header">🐘 PHP Configuration</div>
                <div class="section-content">
                    <table>
                        <tr><th>Setting</th><th>Current Value</th><th>Recommended</th><th>Status</th></tr>
                        <?php foreach ($diagnostics['php_config'] as $setting => $info): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($setting); ?></td>
                            <td><?php echo htmlspecialchars($info['current']); ?></td>
                            <td><?php echo htmlspecialchars($info['recommended']); ?></td>
                            <td>
                                <?php if ($info['status'] === 'pass'): ?>
                                    <span class="status-pass">✅ Good</span>
                                <?php elseif ($info['status'] === 'warn'): ?>
                                    <span class="status-warn">⚠️ Suboptimal</span>
                                <?php else: ?>
                                    <span class="status-fail">❌ Poor</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            
            <!-- File Permissions -->
            <div class="diagnostic-section">
                <div class="section-header">📁 File System Permissions</div>
                <div class="section-content">
                    <table>
                        <tr><th>Path</th><th>Status</th><th>Permissions</th><th>Owner</th></tr>
                        <?php foreach ($diagnostics['file_permissions'] as $path => $info): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($path); ?></td>
                            <td>
                                <?php if ($info['writable']): ?>
                                    <span class="status-pass">✅ Writable</span>
                                <?php else: ?>
                                    <span class="status-fail">❌ Not Writable</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $info['permissions']; ?></td>
                            <td><?php echo htmlspecialchars($info['owner']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            
            <!-- Security Analysis -->
            <div class="diagnostic-section">
                <div class="section-header">🔐 Security Analysis</div>
                <div class="section-content">
                    <table>
                        <tr><th>Security Check</th><th>Status</th><th>Details</th></tr>
                        <?php foreach ($diagnostics['security_checks'] as $check => $info): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $check))); ?></td>
                            <td>
                                <?php if ($info['passed']): ?>
                                    <span class="status-pass">✅ Secure</span>
                                <?php else: ?>
                                    <span class="status-fail">❌ Risk</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($info['details']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            
            <!-- Database Connectivity -->
            <div class="diagnostic-section">
                <div class="section-header">🗄️ Database Connectivity</div>
                <div class="section-content">
                    <?php if (isset($diagnostics['database_test'])): ?>
                        <?php $db_test = $diagnostics['database_test']; ?>
                        <?php if ($db_test['connected']): ?>
                            <div class="alert alert-success">
                                <strong>✅ Database Connection Successful</strong><br>
                                Connected to <?php echo htmlspecialchars($db_test['server']); ?> (version <?php echo htmlspecialchars($db_test['version']); ?>)
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                <strong>❌ Database Connection Failed</strong><br>
                                <?php echo htmlspecialchars($db_test['error']); ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <strong>ℹ️ Database Test Skipped</strong><br>
                            Database credentials not configured. This test will run after database setup.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Performance Metrics -->
            <div class="diagnostic-section">
                <div class="section-header">⚡ Performance Metrics</div>
                <div class="section-content">
                    <div class="grid">
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $diagnostics['performance']['page_load_time']; ?>ms</div>
                            <div>Page Load Time</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $diagnostics['performance']['memory_usage']; ?>MB</div>
                            <div>Memory Usage</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $diagnostics['performance']['disk_space']; ?>GB</div>
                            <div>Available Disk Space</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?php echo $diagnostics['performance']['load_average']; ?></div>
                            <div>System Load</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recommendations -->
            <?php if (!empty($diagnostics['recommendations'])): ?>
            <div class="diagnostic-section">
                <div class="section-header">💡 Recommendations</div>
                <div class="section-content">
                    <?php foreach ($diagnostics['recommendations'] as $priority => $items): ?>
                        <h4><?php echo ucwords($priority); ?> Priority:</h4>
                        <ul style="margin-left: 20px; margin-bottom: 20px;">
                            <?php foreach ($items as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <div style="text-align: center; padding: 20px;">
                <a href="index.php" class="btn">← Back to Installation</a>
                <a href="?refresh=1" class="btn">🔄 Refresh Diagnostics</a>
                <a href="?export=1" class="btn">📄 Export Report</a>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Export functionality
if (isset($_GET['export'])) {
    $diagnostics = runComprehensiveDiagnostics();
    $report = generateDiagnosticReport($diagnostics);
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="oem_diagnostic_report_' . date('Y-m-d_H-i-s') . '.txt"');
    echo $report;
    exit;
}

function runComprehensiveDiagnostics() {
    $start_time = microtime(true);
    
    // Basic system requirements
    $requirements = [
        'php_version' => version_compare(PHP_VERSION, OEM_MINIMUM_PHP, '>='),
        'mysql_extension' => extension_loaded('pdo_mysql'),
        'curl_extension' => extension_loaded('curl'),
        'openssl_extension' => extension_loaded('openssl'),
        'json_extension' => extension_loaded('json'),
        'mbstring_extension' => extension_loaded('mbstring'),
        'memory_limit' => checkMemoryLimit(),
        'execution_time' => checkExecutionTime(),
        'file_uploads' => ini_get('file_uploads') == '1',
        'write_permissions' => checkWritePermissions(),
    ];
    
    // Server information
    $server_info = [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'operating_system' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'web_server' => php_sapi_name(),
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'server_time' => date('Y-m-d H:i:s T'),
        'timezone' => date_default_timezone_get(),
        'server_load' => function_exists('sys_getloadavg') ? implode(', ', sys_getloadavg()) : 'N/A',
    ];
    
    // PHP configuration analysis
    $php_config = [
        'memory_limit' => [
            'current' => ini_get('memory_limit'),
            'recommended' => '256M',
            'status' => checkMemoryLimit() ? 'pass' : 'warn'
        ],
        'max_execution_time' => [
            'current' => ini_get('max_execution_time'),
            'recommended' => '300',
            'status' => checkExecutionTime() ? 'pass' : 'warn'
        ],
        'upload_max_filesize' => [
            'current' => ini_get('upload_max_filesize'),
            'recommended' => '10M',
            'status' => checkUploadSize() ? 'pass' : 'warn'
        ],
        'post_max_size' => [
            'current' => ini_get('post_max_size'),
            'recommended' => '10M',
            'status' => checkPostSize() ? 'pass' : 'warn'
        ],
        'max_input_vars' => [
            'current' => ini_get('max_input_vars'),
            'recommended' => '1000',
            'status' => checkMaxInputVars() ? 'pass' : 'warn'
        ],
    ];
    
    // File permissions check
    $paths_to_check = ['../', '../logs', '../tmp', '../uploads'];
    $file_permissions = [];
    
    foreach ($paths_to_check as $path) {
        $file_permissions[$path] = [
            'writable' => is_writable($path) || createDirectory($path),
            'permissions' => substr(sprintf('%o', fileperms($path)), -4) ?? '0000',
            'owner' => function_exists('posix_getpwuid') && function_exists('fileowner') ? 
                      posix_getpwuid(fileowner($path))['name'] ?? 'Unknown' : 'Unknown'
        ];
    }
    
    // Security checks
    $security_checks = [
        'safe_mode' => [
            'passed' => !ini_get('safe_mode'),
            'details' => ini_get('safe_mode') ? 'Safe mode is enabled (deprecated)' : 'Safe mode is disabled'
        ],
        'register_globals' => [
            'passed' => !ini_get('register_globals'),
            'details' => ini_get('register_globals') ? 'Register globals is enabled (security risk)' : 'Register globals is disabled'
        ],
        'magic_quotes' => [
            'passed' => !function_exists('get_magic_quotes_gpc') || !get_magic_quotes_gpc(),
            'details' => function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc() ? 'Magic quotes is enabled (deprecated)' : 'Magic quotes is disabled or N/A (PHP 8+)'
        ],
        'https_available' => [
            'passed' => isHTTPS(),
            'details' => isHTTPS() ? 'HTTPS is active' : 'HTTP only (HTTPS recommended for production)'
        ],
        'error_reporting' => [
            'passed' => error_reporting() != -1,
            'details' => 'Error reporting level: ' . error_reporting()
        ],
    ];
    
    // Auto-fixes attempted
    $auto_fixes = attemptAutoFixes();
    
    // Performance metrics
    $performance = [
        'page_load_time' => round((microtime(true) - $start_time) * 1000, 2),
        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2),
        'disk_space' => round(disk_free_space('.') / 1024 / 1024 / 1024, 2),
        'load_average' => function_exists('sys_getloadavg') ? round(sys_getloadavg()[0], 2) : 'N/A',
    ];
    
    // Generate recommendations
    $recommendations = generateRecommendations($requirements, $php_config, $security_checks, $file_permissions);
    
    // Calculate security score
    $security_score = calculateSecurityScore($security_checks);
    
    return [
        'requirements' => $requirements,
        'server_info' => $server_info,
        'php_config' => $php_config,
        'file_permissions' => $file_permissions,
        'security_checks' => $security_checks,
        'auto_fixes' => $auto_fixes,
        'performance' => $performance,
        'recommendations' => $recommendations,
        'security_score' => $security_score,
    ];
}

function calculateOverallScore($diagnostics) {
    $total_checks = count($diagnostics['requirements']) + count($diagnostics['security_checks']);
    $passed_checks = count(array_filter($diagnostics['requirements'])) + 
                     count(array_filter($diagnostics['security_checks'], function($check) { 
                         return $check['passed']; 
                     }));
    
    return round(($passed_checks / $total_checks) * 100);
}

function calculateSecurityScore($security_checks) {
    $total = count($security_checks);
    $passed = count(array_filter($security_checks, function($check) { return $check['passed']; }));
    
    return round(($passed / $total) * 100);
}

function generateRecommendations($requirements, $php_config, $security_checks, $file_permissions) {
    $recommendations = ['high' => [], 'medium' => [], 'low' => []];
    
    // High priority recommendations
    if (!$requirements['php_version']) {
        $recommendations['high'][] = 'Upgrade PHP to version ' . OEM_MINIMUM_PHP . ' or higher';
    }
    
    if (!$requirements['mysql_extension']) {
        $recommendations['high'][] = 'Install PHP PDO MySQL extension';
    }
    
    if (!$requirements['write_permissions']) {
        $recommendations['high'][] = 'Fix file write permissions for installation directory';
    }
    
    // Medium priority recommendations
    if (!$security_checks['https_available']['passed']) {
        $recommendations['medium'][] = 'Configure HTTPS/SSL certificate for production use';
    }
    
    if ($php_config['memory_limit']['status'] !== 'pass') {
        $recommendations['medium'][] = 'Increase PHP memory limit to 256MB or higher';
    }
    
    if ($php_config['max_execution_time']['status'] !== 'pass') {
        $recommendations['medium'][] = 'Increase max execution time to 300 seconds or higher';
    }
    
    // Low priority recommendations
    if (!checkModRewrite()) {
        $recommendations['low'][] = 'Enable mod_rewrite for better URL handling';
    }
    
    return $recommendations;
}

function generateDiagnosticReport($diagnostics) {
    $report = "OEM ACTIVATION SYSTEM - DIAGNOSTIC REPORT\n";
    $report .= "Generated: " . date('Y-m-d H:i:s T') . "\n";
    $report .= "System: " . php_uname() . "\n";
    $report .= str_repeat("=", 60) . "\n\n";
    
    // Overall health
    $overall_score = calculateOverallScore($diagnostics);
    $report .= "OVERALL SYSTEM HEALTH: {$overall_score}%\n";
    $report .= "Security Score: {$diagnostics['security_score']}%\n\n";
    
    // Requirements
    $report .= "SYSTEM REQUIREMENTS:\n";
    foreach ($diagnostics['requirements'] as $req => $passed) {
        $status = $passed ? "PASS" : "FAIL";
        $report .= "  {$req}: {$status}\n";
    }
    $report .= "\n";
    
    // PHP Configuration
    $report .= "PHP CONFIGURATION:\n";
    foreach ($diagnostics['php_config'] as $setting => $info) {
        $report .= "  {$setting}: {$info['current']} (recommended: {$info['recommended']}) [{$info['status']}]\n";
    }
    $report .= "\n";
    
    // Security
    $report .= "SECURITY CHECKS:\n";
    foreach ($diagnostics['security_checks'] as $check => $info) {
        $status = $info['passed'] ? "PASS" : "FAIL";
        $report .= "  {$check}: {$status} - {$info['details']}\n";
    }
    $report .= "\n";
    
    // Recommendations
    if (!empty($diagnostics['recommendations'])) {
        $report .= "RECOMMENDATIONS:\n";
        foreach ($diagnostics['recommendations'] as $priority => $items) {
            $report .= "  " . strtoupper($priority) . " Priority:\n";
            foreach ($items as $item) {
                $report .= "    - {$item}\n";
            }
        }
    }
    
    return $report;
}
?>