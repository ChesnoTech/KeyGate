<?php
/**
 * KeyGate — Installer AJAX Backend
 *
 * Handles all installer steps via POST requests.
 * Actions: preflight, test_db, install_db, create_admin, finalize
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Best-effort: defang shared-host timeouts. Some panels ignore these silently.
@set_time_limit(0);
@ignore_user_abort(true);

session_start();

// Auto-unlock recovery: if install.lock exists but the install never finished
// (no admin_users yet, or table missing) — clear it and continue. Logged.
installerCheckIncompleteState();

// Block if (still) installed after auto-unlock check
if (file_exists(__DIR__ . '/../install.lock')) {
    die(json_encode(['success' => false, 'message' => 'System is already installed.']));
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'preflight':
        handlePreflight();
        break;
    case 'test_db':
        handleTestDb();
        break;
    case 'install_db':         // legacy single-shot; falls through to fast-path
    case 'install_db_all':
        handleInstallDbAll();
        break;
    case 'install_db_init':
        handleInstallDbInit();
        break;
    case 'install_db_step':
        handleInstallDbStep();
        break;
    case 'create_admin':
        handleCreateAdmin();
        break;
    case 'finalize':
        handleFinalize();
        break;
    case 'detect_socket':
        handleDetectSocket();
        break;
    case 'health':
        handleHealth();
        break;
    case 'progress_get':
        handleProgressGet();
        break;
    case 'progress_set':
        handleProgressSet();
        break;
    case 'progress_clear':
        handleProgressClear();
        break;
    case 'migration_skip':
        handleMigrationSkip();
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

    // ── PHP Sandbox / Restrictions (panel-specific blockers) ──
    $openBasedir = ini_get('open_basedir');
    if (!empty($openBasedir)) {
        $allowedPaths = preg_split('/[:;]/', $openBasedir);
        // Verify the install root is within allowed paths
        $insideBasedir = false;
        foreach ($allowedPaths as $p) {
            if ($p !== '' && strpos($baseDir, rtrim($p, '/')) === 0) {
                $insideBasedir = true;
                break;
            }
        }
        $result['settings'][] = [
            'label'  => 'open_basedir',
            'value'  => $insideBasedir ? 'OK (' . count($allowedPaths) . ' path(s))' : 'Restricted',
            'status' => $insideBasedir ? 'pass' : 'fail',
            'hint'   => $insideBasedir ? '' : "Add app root '{$baseDir}' to open_basedir in php.ini.",
        ];
    } else {
        $result['settings'][] = [
            'label'  => 'open_basedir',
            'value'  => 'Not set (unrestricted)',
            'status' => 'pass',
        ];
    }

    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $criticalDisabled = array_intersect($disabled, ['mkdir', 'chmod', 'file_put_contents', 'rmdir', 'unlink', 'fopen']);
    $result['settings'][] = [
        'label'  => 'disable_functions',
        'value'  => empty($disabled) ? 'None' : count($disabled) . ' function(s) blocked',
        'status' => empty($criticalDisabled) ? 'pass' : 'fail',
        'hint'   => empty($criticalDisabled) ? '' : 'Critical functions blocked: ' . implode(', ', $criticalDisabled) . '. Backups/upgrades will degrade.',
    ];

    // ── Live mkdir / write / unlink probe ──
    $probeDir = $baseDir . '/uploads/_keygate_probe_' . uniqid();
    $probeFile = $probeDir . '/probe.txt';
    $probeOk = @mkdir($probeDir, 0755, true);
    if ($probeOk) {
        $writeOk = @file_put_contents($probeFile, 'ok') !== false;
        $readOk = $writeOk && @file_get_contents($probeFile) === 'ok';
        @unlink($probeFile);
        @rmdir($probeDir);
    } else {
        $writeOk = $readOk = false;
    }
    $result['directories'][] = [
        'label'  => 'Filesystem write probe',
        'value'  => ($probeOk && $writeOk && $readOk) ? 'OK' : 'Failed',
        'status' => ($probeOk && $writeOk && $readOk) ? 'pass' : 'fail',
        'hint'   => ($probeOk && $writeOk && $readOk) ? '' : 'Cannot create+write+read in uploads/. Check chmod 755 and disable_functions.',
    ];

    // ── Parent-dir writable flag (used by step 6 to surface manual workflow) ──
    $result['parent_writable'] = is_writable($baseDir);
    $result['php_version_full'] = PHP_VERSION;

    echo json_encode($result);
}

// ═══════════════════════════════════════════════════════════════
// Step 2: Test Database Connection
// ═══════════════════════════════════════════════════════════════
function handleTestDb() {
    $host          = trim($_POST['db_host'] ?? '127.0.0.1');
    $port          = (int)($_POST['db_port'] ?? 3306);
    $user          = $_POST['db_user'] ?? '';
    $pass          = $_POST['db_pass'] ?? '';
    $name          = $_POST['db_name'] ?? 'oem_activation';
    $socket        = trim($_POST['db_socket'] ?? '');
    $skipCreate    = !empty($_POST['skip_create_db']);
    $rawPrefix     = trim((string)($_POST['db_prefix'] ?? ''));
    $charset       = 'utf8mb4';  // Default; may downgrade to utf8mb3 below.

    if (empty($user)) {
        echo json_encode(['success' => false, 'message' => 'Username is required']);
        return;
    }

    // Validate prefix: empty OR `^[a-z][a-z0-9_]{0,9}$`. Deny-list reserved.
    if ($rawPrefix !== '' && !preg_match('/^[a-z][a-z0-9_]{0,9}$/', $rawPrefix)) {
        echo json_encode(['success' => false, 'message' => 'Prefix must be 1–10 chars, lowercase letters/digits/_, start with a letter.']);
        return;
    }
    $denyList = ['mysql_', 'sys_', 'information_', 'performance_'];
    foreach ($denyList as $denied) {
        if ($rawPrefix !== '' && strpos($rawPrefix, $denied) === 0) {
            echo json_encode(['success' => false, 'message' => "Prefix '{$rawPrefix}' starts with reserved name. Pick another."]);
            return;
        }
    }

    // aaPanel / cPanel hint: many panels bind MariaDB to TCP only.
    // Coerce 'localhost' → '127.0.0.1' to avoid PDO Unix-socket lookup
    // unless an explicit socket path is supplied.
    if ($socket === '' && strtolower($host) === 'localhost') {
        $host = '127.0.0.1';
    }

    try {
        // First: connect without database to check server
        $dsn = installerBuildDsn($host, $port, '', $socket, $charset);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);

        // Get server version
        $version = $pdo->query("SELECT VERSION() AS v")->fetch(PDO::FETCH_ASSOC)['v'];
        $isMariaDB = stripos($version, 'MariaDB') !== false;
        $serverType = $isMariaDB ? 'MariaDB' : 'MySQL';

        // Charset auto-fallback: MySQL < 5.7 or MariaDB < 5.5.3 → utf8mb3 (legacy 'utf8')
        $numericVer = preg_replace('/[^0-9.].*/', '', $version);
        if ($isMariaDB) {
            if (version_compare($numericVer, '5.5.3', '<')) $charset = 'utf8';
        } else {
            if (version_compare($numericVer, '5.7.0', '<')) $charset = 'utf8';
        }

        // Check if database exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$name]);
        $dbExists = (bool)$stmt->fetch();

        $collation = $charset . '_unicode_ci';
        if (!$dbExists) {
            if ($skipCreate) {
                echo json_encode([
                    'success' => false,
                    'message' => "Database '{$name}' does not exist on the server. Create it in your control panel (aaPanel: Databases → Add database) and uncheck 'skip CREATE' OR pre-create it then retry.",
                ]);
                return;
            }
            // Try to create it
            try {
                $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET {$charset} COLLATE {$collation}");
                $msg = "{$serverType} {$version} — Database '{$name}' created successfully (charset={$charset}).";
            } catch (PDOException $createErr) {
                $code = (int) $createErr->getCode();
                if (in_array($code, [1044, 1142]) || stripos($createErr->getMessage(), 'denied') !== false) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Your DB user lacks CREATE DATABASE privilege (common on Plesk/CyberPanel). Pre-create the DB '{$name}' in your control panel, then retick 'Database already exists — skip CREATE' and retry.",
                        'suggest_skip_create' => true,
                    ]);
                    return;
                }
                throw $createErr;
            }
        } else {
            $msg = "{$serverType} {$version} — Database '{$name}' exists (charset will be {$charset}).";
        }

        // Verify we can connect to the database
        $dsn2 = installerBuildDsn($host, $port, $name, $socket, $charset);
        $pdo2 = new PDO($dsn2, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
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
        $_SESSION['install_db'] = [
            'host'    => $host,
            'port'    => $port,
            'user'    => $user,
            'pass'    => $pass,
            'name'    => $name,
            'socket'  => $socket,
            'prefix'  => $rawPrefix,
            'charset' => $charset,
        ];

        echo json_encode([
            'success'   => true,
            'message'   => $msg,
            'charset'   => $charset,
            'version'   => $version,
            'serverType'=> $serverType,
            'prefix'    => $rawPrefix,
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => installerFriendlyDbError($e, $host, $port, $socket)]);
    }
}

