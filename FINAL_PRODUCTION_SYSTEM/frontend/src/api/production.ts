import { apiGet, apiPost, apiPostJson } from './client'

// ── Types ───────────────────────────────────────────────────

export interface BuildReport {
  id: number
  report_uuid: string
  order_number: string | null
  batch_number: string | null
  work_order_id: number | null
  device_fingerprint: string | null
  system_uuid: string | null
  motherboard_manufacturer: string | null
  motherboard_model: string | null
  motherboard_serial: string | null
  bios_version: string | null
  product_key_masked: string | null
  product_edition: string | null
  activation_status: 'activated' | 'failed' | 'pending' | 'not_attempted'
  activation_timestamp: string | null
  cpu_model: string | null
  ram_total_gb: number | null
  gpu_model: string | null
  os_version: string | null
  qc_passed: number | null
  product_line_name: string | null
  technician_name: string | null
  shipping_status: 'building' | 'testing' | 'ready' | 'shipped' | 'returned'
  customer_name: string | null
  customer_order_ref: string | null
  created_at: string
}

export interface KeyPoolStatus {
  product_edition: string
  total_keys: number
  unused_keys: number
  allocated_keys: number
  used_keys: number
  bad_keys: number
  alert_level: 'ok' | 'low' | 'critical'
  low_threshold: number
  critical_threshold: number
  auto_notify: number
  last_replenished_at: string | null
}

export interface HardwareBinding {
  id: number
  product_key_id: number
  device_fingerprint: string
  motherboard_serial: string | null
  system_uuid: string | null
  primary_mac_address: string | null
  bound_at: string
  status: 'active' | 'released' | 'conflict'
  product_key?: string
  product_type?: string
}

export interface DpkBatch {
  id: number
  batch_name: string
  import_source: string
  product_edition: string | null
  total_keys: number
  imported_keys: number
  duplicate_keys: number
  failed_keys: number
  import_status: 'pending' | 'processing' | 'completed' | 'failed'
  source_filename: string | null
  imported_by_username: string | null
  created_at: string
  completed_at: string | null
}

export interface WorkOrder {
  id: number
  work_order_number: string
  batch_number: string | null
  customer_name: string | null
  customer_email: string | null
  customer_phone: string | null
  customer_order_ref: string | null
  product_line_id: number | null
  product_line_name: string | null
  quantity: number
  completed_quantity: number
  status: 'draft' | 'queued' | 'in_progress' | 'completed' | 'shipped' | 'cancelled'
  priority: 'low' | 'normal' | 'high' | 'urgent'
  assigned_technician_id: number | null
  due_date: string | null
  started_at: string | null
  completed_at: string | null
  shipped_at: string | null
  shipping_method: string | null
  shipping_tracking: string | null
  shipping_address: string | null
  internal_notes: string | null
  customer_notes: string | null
  created_at: string
  build_reports?: BuildReport[]
}

// ── Build Reports ───────────────────────────────────────────

export function listBuildReports(params?: Record<string, string | number>) {
  return apiGet<{ success: boolean; reports: BuildReport[]; total: number }>('list_build_reports', params)
}

export function getBuildReport(id: number) {
  return apiGet<{ success: boolean; report: BuildReport }>('get_build_report', { id })
}

export function updateBuildReportShipping(data: Record<string, unknown>) {
  return apiPostJson<{ success: boolean }>('update_build_report_shipping', data)
}

// ── Key Pool ────────────────────────────────────────────────

export function getKeyPoolStatus() {
  return apiGet<{ success: boolean; pools: KeyPoolStatus[] }>('get_key_pool_status')
}

export function saveKeyPoolConfig(data: Record<string, unknown>) {
  return apiPostJson<{ success: boolean }>('save_key_pool_config', data)
}

// ── Hardware Binding ────────────────────────────────────────

export function checkHardwareBinding(params?: Record<string, string | number>) {
  return apiGet<{ success: boolean; bindings: HardwareBinding[]; conflicts: HardwareBinding[] }>('check_hardware_binding', params)
}

export function releaseHardwareBinding(id: number) {
  return apiPostJson<{ success: boolean }>('release_hardware_binding', { id })
}

// ── DPK Import ──────────────────────────────────────────────

export function importDpkBatch(file: File, batchName: string, productEdition: string) {
  return apiPost<{
    success: boolean
    batch_id: number
    total_in_file: number
    imported: number
    duplicates: number
    failed: number
  }>('import_dpk_batch', {
    key_file: file,
    batch_name: batchName,
    product_edition: productEdition,
  })
}

export function listDpkBatches() {
  return apiGet<{ success: boolean; batches: DpkBatch[] }>('list_dpk_batches')
}

// ── Work Orders ─────────────────────────────────────────────

export function listWorkOrders(params?: Record<string, string | number>) {
  return apiGet<{ success: boolean; work_orders: WorkOrder[]; total: number }>('list_work_orders', params)
}

export function getWorkOrder(id: number) {
  return apiGet<{ success: boolean; work_order: WorkOrder }>('get_work_order', { id })
}

export function saveWorkOrder(data: Record<string, unknown>) {
  return apiPostJson<{ success: boolean; id: number; work_order_number: string }>('save_work_order', data)
}

export function deleteWorkOrder(id: number) {
  return apiPostJson<{ success: boolean }>('delete_work_order', { id })
}
