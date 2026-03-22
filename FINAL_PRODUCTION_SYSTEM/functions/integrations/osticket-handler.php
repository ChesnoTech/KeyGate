<?php
/**
 * osTicket Event Handler
 * Maps OEM system events to osTicket API calls.
 */

require_once __DIR__ . '/osticket-client.php';

/**
 * Handle an integration event for osTicket
 *
 * @param string $eventType  Event type (key_assigned, activation_complete, etc.)
 * @param array  $payload    Event payload data
 * @param array  $config     Integration config from DB
 * @return array Result with success, http_code, response, error keys
 */
function handle_osticket_event(string $eventType, array $payload, array $config): array {
    switch ($eventType) {
        case 'key_assigned':
            return handle_osticket_key_assigned($payload, $config);

        case 'activation_complete':
            return handle_osticket_activation_complete($payload, $config);

        default:
            return ['success' => true, 'response' => 'Event type not handled by osTicket'];
    }
}

/**
 * Key assigned → Create a new ticket for the build order
 */
function handle_osticket_key_assigned(array $payload, array $config): array {
    if (empty($config['auto_create_ticket'])) {
        return ['success' => true, 'response' => 'Auto-create disabled'];
    }

    $orderNumber = $payload['order_number'] ?? 'N/A';
    $techName    = $payload['technician_name'] ?? 'Unknown';
    $techId      = $payload['technician_id'] ?? '';
    $productKey  = $payload['product_key'] ?? '';

    // Build subject from template
    $subjectTemplate = $config['ticket_subject_template'] ?? 'Build Order #{order_number}';
    $subject = str_replace('{order_number}', $orderNumber, $subjectTemplate);

    // Build message body
    $message  = "Build Order: $orderNumber\n";
    $message .= "Technician: $techName (ID: $techId)\n";
    $message .= "Product Key: " . substr($productKey, 0, 5) . "***\n";
    $message .= "Status: Key Assigned - Awaiting Activation\n";
    $message .= "\n---\nAutomatically created by KeyGate";

    return osticket_create_ticket($config, [
        'technician_name'  => $techName,
        'technician_email' => $payload['technician_email'] ?? ($config['default_email'] ?? 'system@localhost'),
        'subject'          => $subject,
        'message'          => $message,
    ]);
}

/**
 * Activation complete → Add a note/reply to existing ticket
 */
function handle_osticket_activation_complete(array $payload, array $config): array {
    if (empty($config['auto_reply_on_activation'])) {
        return ['success' => true, 'response' => 'Auto-reply disabled'];
    }

    $orderNumber = $payload['order_number'] ?? 'N/A';
    $result      = $payload['activation_result'] ?? 'unknown';
    $techName    = $payload['technician_name'] ?? 'Unknown';

    // Build note message
    $message  = "Activation Result: " . strtoupper($result) . "\n";
    $message .= "Order: $orderNumber\n";
    $message .= "Technician: $techName\n";

    if (!empty($config['include_hardware_details']) && !empty($payload['hardware'])) {
        $hw = $payload['hardware'];
        $message .= "\nHardware Details:\n";
        $message .= "  Motherboard: " . ($hw['motherboard_manufacturer'] ?? '') . " " . ($hw['motherboard_product'] ?? '') . "\n";
        $message .= "  BIOS: " . ($hw['bios_version'] ?? '') . "\n";
        $message .= "  CPU: " . ($hw['cpu_name'] ?? '') . "\n";
        $message .= "  RAM: " . ($hw['total_ram_gb'] ?? '') . " GB\n";
    }

    $message .= "\n---\nAutomatically updated by KeyGate";

    // We need to find the ticket by order number. Since osTicket doesn't have
    // great search API, we store the ticket number from creation. For now, add
    // a note referencing the order number.
    // TODO: Store ticket reference in integration_events or a lookup table
    // For now, create a new ticket note if we don't have a reference
    if (!empty($payload['ticket_number'])) {
        return osticket_add_note($config, $payload['ticket_number'], $message);
    }

    // Fallback: create a follow-up ticket with result
    $subjectTemplate = $config['ticket_subject_template'] ?? 'Build Order #{order_number}';
    $subject = str_replace('{order_number}', $orderNumber, $subjectTemplate) . ' - Activation ' . ucfirst($result);

    return osticket_create_ticket($config, [
        'technician_name'  => $techName,
        'technician_email' => $payload['technician_email'] ?? ($config['default_email'] ?? 'system@localhost'),
        'subject'          => $subject,
        'message'          => $message,
    ]);
}