// ═══════════════════════════════════════════════════════════════
// Step 3: Install Database — Async (init/step) + Fast-path (all)
// ═══════════════════════════════════════════════════════════════

/**
 * Canonical migration order. Mirrors 00-init.sh exactly.
 * Returns [filename, version] tuples.
 */
function installerMigrationList(): array {
    return [
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
        ['license_p0_hmac_migration.sql',    20],
        ['license_p1_hwbind_migration.sql',  21],
        ['license_p2_phonehome_migration.sql', 22],
    ];
}

/**
 * Step-3 INIT: ensure schema_versions exists, return ordered file list with
 * applied-status flags. Browser drives the per-file loop from here.
 */
function handleInstallDbInit() {
    $pdo = getInstallerPdo();
    if (!$pdo) return;

    $sqlDir = realpath(__DIR__ . '/../database');
    if (!$sqlDir) {
        echo json_encode(['success' => false, 'message' => 'Database SQL directory not found']);
        return;
    }

    // Bootstrap schema_versions first (the tracking table for all later migrations).
    // Run through installerRunSqlFile so the `#__` prefix substitution kicks in.
    $schemaFile = $sqlDir . '/schema_versions_migration.sql';
    if (file_exists($schemaFile)) {
        installerRunSqlFile($pdo, file_get_contents($schemaFile));  // Best-effort; ignore errors.
    }

    $svTable = '`' . installerT('schema_versions') . '`';
    $list = [];
    foreach (installerMigrationList() as [$file, $version]) {
        $filePath = $sqlDir . '/' . $file;
        $exists = file_exists($filePath);
        $applied = false;

        if ($exists) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$svTable} WHERE filename = ?");
                $stmt->execute([$file]);
                $applied = ((int)$stmt->fetchColumn()) > 0;
            } catch (PDOException $e) { /* table missing → not applied */ }
        }

        $list[] = [
            'file'    => $file,
            'version' => $version,
            'exists'  => $exists,
            'applied' => $applied,
            'sha256'  => $exists ? substr(hash_file('sha256', $filePath), 0, 16) : '',
        ];
    }

    echo json_encode([
        'success'    => true,
        'migrations' => $list,
        'total'      => count($list),
    ]);
}

