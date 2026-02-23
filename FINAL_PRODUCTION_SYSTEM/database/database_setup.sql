-- OEM Activation System Database Schema
-- Run this in PHPMyAdmin SQL tab

CREATE DATABASE IF NOT EXISTS oem_activation;
USE oem_activation;

-- Table to store OEM keys
CREATE TABLE oem_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_key VARCHAR(29) NOT NULL UNIQUE,
    oem_identifier VARCHAR(20) NOT NULL,
    roll_serial VARCHAR(20) NOT NULL,
    barcode VARCHAR(50),
    key_status ENUM('unused', 'good', 'bad', 'retry') DEFAULT 'unused',
    fail_counter INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product_key (product_key),
    INDEX idx_key_status (key_status),
    INDEX idx_roll_serial (roll_serial)
);

-- Table to track activation attempts
CREATE TABLE activation_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_id INT NOT NULL,
    order_number VARCHAR(10) NOT NULL,
    technician_id VARCHAR(20) NOT NULL,
    attempt_number INT NOT NULL,
    attempt_result ENUM('success', 'failed') NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    client_ip VARCHAR(45),
    notes TEXT,
    FOREIGN KEY (key_id) REFERENCES oem_keys(id),
    FOREIGN KEY (technician_id) REFERENCES technicians(technician_id),
    INDEX idx_order_number (order_number),
    INDEX idx_technician_id (technician_id),
    INDEX idx_attempted_at (attempted_at)
);

-- Table to store active sessions/tokens
CREATE TABLE active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id VARCHAR(20) NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    key_id INT,
    order_number VARCHAR(10),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (key_id) REFERENCES oem_keys(id),
    INDEX idx_session_token (session_token),
    INDEX idx_technician_id (technician_id),
    INDEX idx_expires_at (expires_at)
);

-- Table for system configuration
CREATE TABLE system_config (
    config_key VARCHAR(50) PRIMARY KEY,
    config_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert basic configuration
INSERT INTO system_config (config_key, config_value, description) VALUES
('smtp_server', 'smtp.zoho.com', 'SMTP server for notifications'),
('smtp_port', '587', 'SMTP port'),
('smtp_username', 'oem.activation@roo24.chesnotech.ru', 'SMTP username'),
('smtp_password', '', 'SMTP password - SET THIS MANUALLY'),
('email_from', 'oem.activation@roo24.chesnotech.ru', 'From email address'),
('email_to', 'team@roo24.chesnotech.ru', 'Notification recipient'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('max_retry_attempts', '3', 'Maximum retry attempts per key'),
('key_recycling_enabled', '1', 'Enable key recycling (1=yes, 0=no)');