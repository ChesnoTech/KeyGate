<?php
/**
 * System Upgrade Controller — Joomla-style Upgrade Wizard
 *
 * Handles the full upgrade lifecycle:
 *   1. Upload package (ZIP with manifest.json)
 *   2. Pre-flight compatibility checks
 *   3. Create full backup (DB + files)
 *   4. Apply upgrade (migrations + file updates)
 *   5. Post-upgrade verification
 *   6. Rollback (if needed)
 */

// ── Helpers ─────────────────────────────────────────────────

function getAppRoot(): string {
    return dirname(__DIR__, 2);  // FINAL_PRODUCTION_SYSTEM/
}

function getUpgradeDir(): string {
    $dir = getAppRoot() . '/uploads/upgrades';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function getBackupDir(): string {
    $dir = getAppRoot() . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function loadUpgradeRow(PDO $pdo, int $upgradeId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM upgrade_history WHERE id = ?");
    $stmt->execute([$upgradeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateUpgradeStatus(PDO $pdo, int $id, string $status, array $extra = []): void {
    $sets = ['status = ?'];
    $params = [$status];
    foreach ($extra as $col => $val) {
        $sets[] = "$col = ?";
        $params[] = $val;
    }
    $params[] = $id;
    $sql = "UPDATE upgrade_history SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function validateManifest(array $m): ?string {
    if (($m['schema_version'] ?? null) !== 1) return 'Invalid manifest schema_version (expected 1)';
    if (empty($m['version'])) return 'Manifest missing version';
    if (empty($m['version_code']) || !is_int($m['version_code'])) return 'Manifest missing or invalid version_code';
    if (empty($m['requirements'])) return 'Manifest missing requirements';
    return null;
}

function getMariaDBVersion(PDO $pdo): string {
    $row = $pdo->query("SELECT VERSION() AS v")->fetch();
    // Strip -MariaDB suffix
    return preg_replace('/[^0-9.].*/', '', $row['v'] ?? '0');
}

function getCurrentVersion(): array {
    // Re-read VERSION.php to get fresh values
    $versionFile = getAppRoot() . '/VERSION.php';
    if (file_exists($versionFile)) {
        // Parse file directly to avoid cached defines
        $content = file_get_contents($versionFile);
        preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $content, $vm);
        preg_match("/define\('APP_VERSION_CODE',\s*(\d+)\)/", $content, $vc);
        preg_match("/define\('APP_VERSION_DATE',\s*'([^']+)'\)/", $content, $vd);
        return [
            'version'      => $vm[1] ?? (defined('APP_VERSION') ? APP_VERSION : '0.0.0'),
            'version_code' => (int)($vc[1] ?? (defined('APP_VERSION_CODE') ? APP_VERSION_CODE : 0)),
            'version_date' => $vd[1] ?? (defined('APP_VERSION_DATE') ? APP_VERSION_DATE : ''),
        ];
    }
    return [
        'version'      => defined('APP_VERSION') ? APP_VERSION : '0.0.0',
        'version_code' => defined('APP_VERSION_CODE') ? APP_VERSION_CODE : 0,
        'version_date' => defined('APP_VERSION_DATE') ? APP_VERSION_DATE : '',
    ];
}

// ── GitHub Update Config ────────────────────────────────────
// Repository to check for releases (owner/repo)
define('GITHUB_REPO', 'ChesnoTech/OEM_Activation_System');
define('GITHUB_API_BASE', 'https://api.github.com');
define('GITHUB_CACHE_TTL', 3600); // 1 hour cache

function getGitHubHeaders(): array {
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: OEM-Activation-System/' . (defined('APP_VERSION') ? APP_VERSION : '2.0.0'),
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    // Optional: use a GitHub token from env for higher rate limits (60/hr → 5000/hr)
    $token = $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?? '';
    if (!empty($token)) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    return $headers;
}

function fetchGitHubRelease(): ?array {
    $url = GITHUB_API_BASE . '/repos/' . GITHUB_REPO . '/releases/latest';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
            'header' => implode("\r\n", getGitHubHeaders()),
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;

    // Check HTTP status from response headers
    $status = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $m)) {
                $status = (int)$m[1];
            }
        }
    }
    if ($status !== 200) return null;

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['tag_name'])) return null;

    return $data;
}

function parseVersionFromTag(string $tag): string {
    // Strip leading 'v' or 'V'
    return ltrim($tag, 'vV');
}

function findUpgradeAsset(array $release): ?array {
    $assets = $release['assets'] ?? [];
    foreach ($assets as $asset) {
        $name = strtolower($asset['name'] ?? '');
        // Match upgrade-*.zip or *-upgrade-*.zip or upgrade_*.zip
        if (str_ends_with($name, '.zip') &&
            (str_contains($name, 'upgrade') || str_contains($name, 'update'))) {
            return [
                'name'          => $asset['name'],
                'size'          => $asset['size'] ?? 0,
                'download_url'  => $asset['browser_download_url'] ?? '',
                'content_type'  => $asset['content_type'] ?? '',
                'download_count'=> $asset['download_count'] ?? 0,
            ];
        }
    }
    // Fallback: first ZIP asset
    foreach ($assets as $asset) {
        if (str_ends_with(strtolower($asset['name'] ?? ''), '.zip')) {
            return [
                'name'          => $asset['name'],
                'size'          => $asset['size'] ?? 0,
                'download_url'  => $asset['browser_download_url'] ?? '',
                'content_type'  => $asset['content_type'] ?? '',
                'download_count'=> $asset['download_count'] ?? 0,
            ];
        }
    }
    return null;
}

// ── 0. Check GitHub for Updates ─────────────────────────────

