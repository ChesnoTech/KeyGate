<?php
/**
 * API Middleware - Shared bootstrap for all API endpoints
 * Extracted from duplicated boilerplate (Phase 4 refactoring)
 *
 * Usage:
 *   require_once __DIR__ . '/middleware/ApiMiddleware.php';
 *   $input = ApiMiddleware::bootstrap('login', ['technician_id', 'password'], [
 *       'rate_limit' => RATE_LIMIT_LOGIN,
 *       'require_powershell' => true,
 *   ]);
 */

class ApiMiddleware
{
    /**
     * Full endpoint bootstrap: User-Agent check, POST validation,
     * rate limiting, JSON parsing, required field validation.
     *
     * @param string $action          Rate limit action name
     * @param array  $requiredFields  Fields that must be non-empty in input
     * @param array  $options         Optional overrides:
     *   'rate_limit'         => [requests, window] or false to skip
     *   'require_powershell' => bool (default true)
     *   'method'             => 'POST'|'GET' (default 'POST')
     *   'require_json'       => bool (default true)
     * @return array  Parsed JSON input
     */
    public static function bootstrap(string $action, array $requiredFields = [], array $options = []): array
    {
        $requirePowerShell = $options['require_powershell'] ?? true;
        $method = $options['method'] ?? 'POST';
        $requireJson = $options['require_json'] ?? true;
        $rateLimit = $options['rate_limit'] ?? null;

        if ($requirePowerShell) {
            self::requirePowerShell();
        }

        self::requireMethod($method);

        if ($rateLimit !== false && $rateLimit !== null) {
            require_once __DIR__ . '/RateLimiter.php';
            RateLimiter::enforce($action, $rateLimit[0], $rateLimit[1]);
        }

        $input = [];
        if ($requireJson) {
            $input = self::parseJsonInput();
        }

        if (!empty($requiredFields)) {
            self::requireFields($input, $requiredFields);
        }

        return $input;
    }

    /**
     * Block non-PowerShell User-Agents (browser protection).
     */
    public static function requirePowerShell(): void
    {
        if (isset($_SERVER['HTTP_USER_AGENT']) && !stristr($_SERVER['HTTP_USER_AGENT'], 'PowerShell')) {
            http_response_code(403);
            die("Access denied. API access only.");
        }
    }

    /**
     * Validate HTTP method.
     */
    public static function requireMethod(string $method = 'POST'): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            jsonResponse(['error' => "Only $method requests allowed"], 405);
        }
    }

    /**
     * Parse JSON from php://input with error handling.
     * @return array Parsed input (empty array if no body)
     */
    public static function parseJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            jsonResponse(['error' => 'Empty request body'], 400);
        }

        $input = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(['error' => 'Invalid JSON: ' . json_last_error_msg()], 400);
        }

        return $input ?? [];
    }

    /**
     * Validate that required fields are present and non-empty.
     */
    public static function requireFields(array $input, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            jsonResponse([
                'error' => 'Missing required fields: ' . implode(', ', $missing),
                'error_code' => 'MISSING_FIELDS'
            ], 400);
        }
    }

    /**
     * Validate technician ID format.
     */
    public static function validateTechnicianId(string $techId): void
    {
        if (!preg_match(TECH_ID_API_PATTERN, $techId)) {
            jsonResponse([
                'error' => 'Invalid technician ID format',
                'error_code' => 'INVALID_TECH_ID'
            ], 400);
        }
    }

    /**
     * Validate order number format using dynamic config or fallback constant.
     */
    public static function validateOrderNumber(string $orderNumber): void
    {
        try {
            $config = getOrderFieldConfig();
            $pattern = buildOrderNumberPattern($config);
        } catch (\Throwable $e) {
            // Fallback to hardcoded pattern if config unavailable
            $pattern = ORDER_NUMBER_PATTERN;
        }

        if (!preg_match($pattern, $orderNumber)) {
            jsonResponse([
                'error' => 'Invalid order number format',
                'error_code' => 'INVALID_ORDER_NUMBER'
            ], 400);
        }
    }
}
