import { apiGet, apiPost, apiPostJson } from './client'

export interface OemKeyRow {
  id: number
  product_key: string
  oem_identifier: string
  roll_serial: string
  key_status: 'unused' | 'allocated' | 'good' | 'bad' | 'retry'
  last_use_date: string | null
  last_use_time: string | null
  created_at: string
  order_number: string | null
}

export interface ListKeysParams {
  page?: number
  filter?: string
  search?: string
  key_pattern?: string
  oem_pattern?: string
  roll_pattern?: string
  adv_status?: string
  date_from?: string
  date_to?: string
}

export interface ListKeysResponse {
  success: boolean
  keys: OemKeyRow[]
  total: number
  page: number
  pages: number
}

export function listKeys(params: ListKeysParams = {}) {
  return apiGet<ListKeysResponse>('list_keys', params as Record<string, string | number | boolean>)
}

export function recycleKey(keyId: number) {
  return apiPost<{ success: boolean; error?: string }>('recycle_key', { key_id: keyId })
}

export function deleteKey(keyId: number) {
  return apiPost<{ success: boolean; error?: string }>('delete_key', { key_id: keyId })
}

export function exportKeys(filter?: string) {
  return apiGet<{ success: boolean; csv?: string }>('export_keys', filter ? { filter } : undefined)
}

export interface ImportKeysResponse {
  success: boolean
  imported: number
  updated: number
  skipped: number
  errors: string[]
  error?: string
}

export function importKeys(file: File) {
  return apiPost<ImportKeysResponse>('import_keys', { csv_file: file })
}

export interface AddKeyInput {
  product_key: string
  oem_identifier: string
  roll_serial: string
}

export interface AddKeysResponse {
  success: boolean
  imported: number
  skipped: number
  errors: string[]
  error?: string
}

export function addKeys(keys: AddKeyInput[]) {
  return apiPostJson<AddKeysResponse>('add_keys', { keys })
}
