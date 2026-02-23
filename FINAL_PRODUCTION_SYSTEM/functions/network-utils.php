<?php
/**
 * Network Utility Functions
 *
 * Functions for validating IP addresses against trusted network subnets
 * Used for 2FA bypass and USB authentication network restrictions
 */

/**
 * Check if an IP address is within a trusted network
 *
 * @param string $ip IP address to check
 * @param bool $checkUSBAuth True to check USB auth permission, false to check 2FA bypass
 * @return array|null Network info if IP is in trusted network, null otherwise
 */
function checkTrustedNetwork($ip, $checkUSBAuth = false) {
    global $pdo;

    // Build query based on what we're checking
    if ($checkUSBAuth) {
        // Check if network allows USB authentication
        $stmt = $pdo->prepare("
            SELECT id, network_name, ip_range, bypass_2fa, allow_usb_auth
            FROM trusted_networks
            WHERE is_active = 1 AND allow_usb_auth = 1
        ");
    } else {
        // Check if network allows 2FA bypass
        $stmt = $pdo->prepare("
            SELECT id, network_name, ip_range, bypass_2fa, allow_usb_auth
            FROM trusted_networks
            WHERE is_active = 1 AND bypass_2fa = 1
        ");
    }

    $stmt->execute();
    $networks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($networks as $network) {
        if (isIPInRange($ip, $network['ip_range'])) {
            return $network; // Returns network info
        }
    }

    return null; // Not in trusted network
}

/**
 * Check if an IP address is within a CIDR range
 *
 * @param string $ip IP address to check (e.g., "192.168.1.100")
 * @param string $cidr CIDR notation (e.g., "192.168.1.0/24")
 * @return bool True if IP is in range, false otherwise
 */
function isIPInRange($ip, $range) {
    // Wildcard matching (e.g., 192.168.1.*)
    if (strpos($range, '*') !== false) {
        $range_pattern = str_replace('.', '\.', $range);
        $range_pattern = str_replace('*', '[0-9]+', $range_pattern);
        $range_pattern = '/^' . $range_pattern . '$/';
        return (bool)preg_match($range_pattern, $ip);
    }

    // CIDR notation support (e.g., 192.168.1.0/24)
    if (strpos($range, '/') !== false) {
        list($subnet, $mask) = explode('/', $range);
        if (filter_var($ip, FILTER_VALIDATE_IP) && filter_var($subnet, FILTER_VALIDATE_IP)) {
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - (int)$mask);
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }
    }

    // Exact IP match (no mask given)
    return $ip === $range;
}

/**
 * Note: getClientIP() function is already defined in config.php
 * We don't need to redefine it here
 */

/**
 * Validate CIDR notation format
 *
 * @param string $cidr CIDR notation to validate
 * @return bool True if valid, false otherwise
 */
function isValidCIDR($cidr) {
    // Check format: xxx.xxx.xxx.xxx/yy
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/', $cidr)) {
        return false;
    }

    list($subnet, $mask) = explode('/', $cidr);

    // Validate IP address
    if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    // Validate mask (0-32)
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 32) {
        return false;
    }

    return true;
}

/**
 * Log network security event
 *
 * @param string $event_type Type of event (2FA_BYPASS, USB_AUTH_ALLOWED, USB_AUTH_DENIED, etc.)
 * @param string $ip_address IP address involved
 * @param int|null $network_id ID of trusted network (if applicable)
 * @param string|null $description Additional details
 */
function logNetworkSecurityEvent($event_type, $ip_address, $network_id = null, $description = null) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (
                admin_id, session_id, action, description,
                ip_address, user_agent, trusted_network_id
            ) VALUES (
                NULL, NULL, ?, ?,
                ?, ?, ?
            )
        ");

        $stmt->execute([
            $event_type,
            $description ?? "Network security event: {$event_type}",
            $ip_address,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $network_id
        ]);
    } catch (PDOException $e) {
        // Silently fail - don't break application if logging fails
        error_log("Failed to log network security event: " . $e->getMessage());
    }
}
