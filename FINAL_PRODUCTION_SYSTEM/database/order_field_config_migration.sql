-- ============================================================
-- Order Field Configuration Migration
-- Makes the Order Number field fully configurable from admin
-- ============================================================

-- 1. Widen order_number columns from VARCHAR(10) to VARCHAR(50)
--    Preserve original NULL/NOT NULL constraints per table
ALTER TABLE activation_attempts MODIFY order_number VARCHAR(50) NOT NULL;
ALTER TABLE active_sessions MODIFY order_number VARCHAR(50) NULL;
ALTER TABLE hardware_info MODIFY order_number VARCHAR(50) NOT NULL;
ALTER TABLE qc_compliance_results MODIFY order_number VARCHAR(50) NOT NULL;
ALTER TABLE hardware_collection_log MODIFY order_number VARCHAR(50) NOT NULL;

-- 2. Seed system_config with order field configuration defaults
INSERT INTO system_config (config_key, config_value, description, updated_at)
VALUES
    ('order_field_label_en', 'Order Number', 'Display label for the order field (English)', NOW()),
    ('order_field_label_ru', 'Номер заказа', 'Display label for the order field (Russian)', NOW()),
    ('order_field_prompt_en', 'Enter order number', 'PowerShell prompt text (English)', NOW()),
    ('order_field_prompt_ru', 'Введите номер заказа', 'PowerShell prompt text (Russian)', NOW()),
    ('order_field_min_length', '5', 'Minimum length for order number', NOW()),
    ('order_field_max_length', '10', 'Maximum length for order number', NOW()),
    ('order_field_char_type', 'alphanumeric', 'Character type: digits_only, alphanumeric, alphanumeric_dash, custom', NOW()),
    ('order_field_custom_regex', '', 'Custom regex pattern (used when char_type=custom)', NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
