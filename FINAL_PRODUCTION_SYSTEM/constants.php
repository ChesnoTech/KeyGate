<?php
/**
 * Application Constants
 * KeyGate v2.0
 *
 * Centralizes all magic numbers and configuration defaults.
 * Import this file wherever constants are needed.
 */

// ── Authentication ─────────────────────────────────────────────────
define('BCRYPT_COST', 12);
define('PASSWORD_MIN_LENGTH', 8);
define('TECH_ID_LENGTH', 5);
define('TECH_ID_PATTERN', '/^[A-Z0-9]{5}$/');
define('TECH_ID_API_PATTERN', '/^[A-Za-z0-9]{1,20}$/');
define('ORDER_NUMBER_PATTERN', '/^[A-Za-z0-9]{5}$/');  // Legacy fallback — dynamic pattern from system_config is preferred
define('PASSWORD_STRENGTH_PATTERN', '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/');  // Require uppercase, lowercase, and digit
define('ORDER_NUMBER_MAX_DB_LENGTH', 50);
define('PRODUCT_KEY_PATTERN', '/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/');

// ── Login Protection ───────────────────────────────────────────────
define('DEFAULT_MAX_FAILED_LOGINS', 5);
define('DEFAULT_LOCKOUT_MINUTES', 15);
define('DEFAULT_ADMIN_MAX_FAILED_LOGINS', 3);
define('DEFAULT_ADMIN_LOCKOUT_MINUTES', 30);
define('DEFAULT_ADMIN_PASSWORD_CHANGE_DAYS', 90);

// ── Session ────────────────────────────────────────────────────────
define('SESSION_INACTIVITY_SECONDS', 1800);          // 30 minutes
define('DEFAULT_SESSION_TIMEOUT_MINUTES', 30);
define('DEFAULT_ADMIN_SESSION_TIMEOUT_MINUTES', 30);
define('SESSION_TOKEN_BYTES', 32);
define('CSRF_TOKEN_BYTES', 32);

// ── Rate Limiting Defaults ─────────────────────────────────────────
define('RATE_LIMIT_DEFAULT_REQUESTS', 100);
define('RATE_LIMIT_DEFAULT_WINDOW', 60);              // seconds
define('RATE_LIMIT_REDIS_TIMEOUT', 5);                // seconds

// Per-endpoint rate limits: [max_requests, window_seconds]
define('RATE_LIMIT_LOGIN',                  [20,  3600]);
define('RATE_LIMIT_GET_KEY',                [100, 60]);
define('RATE_LIMIT_CHANGE_PASSWORD',        [10,  3600]);
define('RATE_LIMIT_REPORT_RESULT',          [50,  3600]);
define('RATE_LIMIT_AUTHENTICATE_USB',       [50,  3600]);
define('RATE_LIMIT_SUBMIT_HARDWARE',        [50,  3600]);
define('RATE_LIMIT_CHECK_USB_AUTH',          [100, 60]);
define('RATE_LIMIT_TOTP_VERIFY',            [20,  3600]);
define('RATE_LIMIT_TOTP_SETUP',             [10,  3600]);
define('RATE_LIMIT_TOTP_DISABLE',           [10,  3600]);
define('RATE_LIMIT_TOTP_REGENERATE_BACKUP', [5,   3600]);

// ── Pagination ─────────────────────────────────────────────────────
define('PAGINATION_KEYS', 50);
define('PAGINATION_TECHNICIANS', 50);
define('PAGINATION_HISTORY', 100);
define('PAGINATION_LOGS', 100);
define('PAGINATION_RECENT_ACTIVATIONS', 50);
define('SESSION_CLEANUP_BATCH', 1000);

// ── 2FA / TOTP ─────────────────────────────────────────────────────
define('BACKUP_CODE_COUNT', 10);
define('BACKUP_CODE_LENGTH', 8);
define('BACKUP_CODE_MAX_INT', 99999999);

// ── Database ───────────────────────────────────────────────────────
define('DB_DEFAULT_PORT', 3306);
define('DB_MAX_RETRIES', 3);
define('DB_RETRY_DELAY_US', 500000);                  // microseconds (0.5s)
define('DB_LOCK_TIMEOUT', 10);                        // seconds

// ── Security Headers ───────────────────────────────────────────────
define('HSTS_MAX_AGE', 31536000);                     // 1 year

// ── Display / Masking ──────────────────────────────────────────────
define('KEY_MASK_PREFIX_LEN', 5);
define('KEY_MASK_SUFFIX_LEN', 5);
define('ACTIVATION_ID_DISPLAY_LEN', 8);

// ── File Upload ────────────────────────────────────────────────────
define('CSV_MAX_SIZE_BYTES', 10 * 1024 * 1024);       // 10 MB
define('CSV_MAX_ERRORS_DISPLAY', 10);

// ── Alternative Server ─────────────────────────────────────────────
define('ALT_SERVER_DEFAULT_TIMEOUT', 300);             // seconds

// ── Max key fail counter before skip ───────────────────────────────
define('MAX_KEY_FAIL_COUNTER', 4);  // Must match $MaxRetryAttempts in main_v3.PS1

// ── Default Redis ──────────────────────────────────────────────────
define('REDIS_DEFAULT_PORT', 6379);
define('REDIS_DEFAULT_HOST', 'oem-activation-redis');
