-- =============================================================
-- KeyGate — Task Pipeline System
-- =============================================================
-- Configurable per-product-line task pipelines.
-- Each product line can have a custom sequence of tasks
-- (built-in or custom PowerShell code) that run during activation.
-- =============================================================

-- Global task template library (reusable across product lines)
CREATE TABLE IF NOT EXISTS task_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_key VARCHAR(50) NOT NULL UNIQUE COMMENT 'Internal identifier (e.g. hardware_collection)',
    task_name VARCHAR(100) NOT NULL COMMENT 'Display name',
    task_type ENUM('built_in', 'custom') NOT NULL DEFAULT 'custom',
    description TEXT DEFAULT NULL,
    default_code TEXT DEFAULT NULL COMMENT 'PowerShell code block (for custom tasks)',
    default_timeout_seconds INT NOT NULL DEFAULT 120,
    default_on_failure ENUM('stop', 'skip', 'warn') NOT NULL DEFAULT 'stop',
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'System tasks cannot be deleted',
    icon VARCHAR(50) DEFAULT NULL COMMENT 'Lucide icon name for UI',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-product-line task assignments (which tasks run, in what order, with overrides)
CREATE TABLE IF NOT EXISTS product_line_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_line_id INT NOT NULL COMMENT 'FK to product_lines table',
    task_template_id INT NOT NULL COMMENT 'FK to task_templates',
    sort_order INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    custom_name VARCHAR(100) DEFAULT NULL COMMENT 'Override the template name for this product line',
    custom_code TEXT DEFAULT NULL COMMENT 'Override the template code for this product line',
    custom_timeout_seconds INT DEFAULT NULL COMMENT 'Override timeout',
    custom_on_failure ENUM('stop', 'skip', 'warn') DEFAULT NULL COMMENT 'Override failure behavior',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_task (product_line_id, task_template_id),
    INDEX idx_product_order (product_line_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task execution log (tracks what ran during each activation)
CREATE TABLE IF NOT EXISTS task_execution_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activation_attempt_id INT DEFAULT NULL COMMENT 'FK to activation_attempts',
    product_line_id INT DEFAULT NULL,
    task_template_id INT NOT NULL,
    task_key VARCHAR(50) NOT NULL,
    task_name VARCHAR(100) NOT NULL,
    status ENUM('pending', 'running', 'success', 'failed', 'skipped', 'timeout') NOT NULL DEFAULT 'pending',
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    duration_ms INT DEFAULT NULL,
    output TEXT DEFAULT NULL COMMENT 'Task stdout/stderr output',
    error_message TEXT DEFAULT NULL,
    technician_id INT DEFAULT NULL,
    order_number VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exec_activation (activation_attempt_id),
    INDEX idx_exec_product (product_line_id),
    INDEX idx_exec_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed built-in task templates
INSERT INTO task_templates (task_key, task_name, task_type, description, default_timeout_seconds, default_on_failure, is_system, icon) VALUES
('hardware_collection',  'Hardware Collection',     'built_in', 'Collect full hardware inventory (MB, CPU, RAM, GPU, disks, network)',             60,  'stop', 1, 'Cpu'),
('qc_compliance',        'QC Compliance Check',     'built_in', 'Run quality control checks (Secure Boot, BIOS version, HackBGRT)',               30,  'stop', 1, 'ShieldCheck'),
('oem_activation',       'OEM Key Activation',      'built_in', 'Request OEM key from server, install and activate Windows',                     180,  'stop', 1, 'Key'),
('network_diagnostics',  'Network Diagnostics',     'built_in', 'Test internet connectivity and Microsoft licensing server access',               30,  'warn', 1, 'Wifi'),
('generate_fingerprint', 'Generate HW Fingerprint', 'built_in', 'Create SHA256 hardware fingerprint for device tracking',                         10,  'warn', 1, 'Fingerprint'),
('submit_hardware',      'Submit Hardware Data',     'built_in', 'Upload collected hardware info to KeyGate server',                               30,  'stop', 1, 'Upload'),
('report_result',        'Report Activation Result', 'built_in', 'Report activation success/failure to server',                                   15,  'warn', 1, 'FileCheck')
ON DUPLICATE KEY UPDATE task_name = VALUES(task_name);

-- Seed example custom tasks (disabled by default, admin can enable per product line)
INSERT INTO task_templates (task_key, task_name, task_type, description, default_code, default_timeout_seconds, default_on_failure, is_system, icon) VALUES
('set_power_plan',       'Set Power Plan',          'custom', 'Configure Windows power plan (High Performance, Balanced, etc.)',
 'powercfg /setactive 8c5e7fda-e8bf-4a96-9a85-a6e23a8c635c  # High Performance', 15, 'skip', 0, 'Zap'),
('disable_sleep',        'Disable Sleep Mode',      'custom', 'Prevent the PC from going to sleep',
 'powercfg /change standby-timeout-ac 0\npowercfg /change monitor-timeout-ac 0', 10, 'skip', 0, 'Moon'),
('set_timezone',         'Set Timezone',            'custom', 'Set the system timezone',
 'tzutil /s "Russian Standard Time"', 10, 'skip', 0, 'Clock'),
('rename_computer',      'Rename Computer',         'custom', 'Rename the PC based on order number or serial',
 'Rename-Computer -NewName "PC-$($env:ORDER_NUMBER)" -Force', 15, 'warn', 0, 'PenLine'),
('install_software',     'Install Software',        'custom', 'Install additional software via winget or custom script',
 '# winget install --id Google.Chrome --accept-source-agreements --accept-package-agreements', 120, 'skip', 0, 'PackagePlus'),
('run_benchmark',        'Run Benchmark',           'custom', 'Run a performance benchmark and save results',
 '# Add your benchmark script here\nWrite-Host "Benchmark placeholder"', 300, 'skip', 0, 'Gauge'),
('custom_script',        'Custom Script',           'custom', 'Run any custom PowerShell script',
 '# Your custom PowerShell code here\nWrite-Host "Custom task executed"', 60, 'stop', 0, 'Terminal')
ON DUPLICATE KEY UPDATE task_name = VALUES(task_name);