function handle_upgrade_check_github(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $current = getCurrentVersion();
    $forceRefresh = !empty($json_input['force_refresh']);

    // Simple file-based cache
    $cacheFile = sys_get_temp_dir() . '/oem_github_release_cache.json';
    $cached = null;

    if (!$forceRefresh && file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cacheData) && ($cacheData['cached_at'] ?? 0) > time() - GITHUB_CACHE_TTL) {
            $cached = $cacheData;
        }
    }

    if ($cached) {
        $release = $cached['release'];
        $asset = $cached['asset'];
    } else {
        $release = fetchGitHubRelease();
        if (!$release) {
            jsonResponse([
                'success' => true,
                'update_available' => false,
                'error' => 'Could not reach GitHub API. Check network or rate limits.',
            ]);
            return;
        }

        $asset = findUpgradeAsset($release);

        // Cache the result
        file_put_contents($cacheFile, json_encode([
            'cached_at' => time(),
            'release'   => [
                'tag_name'    => $release['tag_name'],
                'name'        => $release['name'] ?? '',
                'body'        => $release['body'] ?? '',
                'published_at'=> $release['published_at'] ?? '',
                'html_url'    => $release['html_url'] ?? '',
                'prerelease'  => $release['prerelease'] ?? false,
                'draft'       => $release['draft'] ?? false,
            ],
            'asset' => $asset,
        ]));
    }

    $latestVersion = parseVersionFromTag($release['tag_name']);
    $updateAvailable = version_compare($latestVersion, $current['version'], '>');

    // Skip pre-releases and drafts
    if (!empty($release['prerelease']) || !empty($release['draft'])) {
        $updateAvailable = false;
    }

    jsonResponse([
        'success'          => true,
        'update_available' => $updateAvailable,
        'current_version'  => $current['version'],
        'latest_version'   => $latestVersion,
        'release' => [
            'tag'          => $release['tag_name'],
            'name'         => $release['name'] ?? '',
            'changelog'    => $release['body'] ?? '',
            'published_at' => $release['published_at'] ?? '',
            'url'          => $release['html_url'] ?? '',
            'prerelease'   => $release['prerelease'] ?? false,
        ],
        'asset' => $asset,
        'has_upgrade_package' => $asset !== null,
    ]);
}

// ── 0b. Download upgrade package from GitHub ────────────────

function handle_upgrade_download_github(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);
    set_time_limit(300);

    $downloadUrl = $json_input['download_url'] ?? '';
    $assetName = $json_input['asset_name'] ?? 'upgrade.zip';

    if (empty($downloadUrl)) {
        jsonResponse(['success' => false, 'error' => 'No download URL provided']);
        return;
    }

    // Validate URL is from GitHub
    if (!preg_match('#^https://github\.com/.+/releases/download/.+\.zip$#', $downloadUrl)) {
        jsonResponse(['success' => false, 'error' => 'Invalid GitHub release asset URL']);
        return;
    }

    // Check no active upgrade
    $stmt = $pdo->prepare("
        SELECT id, status FROM upgrade_history
        WHERE status NOT IN ('completed', 'failed', 'rolled_back')
        LIMIT 1
    ");
    $stmt->execute();
    $active = $stmt->fetch();
    if ($active) {
        jsonResponse([
            'success' => false,
            'error' => "Another upgrade (#{$active['id']}) is in progress. Complete or rollback it first.",
        ]);
        return;
    }

    // Download the file
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => true,
            'max_redirects' => 5,
            'header' => implode("\r\n", getGitHubHeaders()),
        ],
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'oem_upgrade_');
    $source = @fopen($downloadUrl, 'rb', false, $ctx);
    if (!$source) {
        @unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => 'Failed to download from GitHub. Check network or token.']);
        return;
    }

    $dest = fopen($tmpFile, 'wb');
    $bytes = stream_copy_to_stream($source, $dest);
    fclose($source);
    fclose($dest);

    if ($bytes === false || $bytes < 100) {
        @unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => 'Download produced empty file']);
        return;
    }

    // Validate ZIP and extract manifest
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
        @unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => 'Downloaded file is not a valid ZIP']);
        return;
    }

    $manifestJson = $zip->getFromName('manifest.json');
    if ($manifestJson === false) {
        $zip->close();
        @unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => 'ZIP missing manifest.json — not a valid upgrade package']);
        return;
    }

    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest)) {
        $zip->close();
        @unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => 'manifest.json is not valid JSON']);
        return;
    }

    $validationError = validateManifest($manifest);
    if ($validationError) {
        $zip->close();
        @unlink($tmpFile);
        jsonResponse(['success' => false, 'error' => $validationError]);
        return;
    }

    // Version compatibility check
    $current = getCurrentVersion();
    $minVersion = $manifest['min_current_version'] ?? '0.0.0';
    $maxVersion = $manifest['max_current_version'] ?? '99.99.99';

    if (version_compare($current['version'], $minVersion, '<') ||
        version_compare($current['version'], $maxVersion, '>')) {
        $zip->close();
        @unlink($tmpFile);
        jsonResponse([
            'success' => false,
            'error' => "Package requires version {$minVersion}–{$maxVersion}, current is {$current['version']}",
        ]);
        return;
    }

    $zip->close();

    // Move to upgrade directory
    $checksum = hash_file('sha256', $tmpFile);
    $storedName = "upgrade_{$manifest['version']}_{$checksum}.zip";
    $storedPath = getUpgradeDir() . '/' . $storedName;
    rename($tmpFile, $storedPath);

    // Create upgrade_history row
    $stmt = $pdo->prepare("
        INSERT INTO upgrade_history
            (from_version, to_version, from_version_code, to_version_code,
             status, manifest_json, package_filename, package_checksum,
             admin_id, admin_username)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $current['version'],
        $manifest['version'],
        $current['version_code'],
        $manifest['version_code'],
        json_encode($manifest),
        $storedName,
        $checksum,
        (int)$admin_session['admin_id'],
        $admin_session['username'],
    ]);
    $upgradeId = (int)$pdo->lastInsertId();

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'UPGRADE_DOWNLOADED_GITHUB',
        "Downloaded upgrade v{$manifest['version']} from GitHub (#{$upgradeId})"
    );

    jsonResponse([
        'success'    => true,
        'upgrade_id' => $upgradeId,
        'manifest'   => $manifest,
        'package'    => [
            'filename' => $storedName,
            'checksum' => $checksum,
            'size_mb'  => round(filesize($storedPath) / 1024 / 1024, 2),
        ],
    ]);
}

