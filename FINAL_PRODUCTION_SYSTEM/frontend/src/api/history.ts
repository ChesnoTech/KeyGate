import { apiGet } from './client'

export interface HistoryRow {
  id: number
  attempted_date: string
  attempted_time: string
  technician_id: string
  order_number: string
  product_key: string
  attempt_result: string
  notes: string | null
  hardware_collected: boolean
  activation_server: string
  activation_unique_id: string
}

export interface ListHistoryParams {
  page?: number
  filter?: string
  search?: string
}

export interface ListHistoryResponse {
  success: boolean
  history: HistoryRow[]
  total: number
  page: number
  pages: number
}

export function listHistory(params: ListHistoryParams = {}) {
  return apiGet<ListHistoryResponse>('list_history', params as Record<string, string | number | boolean>)
}

// --- Hardware details ---

export interface RamModule {
  manufacturer?: string
  capacity_gb?: number
  speed_mhz?: number
  part_number?: string
  serial_number?: string
}

export interface VideoCard {
  name?: string
  driver_version?: string
  adapter_ram?: string
  resolution?: string
}

export interface StorageDevice {
  model?: string
  interface_type?: string
  size_gb?: number
  serial_number?: string
  media_type?: string
  status?: string
}

export interface DiskPartition {
  drive_letter?: string
  file_system?: string
  size_gb?: number
  free_space_gb?: number
}

export interface NetworkAdapter {
  name?: string
  mac_address?: string
  ip_address?: string
  dhcp_enabled?: boolean
  dns_servers?: string
}

export interface HardwareInfo {
  // identifiers
  id: number
  activation_id: number
  order_number: string | null
  technician_id: string | null
  technician_name?: string | null
  product_key?: string | null
  // motherboard & bios
  motherboard_manufacturer: string | null
  motherboard_product: string | null
  motherboard_serial: string | null
  motherboard_version: string | null
  bios_manufacturer: string | null
  bios_version: string | null
  bios_release_date: string | null
  bios_serial_number: string | null
  // cpu
  cpu_name: string | null
  cpu_manufacturer: string | null
  cpu_cores: number | null
  cpu_logical_processors: number | null
  cpu_max_clock_speed: number | null
  cpu_serial: string | null
  // ram
  ram_total_capacity_gb: string | null
  ram_slots_used: number | null
  ram_slots_total: number | null
  ram_modules: string | null // JSON string
  // gpu
  video_cards: string | null // JSON string
  // storage
  storage_devices: string | null // JSON string
  disk_partitions: string | null // JSON string
  complete_disk_layout: string | null
  // os
  os_name: string | null
  os_version: string | null
  os_architecture: string | null
  os_build_number: string | null
  os_install_date: string | null
  os_serial_number: string | null
  secure_boot_enabled: number | null
  computer_name: string | null
  // network
  primary_mac_address: string | null
  local_ip: string | null
  public_ip: string | null
  network_adapters: string | null // JSON string
  // system identity
  system_manufacturer: string | null
  system_product_name: string | null
  system_serial: string | null
  system_uuid: string | null
  chassis_manufacturer: string | null
  chassis_serial: string | null
  chassis_type: string | null
  // tpm
  tpm_present: number | null
  tpm_version: string | null
  tpm_manufacturer: string | null
  // peripherals
  audio_devices: string | null // JSON string
  monitors: string | null // JSON string
  // metadata
  collected_at: string | null
  collection_timestamp: string | null
  collection_method: string | null
  device_fingerprint: string | null
}

export interface GetHardwareResponse {
  success: boolean
  hardware: HardwareInfo
  error?: string
}

export function getHardware(activationId: number) {
  return apiGet<GetHardwareResponse>('get_hardware', { activation_id: activationId })
}