/**
 * Step-3 STEP: apply ONE migration file.
 * Body: { file: 'install.sql', version: 1 }
 */
function handleInstallDbStep() {
    @set_time_limit(0);
    $pdo = getInstallerPdo();
    if (!$pdo) return;

    $file    = $_POST['file'] ?? '';
    $version = (int)($_POST['version'] ?? 0);

    // Whitelist against the canonical list — no arbitrary file reads.
    $allowed = array_column(installerMigrationList(), 0);
    if (!in_array($file, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => "Migration '{$file}' not on the canonical list."]);
        return;
    }

    $sqlDir   = realpath(__DIR__ . '/../database');
    $filePath = $sqlDir . '/' . $file;
    if (!file_exists($filePath)) {
        echo json_encode(['file' => $file, 'success' => true, 'status' => 'skipped', 'message' => 'File not found (skipped)']);
        return;
    }

    $svTable = '`' . installerT('schema_versions') . '`';

    // Skip if already applied
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$svTable} WHERE filename = ?");
        $stmt->execute([$file]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['file' => $file, 'success' => true, 'status' => 'skipped', 'message' => 'Already applied']);
            return;
        }
    } catch (PDOException $e) { /* table missing — proceed */ }

    $sql = file_get_contents($filePath);
    $result = installerRunSqlFile($pdo, $sql);

    if ($result['ok']) {
        try {
            $checksum = hash('sha256', $sql);
            $stmt = $pdo->prepare("INSERT IGNORE INTO {$svTable} (version, filename, checksum) VALUES (?, ?, ?)");
            $stmt->execute([$version, $file, $checksum]);
        } catch (PDOException $e) { /* ignore */ }

        echo json_encode([
            'file'      => $file,
            'success'   => true,
            'status'    => 'ok',
            'message'   => 'Applied (' . $result['stmts_run'] . ' statements)',
            'stmts_run' => $result['stmts_run'],
        ]);
        return;
    }

    // Tolerate "already exists" / "duplicate" — record as applied.
    if (preg_match('/Duplicate|already exists|1060|1061|1050|1062/i', $result['error'])) {
        try {
            $checksum = hash('sha256', $sql);
            $stmt = $pdo->prepare("INSERT IGNORE INTO {$svTable} (version, filename, checksum) VALUES (?, ?, ?)");
            $stmt->execute([$version, $file, $checksum]);
        } catch (PDOException $e2) { /* ignore */ }

        echo json_encode([
            'file'    => $file,
            'success' => true,
            'status'  => 'ok',
            'message' => 'Applied (some objects already existed)',
        ]);
        return;
    }

    echo json_encode([
        'file'    => $file,
        'success' => false,
        'status'  => 'error',
        'message' => $result['error'],
    ]);
}

/**
 * Step-3 ALL: legacy single-shot path (used by fast-path when host has
 * generous max_execution_time AND no other risk flags).
 */
