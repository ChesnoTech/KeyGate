<?php
/**
 * Application Bootstrap & Database Configuration
 * KeyGate v2.0
 *
 * This file:
 *  1. Loads constants
 *  2. Creates the PDO database connection (with retry logic)
 *  3. Provides getConfig() for reading system_config rows
 *  4. Includes helper modules (http, session, key, order-field)
 *
 * All utility functions live in functions/*.php — this file is
 * intentionally kept small so that "require config.php" is cheap
 * and easy to reason about.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/VERSION.php';

// ── Environment Detection ───────────────────────────────────────
$isProduction = !in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', 'activate.local']);

// ── Database Configuration ──────────────────────────────────────
$db_config = [
    'host'     => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost',
    'dbname'   => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'oem_activation_prod',
    'username' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'oem_user',
    'password' => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? 'CHANGE_THIS_PASSWORD',
    'charset'  => 'utf8mb4',
    'port'     => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?? DB_DEFAULT_PORT,
];

if ($isProduction) {
    if ($db_config['password'] === 'CHANGE_THIS_PASSWORD' || empty($db_config['password'])) {
        error_log("SECURITY WARNING: Default database password detected in production!");
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            die(json_encode(['error' => 'Database configuration required']));
        }
    }
}

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
// Config precedence (highest → lowest):
//   1. .env / environment variables  — infrastructure secrets (DB_HOST, REDIS_PASSWORD, etc.)
//   2. system_config table           — admin-configurable at runtime (session timeout, rate limits, branding)
//   3. constants.php                 — immutable defaults, used as fallback when DB value is absent
//
// Use getConfig() for raw DB lookups.
// Use getConfigWithDefault() when a constant fallback is needed (most common case).
$configCache = [];

/**
 * Read a value from the system_config table (cached per request).
 *
 * @param string $key       Config key name
 * @param bool   $useCache  Whether to use per-request cache (default true)
 * @return string|null       Config value or null if not set
 */
function getConfig($key, $useCache = true) {
    global $pdo, $configCache;

    if ($useCache && isset($configCache[$key])) {
        return $configCache[$key];
    }

    try {
        $stmt = $pdo->prepare("SELECT config_value FROM `" . t('system_config') . "` WHERE config_key = ?");
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

/**
 * Read a config value from system_config with a constant/default fallback.
 *
 * Replaces the widespread pattern: getConfig('key') ?: SOME_CONSTANT
 * Treats empty strings as missing (falls back to default).
 *
 * @param string $key      Config key in system_config
 * @param mixed  $default  Fallback value (typically a constant from constants.php)
 * @return mixed           DB value if non-empty, otherwise $default
 */
function getConfigWithDefault($key, $default) {
    $value = getConfig($key);
    // Treat null and empty string as "not configured"
    if ($value === null || $value === '') {
        return $default;
    }
    return $value;
}

// ── Order-field config helpers (used by ApiMiddleware + login) ───
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

// ── Timezone ────────────────────────────────────────────────────
$timezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? 'UTC';
if (!in_array($timezone, timezone_identifiers_list())) {
    error_log("Invalid timezone '$timezone', falling back to UTC");
    $timezone = 'UTC';
}
date_default_timezone_set($timezone);

// ── Startup log ─────────────────────────────────────────────────
error_log("KeyGate configuration loaded successfully. Environment: " .
    ($isProduction ? "Production" : "Development"));
?>
