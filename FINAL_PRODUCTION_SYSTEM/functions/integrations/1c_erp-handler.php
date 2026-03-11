<?php
/**
 * 1C ERP Event Handler
 * Maps OEM system events to 1C Enterprise API calls.
 */

require_once __DIR__ . '/1c_erp-client.php';

/**
 * Handle an integration event for 1C Enterprise
 */
function handle_1c_erp_event(string $eventType, array $payload, array $config): array {
    switch ($eventType) {
        case 'activation_complete':
            if (empty($config['push_activations'])) {
                return ['success' => true, 'response' => 'Push activations disabled'];
            }
            return onec_push_activation($config, $payload);

        case 'key_assigned':
            if (empty($config['push_key_usage'])) {
                return ['success' => true, 'response' => 'Push key usage disabled'];
            }
            return onec_push_activation($config, array_merge($payload, [
                'activation_result' => 'key_assigned',
            ]));

        default:
            return ['success' => true, 'response' => 'Event type not handled by 1C'];
    }
}