function handleInstallDbAll() {
    @set_time_limit(0);
    $pdo = getInstallerPdo();
    if (!$pdo) return;

    $sqlDir = realpath(__DIR__ . '/../database');
    if (!$sqlDir) {
        echo json_encode(['success' => false, 'message' => 'Database SQL directory not found']);
        return;
    }

    // Bootstrap schema_versions (run through installerRunSqlFile so prefix substitution applies)
    $schemaFile = $sqlDir . '/schema_versions_migration.sql';
    if (file_exists($schemaFile)) {
        installerRunSqlFile($pdo, file_get_contents($schemaFile));
    }

    $svTable = '`' . installerT('schema_versions') . '`';
    $results = [];
    foreach (installerMigrationList() as $i => [$file, $version]) {
        if ($i === 0) {
            $results[] = ['file' => $file, 'status' => 'ok', 'message' => 'Tracking table ready'];
            continue;
        }

        $filePath = $sqlDir . '/' . $file;
        if (!file_exists($filePath)) {
            $results[] = ['file' => $file, 'status' => 'skipped', 'message' => 'File not found'];
            continue;
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM {$svTable} WHERE filename = ?");
            $stmt->execute([$file]);
            if ((int)$stmt->fetchColumn() > 0) {
                $results[] = ['file' => $file, 'status' => 'skipped', 'message' => 'Already applied'];
                continue;
            }
        } catch (PDOException $e) { /* ignore */ }

        $sql = file_get_contents($filePath);
        $r = installerRunSqlFile($pdo, $sql);

        if ($r['ok']) {
            try {
                $checksum = hash('sha256', $sql);
                $stmt = $pdo->prepare("INSERT IGNORE INTO {$svTable} (version, filename, checksum) VALUES (?, ?, ?)");
                $stmt->execute([$version, $file, $checksum]);
            } catch (PDOException $e) { /* ignore */ }
            $results[] = ['file' => $file, 'status' => 'ok', 'message' => 'Applied (' . $r['stmts_run'] . ' statements)'];
        } elseif (preg_match('/Duplicate|already exists|1060|1061|1050|1062/i', $r['error'])) {
            try {
                $checksum = hash('sha256', $sql);
                $stmt = $pdo->prepare("INSERT IGNORE INTO {$svTable} (version, filename, checksum) VALUES (?, ?, ?)");
                $stmt->execute([$version, $file, $checksum]);
            } catch (PDOException $e) { /* ignore */ }
            $results[] = ['file' => $file, 'status' => 'ok', 'message' => 'Applied (some objects already existed)'];
        } else {
            $results[] = ['file' => $file, 'status' => 'error', 'message' => $r['error']];
        }
    }

    $errors = array_filter($results, fn($r) => $r['status'] === 'error');
    $success = count($errors) === 0;

    echo json_encode([
        'success' => $success,
        'results' => $results,
        'message' => $success ? 'All migrations completed' : count($errors) . ' migration(s) had errors',
    ]);
}

/**
 * Run a multi-statement SQL string statement-by-statement.
 * Strips DELIMITER + outer BEGIN/COMMIT wrappers.
 * Returns ['ok' => bool, 'stmts_run' => int, 'error' => string].
 */
function installerRunSqlFile(PDO $pdo, string $sql): array {
    // Strip DELIMITER directives — PDO doesn't honor them.
    $sql = preg_replace('/DELIMITER\s+[^\n]+/i', '', $sql);
    // Strip outer BEGIN/COMMIT wrappers — DDL implicit-commits anyway and
    // wrapper breaks per-statement progress reporting on some panels.
    $sql = preg_replace('/^\s*(START\s+TRANSACTION|BEGIN)\s*;\s*$/im', '', $sql);
    $sql = preg_replace('/^\s*COMMIT\s*;\s*$/im', '', $sql);

    // Substitute table prefix sentinel `#__` with the configured prefix.
    // Empty prefix → identical to pre-prefix schema.
    $prefix = (string)($_SESSION['install_db']['prefix'] ?? '');
    $sql = str_replace('#__', $prefix, $sql);

    // Defense-in-depth: if substitution somehow missed and `#__` remains,
    // abort loudly rather than send invalid SQL.
    if (strpos($sql, '#__') !== false) {
        return ['ok' => false, 'stmts_run' => 0, 'error' => 'Internal error: unsubstituted `#__` sentinel still present after prefix replacement.'];
    }

    $stmts = installerSplitSql($sql);
    $count = 0;
    foreach ($stmts as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            return ['ok' => false, 'stmts_run' => $count, 'error' => $e->getMessage()];
        }
    }
    return ['ok' => true, 'stmts_run' => $count, 'error' => ''];
}

/**
 * Resolve the prefixed name for a logical table during installation.
 * Mirrors functions/db-helpers.php t() but reads from $_SESSION since
 * the installer runs before config.php exists.
 */
function installerT(string $name): string {
    $prefix = (string)($_SESSION['install_db']['prefix'] ?? '');
    return $prefix . $name;
}

/**
 * Split a multi-statement SQL string into individual statements.
 * Respects backticks, single/double-quoted strings, line comments (-- ...),
 * and block comments (/* ... *\/).
 *
 * Returns array of statements (semicolons stripped).
 */
