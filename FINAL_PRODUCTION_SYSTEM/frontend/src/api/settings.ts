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
