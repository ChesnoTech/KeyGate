import { apiGet, apiPostJson } from './client'

export interface LicenseFeatures {
  activation: boolean
  dashboard: boolean
  hardware: boolean
  backups: boolean
  integrations: boolean
  compliance: boolean
  branding: boolean
  upgrade: boolean
  multi_admin: boolean
  multi_site?: boolean
  sso?: boolean
  api_access?: boolean
}

export interface LicenseInfo {
  tier: 'community' | 'pro' | 'enterprise'
  label: string
  is_registered: boolean
  licensed_to: string
  expires_at: string | null
  max_technicians: number
  max_keys: number
  features: LicenseFeatures
  instance_id: string
}

export interface LicenseUsage {
  technicians: number
  keys: number
}

export interface LicenseStatusResponse {
  success: boolean
  license: LicenseInfo
  usage: LicenseUsage
}

export function getLicenseStatus() {
  return apiGet<LicenseStatusResponse>('license_status')
}

export function registerLicense(licenseKey: string) {
  return apiPostJson<{
    success: boolean
    tier?: string
    label?: string
    message?: string
    error?: string
  }>('license_register', { license_key: licenseKey })
}

export function deactivateLicense() {
  return apiPostJson<{ success: boolean; message: string }>('license_deactivate')
}

export function generateDevLicense(tier: string) {
  return apiPostJson<{
    success: boolean
    license_key?: string
    tier?: string
    instance_id?: string
    message?: string
    error?: string
  }>('license_generate_dev', { tier })
}
