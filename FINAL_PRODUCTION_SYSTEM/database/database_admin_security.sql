-- Enhanced Admin Security Tables
-- Add these to your existing database

USE oem_activation;

-- Admin users table (separate from `#__technicians`)
CREATE TABLE `#__admin_users` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'viewer') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    must_change_password BOOLEAN DEFAULT TRUE,
    failed_login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    last_login TIMESTAMP NULL,
    last_login_ip VARCHAR(45),
    password_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    INDEX idx_username (username),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (created_by) REFERENCES `#__admin_users`(id)
);

-- Admin sessions table
CREATE TABLE `#__admin_sessions` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (admin_id) REFERENCES `#__admin_users`(id),
    INDEX idx_session_token (session_token),
    INDEX idx_admin_id (admin_id),
    INDEX idx_expires_at (expires_at)
);

-- Admin activity log
CREATE TABLE `#__admin_activity_log` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT,
    session_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES `#__admin_users`(id),
    FOREIGN KEY (session_id) REFERENCES `#__admin_sessions`(id),
    INDEX idx_admin_id (admin_id),
    INDEX idx_created_at (created_at),
    INDEX idx_action (action)
);

-- IP whitelist table
CREATE TABLE `#__admin_ip_whitelist` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    ip_range VARCHAR(45),
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES `#__admin_users`(id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_is_active (is_active)
);

-- Add security configuration
INSERT INTO `#__system_config` (config_key, config_value, description) VALUES
('admin_session_timeout_minutes', '30', 'Admin session timeout in minutes'),
('admin_max_failed_logins', '3', 'Maximum failed admin login attempts'),
('admin_lockout_duration_minutes', '30', 'Admin account lockout duration'),
('admin_require_https', '1', 'Require HTTPS for admin panel (1=yes, 0=no)'),
('admin_ip_whitelist_enabled', '0', 'Enable IP whitelist for admin access (1=yes, 0=no)'),
('admin_force_password_change_days', '90', 'Force password change every N days'),
('admin_password_history_count', '5', 'Remember last N passwords to prevent reuse'),
('admin_log_retention_days', '365', 'Keep admin activity logs for N days')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Create initial super admin (password: SuperSecure2024!)
-- Password hash for: SuperSecure2024!
INSERT INTO `#__admin_users` (username, full_name, email, password_hash, role, must_change_password, created_by)
VALUES ('superadmin', 'Super Administrator', 'admin@yourcompany.com', 
        '$2y$12$LQv3c1yqBwlVHpPd7u/Dw.G2K2wjDUl9jhJxfTULt3lOAOWuTDBKG', 
        'super_admin', TRUE, NULL);

-- Add some safe IP addresses (update these for your environment)
-- INSERT INTO `#__admin_ip_whitelist` (ip_address, description, created_by) VALUES
-- ('192.168.1.0/24', 'Local network', 1),
-- ('10.0.0.0/8', 'Internal network', 1);