function installerSplitSql(string $sql): array {
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    $inSingle = $inDouble = $inBacktick = false;
    $inLineComment = $inBlockComment = false;

    while ($i < $len) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // Comments
        if (!$inSingle && !$inDouble && !$inBacktick) {
            if (!$inBlockComment && !$inLineComment && $c === '-' && $next === '-') {
                $inLineComment = true;
                $buf .= $c . $next;
                $i += 2;
                continue;
            }
            if (!$inBlockComment && !$inLineComment && $c === '#') {
                $inLineComment = true;
                $buf .= $c;
                $i++;
                continue;
            }
            if (!$inBlockComment && !$inLineComment && $c === '/' && $next === '*') {
                $inBlockComment = true;
                $buf .= $c . $next;
                $i += 2;
                continue;
            }
            if ($inLineComment && ($c === "\n" || $c === "\r")) {
                $inLineComment = false;
                $buf .= $c;
                $i++;
                continue;
            }
            if ($inBlockComment && $c === '*' && $next === '/') {
                $inBlockComment = false;
                $buf .= $c . $next;
                $i += 2;
                continue;
            }
        }

        if ($inLineComment || $inBlockComment) {
            $buf .= $c;
            $i++;
            continue;
        }

        // Quote tracking
        if ($c === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
        } elseif ($c === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($c === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        // Statement terminator
        if ($c === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $stmts[] = $buf;
            $buf = '';
            $i++;
            continue;
        }

        $buf .= $c;
        $i++;
    }

    if (trim($buf) !== '') {
        $stmts[] = $buf;
    }
    return $stmts;
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

    $adminTable = '`' . installerT('admin_users') . '`';
    $aclTable   = '`' . installerT('acl_roles') . '`';

    try {
        // Check if admin already exists
        $stmt = $pdo->prepare("SELECT id FROM {$adminTable} WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            // Update existing
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("UPDATE {$adminTable} SET password_hash = ?, full_name = ?, email = ?, role = 'super_admin', is_active = 1, must_change_password = 0, failed_login_attempts = 0, locked_until = NULL WHERE username = ?");
            $stmt->execute([$hash, $fullName, $email, $username]);
            echo json_encode(['success' => true, 'message' => "Admin account '{$username}' updated."]);
        } else {
            // Create new
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("INSERT INTO {$adminTable} (username, full_name, email, password_hash, role, is_active, must_change_password) VALUES (?, ?, ?, ?, 'super_admin', 1, 0)");
            $stmt->execute([$username, $fullName, $email, $hash]);

            // Try to assign ACL role if table exists
            try {
                $adminId = $pdo->lastInsertId();
                $roleStmt = $pdo->prepare("SELECT id FROM {$aclTable} WHERE name = 'super_admin' LIMIT 1");
                $roleStmt->execute();
                $role = $roleStmt->fetch(PDO::FETCH_ASSOC);
                if ($role) {
                    $pdo->prepare("UPDATE {$adminTable} SET custom_role_id = ? WHERE id = ?")->execute([$role['id'], $adminId]);
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
    $prefix  = (string)($_SESSION['install_db']['prefix']  ?? '');
    $charset = (string)($_SESSION['install_db']['charset'] ?? 'utf8mb4');
    $configContent = generateConfig($host, $port, $user, $pass, $name, $timezone, $prefix, $charset);
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
            $tConfig    = '`' . installerT('system_config')      . '`';
            $tTech      = '`' . installerT('technicians')        . '`';
            $tAdmin     = '`' . installerT('admin_users')        . '`';
            $tTrustedN  = '`' . installerT('trusted_networks')   . '`';
            $tAdminIp   = '`' . installerT('admin_ip_whitelist') . '`';

            $settings = [
                'system_name'      => $systemName,
                'server_url'       => $serverUrl,
                'default_language' => $language,
                'timezone'         => $timezone,
            ];
            foreach ($settings as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO {$tConfig} (config_key, config_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
                $stmt->execute([$key, $value, "Set by installer"]);
            }

            // Delete demo technician if it exists
            try {
                $pdo->exec("DELETE FROM {$tTech} WHERE technician_id = 'demo' AND notes LIKE '%Demo account%'");
            } catch (PDOException $e) { /* ignore */ }

            // ── Auto-detect installer's network and add as trusted ──
            try {
                $clientIp = getClientIp();
                if ($clientIp && $clientIp !== 'unknown') {
                    // Get admin ID for foreign key
                    $adminStmt = $pdo->prepare("SELECT id FROM {$tAdmin} WHERE username = ? LIMIT 1");
                    $adminStmt->execute([$adminUser]);
                    $adminRow = $adminStmt->fetch(PDO::FETCH_ASSOC);
                    $adminId = $adminRow ? $adminRow['id'] : null;

                    // Calculate /24 subnet from the IP
                    $subnet = calculateSubnet($clientIp, 24);

                    // Add to trusted_networks (for 2FA bypass + USB auth)
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO {$tTrustedN} (network_name, ip_range, bypass_2fa, allow_usb_auth, description, created_by_admin_id)
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
                            INSERT INTO {$tAdminIp} (ip_address, ip_range, description, created_by)
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
        'db_prefix'       => $prefix,
        'db_charset'      => $charset,
    ];
    $lockPath = realpath(__DIR__ . '/..') . '/install.lock';
    file_put_contents($lockPath, json_encode($lockData, JSON_PRETTY_PRINT));

    // ── Clear resume breadcrumb (install is complete) ──
    @unlink(installerProgressPath());
    installerLog("finalize: install complete; admin={$adminUser}, prefix='" . $prefix . "', charset={$charset}");

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
    $host    = trim($_POST['db_host']   ?? $_SESSION['install_db']['host']    ?? '127.0.0.1');
    $port    = (int)($_POST['db_port']  ?? $_SESSION['install_db']['port']    ?? 3306);
    $user    = $_POST['db_user']        ?? $_SESSION['install_db']['user']    ?? '';
    $pass    = $_POST['db_pass']        ?? $_SESSION['install_db']['pass']    ?? '';
    $name    = $_POST['db_name']        ?? $_SESSION['install_db']['name']    ?? '';
    $socket  = trim($_POST['db_socket'] ?? $_SESSION['install_db']['socket']  ?? '');
    $charset = $_SESSION['install_db']['charset'] ?? 'utf8mb4';

    if (empty($user) || empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Database credentials missing. Go back to step 2.']);
        return null;
    }

    // Coerce 'localhost' → '127.0.0.1' (force TCP) when no explicit socket given
    if ($socket === '' && strtolower($host) === 'localhost') {
        $host = '127.0.0.1';
    }

    try {
        $dsn = installerBuildDsn($host, $port, $name, $socket, $charset);
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 15,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => installerFriendlyDbError($e, $host, $port, $socket)]);
        return null;
    }
}

/**
 * Build a PDO DSN that supports either TCP host:port or Unix socket path.
 * When $socket is non-empty, host/port are ignored.
 */
function installerBuildDsn(string $host, int $port, string $name, string $socket = '', string $charset = 'utf8mb4'): string {
    if ($socket !== '') {
        $dsn = "mysql:unix_socket={$socket};charset={$charset}";
    } else {
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    }
    if ($name !== '') {
        // Insert dbname between host/socket and charset to keep DSN ordered cleanly
        $dsn = str_replace(";charset={$charset}", ";dbname={$name};charset={$charset}", $dsn);
    }
    return $dsn;
}

/**
 * Convert raw PDOException to a user-friendly, DSN-sanitized message.
 * Hints aaPanel / cPanel users about common pitfalls.
 */
function installerFriendlyDbError(PDOException $e, string $host, int $port, string $socket): string {
    $raw = $e->getMessage();
    $code = (int)$e->getCode();

    // Strip any DSN fragments that could leak host/port/user
    $raw = preg_replace('/\bmysql:[^\s]+/', '[DSN]', $raw);

    // ── 1044/1142: Lacks privilege (Plesk, CyberPanel, ISPConfig) ─
    if ($code === 1044 || $code === 1142 || preg_match('/\b(1044|1142)\b/', $raw)) {
        return "Your DB user lacks the required privilege. On Plesk/CyberPanel, the per-user account often cannot CREATE DATABASE or CREATE TABLE. Pre-create the database in your control panel UI and tick 'Database already exists — skip CREATE'.";
    }
    // ── 1045 with no password supplied ─
    if (stripos($raw, 'Access denied') !== false) {
        if (stripos($raw, 'using password: NO') !== false) {
            return "Access denied. Server says no password was supplied. If your panel set a password (most do), enter it. If not, ensure user is allowed from 127.0.0.1.";
        }
        return 'Access denied. Check username and password. On aaPanel, ensure the user is allowed from this host (set Host = % or 127.0.0.1 in phpMyAdmin → User accounts).';
    }
    if (stripos($raw, 'Unknown database') !== false) {
        return "Database does not exist. Pre-create it in your control panel (aaPanel/cPanel/Plesk → Databases) and tick 'Database already exists — skip CREATE'.";
    }
    if (stripos($raw, 'Unknown MySQL server host') !== false || stripos($raw, 'getaddrinfo') !== false) {
        return "Cannot resolve host '{$host}'. Use 127.0.0.1 instead of localhost on most aaPanel installs.";
    }
    if (stripos($raw, 'Connection refused') !== false) {
        return "Cannot connect to {$host}:{$port}. MariaDB/MySQL service may not be running, or it's bound to a different port. Check the service is started in your panel.";
    }
    if (stripos($raw, 'No such file or directory') !== false) {
        // Classic Unix-socket failure
        $hint = $socket !== ''
            ? "Socket path '{$socket}' does not exist or is not readable by the web user."
            : "PDO tried the default Unix socket but it does not exist. Use 127.0.0.1 instead of localhost, or click 'Detect socket' in advanced settings. Common paths: /tmp/mysql.sock, /var/run/mysqld/mysqld.sock, /www/server/mysql/mysql.sock";
        return $hint;
    }
    if (stripos($raw, 'timed out') !== false || stripos($raw, 'timeout') !== false) {
        return "Connection timed out reaching {$host}:{$port}. Firewall or wrong port?";
    }
    // Fallback — sanitized message only, no stack
    return "Database connection failed: " . $raw;
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
 * Get the real client IP, accounting for proxies.
 *
 * Security: only honor X-Forwarded-For / X-Real-IP / Client-IP headers when
 * the immediate REMOTE_ADDR is in a private/loopback range — that's the
 * only situation where a forward header is trustworthy (real proxy in front).
 * Otherwise the client could spoof any IP.
 */
function getClientIp(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $remoteIsPrivate = $remoteAddr !== ''
        && filter_var($remoteAddr, FILTER_VALIDATE_IP)
        && !filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

    if ($remoteIsPrivate) {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
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
function generateConfig(
    string $host,
    string $port,
    string $user,
    string $pass,
    string $name,
    string $timezone,
    string $prefix = '',
    string $charset = 'utf8mb4'
): string {
    // Escape single quotes in password for PHP string
    $passEscaped   = addcslashes($pass, "'\\");
    $prefixEscaped = addcslashes($prefix, "'\\");
    $charsetEsc    = preg_replace('/[^a-z0-9_]/', '', $charset) ?: 'utf8mb4';

    return <<<'PHP_HEADER'
<?php
/**
 * Application Bootstrap & Database Configuration
 * KeyGate v2.0
 *
 * Generated by the web installer.
 *
 * This file:
 *  1. Loads constants (which loads functions/db-helpers.php for t())
 *  2. Creates the PDO database connection (with retry logic)
 *  3. Provides getConfig() for reading system_config rows
 *  4. Includes helper modules (http, session, key, order-field)
 */

PHP_HEADER
    . "// ── Table Prefix ────────────────────────────────────────────────\n"
    . "// Joomla-style sentinel. Empty string → no prefix (legacy schema).\n"
    . "define('DB_PREFIX', '{$prefixEscaped}');\n\n"
    . "require_once __DIR__ . '/constants.php';\n\n"
    . "// ── Environment Detection ───────────────────────────────────────\n"
    . "\$isProduction = !in_array(\$_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'activate.local']);\n\n"
    . "// ── Database Configuration ──────────────────────────────────────\n"
    . "\$db_config = [\n"
    . "    'host'     => '{$host}',\n"
    . "    'dbname'   => '{$name}',\n"
    . "    'username' => '{$user}',\n"
    . "    'password' => '{$passEscaped}',\n"
    . "    'charset'  => '{$charsetEsc}',\n"
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

// ═══════════════════════════════════════════════════════════════
// Auto-unlock recovery + socket detection + health probe
// ═══════════════════════════════════════════════════════════════

/**
 * Auto-unlock recovery: if install.lock exists but the install never
 * finished (no admin_users yet, or table missing), delete the lock so
 * the user can resume. Triple-gated: lock present + DB connectable +
 * admin_users empty/absent. Logs the auto-action to install.log.
 *
 * Idempotent — safe to call on every request.
 */
function installerCheckIncompleteState(): void {
    $lockPath   = __DIR__ . '/../install.lock';
    $configPath = __DIR__ . '/../config.php';

    if (!file_exists($lockPath)) return;
    if (!file_exists($configPath)) return;  // Without config we can't probe DB

    // Best-effort include of config to get $db_config — but we don't trust
    // its globals in this script's scope, so we re-parse manually.
    $configSrc = @file_get_contents($configPath);
    if ($configSrc === false) return;

    if (!preg_match("/'host'\s*=>\s*'([^']+)'/", $configSrc, $hM)) return;
    if (!preg_match("/'dbname'\s*=>\s*'([^']+)'/", $configSrc, $nM)) return;
    if (!preg_match("/'username'\s*=>\s*'([^']+)'/", $configSrc, $uM)) return;
    if (!preg_match("/'password'\s*=>\s*'([^']*)'/", $configSrc, $pM)) return;
    if (!preg_match("/'port'\s*=>\s*(\d+)/", $configSrc, $portM)) return;

    $host = $hM[1]; $name = $nM[1]; $user = $uM[1]; $pass = $pM[1]; $port = (int)$portM[1];
    if (strtolower($host) === 'localhost') $host = '127.0.0.1';

    // Read DB_PREFIX from config (empty for legacy installs).
    $prefix = '';
    if (preg_match("/define\(\s*'DB_PREFIX'\s*,\s*'([^']*)'\s*\)/", $configSrc, $pxM)) {
        $prefix = $pxM[1];
    }
    $adminTable = $prefix . 'admin_users';

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // admin_users table missing → install was never completed.
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($adminTable));
        if (!$stmt->fetch()) {
            installerLog("auto-unlock: {$adminTable} missing — clearing install.lock");
            @unlink($lockPath);
            return;
        }

        // admin_users empty → install was never completed.
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$adminTable}`")->fetchColumn();
        if ($cnt === 0) {
            installerLog("auto-unlock: admin_users empty — clearing install.lock");
            @unlink($lockPath);
            return;
        }
    } catch (PDOException $e) {
        // Can't connect — might mean DB isn't even available; don't unlock,
        // user must resolve DB issue first. Log it.
        installerLog("auto-unlock: skipped (DB connect failed: " . $e->getMessage() . ")");
        return;
    }

    // Install was completed properly — keep the lock.
}

/**
 * Probe a list of common Unix socket paths and return the first that exists.
 */
function installerProbeSockets(): array {
    $candidates = [
        '/tmp/mysql.sock',
        '/var/run/mysqld/mysqld.sock',
        '/var/lib/mysql/mysql.sock',
        '/www/server/mysql/mysql.sock',
        '/var/run/mariadb/mariadb.sock',
        '/usr/local/mysql/mysql.sock',
        '/usr/local/var/mysql/mysql.sock',
        '/opt/lampp/var/mysql/mysql.sock',
    ];
    $found = [];
    foreach ($candidates as $p) {
        if (@file_exists($p)) {
            $found[] = $p;
        }
    }
    return $found;
}

/**
 * Action: detect_socket — returns list of socket paths discovered on disk.
 */
function handleDetectSocket(): void {
    $found = installerProbeSockets();
    echo json_encode([
        'success'   => true,
        'sockets'   => $found,
        'suggested' => $found[0] ?? '',
    ]);
}

/**
 * Action: health — quick post-install probe (no auth required because
 * install.lock blocks re-entry once installed). Returns DB connect status,
 * tables present, admin row count.
 */
function handleHealth(): void {
    $configPath = __DIR__ . '/../config.php';
    if (!file_exists($configPath)) {
        echo json_encode(['success' => false, 'message' => 'config.php not found']);
        return;
    }

    $pdo = getInstallerPdo();
    if (!$pdo) return;  // getInstallerPdo already echoed error

    $checks = [];
    try {
        $pdo->query('SELECT 1');
        $checks[] = ['label' => 'Database connect', 'status' => 'pass'];
    } catch (PDOException $e) {
        $checks[] = ['label' => 'Database connect', 'status' => 'fail', 'message' => $e->getMessage()];
    }

    $expectTables = ['admin_users', 'oem_keys', 'technicians', 'system_config', 'schema_versions'];
    foreach ($expectTables as $t) {
        $physical = installerT($t);
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($physical));
            $found = (bool)$stmt->fetch();
            $checks[] = ['label' => "Table {$physical}", 'status' => $found ? 'pass' : 'fail'];
        } catch (PDOException $e) {
            $checks[] = ['label' => "Table {$physical}", 'status' => 'fail'];
        }
    }

    try {
        $adminTable = '`' . installerT('admin_users') . '`';
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM {$adminTable}")->fetchColumn();
        $checks[] = ['label' => 'Admin accounts', 'status' => $admins > 0 ? 'pass' : 'fail', 'value' => $admins];
    } catch (PDOException $e) {
        $checks[] = ['label' => 'Admin accounts', 'status' => 'fail'];
    }

    $allPass = !in_array(false, array_map(fn($c) => $c['status'] === 'pass', $checks), true);
    echo json_encode(['success' => $allPass, 'checks' => $checks]);
}

/**
 * Append a single line to install/install.log. Best-effort: silently
 * skipped if the file isn't writable.
 */
function installerLog(string $line): void {
    $logPath = __DIR__ . '/install.log';
    @file_put_contents(
        $logPath,
        '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
        FILE_APPEND
    );
}

// ═══════════════════════════════════════════════════════════════
// P2: Resumable installer (progress breadcrumb file)
// ═══════════════════════════════════════════════════════════════

function installerProgressPath(): string {
    return __DIR__ . '/.progress.json';
}

/**
 * Read the progress breadcrumb. Returns the highest step the user has
 * completed plus a per-step timestamp map.
 */
function handleProgressGet(): void {
    $path = installerProgressPath();
    if (!file_exists($path)) {
        echo json_encode(['success' => true, 'progress' => null]);
        return;
    }
    $data = json_decode(@file_get_contents($path), true);
    if (!is_array($data)) {
        echo json_encode(['success' => true, 'progress' => null]);
        return;
    }
    echo json_encode(['success' => true, 'progress' => $data]);
}

/**
 * Persist a step completion breadcrumb.
 * Body: { step: 1..6 }
 */
function handleProgressSet(): void {
    $step = (int)($_POST['step'] ?? 0);
    if ($step < 1 || $step > 6) {
        echo json_encode(['success' => false, 'message' => 'Invalid step']);
        return;
    }

    $path = installerProgressPath();
    $current = ['steps' => [], 'last_step' => 0, 'updated_at' => date('Y-m-d H:i:s')];
    if (file_exists($path)) {
        $loaded = json_decode(@file_get_contents($path), true);
        if (is_array($loaded)) $current = array_merge($current, $loaded);
    }

    $current['steps'][(string)$step] = date('Y-m-d H:i:s');
    if ($step > (int)($current['last_step'] ?? 0)) {
        $current['last_step'] = $step;
    }
    $current['updated_at'] = date('Y-m-d H:i:s');

    @file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT));
    installerLog("step_done: {$step}");
    echo json_encode(['success' => true, 'progress' => $current]);
}

/**
 * Wipe the progress file. Used on user-initiated "Start Over".
 */
function handleProgressClear(): void {
    @unlink(installerProgressPath());
    installerLog("progress_cleared by user");
    echo json_encode(['success' => true]);
}

/**
 * Mark a migration as forcibly skipped after an error (user clicked Skip
 * on the per-migration retry UI). Records in schema_versions with the
 * `checksum` column suffixed `:SKIPPED` so we can tell apart from
 * successful applies.
 *
 * Body: { file: 'install.sql', version: 1, error: 'optional msg' }
 */
function handleMigrationSkip(): void {
    $pdo = getInstallerPdo();
    if (!$pdo) return;

    $file    = $_POST['file']    ?? '';
    $version = (int)($_POST['version'] ?? 0);
    $error   = (string)($_POST['error'] ?? '');

    $allowed = array_column(installerMigrationList(), 0);
    if (!in_array($file, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => "Migration '{$file}' not on the canonical list."]);
        return;
    }

    $svTable = '`' . installerT('schema_versions') . '`';
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO {$svTable} (version, filename, checksum) VALUES (?, ?, ?)");
        $stmt->execute([$version, $file, 'SKIPPED:' . substr(hash('sha256', $error . microtime()), 0, 16)]);
        installerLog("migration_skipped: {$file} (error: " . substr($error, 0, 200) . ")");
        echo json_encode(['success' => true, 'message' => "Migration '{$file}' marked as skipped."]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
