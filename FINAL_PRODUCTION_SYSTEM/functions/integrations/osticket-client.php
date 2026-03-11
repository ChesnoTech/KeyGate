<?php
/**
 * osTicket API Client
 * Wraps osTicket REST API for ticket creation and management.
 */

/**
 * Test connection to osTicket instance
 */
function osticket_test_connection(array $config): array {
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $apiKey  = $config['api_key'] ?? '';

    if (empty($baseUrl) || empty($apiKey)) {
        return ['success' => false, 'error' => 'Base URL and API key are required'];
    }

    // osTicket doesn't have a dedicated health endpoint; attempt to list tickets
    $ch = curl_init("$baseUrl/api/tickets.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "X-API-Key: $apiKey",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => 'GET',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Connection error: $error"];
    }

    // osTicket returns 200 or 405 (if GET not allowed but server is reachable)
    if ($httpCode >= 200 && $httpCode < 500) {
        return ['success' => true, 'message' => "Connected to osTicket (HTTP $httpCode)"];
    }

    return ['success' => false, 'error' => "osTicket returned HTTP $httpCode"];
}

/**
 * Create a new ticket in osTicket
 */
function osticket_create_ticket(array $config, array $data): array {
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $apiKey  = $config['api_key'] ?? '';

    $ticketData = [
        'name'    => $data['technician_name'] ?? 'System',
        'email'   => $data['technician_email'] ?? 'system@localhost',
        'subject' => $data['subject'] ?? 'Build Order',
        'message' => $data['message'] ?? '',
    ];

    // Optional fields
    if (!empty($config['department_id'])) {
        $ticketData['deptId'] = (int)$config['department_id'];
    }
    if (!empty($config['topic_id'])) {
        $ticketData['topicId'] = (int)$config['topic_id'];
    }

    $ch = curl_init("$baseUrl/api/tickets.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($ticketData),
        CURLOPT_HTTPHEADER => [
            "X-API-Key: $apiKey",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error, 'http_code' => 0];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success'   => true,
            'http_code' => $httpCode,
            'response'  => $response,
            'ticket_id' => trim($response, '"'),
        ];
    }

    return [
        'success'   => false,
        'http_code' => $httpCode,
        'response'  => $response,
        'error'     => "osTicket returned HTTP $httpCode: $response",
    ];
}

/**
 * Add a reply/note to an existing ticket
 */
function osticket_add_note(array $config, string $ticketNumber, string $message): array {
    $baseUrl = rtrim($config['base_url'] ?? '', '/');
    $apiKey  = $config['api_key'] ?? '';

    // osTicket API for adding notes: POST /api/tickets/{number}/notes.json
    $ch = curl_init("$baseUrl/api/tickets/$ticketNumber/notes.json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'note' => $message,
            'title' => 'Activation Update',
        ]),
        CURLOPT_HTTPHEADER => [
            "X-API-Key: $apiKey",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
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
