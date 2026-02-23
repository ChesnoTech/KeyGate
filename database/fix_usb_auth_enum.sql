-- Fix: Add 'network_restricted' to usb_auth_attempts.attempt_result ENUM
-- This value is inserted by authenticate-usb.php when a USB auth attempt
-- is blocked due to the client IP not being in a trusted network.
-- Without this fix, those INSERT statements fail silently and the
-- security audit trail for stolen-device attempts is lost.
-- Created: 2026-02-07

USE oem_activation;

ALTER TABLE `usb_auth_attempts`
MODIFY COLUMN `attempt_result` ENUM('success', 'no_match', 'disabled_device', 'inactive_technician', 'system_disabled', 'network_restricted') NOT NULL;

SELECT 'Fixed: usb_auth_attempts.attempt_result ENUM now includes network_restricted' AS Status;
