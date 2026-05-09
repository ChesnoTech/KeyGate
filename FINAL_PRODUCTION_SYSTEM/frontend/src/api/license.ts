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

// P1: hardware fingerprint metadata exposed to the License page so the
// admin can see current vs. bound state and the rebind quota counter.
export interface LicenseHardware {
  current_fingerprint: string
  components: Record<string, string>
  bound_fingerprint: string
  rebind_count: number
  rebind_quota_limit: number
  rebind_window_days: number
}

// P2: phone-home status surfaced to the License page.
export interface LicensePhoneHome {
  available: boolean
  last_validated_at?: string | null
  failure_count?: number
  last_error?: string | null
  server_time_drift_seconds?: number
  clock_drift_strikes?: number
  current_jti?: string | null
  grace_band?: 'ok' | 'banner' | 'expired'
  grace_days?: number
  grace_banner?: string | null
  grace_banner_threshold_d?: number
  grace_hard_threshold_d?: number
  effective_band?: string | null
  effective_banner?: string | null
}

export interface LicenseStatusResponse {
  success: boolean
  license: LicenseInfo & { rebind_required?: boolean; rebind_grace_ends?: string | null }
  usage: LicenseUsage
  hardware?: LicenseHardware
  phonehome?: LicensePhoneHome
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

export function generateDevLicense(tier: string, devToken: string) {
  return apiPostJson<{
    success: boolean
    license_key?: string
    tier?: string
    instance_id?: string
    message?: string
    error?: string
  }>('license_generate_dev', { tier, dev_token: devToken })
}

// P0: claim a pending GitHub Sponsors / LemonSqueezy / T-Bank purchase by
// binding it to this install's instance_id. Worker mints an RS256 JWT.
export function claimLicense(email: string, sponsorLogin?: string) {
  return apiPostJson<{
    success: boolean
    license_key?: string
    tier?: string
    expires_at?: string
    message?: string
    error?: string
  }>('license_claim', { email, sponsor_login: sponsorLogin || '' })
}

// P0: migrate a legacy HS256 license to RS256 (90-day window post v2.3.0).
export function migrateLegacyLicense(licenseKey: string) {
  return apiPostJson<{
    success: boolean
    license_key?: string
    tier?: string
    expires_at?: string
    message?: string
    error?: string
  }>('license_migrate', { license_key: licenseKey })
}

// P1: re-detect the server's hardware fingerprint (admin-triggered, force
// refresh of the cached system_config('server_hwfp')).
export function redetectHardware() {
  return apiPostJson<{
    success: boolean
    fingerprint?: string
    components?: Record<string, string>
    computed_at?: string
    error?: string
  }>('license_redetect_hw')
}

// P1: rebind the active license to the current host's hardware
// fingerprint. Worker enforces 3-per-365-day quota.
export function rebindLicense(reason?: string) {
  return apiPostJson<{
    success: boolean
    tier?: string
    rebind_count?: number
    rebind_quota_remaining?: number
    rebind_quota_limit?: number
    quota_window_days?: number
    quota_limit?: number
    retry_after_iso?: string
    message?: string
    error?: string
  }>('license_rebind', { reason: reason || '' })
}

// P2: force a phone-home validate now (bypass 24h throttle).
export function forceValidate() {
  return apiPostJson<{
    success: boolean
    valid?: boolean
    tier?: string
    revoked?: boolean
    must_rebind?: boolean
    expires_at?: string | null
    jti?: string | null
    server_time?: string | null
    message?: string
    error?: string
  }>('license_force_validate')
}