// ── 1. Get Status ───────────────────────────────────────────

function handle_upgrade_get_status(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $current = getCurrentVersion();
    $mariadbVersion = getMariaDBVersion($pdo);

    // Active (non-terminal) upgrade
    $stmt = $pdo->query("
        SELECT * FROM upgrade_history
        WHERE status NOT IN ('completed', 'failed', 'rolled_back')
        ORDER BY created_at DESC LIMIT 1
    ");
    $activeUpgrade = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Recent completed
    $stmt2 = $pdo->query("
        SELECT id, from_version, to_version, status, package_filename,
               error_message, started_at, completed_at, rolled_back_at,
               admin_username, created_at
        FROM upgrade_history
        ORDER BY created_at DESC LIMIT 10
    ");
    $recentUpgrades = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'success' => true,
        'data' => [
            'current_version'      => $current['version'],
            'current_version_code' => $current['version_code'],
            'current_version_date' => $current['version_date'],
            'php_version'          => PHP_VERSION,
            'mariadb_version'      => $mariadbVersion,
            'disk_free_mb'         => round(@disk_free_space(getAppRoot()) / 1024 / 1024),
            'active_upgrade'       => $activeUpgrade,
            'recent_upgrades'      => $recentUpgrades,
        ],
    ]);
}

// ── 2. Upload Package ───────────────────────────────────────

function handle_upgrade_upload_package(PDO $pdo, array $admin_session): void {
    requirePermission('system_settings', $admin_session);
    set_time_limit(120);

    if (!isset($_FILES['upgrade_package']) || $_FILES['upgrade_package']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['upgrade_package']['error'] ?? -1;
        jsonResponse(['success' => false, 'error' => "Upload failed (error code: $code)"]);
        return;
    }

    $file = $_FILES['upgrade_package'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        jsonResponse(['success' => false, 'error' => 'Only ZIP files are accepted']);
        return;
    }

    // Validate ZIP and extract manifest
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        jsonResponse(['success' => false, 'error' => 'Invalid or corrupted ZIP file']);
        return;
    }

    $manifestJson = $zip->getFromName('manifest.json');
    if ($manifestJson === false) {
        $zip->close();
        jsonResponse(['success' => false, 'error' => 'ZIP missing manifest.json at root level']);
        return;
    }

    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest)) {
        $zip->close();
        jsonResponse(['success' => false, 'error' => 'manifest.json is not valid JSON']);
        return;
    }

    $validationError = validateManifest($manifest);
    if ($validationError) {
        $zip->close();
        jsonResponse(['success' => false, 'error' => $validationError]);
        return;
    }

    // Version compatibility check
    $current = getCurrentVersion();
    $minVersion = $manifest['min_current_version'] ?? '0.0.0';
    $maxVersion = $manifest['max_current_version'] ?? '99.99.99';

    if (version_compare($current['version'], $minVersion, '<') ||
        version_compare($current['version'], $maxVersion, '>')) {
        $zip->close();
        jsonResponse([
            'success' => false,
            'error' => "This package requires version {$minVersion}–{$maxVersion}, but current is {$current['version']}",
        ]);
        return;
    }

    // Check no active upgrade in progress
    $stmt = $pdo->prepare("
        SELECT id, status FROM upgrade_history
        WHERE status NOT IN ('completed', 'failed', 'rolled_back')
        LIMIT 1
    ");
    $stmt->execute();
    $active = $stmt->fetch();
    if ($active) {
        $zip->close();
        jsonResponse([
            'success' => false,
            'error' => "Another upgrade (#{$active['id']}) is in progress (status: {$active['status']}). Complete or rollback it first.",
        ]);
        return;
    }

    $zip->close();

    // Store the package
    $checksum = hash_file('sha256', $file['tmp_name']);
    $storedName = "upgrade_{$manifest['version']}_{$checksum}.zip";
    $storedPath = getUpgradeDir() . '/' . $storedName;
    move_uploaded_file($file['tmp_name'], $storedPath);

    // Create upgrade_history row
    $stmt = $pdo->prepare("
        INSERT INTO upgrade_history
            (from_version, to_version, from_version_code, to_version_code,
             status, manifest_json, package_filename, package_checksum,
             admin_id, admin_username)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $current['version'],
        $manifest['version'],
        $current['version_code'],
        $manifest['version_code'],
        json_encode($manifest),
        $storedName,
        $checksum,
        (int)$admin_session['admin_id'],
        $admin_session['username'],
    ]);
    $upgradeId = (int)$pdo->lastInsertId();

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'UPGRADE_PACKAGE_UPLOADED',
        "Uploaded upgrade package v{$manifest['version']} (#{$upgradeId})"
    );

    jsonResponse([
        'success'    => true,
        'upgrade_id' => $upgradeId,
        'manifest'   => $manifest,
        'package'    => [
            'filename' => $storedName,
            'checksum' => $checksum,
            'size_mb'  => round(filesize($storedPath) / 1024 / 1024, 2),
        ],
    ]);
}

