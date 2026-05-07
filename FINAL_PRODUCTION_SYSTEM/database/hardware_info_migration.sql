-- Hardware Information Collection Migration
-- Date: 2026-01-27
-- Purpose: Make OEM ID and Roll Serial optional, add hardware tracking

-- Step 1: Make OEM ID and Roll Serial optional in oem_keys table
ALTER TABLE `#__oem_keys`
MODIFY COLUMN oem_identifier VARCHAR(20) NULL DEFAULT NULL,
MODIFY COLUMN roll_serial VARCHAR(20) NULL DEFAULT NULL;

-- Step 2: Create hardware_info table for tracking PC hardware details
CREATE TABLE IF NOT EXISTS `#__hardware_info` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activation_id INT NOT NULL COMMENT 'Links to activation_attempts.id',
    order_number VARCHAR(10) NOT NULL COMMENT 'Order number for easy reference',

    -- Motherboard Information
    motherboard_manufacturer VARCHAR(100) NULL,
    motherboard_product VARCHAR(100) NULL,
    motherboard_serial VARCHAR(100) NULL,
    motherboard_version VARCHAR(50) NULL,

    -- BIOS Information
    bios_manufacturer VARCHAR(100) NULL,
    bios_version VARCHAR(100) NULL,
    bios_release_date VARCHAR(50) NULL,
    bios_serial_number VARCHAR(100) NULL,

    -- CPU Information
    cpu_name VARCHAR(200) NULL,
    cpu_manufacturer VARCHAR(100) NULL,
    cpu_cores INT NULL,
    cpu_logical_processors INT NULL,
    cpu_max_clock_speed INT NULL COMMENT 'In MHz',

    -- RAM Information
    ram_total_capacity_gb DECIMAL(10,2) NULL,
    ram_slots_used INT NULL,
    ram_slots_total INT NULL,
    ram_modules JSON NULL COMMENT 'Array of RAM module details',

    -- Video Card Information
    video_cards JSON NULL COMMENT 'Array of video card details',

    -- Storage Information
    storage_devices JSON NULL COMMENT 'Array of storage device details',
    disk_partitions JSON NULL COMMENT 'Array of partition layout details',

    -- System Information
    os_name VARCHAR(200) NULL,
    os_version VARCHAR(100) NULL,
    os_architecture VARCHAR(20) NULL,
    secure_boot_enabled TINYINT(1) NULL COMMENT 'Whether Secure Boot is enabled (1=yes, 0=no, NULL=unknown)',
    computer_name VARCHAR(100) NULL,

    -- Metadata
    collected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    collection_method VARCHAR(50) DEFAULT 'PowerShell' COMMENT 'Method used to collect data',

    INDEX idx_activation_id (activation_id),
    INDEX idx_order_number (order_number),
    INDEX idx_collected_at (collected_at),

    FOREIGN KEY (activation_id) REFERENCES `#__activation_attempts`(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Hardware information collected during activation';

-- Step 3: Add hardware_collected flag to activation_attempts
ALTER TABLE `#__activation_attempts`
ADD COLUMN hardware_collected TINYINT(1) DEFAULT 0 COMMENT 'Whether hardware info was collected for this activation';

-- Step 4: Create view for easy hardware lookup by order number
CREATE OR REPLACE VIEW v_activation_hardware AS
SELECT
    a.id AS activation_id,
    a.order_number,
    a.attempt_result,
    a.attempted_at,
    t.technician_id,
    t.full_name AS technician_name,
    k.product_key,
    k.oem_identifier,
    k.roll_serial,
    h.motherboard_manufacturer,
    h.motherboard_product,
    h.bios_version,
    h.cpu_name,
    h.ram_total_capacity_gb,
    h.secure_boot_enabled,
    h.collected_at AS hardware_collected_at
FROM `#__activation_attempts` a
LEFT JOIN `#__technicians` t ON a.technician_id = t.technician_id
LEFT JOIN `#__oem_keys` k ON a.key_id = k.id
LEFT JOIN `#__hardware_info` h ON h.activation_id = a.id
ORDER BY a.attempted_at DESC;

-- Step 5: (Optional) Add secure_boot_enabled column if table already exists without it
-- Run this only if you applied the migration before this column was added:
-- ALTER TABLE `#__hardware_info` ADD COLUMN secure_boot_enabled TINYINT(1) NULL COMMENT 'Whether Secure Boot is enabled (1=yes, 0=no, NULL=unknown)' AFTER os_architecture;
