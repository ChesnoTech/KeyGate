-- Push Notifications Migration
-- Phase 8: Web Push Notifications for OEM Activation System
-- Run this migration to add push notification support

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- Push subscriptions: stores browser push endpoints per admin
CREATE TABLE IF NOT EXISTS `#__push_subscriptions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `admin_id` int(11) NOT NULL,
    `endpoint` text NOT NULL,
    `p256dh_key` varchar(255) NOT NULL,
    `auth_key` varchar(255) NOT NULL,
    `user_agent` varchar(512) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `last_used_at` timestamp NULL DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_endpoint` (`admin_id`, `endpoint`(500)),
    KEY `idx_admin_active` (`admin_id`, `is_active`),
    CONSTRAINT `fk_push_sub_admin` FOREIGN KEY (`admin_id`) REFERENCES `#__admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Push preferences: per-admin category toggles
CREATE TABLE IF NOT EXISTS `#__push_preferences` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `admin_id` int(11) NOT NULL,
    `category` varchar(50) NOT NULL,
    `enabled` tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_category` (`admin_id`, `category`),
    KEY `idx_admin_id` (`admin_id`),
    CONSTRAINT `fk_push_pref_admin` FOREIGN KEY (`admin_id`) REFERENCES `#__admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications: bell dropdown history
CREATE TABLE IF NOT EXISTS `#__notifications` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `admin_id` int(11) NOT NULL,
    `category` varchar(50) NOT NULL,
    `title_key` varchar(100) NOT NULL,
    `body` text DEFAULT NULL,
    `body_params` json DEFAULT NULL,
    `action_url` varchar(255) DEFAULT NULL,
    `is_read` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_admin_read` (`admin_id`, `is_read`),
    KEY `idx_admin_created` (`admin_id`, `created_at` DESC),
    CONSTRAINT `fk_notif_admin` FOREIGN KEY (`admin_id`) REFERENCES `#__admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VAPID keys and push settings in system_config
INSERT INTO `#__system_config` (`config_key`, `config_value`, `description`)
VALUES
    ('vapid_public_key', '', 'VAPID public key for Web Push (auto-generated)'),
    ('vapid_private_key', '', 'VAPID private key for Web Push (auto-generated)'),
    ('vapid_subject', 'mailto:admin@oem-activation.local', 'VAPID subject (contact email for push service)'),
    ('push_notifications_enabled', '1', 'Enable/disable Web Push notifications globally')
ON DUPLICATE KEY UPDATE config_key = config_key;
