<?php
/**
 * KeyGate — Installer AJAX Backend
 *
 * Handles all installer steps via POST requests.
 * Actions: preflight, test_db, install_db, create_admin, finalize
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Block if already installed
if (file_exists(__DIR__ . '/../install.lock')) {
    die(json_encode(['success' => false, 'message' => 'System is already installed.']));
}

session_start();

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'preflight':
        handlePreflight();
        break;
    case 'test_db':
        handleTestDb();
        break;
    case 'install_db':
        handleInstallDb();
        break;
    case 'create_admin':
        handleCreateAdmin();
        break;
    case 'finalize':
        handleFinalize();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}

// ═══════════════════════════════════════════════════════════════
// Step 1: Pre-flight Environment Checks
// ═══════════════════════════════════════════════════════════════
function handlePreflight() {
    $result = [
        'php'        => [],
        'extensions' => [],
        'settings'   => [],
        'directories'=> [],
    ];

    // ── PHP Version ──
    $phpVer = PHP_VERSION;
    $result['php'][] = [
        'label'  => 'PHP Version',
        'value'  => $phpVer,
        'status' => version_compare($phpVer, '8.0.0', '>=')
            ? (version_compare($phpVer, '8.3.0', '>=') ? 'pass' : 'warn')
            : 'fail',
    ];

    // ── PHP SAPI ──
    $sapi = php_sapi_name();
    $result['php'][] = [
        'label'  => 'Server API',
        'value'  => $sapi,
        'status' => 'pass',
    ];

    // ── Database server check ──
    // We check for either MySQL or MariaDB CLI availability, but connection is tested in step 2
    $result['php'][] = [
        'label'  => 'PDO MySQL Driver',
        'value'  => extension_loaded('pdo_mysql') ? 'Available' : 'Missing',
        'status' => extension_loaded('pdo_mysql') ? 'pass' : 'fail',
    ];

    // ── Required Extensions ──
    $required = [
        'PDO'       => 'Database abstraction layer',
        'pdo_mysql' => 'MySQL/MariaDB database driver',
        'json'      => 'JSON encoding/decoding',
        'mbstring'  => 'Multi-byte string handling (UTF-8)',
        'openssl'   => 'Encryption & token generation',
        'curl'      => 'HTTP client for integrations',
        'session'   => 'Session management',
    ];
    $optional = [
        'redis'   => 'Rate limiting (optional, graceful degradation)',
        'gd'      => 'Image processing for branding uploads',
        'zip'     => 'Backup compression',
        'intl'    => 'Internationalization support',
    ];

    foreach ($required as $ext => $desc) {
        $result['extensions'][] = [
            'label'  => "$ext — $desc",
            'value'  => extension_loaded($ext) ? 'OK' : 'Missing',
            'status' => extension_loaded($ext) ? 'pass' : 'fail',
        ];
    }
    foreach ($optional as $ext => $desc) {
        $result['extensions'][] = [
            'label'  => "$ext — $desc",
            'value'  => extension_loaded($ext) ? 'OK' : 'Not installed',
            'status' => extension_loaded($ext) ? 'pass' : 'warn',
        ];
    }

    // ── PHP Settings ──
    $memLimit = ini_get('memory_limit');
    $memBytes = returnBytes($memLimit);
    $result['settings'][] = [
        'label'  => 'memory_limit (>= 128M)',
        'value'  => $memLimit,
        'status' => $memBytes >= 128 * 1024 * 1024 || $memBytes == -1 ? 'pass' : ($memBytes >= 64 * 1024 * 1024 ? 'warn' : 'fail'),
    ];

    $maxExec = ini_get('max_execution_time');
    $result['settings'][] = [
        'label'  => 'max_execution_time (>= 120s)',
        'value'  => $maxExec . 's',
        'status' => $maxExec == 0 || $maxExec >= 120 ? 'pass' : ($maxExec >= 60 ? 'warn' : 'fail'),
        'hint'   => ($maxExec != 0 && $maxExec < 120) ? 'Set max_execution_time = 120 in php.ini. Values below 60s will block installation.' : '',
    ];

    $uploads = ini_get('file_uploads');
    $result['settings'][] = [
        'label'  => 'file_uploads',
        'value'  => $uploads ? 'On' : 'Off',
        'status' => $uploads ? 'pass' : 'warn',
    ];

    $uploadMax = ini_get('upload_max_filesize');
    $uploadBytes = returnBytes($uploadMax);
    $result['settings'][] = [
        'label'  => 'upload_max_filesize (>= 10M)',
        'value'  => $uploadMax,
        'status' => $uploadBytes >= 10 * 1024 * 1024 ? 'pass' : 'warn',
    ];

    $postMax = ini_get('post_max_size');
    $postBytes = returnBytes($postMax);
    $result['settings'][] = [
        'label'  => 'post_max_size (>= 10M)',
        'value'  => $postMax,
        'status' => $postBytes >= 10 * 1024 * 1024 ? 'pass' : 'warn',
    ];

    $result['settings'][] = [
        'label'  => 'allow_url_fopen',
        'value'  => ini_get('allow_url_fopen') ? 'On' : 'Off',
        'status' => ini_get('allow_url_fopen') ? 'pass' : 'warn',
    ];

    // ── Directory Permissions ──
    $baseDir = realpath(__DIR__ . '/..');
    $dirs = [
        '.' => $baseDir,  // config.php will be written here
        'uploads'          => $baseDir . '/uploads',
        'uploads/branding' => $baseDir . '/uploads/branding',
        'database'         => $baseDir . '/database',
    ];

    foreach ($dirs as $label => $path) {
        if (!file_exists($path)) {
            // Try to create it
            $created = @mkdir($path, 0755, true);
            $result['directories'][] = [
                'label'  => "/$label/",
                'value'  => $created ? 'Created' : 'Cannot create',
                'status' => $created ? 'pass' : 'fail',
            ];
        } else {
            $writable = is_writable($path);
            $result['directories'][] = [
                'label'  => "/$label/",
                'value'  => $writable ? 'Writable' : 'Not writable',
                'status' => $writable ? 'pass' : 'fail',
            ];
        }
    }

    // Check config.php writability specifically
    $configPath = $baseDir . '/config.php';
    if (file_exists($configPath)) {
        $result['directories'][] = [
            'label'  => 'config.php (existing)',
            'value'  => is_writable($configPath) ? 'Writable' : 'Not writable',
            'status' => is_writable($configPath) ? 'pass' : 'fail',
        ];
    } else {
        $result['directories'][] = [
            'label'  => 'config.php (will create)',
            'value'  => is_writable($baseDir) ? 'OK' : 'Parent not writable',
            'status' => is_writable($baseDir) ? 'pass' : 'fail',
        ];
    }

    echo json_encode($result);
}

// ═══════════════════════════════════════════════════════════════
// Step 2: Test Database Connection
// ═══════════════════════════════════════════════════════════════
function handleTestDb() {
    $host = $_POST['db_host'] ?? 'localhost';
    $port = $_POST['db_port'] ?? '3306';
    $user = $_POST['db_user'] ?? '';
    $pass = $_POST['db_pass'] ?? '';
    $name = $_POST['db_name'] ?? 'oem_activation';

    if (empty($user)) {
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        return;
    }

    try {
        // First: connect without database to check server
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // Get server version
        $version = $pdo->query("SELECT VERSION() AS v")->fetch(PDO::FETCH_ASSOC)['v'];
        $isMariaDB = stripos($version, 'MariaDB') !== false;
        $serverType = $isMariaDB ? 'MariaDB' : 'MySQL';

        // Check if database exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$name]);
        $dbExists = (bool)$stmt->fetch();

        if (!$dbExists) {
            // Try to create it
            $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $msg = "{$serverType} {$version} — Database '{$name}' created successfully.";
        } else {
            $msg = "{$serverType} {$version} — Database '{$name}' exists.";
        }

        // Verify we can connect to the database
        $dsn2 = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo2 = new PDO($dsn2, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Check InnoDB support
        $engines = $pdo2->query("SHOW ENGINES")->fetchAll(PDO::FETCH_ASSOC);
        $innodb = false;
        foreach ($engines as $e) {
            if ($e['Engine'] === 'InnoDB' && in_array($e['Support'], ['YES', 'DEFAULT'])) {
                $innodb = true;
                break;
            }
        }

        if (!$innodb) {
            echo json_encode(['success' => false, 'message' => 'InnoDB engine is required but not available.']);
            return;
        }

        // Store in session for later steps
        $_SESSION['install_db'] = compact('host', 'port', 'user', 'pass', 'name');

        echo json_encode(['success' => true, 'message' => $msg]);

    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Simplify common errors
        if (strpos($msg, 'Access denied') !== false) {
            $msg = 'Access denied. Check username and password.';
        } elseif (strpos($msg, 'Connection refused') !== false || strpos($msg, 'No such file') !== false) {
            $msg = "Cannot connect to {$host}:{$port}. Check host and port.";
        } elseif (strpos($msg, 'Unknown MySQL server host') !== false || strpos($msg, 'getaddrinfo') !== false) {
            $msg = "Cannot resolve host '{$host}'. Check the hostname.";
        }
        echo json_encode(['success' => false, 'message' => $msg]);
    }
}

// ═══════════════════════════════════════════════════════════════
// Step 3: Install Database (Run Migrations)
// ═══════════════════════════════════════════════════════════════
function handleInstallDb() {
    $pdo = getInstallerPdo();
    if (!$pdo) return;

    $sqlDir = realpath(__DIR__ . '/../database');
    if (!$sqlDir) {
        echo json_encode(['success' => false, 'message' => 'Database SQL directory not found']);
        return;
    }

    // Migration order — matches 00-init.sh
    $migrations = [
        ['schema_versions_migration.sql',    0],
        ['install.sql',                       1],
        ['database_concurrency_indexes.sql',  2],
        ['rbac_migration.sql',                3],
        ['acl_migration.sql',                 4],
        ['2fa_migration.sql',                 5],
        ['rate_limiting_migration.sql',       6],
        ['backup_migration.sql',              7],
        ['hardware_info_migration.sql',       8],
        ['hardware_info_v2_migration.sql',    9],
        ['push_notifications_migration.sql', 10],
        ['client_resources_migration.sql',   11],
        ['i18n_migration.sql',               12],
        ['qc_compliance_migration.sql',      13],
        ['order_field_config_migration.sql', 14],
        ['integrations_migration.sql',       15],
        ['temp_password_hash_migration.sql', 16],
        ['product_variants_migration.sql',   17],
        ['missing_drivers_migration.sql',    18],
        ['unallocated_space_migration.sql',  19],
    ];

    $results = [];

    // Step 0: ensure schema_versions exists (run unconditionally)
    $schemaFile = $sqlDir . '/schema_versions_migration.sql';
    if (file_exists($schemaFile)) {
        try {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
            $results[] = ['file' => 'schema_versions_migration.sql', 'status' => 'ok', 'message' => 'Tracking table ready'];
        } catch (PDOException $e) {
            // Table probably exists already
            $results[] = ['file' => 'schema_versions_migration.sql', 'status' => 'ok', 'message' => 'Tracking table exists'];
        }
    }

    // Run remaining migrations
    for ($i = 1; $i < count($migrations); $i++) {
        [$file, $version] = $migrations[$i];
        $filePath = $sqlDir . '/' . $file;

        if (!file_exists($filePath)) {
            $results[] = ['file' => $file, 'status' => 'skipped', 'message' => 'File not found'];
            continue;
        }

        // Check if already applied
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM schema_versions WHERE filename = ?");
            $stmt->execute([$file]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['cnt'] > 0) {
                $results[] = ['file' => $file, 'status' => 'skipped', 'message' => 'Already applied'];
                continue;
            }
        } catch (PDOException $e) {
            // schema_versions might not exist yet
        }

        // Execute migration
        try {
            $sql = file_get_contents($filePath);

            // For multi-statement SQL, we need to use exec
            // Some files use DELIMITER which doesn't work with PDO — strip them
            $sql = preg_replace('/DELIMITER\s+[^\n]+/i', '', $sql);

            $pdo->exec($sql);

            // Record in schema_versions
            $checksum = hash('sha256', $sql);
            $stmt = $pdo->prepare("INSERT IGNORE INTO schema_versions (version, filename, checksum) VALUES (?, ?, ?)");
            $stmt->execute([$version, $file, $checksum]);

            $results[] = ['file' => $file, 'status' => 'ok', 'message' => 'Applied successfully'];
        } catch (PDOException $e) {
            $errMsg = $e->getMessage();
            // Some errors are non-fatal (duplicate index, column already exists, etc.)
            if (preg_match('/Duplicate|already exists|1060|1061/i', $errMsg)) {
                // Record as applied anyway
                try {
                    $checksum = hash('sha256', $sql);
                    $stmt = $pdo->prepare("INSERT IGNORE INTO schema_versions (version, filename, checksum) VALUES (?, ?, ?)");
                    $stmt->execute([$version, $file, $checksum]);
                } catch (PDOException $e2) { /* ignore */ }
                $results[] = ['file' => $file, 'status' => 'ok', 'message' => 'Applied (some objects already existed)'];
            } else {
                $results[] = ['file' => $file, 'status' => 'error', 'message' => $errMsg];
                // Don't stop — try remaining migrations
            }
        }
    }

    // Check if any critical errors
    $errors = array_filter($results, fn($r) => $r['status'] === 'error');
    $success = count($errors) === 0;

    echo json_encode([
        'success' => $success,
        'results' => $results,
        'message' => $success ? 'All migrations completed' : count($errors) . ' migration(s) had errors',
    ]);
}

