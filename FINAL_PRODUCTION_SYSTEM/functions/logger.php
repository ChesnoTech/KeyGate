<?php
/**
 * Structured JSON Logger
 * OEM Activation System v2.0
 *
 * Outputs one JSON object per line to PHP's error_log.
 * Designed for easy parsing by log aggregators (ELK, Loki, CloudWatch, etc.).
 *
 * Usage:
 *   require_once __DIR__ . '/logger.php';
 *   appLog('warning', 'Login failed', ['username' => 'admin', 'ip' => '1.2.3.4']);
 *
 * Levels: debug, info, notice, warning, error, critical
 */

/**
 * Write a structured JSON log entry.
 *
 * @param string $level   Log level (debug|info|notice|warning|error|critical)
 * @param string $message Human-readable message
 * @param array  $context Additional key-value pairs (never include secrets)
 */
function appLog(string $level, string $message, array $context = []): void {
    $entry = [
        'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
        'level'   => $level,
        'msg'     => $message,
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        'method'  => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri'     => $_SERVER['REQUEST_URI'] ?? null,
    ];

    // Merge caller-supplied context (overwrites colliding keys intentionally)
    if (!empty($context)) {
        $entry = array_merge($entry, $context);
    }

    // Strip null values to keep lines short
    $entry = array_filter($entry, static fn($v) => $v !== null);

    error_log(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
