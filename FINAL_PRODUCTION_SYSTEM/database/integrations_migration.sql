-- =============================================================
-- Integration Framework Tables
-- Supports osTicket, 1C ERP, and future integrations
-- =============================================================

CREATE TABLE IF NOT EXISTS `integrations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `integration_key` VARCHAR(50) NOT NULL UNIQUE,
  `display_name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `integration_type` ENUM('webhook','api_sync','plugin') NOT NULL DEFAULT 'api_sync',
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `config` JSON DEFAULT NULL,
  `status` ENUM('disconnected','connected','error') NOT NULL DEFAULT 'disconnected',
  `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `integration_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `integration_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `payload` JSON DEFAULT NULL,
  `status` ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `response_code` SMALLINT UNSIGNED DEFAULT NULL,
  `response_body` TEXT,
  `error_message` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`integration_id`) REFERENCES `integrations`(`id`) ON DELETE CASCADE,
  INDEX `idx_ie_status` (`status`),
  INDEX `idx_ie_event_type` (`event_type`),
  INDEX `idx_ie_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default integrations
INSERT INTO `integrations` (`integration_key`, `display_name`, `description`, `integration_type`, `enabled`, `config`) VALUES
('osticket', 'osTicket', 'Track PC build orders as support tickets in osTicket', 'api_sync', 0,
 JSON_OBJECT(
   'base_url', '',
   'api_key', '',
   'department_id', '',
   'topic_id', '',
   'auto_create_ticket', true,
   'auto_reply_on_activation', true,
   'ticket_subject_template', 'Build Order #{order_number}',
   'include_hardware_details', true
 )),
('1c_erp', '1C Enterprise', 'Sync orders and inventory with 1C ERP (v8+)', 'api_sync', 0,
 JSON_OBJECT(
   'base_url', '',
   'auth_type', 'basic',
   'username', '',
   'password', '',
   'sync_direction', 'push',
   'push_activations', true,
   'push_key_usage', true,
   'pull_inventory', false,
   'endpoint_activations', '/api/hs/activations',
   'endpoint_inventory', '/api/hs/inventory'
 ));
