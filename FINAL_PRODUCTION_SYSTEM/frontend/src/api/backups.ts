import { apiGet, apiPost } from './client'

export interface BackupRow {
  id: number
  backup_type: string
  backup_filename: string
  backup_size_mb: number
  backup_status: string
  backup_duration_seconds: number | null
  tables_count: number | null
  rows_count: number | null
  compression_ratio: number | null
  error_message: string | null
  created_at: string
  created_by_admin_id: number | null
  deleted_at: string | null
}

export interface ListBackupsResponse {
  success: boolean
  backups: BackupRow[]
}

export function listBackups() {
  return apiGet<ListBackupsResponse>('list_backups')
}

export function triggerManualBackup() {
  return apiPost<{ success: boolean; error?: string }>('trigger_manual_backup')
}
