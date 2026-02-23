-- OEM Activation System v2.0 Database Installation
-- Generated: 2025-08-24
-- This script creates all necessary tables and initial data

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- Create technicians table
CREATE TABLE `technicians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `technician_id` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `temp_password` varchar(50) DEFAULT NULL,
  `must_change_password` tinyint(1) DEFAULT 1,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `failed_login_attempts` int(11) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `technician_id` (`technician_id`),
  KEY `idx_technician_id` (`technician_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_active_locked` (`is_active`, `locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create oem_keys table
CREATE TABLE `oem_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_key` varchar(29) NOT NULL,
  `oem_identifier` varchar(20) NOT NULL,
  `roll_serial` varchar(20) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `key_status` enum('unused','allocated','good','bad','retry') DEFAULT 'unused',
  `fail_counter` int(11) DEFAULT 0,
  `last_use_date` date DEFAULT NULL,
  `last_use_time` time DEFAULT NULL,
  `first_usage_date` date DEFAULT NULL,
  `first_usage_time` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_key` (`product_key`),
  KEY `idx_product_key` (`product_key`),
  KEY `idx_key_status` (`key_status`),
  KEY `idx_roll_serial` (`roll_serial`),
  KEY `idx_last_use_date` (`last_use_date`),
  KEY `idx_status_fail_date` (`key_status`, `fail_counter`, `last_use_date`, `id`),
  KEY `idx_first_usage` (`first_usage_date`, `key_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create activation_attempts table
CREATE TABLE `activation_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key_id` int(11) NOT NULL,
  `technician_id` varchar(20) NOT NULL,
  `order_number` varchar(10) NOT NULL,
  `attempt_number` int(11) NOT NULL,
  `attempt_result` enum('success','failed') NOT NULL,
  `attempted_date` date NOT NULL,
  `attempted_time` time NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `client_ip` varchar(45) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `key_id` (`key_id`),
  KEY `technician_id` (`technician_id`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_technician_id` (`technician_id`),
  KEY `idx_attempted_at` (`attempted_at`),
  KEY `idx_attempted_date` (`attempted_date`),
  KEY `idx_key_tech_date` (`key_id`, `technician_id`, `attempted_at`),
  CONSTRAINT `activation_attempts_ibfk_1` FOREIGN KEY (`key_id`) REFERENCES `oem_keys` (`id`),
  CONSTRAINT `activation_attempts_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`technician_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create active_sessions table
CREATE TABLE `active_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `technician_id` varchar(20) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `key_id` int(11) DEFAULT NULL,
  `order_number` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `key_id` (`key_id`),
  KEY `technician_id` (`technician_id`),
  KEY `idx_session_token` (`session_token`),
  KEY `idx_technician_id` (`technician_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_tech_active_expires` (`technician_id`, `is_active`, `expires_at`),
  KEY `idx_expires_active` (`expires_at`, `is_active`),
  CONSTRAINT `active_sessions_ibfk_1` FOREIGN KEY (`key_id`) REFERENCES `oem_keys` (`id`),
  CONSTRAINT `active_sessions_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`technician_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create admin_users table
CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','admin','viewer') DEFAULT 'admin',
  `is_active` tinyint(1) DEFAULT 1,
  `must_change_password` tinyint(1) DEFAULT 1,
  `failed_login_attempts` int(11) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `password_changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `created_by` (`created_by`),
  KEY `idx_username` (`username`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `admin_users_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create admin_sessions table
CREATE TABLE `admin_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `admin_id` (`admin_id`),
  KEY `idx_session_token` (`session_token`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create admin_activity_log table
CREATE TABLE `admin_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `session_id` (`session_id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_action` (`action`),
  CONSTRAINT `admin_activity_log_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `admin_activity_log_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `admin_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create admin_ip_whitelist table
CREATE TABLE `admin_ip_whitelist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `ip_range` varchar(45) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `admin_ip_whitelist_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create system_config table
CREATE TABLE `system_config` (
  `config_key` varchar(50) NOT NULL,
  `config_value` text NOT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create password_reset_tokens table
CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `technician_id` varchar(20) NOT NULL,
  `reset_token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reset_token` (`reset_token`),
  KEY `technician_id` (`technician_id`),
  KEY `idx_reset_token` (`reset_token`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`technician_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default system configuration
INSERT INTO `system_config` (`config_key`, `config_value`, `description`) VALUES
('smtp_server', 'smtp.zoho.com', 'SMTP server for notifications'),
('smtp_port', '587', 'SMTP port'),
('smtp_username', '', 'SMTP username'),
('smtp_password', '', 'SMTP password - SET THIS MANUALLY'),
('email_from', '', 'From email address'),
('email_to', '', 'Notification recipient'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('max_retry_attempts', '3', 'Maximum retry attempts per key'),
('key_recycling_enabled', '1', 'Enable key recycling (1=yes, 0=no)'),
('max_failed_logins', '5', 'Maximum failed login attempts before lockout'),
('lockout_duration_minutes', '15', 'Account lockout duration in minutes'),
('password_min_length', '8', 'Minimum password length'),
('temp_password_length', '12', 'Temporary password length'),
('hide_product_keys_in_emails', '1', 'Hide full product keys in email alerts (1=yes, 0=no)'),
('show_full_keys_in_admin', '0', 'Show full product keys in admin panel (1=yes, 0=no - admin only)'),
('admin_session_timeout_minutes', '30', 'Admin session timeout in minutes'),
('admin_max_failed_logins', '3', 'Maximum failed admin login attempts'),
('admin_lockout_duration_minutes', '30', 'Admin account lockout duration'),
('admin_require_https', '0', 'Require HTTPS for admin panel (1=yes, 0=no)'),
('admin_ip_whitelist_enabled', '0', 'Enable IP whitelist for admin access (1=yes, 0=no)'),
('admin_force_password_change_days', '90', 'Force password change every N days'),
('admin_password_history_count', '5', 'Remember last N passwords to prevent reuse'),
('admin_log_retention_days', '365', 'Keep admin activity logs for N days');

-- Create sample technician account (for testing)
INSERT INTO `technicians` (`technician_id`, `full_name`, `email`, `password_hash`, `temp_password`, `must_change_password`, `created_by`, `notes`) VALUES
('demo', 'Demo Technician', 'demo@example.com', '$2y$12$LQv3c1yqBwlVHpPd7u/Dw.G2K2wjDUl9jhJxfTULt3lOAOWuTDBKG', 'demo123', 1, 'system', 'Demo account for testing - Password: demo123');

COMMIT;