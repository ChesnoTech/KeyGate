<?php
/**
 * Rate Limit Check Helper
 *
 * Include this file in API endpoints to enforce rate limiting
 * Usage: require_once 'rate-limit-check.php'; checkRateLimit('action_name', 100, 60);
 */

require_once __DIR__ . '/middleware/RateLimiter.php';
require_once __DIR__ . '/../functions/network-utils.php';

/**
 * Check rate limit for current request
 *
 * @param string $action Action name for this endpoint
 * @param int $limit Maximum requests allowed
 * @param int $window Time window in seconds
 * @param string|null $identifier Custom identifier (default: client IP)
 */
function checkRateLimit($action, $limit = RATE_LIMIT_DEFAULT_REQUESTS, $window = RATE_LIMIT_DEFAULT_WINDOW, $identifier = null) {
    global $pdo;

    // Get rate limiting configuration from database
    try {
        $stmt = $pdo->query("SELECT config_value FROM system_config WHERE config_key = 'rate_limit_enabled'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['config_value'] == '0') {
            // Rate limiting disabled in system config
            return;
        }
    } catch (PDOException $e) {
        // If config check fails, proceed with rate limiting for security
        error_log("Rate limit config check failed: " . $e->getMessage());
    }

    try {
        // RateLimiter reads Redis connection details from environment variables
        $rateLimiter = new RateLimiter();

        // Use custom identifier or client IP
        $identifier = $identifier ?? getClientIP();

        // Check rate limit
        $result = $rateLimiter->checkLimit($identifier, $action, $limit, $window);

        // Add rate limit headers to response
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset_at']);

        if (!$result['allowed']) {
            // Rate limit exceeded - log violation
            $rateLimiter->logViolation(
                $identifier,
                $action,
                $_SERVER['REQUEST_URI'] ?? 'unknown',
                $limit + 1, // Approximate request count
                $limit,
                $window
            );

            // Send 429 Too Many Requests response
            http_response_code(429);
            header('Retry-After: ' . $result['retry_after']);
            header('Content-Type: application/json');

            echo json_encode([
                'success' => false,
                'error' => 'Too many requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $result['retry_after'],
                'limit' => $limit,
                'window_seconds' => $window,
                'reset_at' => date('Y-m-d H:i:s', $result['reset_at'])
            ]);

            exit;
        }

    } catch (Exception $e) {
        // Rate limiter initialization failed - fail closed for security
        error_log("Rate limiter initialization failed (fail-closed): " . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Service temporarily unavailable',
            'message' => 'Rate limiting service is not available. Please try again later.'
        ]);
        exit;
    }
}

/**
 * Get custom rate limit for specific action from database config
 *
 * @param string $action Action name
 * @return array ['limit' => int, 'window' => int] or null if not configured
 */
function getCustomRateLimit($action) {
    global $pdo;

    $configMap = [
        'login' => ['limit_key' => 'rate_limit_login_per_hour', 'window' => 3600],
        'get-key' => ['limit_key' => 'rate_limit_get_key_per_minute', 'window' => 60],
        'report-result' => ['limit_key' => 'rate_limit_report_per_hour', 'window' => 3600],
        'authenticate-usb' => ['limit_key' => 'rate_limit_usb_auth_per_hour', 'window' => 3600],
    ];

    if (!isset($configMap[$action])) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $stmt->execute([$configMap[$action]['limit_key']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            return [
                'limit' => (int)$result['config_value'],
                'window' => $configMap[$action]['window']
            ];
        }
    } catch (PDOException $e) {
        error_log("Failed to get custom rate limit: " . $e->getMessage());
    }

    return null;
}
