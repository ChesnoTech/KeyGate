-- ============================================================================
-- Hardware Collection v2.0 - Remaining Changes
-- ============================================================================

USE oem_activation;

-- Add index on technician + session if not exists
CREATE INDEX IF NOT EXISTS idx_technician_session ON hardware_info(technician_id, session_token);

-- Replace disk_partitions with complete_disk_layout
ALTER TABLE hardware_info
ADD COLUMN complete_disk_layout JSON NULL COMMENT 'Complete disk and partition layout including hidden/system partitions' AFTER storage_devices;

-- Update column comment for activation_id
ALTER TABLE hardware_info
MODIFY COLUMN collected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Deprecated: Use collection_timestamp';

-- Create hardware collection log table
CREATE TABLE IF NOT EXISTS hardware_collection_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(10) NOT NULL,
    technician_id VARCHAR(10) NOT NULL,
    session_token VARCHAR(64) NOT NULL,
    hardware_info_id INT NULL COMMENT 'Links to hardware_info.id after successful collection',
    collection_status ENUM('success', 'failed', 'partial') DEFAULT 'success',
    collection_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT NULL,

    INDEX idx_order_tech (order_number, technician_id),
    INDEX idx_session (session_token),
    FOREIGN KEY (hardware_info_id) REFERENCES hardware_info(id) ON DELETE SET NULL,
    FOREIGN KEY (technician_id) REFERENCES technicians(technician_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks all hardware collection attempts per order';

-- Update the view
DROP VIEW IF EXISTS v_activation_hardware;
DROP VIEW IF EXISTS v_order_hardware;

CREATE VIEW v_order_hardware AS
SELECT
    h.id AS hardware_id,
    h.order_number,
    h.technician_id,
    t.full_name AS technician_name,
    h.collection_timestamp,
    h.activation_id,
    a.attempt_result AS activation_result,
    a.attempted_at AS activation_time,
    k.product_key,
    k.oem_identifier,
    k.roll_serial,
    h.motherboard_manufacturer,
    h.motherboard_product,
    h.motherboard_serial,
    h.bios_version,
    h.cpu_name,
    h.cpu_cores,
    h.ram_total_capacity_gb,
    h.ram_slots_used,
    h.os_name,
    h.os_version,
    h.secure_boot_enabled,
    h.computer_name
FROM hardware_info h
LEFT JOIN technicians t ON h.technician_id = t.technician_id
LEFT JOIN activation_attempts a ON h.activation_id = a.id
LEFT JOIN oem_keys k ON a.key_id = k.id
ORDER BY h.collection_timestamp DESC;

-- Update table comment
ALTER TABLE hardware_info COMMENT='Stores hardware information collected at login time for each order';
