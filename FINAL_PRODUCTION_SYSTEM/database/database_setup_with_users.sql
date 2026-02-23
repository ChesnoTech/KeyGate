-- OEM Activation System Database Schema with User Management
-- Run this in PHPMyAdmin SQL tab

CREATE DATABASE IF NOT EXISTS oem_activation;
USE oem_activation;

-- Table to store technician accounts
CREATE TABLE technicians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    password_hash VARCHAR(255) NOT NULL,
    temp_password VARCHAR(50) DEFAULT NULL,
    must_change_password BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    failed_login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(20),
    notes TEXT,
    INDEX idx_technician_id (technician_id),
    INDEX idx_is_active (is_active)
);

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
    technician_id VARCHAR(20) NOT NULL,
    order_number VARCHAR(10) NOT NULL,
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
    FOREIGN KEY (technician_id) REFERENCES technicians(technician_id),
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

-- Table for password reset tokens
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technician_id VARCHAR(20) NOT NULL,
    reset_token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technician_id) REFERENCES technicians(technician_id),
    INDEX idx_reset_token (reset_token),
    INDEX idx_expires_at (expires_at)
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
('key_recycling_enabled', '1', 'Enable key recycling (1=yes, 0=no)'),
('max_failed_logins', '5', 'Maximum failed login attempts before lockout'),
('lockout_duration_minutes', '15', 'Account lockout duration in minutes'),
('password_min_length', '8', 'Minimum password length'),
('temp_password_length', '12', 'Temporary password length'),
('hide_product_keys_in_emails', '1', 'Hide full product keys in email alerts (1=yes, 0=no)'),
('show_full_keys_in_admin', '0', 'Show full product keys in admin panel (1=yes, 0=no - admin only)');

-- Create default admin account (password: admin123 - CHANGE THIS!)
INSERT INTO technicians (technician_id, full_name, email, password_hash, must_change_password, created_by, notes)
VALUES ('admin', 'System Administrator', 'admin@yourcompany.com', 
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
        TRUE, 'system', 'Default admin account - change password immediately');

-- Create sample technician account (password: temp123)  
INSERT INTO technicians (technician_id, full_name, email, password_hash, temp_password, must_change_password, created_by, notes)
VALUES ('tech001', 'John Technician', 'tech001@yourcompany.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'temp123', TRUE, 'admin', 'Sample technician account');