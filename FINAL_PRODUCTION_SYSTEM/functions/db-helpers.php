<?php
/**
 * KeyGate Database Helpers — table-prefix aware naming
 *
 * Loaded from constants.php (very early), so every PHP entry point has
 * access to t() before any controller/middleware fires its first query.
 *
 * Convention:
 *   - SQL files use `#__tablename` sentinel.
 *   - PHP runtime uses `t('tablename')` returning DB_PREFIX . 'tablename'.
 *   - DB_PREFIX is defined in config.php (emitted by web installer) or
 *     defaults to '' (empty) for backward-compat with existing installs.
 */

if (!defined('DB_PREFIX')) {
    // Backward-compat fallback: every legacy install without DB_PREFIX
    // gets an empty string → no behavior change vs pre-prefix release.
    define('DB_PREFIX', '');
}

if (!function_exists('t')) {
    /**
     * Resolve a logical table name to its actual database identifier.
     *
     * Example: t('oem_keys') === DB_PREFIX . 'oem_keys'
     *
     * The function is intentionally tiny and inline-safe so it disappears
     * after PHP opcache compilation. No validation — caller is trusted
     * to pass a known table name.
     *
     * @param string $name Logical table name (without prefix).
     * @return string Actual database identifier.
     */
    function t(string $name): string {
        return DB_PREFIX . $name;
    }
}