// ── 3. Pre-flight Checks ────────────────────────────────────

function handle_upgrade_preflight(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $upgradeId = (int)($json_input['upgrade_id'] ?? 0);
    $row = loadUpgradeRow($pdo, $upgradeId);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Upgrade not found']);
        return;
    }
    if (!in_array($row['status'], ['pending', 'preflight'])) {
        jsonResponse(['success' => false, 'error' => "Cannot run preflight from status: {$row['status']}"]);
        return;
    }

    $manifest = json_decode($row['manifest_json'], true);
    $reqs = $manifest['requirements'] ?? [];
    $checks = [];

    // PHP version
    $phpMin = $reqs['php_min'] ?? '8.0';
    $checks[] = [
        'name'     => 'PHP Version',
        'status'   => version_compare(PHP_VERSION, $phpMin, '>=') ? 'pass' : 'fail',
        'message'  => "Current: " . PHP_VERSION . ", Required: ≥{$phpMin}",
        'required' => true,
    ];

    // MariaDB version
    $dbMin = $reqs['mariadb_min'] ?? '10.6';
    $dbVersion = getMariaDBVersion($pdo);
    $checks[] = [
        'name'     => 'MariaDB Version',
        'status'   => version_compare($dbVersion, $dbMin, '>=') ? 'pass' : 'fail',
        'message'  => "Current: {$dbVersion}, Required: ≥{$dbMin}",
        'required' => true,
    ];

    // Disk space
    $diskMin = (int)($reqs['disk_mb_min'] ?? 200);
    $freeMB = round(@disk_free_space(getAppRoot()) / 1024 / 1024);
    $checks[] = [
        'name'     => 'Disk Space',
        'status'   => $freeMB >= $diskMin ? 'pass' : 'fail',
        'message'  => "Free: {$freeMB} MB, Required: ≥{$diskMin} MB",
        'required' => true,
    ];

    // PHP extensions
    $requiredExts = $reqs['php_extensions'] ?? ['pdo_mysql', 'json', 'mbstring', 'openssl', 'zip'];
    $missing = array_filter($requiredExts, fn($ext) => !extension_loaded($ext));
    $checks[] = [
        'name'     => 'PHP Extensions',
        'status'   => empty($missing) ? 'pass' : 'fail',
        'message'  => empty($missing) ? 'All required extensions loaded' : 'Missing: ' . implode(', ', $missing),
        'required' => true,
    ];

    // ZIP extension (needed for upgrade)
    $checks[] = [
        'name'     => 'ZIP Extension',
        'status'   => extension_loaded('zip') ? 'pass' : 'fail',
        'message'  => extension_loaded('zip') ? 'Available' : 'Required for package extraction',
        'required' => true,
    ];

    // Writable paths
    $writablePaths = $reqs['writable_paths'] ?? [getAppRoot()];
    foreach ($writablePaths as $wp) {
        $realPath = strpos($wp, '/') === 0 ? $wp : getAppRoot() . '/' . $wp;
        $checks[] = [
            'name'     => "Writable: {$wp}",
            'status'   => is_writable($realPath) ? 'pass' : 'fail',
            'message'  => is_writable($realPath) ? 'Writable' : 'Not writable — check permissions',
            'required' => true,
        ];
    }

    // Application root writable (always required)
    $checks[] = [
        'name'     => 'Application Root Writable',
        'status'   => is_writable(getAppRoot()) ? 'pass' : 'fail',
        'message'  => is_writable(getAppRoot()) ? 'Writable' : 'Not writable',
        'required' => true,
    ];

    // Backup directory writable
    $checks[] = [
        'name'     => 'Backup Directory',
        'status'   => is_writable(getBackupDir()) ? 'pass' : 'warn',
        'message'  => is_writable(getBackupDir()) ? 'Writable' : 'Not writable — backup may fail',
        'required' => false,
    ];

    // Version compatibility (double-check)
    $current = getCurrentVersion();
    $minV = $manifest['min_current_version'] ?? '0.0.0';
    $maxV = $manifest['max_current_version'] ?? '99.99.99';
    $versionOk = version_compare($current['version'], $minV, '>=') &&
                 version_compare($current['version'], $maxV, '<=');
    $checks[] = [
        'name'     => 'Version Compatibility',
        'status'   => $versionOk ? 'pass' : 'fail',
        'message'  => "Current: {$current['version']}, Required: {$minV}–{$maxV}",
        'required' => true,
    ];

    // Package integrity
    $pkgPath = getUpgradeDir() . '/' . $row['package_filename'];
    $pkgOk = file_exists($pkgPath) && hash_file('sha256', $pkgPath) === $row['package_checksum'];
    $checks[] = [
        'name'     => 'Package Integrity',
        'status'   => $pkgOk ? 'pass' : 'fail',
        'message'  => $pkgOk ? 'SHA256 checksum verified' : 'Package missing or checksum mismatch',
        'required' => true,
    ];

    $allPassed = !in_array(false, array_map(
        fn($c) => $c['required'] ? $c['status'] === 'pass' : true,
        $checks
    ));

    updateUpgradeStatus($pdo, $upgradeId, 'preflight', [
        'step_details' => json_encode(['preflight_checks' => $checks, 'all_passed' => $allPassed]),
    ]);

    jsonResponse([
        'success'    => true,
        'checks'     => $checks,
        'all_passed' => $allPassed,
    ]);
}

// ── 4. Create Backup ────────────────────────────────────────

