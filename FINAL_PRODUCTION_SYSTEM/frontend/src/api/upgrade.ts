import { apiGet, apiPost, apiPostJson } from './client'

// ── Types ───────────────────────────────────────────────────

export interface UpgradeManifest {
  schema_version: number
  version: string
  version_code: number
  min_current_version: string
  max_current_version: string
  release_date: string
  description: string
  author?: string
  requirements: {
    php_min: string
    mariadb_min: string
    disk_mb_min: number
    php_extensions: string[]
    writable_paths: string[]
  }
  migrations: { file: string; version: number }[]
  files: { action: 'replace' | 'delete' | 'create_dir'; source?: string; target: string }[]
  post_upgrade?: {
    clear_opcache?: boolean
    run_health_check?: boolean
  }
}

export interface PreflightCheck {
  name: string
  status: 'pass' | 'fail' | 'warn'
  message: string
  required: boolean
}

export interface UpgradeHistoryRow {
  id: number
  from_version: string
  to_version: string
  from_version_code: number
  to_version_code: number
  status: string
  package_filename: string | null
  error_message: string | null
  started_at: string | null
  completed_at: string | null
  rolled_back_at: string | null
  admin_username: string | null
  created_at: string
  // Only present on active_upgrade (full row), not in history list
  manifest_json?: string | UpgradeManifest | null
  step_details?: string | null
}

export interface UpgradeStatus {
  current_version: string
  current_version_code: number
  current_version_date: string
  php_version: string
  mariadb_version: string
  disk_free_mb: number
  active_upgrade: UpgradeHistoryRow | null
  recent_upgrades: UpgradeHistoryRow[]
}

export interface GitHubReleaseInfo {
  tag: string
  name: string
  changelog: string
  published_at: string
  url: string
  prerelease: boolean
}

export interface GitHubAsset {
  name: string
  size: number
  download_url: string
  content_type: string
  download_count: number
}

export interface GitHubCheckResult {
  success: boolean
  update_available: boolean
  current_version: string
  latest_version: string
  release: GitHubReleaseInfo
  asset: GitHubAsset | null
  has_upgrade_package: boolean
  error?: string
}

export interface MigrationResult {
  file: string
  status: 'applied' | 'skipped' | 'failed'
  message?: string
}

export interface FileResult {
  action: string
  target: string
  status: string
  error?: string
}

// ── API Functions ───────────────────────────────────────────

export function checkGitHubUpdate(forceRefresh = false) {
  return apiGet<GitHubCheckResult>('upgrade_check_github', forceRefresh ? { force_refresh: '1' } : undefined)
}

export function downloadFromGitHub(downloadUrl: string, assetName: string) {
  return apiPostJson<{
    success: boolean
    upgrade_id: number
    manifest: UpgradeManifest
    package: { filename: string; checksum: string; size_mb: number }
  }>('upgrade_download_github', { download_url: downloadUrl, asset_name: assetName })
}

export function getUpgradeStatus() {
  return apiGet<{ success: boolean; data: UpgradeStatus }>('upgrade_get_status')
}

export function uploadUpgradePackage(file: File) {
  return apiPost<{
    success: boolean
    upgrade_id: number
    manifest: UpgradeManifest
    package: { filename: string; checksum: string; size_mb: number }
  }>('upgrade_upload_package', { upgrade_package: file })
}

export function runPreflight(upgradeId: number) {
  return apiPostJson<{
    success: boolean
    checks: PreflightCheck[]
    all_passed: boolean
  }>('upgrade_preflight', { upgrade_id: upgradeId })
}

export function runBackup(upgradeId: number) {
  return apiPostJson<{
    success: boolean
    db_backup: { filename: string; size_mb: number }
    file_backup: { filename: string; size_mb: number }
  }>('upgrade_backup', { upgrade_id: upgradeId })
}

export function applyUpgrade(upgradeId: number) {
  return apiPostJson<{
    success: boolean
    migrations_applied: MigrationResult[]
    files_changed: FileResult[]
  }>('upgrade_apply', { upgrade_id: upgradeId })
}

export function verifyUpgrade(upgradeId: number) {
  return apiPostJson<{
    success: boolean
    checks: PreflightCheck[]
    all_passed: boolean
    status: string
  }>('upgrade_verify', { upgrade_id: upgradeId })
}

export function rollbackUpgrade(upgradeId: number) {
  return apiPostJson<{
    success: boolean
    message: string
    warnings?: string[]
  }>('upgrade_rollback', { upgrade_id: upgradeId })
}

export function getUpgradeHistory() {
  return apiGet<{ success: boolean; upgrades: UpgradeHistoryRow[] }>('upgrade_history')
}
