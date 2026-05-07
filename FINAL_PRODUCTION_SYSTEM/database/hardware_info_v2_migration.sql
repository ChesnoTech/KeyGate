-- Hardware Information v2 Migration
-- Date: 2026-02-08
-- Purpose: Add network info (IPs, MACs), extended serial numbers, TPM, monitors, audio, chassis info

-- Add new columns to hardware_info table

-- Chassis / Enclosure Information
ALTER TABLE `#__hardware_info` ADD COLUMN chassis_manufacturer VARCHAR(100) NULL AFTER computer_name;
ALTER TABLE `#__hardware_info` ADD COLUMN chassis_serial VARCHAR(100) NULL AFTER chassis_manufacturer;
ALTER TABLE `#__hardware_info` ADD COLUMN chassis_type VARCHAR(50) NULL AFTER chassis_serial;

-- System Product Information (OEM)
ALTER TABLE `#__hardware_info` ADD COLUMN system_manufacturer VARCHAR(100) NULL AFTER chassis_type;
ALTER TABLE `#__hardware_info` ADD COLUMN system_product_name VARCHAR(200) NULL AFTER system_manufacturer;
ALTER TABLE `#__hardware_info` ADD COLUMN system_serial VARCHAR(100) NULL AFTER system_product_name;
ALTER TABLE `#__hardware_info` ADD COLUMN system_uuid VARCHAR(50) NULL AFTER system_serial;

-- TPM Information
ALTER TABLE `#__hardware_info` ADD COLUMN tpm_present TINYINT(1) NULL AFTER system_uuid;
ALTER TABLE `#__hardware_info` ADD COLUMN tpm_version VARCHAR(50) NULL AFTER tpm_present;
ALTER TABLE `#__hardware_info` ADD COLUMN tpm_manufacturer VARCHAR(100) NULL AFTER tpm_version;

-- CPU Serial (Processor ID)
ALTER TABLE `#__hardware_info` ADD COLUMN cpu_serial VARCHAR(50) NULL AFTER cpu_max_clock_speed;

-- Network Information
ALTER TABLE `#__hardware_info` ADD COLUMN primary_mac_address VARCHAR(20) NULL AFTER computer_name;
ALTER TABLE `#__hardware_info` ADD COLUMN local_ip VARCHAR(45) NULL AFTER primary_mac_address;
ALTER TABLE `#__hardware_info` ADD COLUMN public_ip VARCHAR(45) NULL AFTER local_ip;
ALTER TABLE `#__hardware_info` ADD COLUMN network_adapters JSON NULL COMMENT 'Array of network adapter details with MAC, IP, etc.' AFTER public_ip;

-- Audio Devices
ALTER TABLE `#__hardware_info` ADD COLUMN audio_devices JSON NULL COMMENT 'Array of sound device details' AFTER network_adapters;

-- Monitor Information
ALTER TABLE `#__hardware_info` ADD COLUMN monitors JSON NULL COMMENT 'Array of connected monitor details with serials' AFTER audio_devices;

-- OS Extended Info
ALTER TABLE `#__hardware_info` ADD COLUMN os_build_number VARCHAR(20) NULL AFTER os_architecture;
ALTER TABLE `#__hardware_info` ADD COLUMN os_install_date VARCHAR(50) NULL AFTER os_build_number;
ALTER TABLE `#__hardware_info` ADD COLUMN os_serial_number VARCHAR(100) NULL AFTER os_install_date;

-- Device Fingerprint (composite unique identifier)
ALTER TABLE `#__hardware_info` ADD COLUMN device_fingerprint VARCHAR(500) NULL COMMENT 'Composite hardware fingerprint for duplicate detection' AFTER collection_method;

-- Index for device fingerprint lookups
ALTER TABLE `#__hardware_info` ADD INDEX idx_device_fingerprint (device_fingerprint(255));
ALTER TABLE `#__hardware_info` ADD INDEX idx_public_ip (public_ip);
ALTER TABLE `#__hardware_info` ADD INDEX idx_primary_mac (primary_mac_address);

-- Update the view to include new network fields
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
    h.motherboard_serial,
    h.bios_version,
    h.cpu_name,
    h.ram_total_capacity_gb,
    h.secure_boot_enabled,
    h.primary_mac_address,
    h.local_ip,
    h.public_ip,
    h.system_uuid,
    h.device_fingerprint,
    h.collected_at AS hardware_collected_at
FROM `#__activation_attempts` a
LEFT JOIN `#__technicians` t ON a.technician_id = t.technician_id
LEFT JOIN `#__oem_keys` k ON a.key_id = k.id
LEFT JOIN `#__hardware_info` h ON h.activation_id = a.id
ORDER BY a.attempted_at DESC;