function handle_upgrade_backup(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);
    set_time_limit(600);

    $upgradeId = (int)($json_input['upgrade_id'] ?? 0);
    $row = loadUpgradeRow($pdo, $upgradeId);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Upgrade not found']);
        return;
    }
    if ($row['status'] !== 'preflight') {
        jsonResponse(['success' => false, 'error' => "Backup requires preflight status, current: {$row['status']}"]);
        return;
    }

    updateUpgradeStatus($pdo, $upgradeId, 'backing_up');
    $backupDir = getBackupDir();
    $timestamp = date('Ymd_His');
    $results = ['db' => null, 'files' => null];

    // ── Database Backup ──
    try {
        $dbBackupFile = "pre_upgrade_{$upgradeId}_{$timestamp}_db.sql.gz";
        $dbBackupPath = $backupDir . '/' . $dbBackupFile;

        $dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'oem_activation';
        $dbUser = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'oem_user';
        $dbPass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';
        $dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';

        $cmd = sprintf(
            'mysqldump --single-transaction --routines --triggers --host=%s --port=%s --user=%s --password=%s %s 2>&1 | gzip > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($dbBackupPath)
        );

        $output = [];
        $retCode = 0;
        exec($cmd, $output, $retCode);

        if ($retCode !== 0 || !file_exists($dbBackupPath) || filesize($dbBackupPath) < 100) {
            throw new Exception('Database backup failed: ' . implode("\n", $output));
        }

        // Verify gzip integrity
        exec("gzip -t " . escapeshellarg($dbBackupPath) . " 2>&1", $gzOutput, $gzRet);
        if ($gzRet !== 0) {
            throw new Exception('Database backup integrity check failed');
        }

        $results['db'] = [
            'filename' => $dbBackupFile,
            'size_mb'  => round(filesize($dbBackupPath) / 1024 / 1024, 2),
        ];
    } catch (Exception $e) {
        error_log("Upgrade backup DB error: " . $e->getMessage());
        updateUpgradeStatus($pdo, $upgradeId, 'failed', [
            'error_message' => 'Database backup failed: ' . $e->getMessage(),
        ]);
        jsonResponse(['success' => false, 'error' => 'Database backup failed. Upgrade aborted.']);
        return;
    }

    // ── File Backup ──
    try {
        $fileBackupFile = "pre_upgrade_{$upgradeId}_{$timestamp}_files.tar.gz";
        $fileBackupPath = $backupDir . '/' . $fileBackupFile;
        $appRoot = getAppRoot();

        $cmd = sprintf(
            'tar -czf %s --exclude=uploads/upgrades --exclude=backups --exclude=node_modules --exclude=.git --exclude=vendor -C %s . 2>&1',
            escapeshellarg($fileBackupPath),
            escapeshellarg($appRoot)
        );

        $output = [];
        $retCode = 0;
        exec($cmd, $output, $retCode);

        if ($retCode !== 0 && $retCode !== 1) {
            // tar returns 1 for "file changed during archive" which is non-fatal
            throw new Exception('File backup failed (exit code: ' . $retCode . '): ' . implode("\n", $output));
        }

        if (!file_exists($fileBackupPath) || filesize($fileBackupPath) < 100) {
            throw new Exception('File backup produced empty or missing archive');
        }

        $results['files'] = [
            'filename' => $fileBackupFile,
            'size_mb'  => round(filesize($fileBackupPath) / 1024 / 1024, 2),
        ];
    } catch (Exception $e) {
        error_log("Upgrade backup files error: " . $e->getMessage());
        updateUpgradeStatus($pdo, $upgradeId, 'failed', [
            'error_message' => 'File backup failed: ' . $e->getMessage(),
        ]);
        jsonResponse(['success' => false, 'error' => 'File backup failed. Upgrade aborted.']);
        return;
    }

    // Record backup paths in upgrade row
    $stepDetails = json_decode($row['step_details'] ?? '{}', true) ?: [];
    $stepDetails['backup'] = $results;

    updateUpgradeStatus($pdo, $upgradeId, 'backing_up', [
        'backup_db_filename' => $backupDir . '/' . $dbBackupFile,
        'backup_files_path'  => $backupDir . '/' . $fileBackupFile,
        'step_details'       => json_encode($stepDetails),
    ]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'UPGRADE_BACKUP_CREATED',
        "Pre-upgrade backup for #{$upgradeId}: DB={$results['db']['size_mb']}MB, Files={$results['files']['size_mb']}MB"
    );

    jsonResponse([
        'success'     => true,
        'db_backup'   => $results['db'],
        'file_backup' => $results['files'],
    ]);
}

// ── 5. Apply Upgrade ────────────────────────────────────────

