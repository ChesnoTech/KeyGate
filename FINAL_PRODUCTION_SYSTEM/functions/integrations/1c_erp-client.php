<?php
/**
 * 1C Enterprise ERP API Client (v8+)
 * Connects to 1C HTTP services for data exchange.
 */

/**
 * Test connection to 1C Enterprise instance
 */
function onec_erp_test_connection(array $config): array {
    // Map to function name convention: 1c_erp → onec_erp
    return _1c_erp_test($config);
}

// Alias for the integration framework's naming convention
function _1c_erp_test(array $config): array {
    $baseUrl  = rtrim($config['base_url'] ?? '', '/');
    $authType = $config['auth_type'] ?? 'basic';
    $username = $config['username'] ?? '';
    $password = $config['password'] ?? '';

    if (empty($baseUrl)) {
        return ['success' => false, 'error' => '1C base URL is required'];
    }

    // Try to reach the base URL or a metadata endpoint
    $testUrl = $baseUrl;
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOBODY => false,
    ]);

    if ($authType === 'basic' && $username) {
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Connection error: $error"];
    }

    if ($httpCode >= 200 && $httpCode < 400) {
        return ['success' => true, 'message' => "Connected to 1C Enterprise (HTTP $httpCode)"];
    }

    if ($httpCode === 401) {
        return ['success' => false, 'error' => 'Authentication failed — check username and password'];
    }

    return ['success' => false, 'error' => "1C returned HTTP $httpCode"];
}

/**
 * Push an activation record to 1C
 */
function onec_push_activation(array $config, array $data): array {
    $baseUrl  = rtrim($config['base_url'] ?? '', '/');
    $endpoint = $config['endpoint_activations'] ?? '/api/hs/activations';
    $url = $baseUrl . $endpoint;

    $postData = [
        'order_number'      => $data['order_number'] ?? '',
        'product_key'       => $data['product_key'] ?? '',
        'activation_result' => $data['activation_result'] ?? '',
        'technician_id'     => $data['technician_id'] ?? '',
        'technician_name'   => $data['technician_name'] ?? '',
        'activated_at'      => $data['activated_at'] ?? date('c'),
    ];

    if (!empty($data['hardware'])) {
        $postData['hardware'] = $data['hardware'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $authType = $config['auth_type'] ?? 'basic';
    if ($authType === 'basic' && !empty($config['username'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $config['username'] . ':' . ($config['password'] ?? ''));
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error, 'http_code' => 0];
    }

    return [
        'success'   => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response'  => $response,
        'error'     => ($httpCode >= 300) ? "HTTP $httpCode: $response" : null,
    ];
}
