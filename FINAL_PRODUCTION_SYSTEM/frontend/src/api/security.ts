import { apiGet, apiPostJson } from './client'

export interface TwoFaStatusResponse {
  success: boolean
  enabled: boolean
  verified_at: string | null
  backup_codes_remaining: number
}

export interface TrustedNetworkRow {
  id: number
  network_name: string
  ip_range: string
  bypass_2fa: boolean
  allow_usb_auth: boolean
  description: string
  created_by_username: string
  created_at: string
}

export interface ListTrustedNetworksResponse {
  success: boolean
  networks: TrustedNetworkRow[]
}

export interface AddTrustedNetworkData {
  network_name: string
  ip_range: string
  bypass_2fa: boolean
  allow_usb_auth: boolean
  description: string
}

export function get2faStatus() {
  return apiGet<TwoFaStatusResponse>('get_2fa_status')
}

export function listTrustedNetworks() {
  return apiGet<ListTrustedNetworksResponse>('list_trusted_networks')
}

export function addTrustedNetwork(data: AddTrustedNetworkData) {
  return apiPostJson<{ success: boolean; error?: string }>('add_trusted_network', {
    network_name: data.network_name,
    ip_range: data.ip_range,
    bypass_2fa: data.bypass_2fa,
    allow_usb_auth: data.allow_usb_auth,
    description: data.description,
  })
}

export function deleteTrustedNetwork(networkId: number) {
  return apiPostJson<{ success: boolean; error?: string }>('delete_trusted_network', {
    network_id: networkId,
  })
}
