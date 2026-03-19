import { apiGet, apiPost, apiPostJson } from './client'

const API_BASE = '/activate/admin_v2.php'

export interface ClientResource {
  id: number
  resource_key: string
  filename: string
  original_filename: string
  file_size: number
  mime_type: string
  checksum_sha256: string
  description: string | null
  uploaded_by: number | null
  uploaded_by_name: string | null
  created_at: string
  updated_at: string
}

export interface ListResourcesResponse {
  success: boolean
  resources: ClientResource[]
}

export interface UploadResourceResponse {
  success: boolean
  resource: Partial<ClientResource>
}

export function listClientResources() {
  return apiGet<ListResourcesResponse>('list_client_resources')
}

export function uploadClientResource(resourceKey: string, file: File) {
  return apiPost<UploadResourceResponse>('upload_client_resource', {
    resource_key: resourceKey,
    resource_file: file,
  })
}

export function deleteClientResource(resourceKey: string) {
  return apiPostJson<{ success: boolean }>('delete_client_resource', {
    resource_key: resourceKey,
  })
}

export function getDownloadUrl(resourceKey: string): string {
  return `${API_BASE}?action=download_client_resource&resource_key=${encodeURIComponent(resourceKey)}`
}
