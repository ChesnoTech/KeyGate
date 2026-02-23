<?php
/**
 * Redis-Based Rate Limiter Middleware
 *
 * Implements distributed rate limiting using Redis to prevent brute force attacks
 * Uses sliding window algorithm for accurate rate limiting across multiple servers
 */

class RateLimiter {
    private $redis;
    private $defaultLimit;
    private $defaultWindow;
    private $redisHost;
    private $redisPort;
    private $redisPassword;
    private $connected = false;

    /**
     * Initialize Rate Limiter
     *
     * @param string $redisHost Redis server hostname
     * @param int $redisPort Redis server port
     * @param string $redisPassword Redis auth password
     */
    public function __construct($redisHost = null, $redisPort = null, $redisPassword = null) {
        $this->defaultLimit = defined('RATE_LIMIT_DEFAULT_REQUESTS') ? RATE_LIMIT_DEFAULT_REQUESTS : 100;
        $this->defaultWindow = defined('RATE_LIMIT_DEFAULT_WINDOW') ? RATE_LIMIT_DEFAULT_WINDOW : 60;
        $this->redisHost = $redisHost ?? (getenv('REDIS_HOST') ?: (defined('REDIS_DEFAULT_HOST') ? REDIS_DEFAULT_HOST : 'oem-activation-redis'));
        $this->redisPort = $redisPort ?? (getenv('REDIS_PORT') ?: (defined('REDIS_DEFAULT_PORT') ? REDIS_DEFAULT_PORT : 6379));
        $this->redisPassword = $redisPassword ?? getenv('REDIS_PASSWORD');

        if (empty($this->redisPassword)) {
            error_log("Rate limiter: REDIS_PASSWORD environment variable not set");
            $this->connected = false;
            return;
        }

        try {
            $this->redis = new Redis();
            $timeout = defined('RATE_LIMIT_REDIS_TIMEOUT') ? RATE_LIMIT_REDIS_TIMEOUT : 5;
            $this->redis->connect($this->redisHost, $this->redisPort, $timeout);
            $this->redis->auth($this->redisPassword);
            $this->redis->ping(); // Verify connection
            $this->connected = true;
        } catch (Exception $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            $this->connected = false;
        }
    }

    /**
     * Check if request should be rate limited
     *
     * @param string $identifier IP address or user ID
     * @param string $action Endpoint name (e.g., 'login', 'get-key')
     * @param int $limit Max requests allowed
     * @param int $window Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int, 'retry_after' => int]
     */
    public function checkLimit($identifier, $action, $limit = null, $window = null) {
        // Use default values if not specified
        $limit = $limit ?? $this->defaultLimit;
        $window = $window ?? $this->defaultWindow;

        // If Redis is not connected, deny the request (fail closed for security)
        if (!$this->connected) {
            error_log("Rate limiter: Redis not connected, denying request (fail-closed)");
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => time() + $window,
                'retry_after' => $window
            ];
        }

        try {
            // Calculate window start time (aligned to window boundary)
            $windowStart = floor(time() / $window) * $window;
            $key = "ratelimit:{$action}:{$identifier}:{$windowStart}";

            // Get current count
            $current = $this->redis->get($key);

            if ($current === false) {
                // First request in this window
                $this->redis->setex($key, $window, 1);
                return [
                    'allowed' => true,
                    'remaining' => $limit - 1,
                    'reset_at' => $windowStart + $window,
                    'retry_after' => 0
                ];
            }

            $current = (int)$current;

            if ($current >= $limit) {
                // Rate limit exceeded
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_at' => $windowStart + $window,
                    'retry_after' => ($windowStart + $window) - time()
                ];
            }

            // Increment counter
            $this->redis->incr($key);

            return [
                'allowed' => true,
                'remaining' => $limit - $current - 1,
                'reset_at' => $windowStart + $window,
                'retry_after' => 0
            ];
        } catch (Exception $e) {
            // Redis error - fail closed (deny request)
            error_log("Rate limiter error (fail-closed): " . $e->getMessage());
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => time() + $window,
                'retry_after' => $window
            ];
        }
    }

    /**
     * Log rate limit violation to database
     *
     * @param string $identifier IP address or user ID
     * @param string $action Endpoint action name
     * @param string $endpoint Full API endpoint path
     * @param int $requestCount Number of requests made
     * @param int $limitThreshold Limit that was exceeded
     * @param int $windowSeconds Time window
     */
    public function logViolation($identifier, $action, $endpoint, $requestCount, $limitThreshold, $windowSeconds) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO rate_limit_violations (
                    identifier, action, endpoint, client_ip, user_agent,
                    request_count, limit_threshold, window_seconds
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $identifier,
                $action,
                $endpoint,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $requestCount,
                $limitThreshold,
                $windowSeconds
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log rate limit violation: " . $e->getMessage());
        }
    }

    /**
     * Get current rate limit status for identifier
     *
     * @param string $identifier IP address or user ID
     * @param string $action Endpoint action name
     * @param int $window Time window in seconds
     * @return array ['current' => int, 'window_start' => int, 'window_end' => int]
     */
    public function getStatus($identifier, $action, $window = null) {
        $window = $window ?? $this->defaultWindow;

        if (!$this->connected) {
            return [
                'current' => 0,
                'window_start' => time(),
                'window_end' => time() + $window
            ];
        }

        try {
            $windowStart = floor(time() / $window) * $window;
            $key = "ratelimit:{$action}:{$identifier}:{$windowStart}";

            $current = $this->redis->get($key);

            return [
                'current' => $current !== false ? (int)$current : 0,
                'window_start' => $windowStart,
                'window_end' => $windowStart + $window
            ];
        } catch (Exception $e) {
            error_log("Rate limiter getStatus error: " . $e->getMessage());
            return [
                'current' => 0,
                'window_start' => time(),
                'window_end' => time() + $window
            ];
        }
    }

    /**
     * Reset rate limit for specific identifier (admin function)
     *
     * @param string $identifier IP address or user ID
     * @param string $action Endpoint action name
     * @return bool True if reset successful
     */
    public function reset($identifier, $action) {
        if (!$this->connected) {
            return false;
        }

        try {
            $pattern = "ratelimit:{$action}:{$identifier}:*";
            $keys = $this->redis->keys($pattern);

            if (!empty($keys)) {
                $this->redis->del($keys);
            }

            return true;
        } catch (Exception $e) {
            error_log("Rate limiter reset error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Close Redis connection
     */
    public function __destruct() {
        if ($this->connected && $this->redis) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                // Ignore close errors
            }
        }
    }
}
