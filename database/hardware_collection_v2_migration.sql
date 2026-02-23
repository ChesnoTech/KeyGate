-- ============================================================================
-- Hardware Collection v2.0 Migration
-- ============================================================================
-- This migration decouples hardware collection from activation success
-- Hardware is now collected at login time for every order, regardless of
-- activation status (success, failure, or already activated)
-- ============================================================================

USE oem_activation;

-- Drop the old foreign key constraint that links to activation_attempts
ALTER TABLE hardware_info DROP FOREIGN KEY IF EXISTS hardware_info_ibfk_1;

-- Add new columns for direct order tracking
ALTER TABLE hardware_info
ADD COLUMN technician_id VARCHAR(10) NULL AFTER order_number,
ADD COLUMN session_token VARCHAR(64) NULL AFTER technician_id,
ADD COLUMN collection_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER collected_at,
MODIFY COLUMN activation_id INT NULL COMMENT 'Optional: Links to activation_attempts.id if activation occurred';

-- Create index on order_number for fast lookups
CREATE INDEX idx_order_number ON hardware_info(order_number);
CREATE INDEX idx_technician_session ON hardware_info(technician_id, session_token);

-- Update disk_partitions to store complete disk layout (not just logical drives)
ALTER TABLE hardware_info
DROP COLUMN disk_partitions,
ADD COLUMN complete_disk_layout JSON NULL COMMENT 'Complete disk and partition layout including hidden/system partitions';

-- Create a new table for tracking hardware collection per order
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

-- Update the view to include hardware regardless of activation status
DROP VIEW IF EXISTS v_activation_hardware;

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

-- Add comment to hardware_info table
ALTER TABLE hardware_info COMMENT='Stores hardware information collected at login time for each order';

-- Sample query to get hardware info by order number (regardless of activation status)
-- SELECT * FROM v_order_hardware WHERE order_number = 'ORD12345';

-- Sample query to get all hardware collections by a technician
-- SELECT * FROM v_order_hardware WHERE technician_id = 'TECH001' ORDER BY collection_timestamp DESC;

-- Sample query to get hardware for orders that failed activation
-- SELECT * FROM v_order_hardware WHERE activation_result = 'failed' OR activation_id IS NULL;
