import { apiGet, apiPostJson } from './client'

// ── Types ───────────────────────────────────────────────

export interface QcGlobalSettings {
  qc_enabled: string
  default_bios_enforcement: string
  default_secure_boot_enforcement: string
  default_hackbgrt_enforcement: string
  default_partition_enforcement: string
  default_missing_drivers_enforcement: string
  blocking_prevents_key: string
  max_unallocated_mb: string
}

export interface MotherboardRow {
  id: number
  manufacturer: string
  product: string
  first_seen_at: string
  last_seen_at: string
  times_seen: number
  secure_boot_required: number | null
  secure_boot_enforcement: number | null
  min_bios_version: string | null
  recommended_bios_version: string | null
  bios_enforcement: number | null
  hackbgrt_enforcement: number | null
  missing_drivers_enforcement: number | null
  known_bios_versions: string[]
  notes: string | null
  is_active: number
  // Effective (resolved) values
  effective_secure_boot_enforcement: number
  effective_bios_enforcement: number
  effective_hackbgrt_enforcement: number
  effective_partition_enforcement: number
  effective_missing_drivers_enforcement: number
  effective_secure_boot_required: number
  effective_min_bios: string | null
  effective_rec_bios: string | null
}

export interface ManufacturerDefault {
  id: number
  manufacturer: string
  secure_boot_required: number
  secure_boot_enforcement: number
  min_bios_version: string | null
  recommended_bios_version: string | null
  bios_enforcement: number
  hackbgrt_enforcement: number
  notes: string | null
  updated_by: number | null
}

export interface ComplianceResult {
  id: number
  hardware_info_id: number
  order_number: string
  check_type: 'bios_version' | 'secure_boot' | 'hackbgrt_boot_priority' | 'partition_layout'
  check_result: 'pass' | 'info' | 'warning' | 'fail'
  enforcement_level: number
  expected_value: string | null
  actual_value: string | null
  message: string | null
  rule_source: 'global' | 'manufacturer' | 'model'
  motherboard_registry_id: number | null
  is_retroactive: number
  checked_at: string
  // JOINed fields
  motherboard_manufacturer: string | null
  motherboard_product: string | null
  hw_bios_version: string | null
}

export interface QcStats {
  total_checks: number
  pass_count: number
  info_count: number
  warning_count: number
  fail_count: number
  pass_rate: number
  top_failing_boards: { motherboard_manufacturer: string; motherboard_product: string; fail_count: number }[]
  unresolved_blocking: number
  registered_motherboards: number
  manufacturers_with_defaults: number
  by_check_type: Record<string, Record<string, number>>
}

// ── API Functions ───────────────────────────────────────

export function getQcSettings() {
  return apiGet<{ success: boolean; settings: QcGlobalSettings }>('qc_get_settings')
}

export function saveQcSettings(settings: Partial<QcGlobalSettings>) {
  return apiPostJson<{ success: boolean }>('qc_save_settings', settings)
}

export interface ListMotherboardsParams {
  page?: number
  search?: string
  manufacturer?: string
}

export function listMotherboards(params: ListMotherboardsParams = {}) {
  return apiGet<{
    success: boolean
    motherboards: MotherboardRow[]
    total: number
    page: number
    total_pages: number
    manufacturers: string[]
  }>('qc_list_motherboards', params as Record<string, string | number | boolean>)
}

export function getMotherboard(id: number) {
  return apiGet<{
    success: boolean
    motherboard: MotherboardRow
    effective_rules: Record<string, unknown>
  }>('qc_get_motherboard', { id })
}

export interface UpdateMotherboardInput {
  id: number
  secure_boot_required?: string | null
  secure_boot_enforcement?: string | null
  min_bios_version?: string | null
  recommended_bios_version?: string | null
  bios_enforcement?: string | null
  hackbgrt_enforcement?: string | null
  missing_drivers_enforcement?: string | null
  notes?: string | null
}

export function updateMotherboard(data: UpdateMotherboardInput) {
  return apiPostJson<{ success: boolean }>('qc_update_motherboard', { ...data })
}

export function listManufacturers() {
  return apiGet<{
    success: boolean
    manufacturers: ManufacturerDefault[]
    unconfigured: string[]
  }>('qc_list_manufacturers')
}

export interface UpdateManufacturerInput {
  manufacturer: string
  secure_boot_required?: number | string
  secure_boot_enforcement?: number | string
  min_bios_version?: string | null
  recommended_bios_version?: string | null
  bios_enforcement?: number | string
  hackbgrt_enforcement?: number | string
  notes?: string | null
}

export function updateManufacturer(data: UpdateManufacturerInput) {
  return apiPostJson<{ success: boolean }>('qc_update_manufacturer', { ...data })
}

export interface ListComplianceResultsParams {
  page?: number
  search?: string
  check_type?: string
  check_result?: string
}

export function listComplianceResults(params: ListComplianceResultsParams = {}) {
  return apiGet<{
    success: boolean
    results: ComplianceResult[]
    total: number
    page: number
    total_pages: number
  }>('qc_list_compliance_results', params as Record<string, string | number | boolean>)
}

// ── Grouped by Order ────────────────────────────────────

export interface CheckSummary {
  result: 'pass' | 'info' | 'warning' | 'fail'
  enforcement_level: number
  expected_value: string | null
  actual_value: string | null
  message: string | null
  rule_source: string
}

export interface GroupedComplianceRow {
  order_number: string
  hardware_info_id: number
  motherboard_manufacturer: string | null
  motherboard_product: string | null
  hw_bios_version: string | null
  detected_variant_name: string | null
  detected_line_name: string | null
  checked_at: string
  worst_result: 'pass' | 'info' | 'warning' | 'fail'
  checks: Record<string, CheckSummary>
}

export interface ListComplianceGroupedParams {
  page?: number
  search?: string
  check_result?: string
}

export function listComplianceGrouped(params: ListComplianceGroupedParams = {}) {
  return apiGet<{
    success: boolean
    results: GroupedComplianceRow[]
    total: number
    page: number
    total_pages: number
  }>('qc_list_compliance_grouped', params as Record<string, string | number | boolean>)
}


export function getQcStats() {
  return apiGet<{ success: boolean; stats: QcStats }>('qc_get_stats')
}
