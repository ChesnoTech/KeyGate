-- =============================================================
-- KeyGate — USB Device Authentication
-- =============================================================
-- Tracks registered USB devices for passwordless technician auth.
-- =============================================================

CREATE TABLE IF NOT EXISTS usb_devices (
    device_id INT AUTO_INCREMENT PRIMARY KEY,
    device_serial_number VARCHAR(255) NOT NULL UNIQUE,
    device_name VARCHAR(255) NOT NULL,
    device_manufacturer VARCHAR(255) DEFAULT NULL,
    device_model VARCHAR(255) DEFAULT NULL,
    device_vid VARCHAR(10) DEFAULT NULL COMMENT 'USB Vendor ID',
    device_pid VARCHAR(10) DEFAULT NULL COMMENT 'USB Product ID',
    technician_id VARCHAR(20) NOT NULL,
    device_status ENUM('active','disabled','revoked') NOT NULL DEFAULT 'active',
    registered_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    registered_by_admin VARCHAR(100) DEFAULT NULL,
    INDEX idx_usb_tech (technician_id),
    INDEX idx_usb_status (device_status),
    INDEX idx_usb_serial (device_serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