// ═══════════════════════════════════════════════════════════════
// Step 4: Create Admin Account
// ═══════════════════════════════════════════════════════════════
function handleCreateAdmin() {
    $pdo = getInstallerPdo();
    if (!$pdo) return;

    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    // Validation
    if (strlen($username) < 3) {
        echo json_encode(['success' => false, 'message' => 'Username must be at least 3 characters']);
        return;
    }
    if (empty($fullName)) {
        echo json_encode(['success' => false, 'message' => 'Full name is required']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Valid email address is required']);
        return;
    }
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
        return;
    }
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain uppercase, lowercase, and a digit']);
        return;
    }
    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        return;
    }

    try {
        // Check if admin already exists
        $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            // Update existing
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ?, full_name = ?, email = ?, role = 'super_admin', is_active = 1, must_change_password = 0, failed_login_attempts = 0, locked_until = NULL WHERE username = ?");
            $stmt->execute([$hash, $fullName, $email, $username]);
            echo json_encode(['success' => true, 'message' => "Admin account '{$username}' updated."]);
        } else {
            // Create new
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO admin_users (username, full_name, email, password_hash, role, is_active, must_change_password) VALUES (?, ?, ?, ?, 'super_admin', 1, 0)");
            $stmt->execute([$username, $fullName, $email, $hash]);

            // Try to assign ACL role if table exists
            try {
                $adminId = $pdo->lastInsertId();
                $roleStmt = $pdo->prepare("SELECT id FROM acl_roles WHERE name = 'super_admin' LIMIT 1");
                $roleStmt->execute();
                $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
                if ($role) {
                    $pdo->prepare("UPDATE admin_users SET custom_role_id = ? WHERE id = ?")->execute([$role['id'], $adminId]);
                }
            } catch (PDOException $e) {
                // ACL tables might not exist — that's fine
            }

            echo json_encode(['success' => true, 'message' => "Admin account '{$username}' created."]);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════════
// Step 5+6: Finalize — Write config.php + install.lock
// ═══════════════════════════════════════════════════════════════
function handleFinalize() {
    $host = $_POST['db_host'] ?? 'localhost';
    $port = $_POST['db_port'] ?? '3306';
    $user = $_POST['db_user'] ?? '';
    $pass = $_POST['db_pass'] ?? '';
    $name = $_POST['db_name'] ?? 'oem_activation';

    $systemName = $_POST['system_name'] ?? 'KeyGate';
    $serverUrl  = rtrim($_POST['server_url'] ?? '', '/');
    $timezone   = $_POST['timezone'] ?? 'UTC';
    $language   = $_POST['language'] ?? 'en';
    $adminUser  = $_POST['admin_username'] ?? 'admin';

    // Validate timezone
    if (!in_array($timezone, timezone_identifiers_list())) {
        $timezone = 'UTC';
    }

    // ── Write config.php ──
    $configContent = generateConfig($host, $port, $user, $pass, $name, $timezone);
    $configPath = realpath(__DIR__ . '/..') . '/config.php';

    if (file_put_contents($configPath, $configContent) === false) {
        echo json_encode(['success' => false, 'message' => 'Failed to write config.php — check directory permissions']);
        return;
    }

    // Set restrictive permissions (may fail on Windows, that's OK)
    @chmod($configPath, 0640);

    // ── Save system settings to DB ──
    try {
        $pdo = getInstallerPdo();
        if ($pdo) {
            $settings = [
                'system_name'      => $systemName,
                'server_url'       => $serverUrl,
                'default_language' => $language,
                'timezone'         => $timezone,
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO system_config (config_key, config_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $stmt->execute([$key, $value, "Set by installer"]);
            }

            // Delete demo technician if it exists
            try {
                $pdo->exec("DELETE FROM technicians WHERE technician_id = 'demo' AND notes LIKE '%Demo account%'");
            } catch (PDOException $e) { /* ignore */ }

            // ── Auto-detect installer's network and add as trusted ──
            try {
                $clientIp = getClientIp();
                if ($clientIp && $clientIp !== 'unknown') {
                    // Get admin ID for foreign key
                    $adminStmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
                    $adminStmt->execute([$adminUser]);
                    $adminRow = $adminStmt->fetch(PDO::FETCH_ASSOC);
                    $adminId = $adminRow ? $adminRow['id'] : null;

                    // Calculate /24 subnet from the IP
                    $subnet = calculateSubnet($clientIp, 24);

                    // Add to trusted_networks (for 2FA bypass + USB auth)
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO trusted_networks (network_name, ip_range, bypass_2fa, allow_usb_auth, description, created_by_admin_id)
                            VALUES (?, ?, 1, 1, ?, ?)
                        ");
                        $stmt->execute([
                            'Installation Network',
                            $subnet,
                            "Auto-detected during installation from IP {$clientIp}",
                            $adminId,
                        ]);
                    } catch (PDOException $e) { /* table may not exist */ }

                    // Add to admin_ip_whitelist (for admin panel access)
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO admin_ip_whitelist (ip_address, ip_range, description, created_by)
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $clientIp,
                            $subnet,
                            "Auto-detected during installation",
                            $adminId,
                        ]);
                    } catch (PDOException $e) { /* table may not exist */ }
                }
            } catch (Exception $e) {
                // Non-fatal — network detection is best-effort
                error_log("Installer: network auto-detection failed: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        // Non-fatal — config.php is already written
    }

    // ── Write install.lock ──
    $lockData = [
        'installed_at'    => date('Y-m-d H:i:s'),
        'installer_ver'   => '2.0',
        'admin_username'  => $adminUser,
        'php_version'     => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    ];
    $lockPath = realpath(__DIR__ . '/..') . '/install.lock';
    file_put_contents($lockPath, json_encode($lockData, JSON_PRETTY_PRINT));

    // ── Response ──
    echo json_encode([
        'success' => true,
        'message' => 'Installation complete',
        'info' => [
            'Admin Panel'   => $serverUrl . '/secure-admin.php',
            'API Health'    => $serverUrl . '/api/health.php',
            'Admin User'    => $adminUser,
            'Database'      => $name . '@' . $host . ':' . $port,
            'Timezone'      => $timezone,
            'Trusted Net'   => isset($subnet) ? $subnet : 'Not detected',
            'Installer IP'  => isset($clientIp) ? $clientIp : 'Unknown',
            'Installed'     => date('Y-m-d H:i:s'),
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════
// Helper Functions
// ═══════════════════════════════════════════════════════════════

/**
 * Create PDO connection from POST params or session
 */
function getInstallerPdo(): ?PDO {
    $host = $_POST['db_host'] ?? $_SESSION['install_db']['host'] ?? '';
    $port = $_POST['db_port'] ?? $_SESSION['install_db']['port'] ?? '3306';
    $user = $_POST['db_user'] ?? $_SESSION['install_db']['user'] ?? '';
    $pass = $_POST['db_pass'] ?? $_SESSION['install_db']['pass'] ?? '';
    $name = $_POST['db_name'] ?? $_SESSION['install_db']['name'] ?? '';

    if (empty($user) || empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Database credentials missing. Go back to step 2.']);
        return null;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        return null;
    }
}

/**
 * Convert PHP ini shorthand notation to bytes
 */
function returnBytes(string $val): int {
    $val = trim($val);
    if ($val === '-1') return -1;
    $last = strtolower($val[strlen($val) - 1]);
    $val = (int)$val;
    switch ($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

/**
 * Get the real client IP, accounting for proxies
 */
function getClientIp(): string {
    // Check common proxy headers (trusted only in installer context)
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For may contain multiple IPs — take the first (client)
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            // Accept private range IPs too (common in LAN setups)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Calculate /N subnet from an IP address (e.g., 192.168.1.57 + /24 → 192.168.1.0/24)
 */
function calculateSubnet(string $ip, int $prefix = 24): string {
    $long = ip2long($ip);
    if ($long === false) {
        return $ip . '/' . $prefix;
    }
    $mask = -1 << (32 - $prefix);
    $network = long2ip($long & $mask);
    return $network . '/' . $prefix;
}

/**
 * Generate the production config.php content
 */
function generateConfig(string $host, string $port, string $user, string $pass, string $name, string $timezone): string {
    // Escape single quotes in password for PHP string
    $passEscaped = addcslashes($pass, "'\\");

    return <<<'PHP_HEADER'
<?php
/**
 * Application Bootstrap & Database Configuration
 * KeyGate v2.0
 *
 * Generated by the web installer.
 *
 * This file:
 *  1. Loads constants
 *  2. Creates the PDO database connection (with retry logic)
 *  3. Provides getConfig() for reading system_config rows
 *  4. Includes helper modules (http, session, key, order-field)
 */

require_once __DIR__ . '/constants.php';

// ── Environment Detection ───────────────────────────────────────
$isProduction = !in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'activate.local']);

PHP_HEADER
    . "\n// ── Database Configuration ──────────────────────────────────────\n"
    . "\$db_config = [\n"
    . "    'host'     => '{$host}',\n"
    . "    'dbname'   => '{$name}',\n"
    . "    'username' => '{$user}',\n"
    . "    'password' => '{$passEscaped}',\n"
    . "    'charset'  => 'utf8mb4',\n"
    . "    'port'     => {$port},\n"
    . "];\n\n"
    . <<<'PHP_BODY'
// ── Database Connection with Retry Logic ────────────────────────
function createDatabaseConnection($config, $maxRetries = DB_MAX_RETRIES) {
    $attempts = 0;
    $lastException = null;

    while ($attempts < $maxRetries) {
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']} COLLATE {$config['charset']}_unicode_ci",
            ]);

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

    error_log("Database connection failed after $maxRetries attempts. Last error: " . $lastException->getMessage());

    if (php_sapi_name() !== 'cli') {
        http_response_code(503);
        die(json_encode([
            'error'   => 'Database service temporarily unavailable',
            'support' => 'Please check server configuration and try again',
        ]));
    } else {
        throw $lastException;
    }
}

$pdo = createDatabaseConnection($db_config);

// ── System Config Cache ─────────────────────────────────────────
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
        $value  = $result ? $result['config_value'] : null;

        if ($useCache) {
            $configCache[$key] = $value;
        }
        return $value;
    } catch (PDOException $e) {
        error_log("Failed to get config for key '$key': " . $e->getMessage());
        return null;
    }
}

function getConfigWithDefault($key, $default) {
    $value = getConfig($key);
    if ($value === null || $value === '') {
        return $default;
    }
    return $value;
}

// ── Order-field config helpers ──────────────────────────────────
function getOrderFieldConfig() {
    $defaults = [
        'order_field_label_en'     => 'Order Number',
        'order_field_label_ru'     => 'Номер заказа',
        'order_field_prompt_en'    => 'Enter order number',
        'order_field_prompt_ru'    => 'Введите номер заказа',
        'order_field_min_length'   => '5',
        'order_field_max_length'   => '10',
        'order_field_char_type'    => 'alphanumeric',
        'order_field_custom_regex' => '',
    ];

    $config = [];
    foreach ($defaults as $key => $default) {
        $config[$key] = getConfig($key) ?? $default;
    }
    return $config;
}

function buildOrderNumberPattern(array $config): string {
    $min      = max(1, (int) $config['order_field_min_length']);
    $max      = max($min, (int) $config['order_field_max_length']);
    $charType = $config['order_field_char_type'] ?? 'alphanumeric';

    switch ($charType) {
        case 'digits_only':
            return '/^[0-9]{' . $min . ',' . $max . '}$/';
        case 'alphanumeric':
            return '/^[A-Za-z0-9]{' . $min . ',' . $max . '}$/';
        case 'alphanumeric_dash':
            return '/^[A-Za-z0-9_-]{' . $min . ',' . $max . '}$/';
        case 'custom':
            $regex = $config['order_field_custom_regex'] ?? '';
            if ($regex !== '' && @preg_match($regex, '') !== false) {
                return $regex;
            }
            return '/^[A-Za-z0-9]{' . $min . ',' . $max . '}$/';
        default:
            return '/^[A-Za-z0-9]{' . $min . ',' . $max . '}$/';
    }
}

// ── Include helper modules ──────────────────────────────────────
require_once __DIR__ . '/functions/logger.php';
require_once __DIR__ . '/functions/http-helpers.php';
require_once __DIR__ . '/functions/session-helpers.php';
require_once __DIR__ . '/functions/key-helpers.php';

PHP_BODY
    . "\n// ── Timezone ────────────────────────────────────────────────────\n"
    . "\$timezone = '{$timezone}';\n"
    . <<<'PHP_TAIL'
if (!in_array($timezone, timezone_identifiers_list())) {
    error_log("Invalid timezone '$timezone', falling back to UTC");
    $timezone = 'UTC';
}
date_default_timezone_set($timezone);

// ── Startup log ─────────────────────────────────────────────────
error_log("KeyGate configuration loaded successfully. Environment: " .
    ($isProduction ? "Production" : "Development"));
?>
PHP_TAIL;
}
