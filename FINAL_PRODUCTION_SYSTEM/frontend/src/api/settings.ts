import { apiGet, apiPostJson } from './client'

export interface AltServerConfig {
  alt_server_enabled: boolean
  alt_server_script_path: string
  alt_server_pre_command: string
  alt_server_script_args: string
  alt_server_script_type: string
  alt_server_timeout: number
  alt_server_prompt_technician: boolean
  alt_server_auto_failover: boolean
  alt_server_verify_activation: boolean
}

export interface GetAltServerSettingsResponse {
  success: boolean
  config: AltServerConfig
}

export function getAltServerSettings() {
  return apiGet<GetAltServerSettingsResponse>('get_alt_server_settings')
}

export function saveAltServerSettings(config: AltServerConfig) {
  return apiPostJson<{ success: boolean; error?: string }>('save_alt_server_settings', {
    alt_server_enabled: config.alt_server_enabled,
    alt_server_script_path: config.alt_server_script_path,
    alt_server_pre_command: config.alt_server_pre_command,
    alt_server_script_args: config.alt_server_script_args,
    alt_server_script_type: config.alt_server_script_type,
    alt_server_timeout: config.alt_server_timeout,
    alt_server_prompt_technician: config.alt_server_prompt_technician,
    alt_server_auto_failover: config.alt_server_auto_failover,
    alt_server_verify_activation: config.alt_server_verify_activation,
  })
}

// ── Order Field Configuration ──────────────────────────────

export interface OrderFieldConfig {
  order_field_label_en: string
  order_field_label_ru: string
  order_field_prompt_en: string
  order_field_prompt_ru: string
  order_field_min_length: string
  order_field_max_length: string
  order_field_char_type: string
  order_field_custom_regex: string
}

export interface GetOrderFieldSettingsResponse {
  success: boolean
  config: OrderFieldConfig
  computed_pattern: string
}

export interface SaveOrderFieldSettingsResponse {
  success: boolean
  config?: OrderFieldConfig
  computed_pattern?: string
  error?: string
}

export function getOrderFieldSettings() {
  return apiGet<GetOrderFieldSettingsResponse>('get_order_field_settings')
}

export function saveOrderFieldSettings(config: OrderFieldConfig) {
  return apiPostJson<SaveOrderFieldSettingsResponse>(
    'save_order_field_settings',
    config as unknown as Record<string, unknown>
  )
}

// ── Session Settings ────────────────────────────────────────

export interface SessionConfig {
  admin_session_timeout_minutes: number
  admin_max_failed_logins: number
  admin_lockout_duration_minutes: number
  admin_force_password_change_days: number
}

export interface GetSessionSettingsResponse {
  success: boolean
  config: SessionConfig
}

export function getSessionSettings() {
  return apiGet<GetSessionSettingsResponse>('get_session_settings')
}

export function saveSessionSettings(config: SessionConfig) {
  return apiPostJson<{ success: boolean; config: SessionConfig }>('save_session_settings', config as unknown as Record<string, unknown>)
}

// ── SMTP / Email Settings ──────────────────────────────────────────

export interface SmtpConfig {
  smtp_enabled: boolean
  smtp_server: string
  smtp_port: number
  smtp_encryption: string    // tls | ssl | none
  smtp_username: string
  smtp_password: string
  smtp_password_set?: boolean
  smtp_auth: boolean
  email_from: string
  email_from_name: string
  email_to: string
  email_on_activation_fail: boolean
  email_on_key_exhausted: boolean
  email_on_daily_summary: boolean
}

export interface GetSmtpSettingsResponse {
  success: boolean
  config: SmtpConfig
}

export function getSmtpSettings() {
  return apiGet<GetSmtpSettingsResponse>('get_smtp_settings')
}

export function saveSmtpSettings(config: SmtpConfig) {
  return apiPostJson<{ success: boolean; config: SmtpConfig; error?: string }>(
    'save_smtp_settings',
    config as unknown as Record<string, unknown>
  )
}

export function testSmtpConnection(params: Record<string, unknown> = {}) {
  return apiPostJson<{ success: boolean; message?: string; error?: string }>(
    'test_smtp_connection',
    params
  )
}

// ── Client Configuration ──────────────────────────────────────────

export interface ClientConfig {
  client_task_wsus_cleanup: string
  client_task_security_hardening: string
  client_task_edrive_format: string
  client_task_ps7_install: string
  client_task_self_update: string
  client_activation_delay_seconds: string
  client_max_retry_attempts: string
  client_max_check_iterations: string
  client_check_delay_base: string
  client_net_threshold_1: string
  client_net_threshold_2: string
  client_net_threshold_3: string
  client_net_threshold_4: string
  client_net_threshold_5: string
  client_net_multiplier_1: string
  client_net_multiplier_2: string
  client_net_multiplier_3: string
  client_net_multiplier_4: string
  client_net_multiplier_5: string
  client_net_max_multiplier: string
  client_net_ping_samples: string
  client_net_test_endpoint_1: string
  client_net_test_endpoint_2: string
  client_net_test_endpoint_3: string
  // Key Retry & Fallback
  client_max_keys_to_try: string
  client_key_exhaustion_action: string
  client_retry_cooldown_seconds: string
  client_network_error_retries: string
  client_network_reconnect_wait: string
  client_server_busy_delay: string
  client_skip_key_on_invalid: string
  client_skip_key_on_service_error: string
}

export function getClientConfigSettings() {
  return apiGet<{ success: boolean; config: ClientConfig }>('get_client_config_settings')
}

export function saveClientConfigSettings(config: ClientConfig) {
  return apiPostJson<{ success: boolean; error?: string }>(
    'save_client_config_settings',
    config as unknown as Record<string, unknown>
  )
}