function handle_upgrade_apply(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);
    set_time_limit(600);

    $upgradeId = (int)($json_input['upgrade_id'] ?? 0);
    $row = loadUpgradeRow($pdo, $upgradeId);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Upgrade not found']);
        return;
    }
    if ($row['status'] !== 'backing_up') {
        jsonResponse(['success' => false, 'error' => "Apply requires backing_up status, current: {$row['status']}"]);
        return;
    }

    // Lock the row to prevent concurrent execution
    $pdo->beginTransaction();
    $lockStmt = $pdo->prepare("SELECT id FROM upgrade_history WHERE id = ? FOR UPDATE");
    $lockStmt->execute([$upgradeId]);
    $lockStmt->closeCursor();
    $pdo->commit();

    updateUpgradeStatus($pdo, $upgradeId, 'upgrading', [
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $manifest = json_decode($row['manifest_json'], true);
    $pkgPath = getUpgradeDir() . '/' . $row['package_filename'];
    $appRoot = getAppRoot();

    if (!file_exists($pkgPath)) {
        updateUpgradeStatus($pdo, $upgradeId, 'failed', [
            'error_message' => 'Upgrade package file not found',
        ]);
        jsonResponse(['success' => false, 'error' => 'Upgrade package file not found']);
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($pkgPath) !== true) {
        updateUpgradeStatus($pdo, $upgradeId, 'failed', [
            'error_message' => 'Cannot open upgrade package ZIP',
        ]);
        jsonResponse(['success' => false, 'error' => 'Cannot open upgrade package']);
        return;
    }

    $migrationsApplied = [];
    $filesChanged = [];

    // ── Phase 1: Database Migrations ──
    $migrations = $manifest['migrations'] ?? [];
    foreach ($migrations as $mig) {
        $migFile = $mig['file'] ?? '';
        $migVersion = (int)($mig['version'] ?? 0);

        // Check if already applied
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_versions WHERE filename = ?");
        $checkStmt->execute([basename($migFile)]);
        $alreadyApplied = (int)$checkStmt->fetchColumn() > 0;
        $checkStmt->closeCursor();
        if ($alreadyApplied) {
            $migrationsApplied[] = ['file' => $migFile, 'status' => 'skipped', 'message' => 'Already applied'];
            continue;
        }

        $migContent = $zip->getFromName($migFile);
        if ($migContent === false) {
            $zip->close();
            updateUpgradeStatus($pdo, $upgradeId, 'failed', [
                'error_message' => "Migration file not found in package: {$migFile}",
                'migrations_applied' => json_encode($migrationsApplied),
            ]);
            jsonResponse(['success' => false, 'error' => "Migration file not found: {$migFile}"]);
            return;
        }

        $ext = strtolower(pathinfo($migFile, PATHINFO_EXTENSION));

        try {
            if ($ext === 'sql') {
                // Use a separate PDO connection for migrations to avoid
                // unbuffered query conflicts with the main connection
                $migPdo = new PDO(
                    $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) ? $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'mysql',
                    null, null
                );
                // Actually, create a fresh connection from env vars
                $migDsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost',
                    $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306',
                    $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'oem_activation'
                );
                $migPdo = new PDO($migDsn,
                    $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'oem_user',
                    $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                     PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
                );
                $migPdo->exec($migContent);
                $migPdo = null; // close connection
            } elseif ($ext === 'php') {
                // Extract to temp and execute
                $tmpFile = sys_get_temp_dir() . '/upgrade_mig_' . $upgradeId . '_' . basename($migFile);
                file_put_contents($tmpFile, $migContent);
                require $tmpFile;
                @unlink($tmpFile);
            } else {
                throw new Exception("Unsupported migration type: {$ext}");
            }

            // Record in schema_versions
            $checksum = hash('sha256', $migContent);
            $svStmt = $pdo->prepare("INSERT INTO schema_versions (version, filename, checksum) VALUES (?, ?, ?)");
            $svStmt->execute([$migVersion, basename($migFile), $checksum]);
            $svStmt->closeCursor();

            $migrationsApplied[] = ['file' => $migFile, 'status' => 'applied'];
        } catch (Exception $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Exception $re) { /* ignore rollback error */ }
            $zip->close();
            error_log("Upgrade migration failed ({$migFile}): " . $e->getMessage());
            updateUpgradeStatus($pdo, $upgradeId, 'failed', [
                'error_message' => "Migration failed ({$migFile}): " . $e->getMessage(),
                'migrations_applied' => json_encode($migrationsApplied),
            ]);
            jsonResponse(['success' => false, 'error' => "Migration failed: {$migFile}", 'details' => $e->getMessage()]);
            return;
        }
    }

    // ── Phase 2: File Updates ──
    $files = $manifest['files'] ?? [];
    $stagingDir = sys_get_temp_dir() . '/upgrade_files_' . $upgradeId . '_' . time();
    @mkdir($stagingDir, 0755, true);

    // Extract all files to staging first
    foreach ($files as $fileOp) {
        $action = $fileOp['action'] ?? '';
        $target = $fileOp['target'] ?? '';
        $source = $fileOp['source'] ?? '';

        if ($action === 'replace' && !empty($source)) {
            $content = $zip->getFromName($source);
            if ($content === false) {
                $zip->close();
                updateUpgradeStatus($pdo, $upgradeId, 'failed', [
                    'error_message' => "File not found in package: {$source}",
                    'migrations_applied' => json_encode($migrationsApplied),
                    'files_changed' => json_encode($filesChanged),
                ]);
                jsonResponse(['success' => false, 'error' => "File not found in package: {$source}"]);
                return;
            }
            $stagingPath = $stagingDir . '/' . $target;
            @mkdir(dirname($stagingPath), 0755, true);
            file_put_contents($stagingPath, $content);
        }
    }

    // Apply files from staging
    foreach ($files as $fileOp) {
        $action = $fileOp['action'] ?? '';
        $target = $fileOp['target'] ?? '';

        $targetPath = $appRoot . '/' . $target;

        try {
            if ($action === 'replace') {
                $stagingPath = $stagingDir . '/' . $target;
                if (!file_exists($stagingPath)) {
                    throw new Exception("Staging file missing for: {$target}");
                }
                @mkdir(dirname($targetPath), 0755, true);
                // Use copy+unlink for cross-filesystem safety
                if (!copy($stagingPath, $targetPath)) {
                    throw new Exception("Failed to copy file to: {$target}");
                }
                $filesChanged[] = ['action' => 'replace', 'target' => $target, 'status' => 'ok'];
            } elseif ($action === 'delete') {
                if (file_exists($targetPath)) {
                    unlink($targetPath);
                }
                $filesChanged[] = ['action' => 'delete', 'target' => $target, 'status' => 'ok'];
            } elseif ($action === 'create_dir') {
                @mkdir($targetPath, 0755, true);
                $filesChanged[] = ['action' => 'create_dir', 'target' => $target, 'status' => 'ok'];
            }
        } catch (Exception $e) {
            error_log("Upgrade file operation failed ({$target}): " . $e->getMessage());
            $filesChanged[] = ['action' => $action, 'target' => $target, 'status' => 'failed', 'error' => $e->getMessage()];
            $zip->close();
            // Clean up staging
            exec('rm -rf ' . escapeshellarg($stagingDir));
            updateUpgradeStatus($pdo, $upgradeId, 'failed', [
                'error_message' => "File operation failed ({$target}): " . $e->getMessage(),
                'migrations_applied' => json_encode($migrationsApplied),
                'files_changed' => json_encode($filesChanged),
            ]);
            jsonResponse(['success' => false, 'error' => "File update failed: {$target}"]);
            return;
        }
    }

    $zip->close();

    // Clean up staging
    exec('rm -rf ' . escapeshellarg($stagingDir));

    // ── Phase 3: Update VERSION.php ──
    $newVersionContent = "<?php\n";
    $newVersionContent .= "/**\n * Application Version — OEM Activation System\n *\n";
    $newVersionContent .= " * This file is updated automatically by the upgrade system.\n";
    $newVersionContent .= " * Do NOT edit manually unless you know what you are doing.\n */\n";
    $newVersionContent .= "define('APP_VERSION', '" . addslashes($manifest['version']) . "');\n";
    $newVersionContent .= "define('APP_VERSION_CODE', " . (int)$manifest['version_code'] . ");\n";
    $newVersionContent .= "define('APP_VERSION_DATE', '" . addslashes($manifest['release_date'] ?? date('Y-m-d')) . "');\n";
    file_put_contents($appRoot . '/VERSION.php', $newVersionContent);

    // ── Phase 4: Post-upgrade tasks ──
    $postUpgrade = $manifest['post_upgrade'] ?? [];
    if (!empty($postUpgrade['clear_opcache']) && function_exists('opcache_reset')) {
        opcache_reset();
    }

    updateUpgradeStatus($pdo, $upgradeId, 'verifying', [
        'migrations_applied' => json_encode($migrationsApplied),
        'files_changed' => json_encode($filesChanged),
    ]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'] ?? 0,
        'UPGRADE_APPLIED',
        "Applied upgrade #{$upgradeId} to v{$manifest['version']}: " .
        count($migrationsApplied) . " migrations, " . count($filesChanged) . " files"
    );

    jsonResponse([
        'success'             => true,
        'migrations_applied'  => $migrationsApplied,
        'files_changed'       => $filesChanged,
    ]);
}

