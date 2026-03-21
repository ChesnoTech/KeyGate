<?php
/**
 * Health Check Endpoint
 * Returns system health status for monitoring / load balancers.
 *
 * GET /activate/api/health.php
 *
 * Response:
 *   200 — All checks pass
 *   503 — One or more checks failing
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$checks = [];
$healthy = true;

// ── Database ────────────────────────────────────────────────
try {
    require_once __DIR__ . '/../config.php';
    $pdo->query('SELECT 1');
    $checks['database'] = ['status' => 'ok'];
} catch (Throwable $e) {
    $checks['database'] = ['status' => 'fail', 'message' => 'Connection failed'];
    $healthy = false;
}

// ── Redis (rate limiter) ────────────────────────────────────
try {
    $redisHost = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?? 'oem-activation-redis';
    $redisPort = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?? 6379);
    $redis = new Redis();
    $connected = @$redis->connect($redisHost, $redisPort, 2);
    $redisPass = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?? '';
    if ($connected && $redisPass !== '') {
        @$redis->auth($redisPass);
    }
    if ($connected && @$redis->ping()) {
        $checks['redis'] = ['status' => 'ok'];
    } else {
        $checks['redis'] = ['status' => 'warn', 'message' => 'Not available (rate limiting degraded)'];
    }
} catch (Throwable $e) {
    $checks['redis'] = ['status' => 'warn', 'message' => 'Not available (rate limiting degraded)'];
}

// ── Disk Space ──────────────────────────────────────────────
$freeBytes = @disk_free_space('/');
if ($freeBytes !== false) {
    $freeMB = round($freeBytes / 1024 / 1024);
    $checks['disk'] = [
        'status' => $freeMB > 500 ? 'ok' : ($freeMB > 100 ? 'warn' : 'fail'),
        'free_mb' => $freeMB,
    ];
    if ($freeMB <= 100) $healthy = false;
} else {
    $checks['disk'] = ['status' => 'unknown'];
}

// ── PHP Extensions ──────────────────────────────────────────
$required = ['pdo_mysql', 'json', 'mbstring', 'openssl'];
$missing = array_filter($required, fn($ext) => !extension_loaded($ext));
$checks['php_extensions'] = empty($missing)
    ? ['status' => 'ok']
    : ['status' => 'fail', 'missing' => $missing];
if (!empty($missing)) $healthy = false;

// ── Application Version ────────────────────────────────────
if (defined('APP_VERSION')) {
    $checks['app_version'] = [
        'status'       => 'ok',
        'version'      => APP_VERSION,
        'version_code' => APP_VERSION_CODE,
        'version_date' => defined('APP_VERSION_DATE') ? APP_VERSION_DATE : '',
    ];
} else {
    $checks['app_version'] = ['status' => 'warn', 'message' => 'VERSION.php not loaded'];
}

// ── Response ────────────────────────────────────────────────
http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'healthy' : 'degraded',
    'timestamp' => gmdate('c'),
    'checks' => $checks,
], JSON_PRETTY_PRINT);
