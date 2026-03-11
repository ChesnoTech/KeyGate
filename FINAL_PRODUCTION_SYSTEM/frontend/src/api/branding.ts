import { apiGet, apiPost, apiPostJson } from './client'

export interface BrandingConfig {
  brand_company_name: string
  brand_app_version: string
  brand_logo_path: string
  brand_favicon_path: string
  brand_login_title: string
  brand_login_subtitle: string
  brand_primary_color: string
  brand_sidebar_color: string
  brand_accent_color: string
}

export interface GetBrandingResponse {
  success: boolean
  config: BrandingConfig
}

export function getBranding() {
  return apiGet<GetBrandingResponse>('get_branding')
}

export function saveBranding(config: Partial<BrandingConfig>) {
  return apiPostJson<{ success: boolean; config: BrandingConfig; error?: string }>(
    'save_branding',
    config as unknown as Record<string, unknown>
  )
}

export function uploadBrandAsset(file: File, assetType: 'logo' | 'favicon') {
  return apiPost<{ success: boolean; path: string; error?: string }>(
    'upload_brand_asset',
    { file, asset_type: assetType }
  )
}

export function deleteBrandAsset(assetType: 'logo' | 'favicon') {
  return apiPostJson<{ success: boolean; error?: string }>('delete_brand_asset', {
    asset_type: assetType,
  })
}