// ── 6. Verify Upgrade ───────────────────────────────────────

function handle_upgrade_verify(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $upgradeId = (int)($json_input['upgrade_id'] ?? 0);
    $row = loadUpgradeRow($pdo, $upgradeId);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Upgrade not found']);
        return;
    }
    if ($row['status'] !== 'verifying') {
        jsonResponse(['success' => false, 'error' => "Verify requires verifying status, current: {$row['status']}"]);
        return;
    }

    $manifest = json_decode($row['manifest_json'], true);
    $checks = [];

    // Version file updated
    $current = getCurrentVersion();
    $versionMatch = $current['version'] === $manifest['version'];
    $checks[] = [
        'name'     => 'Version Updated',
        'status'   => $versionMatch ? 'pass' : 'fail',
        'message'  => "Expected: {$manifest['version']}, Got: {$current['version']}",
        'required' => true,
    ];

    // Database accessible
    try {
        $pdo->query('SELECT 1');
        $checks[] = ['name' => 'Database Connection', 'status' => 'pass', 'message' => 'Connected', 'required' => true];
    } catch (Exception $e) {
        $checks[] = ['name' => 'Database Connection', 'status' => 'fail', 'message' => 'Failed', 'required' => true];
    }

    // Check replaced files exist
    $filesChanged = json_decode($row['files_changed'] ?? '[]', true) ?: [];
    $appRoot = getAppRoot();
    $fileMissing = 0;
    foreach ($filesChanged as $fc) {
        if ($fc['action'] === 'replace') {
            if (!file_exists($appRoot . '/' . $fc['target'])) {
                $fileMissing++;
            }
        }
    }
    $checks[] = [
        'name'     => 'Updated Files Present',
        'status'   => $fileMissing === 0 ? 'pass' : 'fail',
        'message'  => $fileMissing === 0 ? 'All files verified' : "{$fileMissing} file(s) missing",
        'required' => true,
    ];

    // Check migrations recorded
    $migrationsApplied = json_decode($row['migrations_applied'] ?? '[]', true) ?: [];
    $migAppliedCount = count(array_filter($migrationsApplied, fn($m) => ($m['status'] ?? '') === 'applied'));
    $checks[] = [
        'name'     => 'Migrations Recorded',
        'status'   => 'pass',
        'message'  => "{$migAppliedCount} migration(s) applied",
        'required' => false,
    ];

    // Disk space post-upgrade
    $freeMB = round(@disk_free_space($appRoot) / 1024 / 1024);
    $checks[] = [
        'name'     => 'Disk Space',
        'status'   => $freeMB > 100 ? 'pass' : 'warn',
        'message'  => "{$freeMB} MB free",
        'required' => false,
    ];

    // Health endpoint self-check
    try {
        $healthUrl = 'http://localhost/api/health.php';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $healthResp = @file_get_contents($healthUrl, false, $ctx);
        if ($healthResp) {
            $health = json_decode($healthResp, true);
            $checks[] = [
                'name'     => 'Health Endpoint',
                'status'   => ($health['status'] ?? '') === 'healthy' ? 'pass' : 'warn',
                'message'  => $health['status'] ?? 'Unknown',
                'required' => false,
            ];
        }
    } catch (Exception $e) {
        // Non-critical
    }

    $allPassed = !in_array(false, array_map(
        fn($c) => $c['required'] ? $c['status'] === 'pass' : true,
        $checks
    ));

    if ($allPassed) {
        updateUpgradeStatus($pdo, $upgradeId, 'completed', [
            'completed_at' => date('Y-m-d H:i:s'),
            'step_details' => json_encode(array_merge(
                json_decode($row['step_details'] ?? '{}', true) ?: [],
                ['verification' => $checks]
            )),
        ]);

        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'] ?? 0,
            'UPGRADE_COMPLETED',
            "System upgraded to v{$manifest['version']} (#{$upgradeId})"
        );
    }

    jsonResponse([
        'success'    => true,
        'checks'     => $checks,
        'all_passed' => $allPassed,
        'status'     => $allPassed ? 'completed' : 'verifying',
    ]);
}

