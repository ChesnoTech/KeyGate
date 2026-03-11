<?php
/**
 * Check if USB authentication is enabled globally
 * Public endpoint - no authentication required
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::bootstrap('check-usb-auth', [], [
    'rate_limit' => RATE_LIMIT_CHECK_USB_AUTH,
    'require_json' => false,
]);

try {
    // Get USB authentication enabled setting
    $usbAuthEnabled = (bool)getConfig('usb_auth_enabled');

    jsonResponse([
        'success' => true,
        'usb_auth_enabled' => $usbAuthEnabled
    ]);

} catch (PDOException $e) {
    error_log("USB auth config check error: " . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Database error'], 503);
}
?>
