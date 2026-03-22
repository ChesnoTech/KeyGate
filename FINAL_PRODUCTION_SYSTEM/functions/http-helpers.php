<?php
/**
 * HTTP Response & Request Helpers
 * KeyGate v2.0
 *
 * Extracted from config.php — contains jsonResponse(), getClientIP().
 */

/**
 * Send a JSON response with security headers and exit.
 */
function jsonResponse($data, $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');

    $allowedOrigins = array_filter(array_map('trim', explode(',', getenv('CORS_ORIGINS') ?: '')));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!empty($allowedOrigins) && in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    }

    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Enhanced client IP detection.
 *
 * Checks proxy headers for public IP first, then private IPs
 * (Docker/local network), then falls back to REMOTE_ADDR.
 */
function getClientIP() {
    $proxyHeaders = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
    ];

    // Priority 1: Public IPs from proxy headers
    foreach ($proxyHeaders as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    // Priority 2: Private IPs from proxy headers (Docker/local network)
    foreach ($proxyHeaders as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    // Priority 3: Direct connection
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
