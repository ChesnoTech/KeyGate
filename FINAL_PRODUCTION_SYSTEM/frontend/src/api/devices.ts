import { apiGet, apiPostJson } from './client'

export interface UsbDeviceRow {
  device_id: number
  technician_id: string
  full_name: string
  device_name: string
  device_serial_number: string
  device_manufacturer: string
  device_model: string
  device_capacity_gb: number
  device_description: string
  device_status: string
  registered_date: string
  disabled_date: string | null
  disabled_reason: string | null
}

export interface ListDevicesParams {
  page?: number
  filter?: string
  search?: string
}

export interface ListDevicesResponse {
  success: boolean
  devices: UsbDeviceRow[]
  stats: { active: number; disabled: number; lost: number; stolen: number; total: number }
}

export function listDevices(params: ListDevicesParams = {}) {
  return apiGet<ListDevicesResponse>('list_usb_devices', params as Record<string, string | number | boolean>)
}

export function updateDeviceStatus(device_id: number, status: string, reason?: string) {
  return apiPostJson<{ success: boolean; error?: string }>('update_usb_device_status', {
    device_id,
    status,
    ...(reason ? { reason } : {}),
  })
}

export function deleteDevice(device_id: number) {
  return apiPostJson<{ success: boolean; error?: string }>('delete_usb_device', { device_id })
}