// ── 7. Rollback ─────────────────────────────────────────────

function handle_upgrade_rollback(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);
    set_time_limit(600);

    $upgradeId = (int)($json_input['upgrade_id'] ?? 0);
    $row = loadUpgradeRow($pdo, $upgradeId);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'Upgrade not found']);
        return;
    }

    $allowedStatuses = ['failed', 'verifying', 'upgrading', 'backing_up'];
    if (!in_array($row['status'], $allowedStatuses)) {
        jsonResponse(['success' => false, 'error' => "Cannot rollback from status: {$row['status']}"]);
        return;
    }

    // Save rollback info before potentially restoring DB
    $rollbackInfo = [
        'upgrade_id'     => $upgradeId,
        'from_version'   => $row['from_version'],
        'to_version'     => $row['to_version'],
        'admin_id'       => (int)$admin_session['admin_id'],
        'admin_username' => $admin_session['username'],
        'rolled_back_at' => date('Y-m-d H:i:s'),
    ];

    $appRoot = getAppRoot();
    $errors = [];

    // ── Step 1: Restore files ──
    if (!empty($row['backup_files_path']) && file_exists($row['backup_files_path'])) {
        $cmd = sprintf(
            'tar -xzf %s -C %s 2>&1',
            escapeshellarg($row['backup_files_path']),
            escapeshellarg($appRoot)
        );
        $output = [];
        $retCode = 0;
        exec($cmd, $output, $retCode);
        if ($retCode !== 0 && $retCode !== 1) {
            $errors[] = 'File restoration had errors (code: ' . $retCode . ')';
            error_log("Rollback file restore error: " . implode("\n", $output));
        }
    } else {
        $errors[] = 'No file backup available for restoration';
    }

    // ── Step 2: Restore database ──
    if (!empty($row['backup_db_filename']) && file_exists($row['backup_db_filename'])) {
        $dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'oem_activation';
        $dbUser = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'oem_user';
        $dbPass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';
        $dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306';

        $cmd = sprintf(
            'zcat %s | mysql --host=%s --port=%s --user=%s --password=%s %s 2>&1',
            escapeshellarg($row['backup_db_filename']),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName)
        );
        $output = [];
        $retCode = 0;
        exec($cmd, $output, $retCode);
        if ($retCode !== 0) {
            $errors[] = 'Database restoration failed (code: ' . $retCode . ')';
            error_log("Rollback DB restore error: " . implode("\n", $output));
        }
    } else {
        $errors[] = 'No database backup available for restoration';
    }

    // ── Step 3: Clear opcache ──
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    // ── Step 4: Re-insert rollback record ──
    // After DB restore, the upgrade_history row is gone. Re-insert it.
    try {
        // Reconnect since DB was restored
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost',
            $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? '3306',
            $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'oem_activation'
        );
        $freshPdo = new PDO($dsn,
            $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'oem_user',
            $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $freshPdo->prepare("
            INSERT INTO upgrade_history
                (from_version, to_version, status, error_message,
                 admin_id, admin_username, rolled_back_at)
            VALUES (?, ?, 'rolled_back', ?, ?, ?, ?)
        ")->execute([
            $rollbackInfo['from_version'],
            $rollbackInfo['to_version'],
            !empty($errors) ? implode('; ', $errors) : null,
            $rollbackInfo['admin_id'],
            $rollbackInfo['admin_username'],
            $rollbackInfo['rolled_back_at'],
        ]);
    } catch (Exception $e) {
        error_log("Rollback: Failed to re-insert upgrade_history record: " . $e->getMessage());
        // Non-fatal — the rollback itself still worked
    }

    $success = empty($errors);

    jsonResponse([
        'success'  => $success,
        'message'  => $success ? 'System rolled back successfully' : 'Rollback completed with warnings',
        'warnings' => $errors,
    ]);
}

// ── 8. Upgrade History ──────────────────────────────────────

function handle_upgrade_history(PDO $pdo, array $admin_session, $json_input): void {
    requirePermission('system_settings', $admin_session);

    $stmt = $pdo->query("
        SELECT id, from_version, to_version, from_version_code, to_version_code,
               status, package_filename, error_message,
               started_at, completed_at, rolled_back_at,
               admin_id, admin_username, created_at
        FROM upgrade_history
        ORDER BY created_at DESC
        LIMIT 50
    ");

    jsonResponse([
        'success'  => true,
        'upgrades' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}
