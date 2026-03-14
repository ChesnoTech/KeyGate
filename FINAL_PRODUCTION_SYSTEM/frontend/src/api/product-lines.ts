import { apiGet, apiPostJson } from './client'

// ── Types ───────────────────────────────────────────────

export interface ProductLine {
  id: number
  name: string
  order_pattern: string
  description: string | null
  enforcement_level: number
  secure_boot_enforcement: number | null
  bios_enforcement: number | null
  hackbgrt_enforcement: number | null
  partition_enforcement: number | null
  missing_drivers_enforcement: number | null
  is_active: number
  created_at: string
  updated_at: string
  variant_count?: number
}

export interface PartitionTemplate {
  id: number
  variant_id: number
  partition_order: number
  partition_name: string
  partition_type: string | null
  expected_size_mb: number
  tolerance_percent: number
  is_flexible: number
}

export interface ProductVariant {
  id: number
  line_id: number
  name: string
  disk_size_min_mb: number
  disk_size_max_mb: number
  is_active: number
  created_at: string
  updated_at: string
  partitions?: PartitionTemplate[]
}

export interface ProductLineDetail extends ProductLine {
  variants: (ProductVariant & { partitions: PartitionTemplate[] })[]
}

// ── API Functions ───────────────────────────────────────

export function getProductLines() {
  return apiGet<{ success: boolean; lines: ProductLine[] }>('get_product_lines')
}

export function getProductLine(id: number) {
  return apiGet<{ success: boolean; line: ProductLineDetail }>('get_product_line', { id })
}

export interface SaveProductLineInput {
  id?: number
  name: string
  order_pattern: string
  description?: string
  enforcement_level: number
  secure_boot_enforcement?: number | null
  bios_enforcement?: number | null
  hackbgrt_enforcement?: number | null
  partition_enforcement?: number | null
  missing_drivers_enforcement?: number | null
}

export function saveProductLine(data: SaveProductLineInput) {
  return apiPostJson<{ success: boolean; id: number }>('save_product_line', data as Record<string, unknown>)
}

export function deleteProductLine(id: number) {
  return apiPostJson<{ success: boolean }>('delete_product_line', { id })
}

export interface SaveProductVariantInput {
  id?: number
  line_id: number
  name: string
  disk_size_min_mb: number
  disk_size_max_mb: number
  partitions: {
    partition_order: number
    partition_name: string
    partition_type?: string
    expected_size_mb: number
    tolerance_percent: number
    is_flexible: boolean
  }[]
}

export function saveProductVariant(data: SaveProductVariantInput) {
  return apiPostJson<{ success: boolean; id: number }>('save_product_variant', data as Record<string, unknown>)
}

export function deleteProductVariant(id: number) {
  return apiPostJson<{ success: boolean }>('delete_product_variant', { id })
}
