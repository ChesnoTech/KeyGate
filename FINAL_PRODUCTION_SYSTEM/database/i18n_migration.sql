-- i18n Migration: Add language preference support
-- Run this migration to enable per-user language selection

-- Add preferred_language column to admin_users
ALTER TABLE admin_users ADD COLUMN preferred_language VARCHAR(5) DEFAULT 'en' AFTER role;

-- Add preferred_language column to technicians
ALTER TABLE technicians ADD COLUMN preferred_language VARCHAR(5) DEFAULT 'en' AFTER notes;

-- Add system default language setting
INSERT INTO system_config (config_key, config_value, description)
VALUES ('default_language', 'en', 'Default system language (en = English, ru = Russian)')
ON DUPLICATE KEY UPDATE config_value = config_value;
