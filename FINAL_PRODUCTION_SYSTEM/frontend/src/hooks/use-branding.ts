import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getBranding,
  saveBranding,
  uploadBrandAsset,
  deleteBrandAsset,
  type BrandingConfig,
} from '@/api/branding'

export function useBranding() {
  return useQuery({
    queryKey: ['branding'],
    queryFn: () => getBranding(),
    staleTime: 5 * 60 * 1000,
  })
}

export function useSaveBranding() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: Partial<BrandingConfig>) => saveBranding(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branding'] })
      toast.success(t('toast.branding_saved', 'Branding saved successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useUploadBrandAsset() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ file, assetType }: { file: File; assetType: 'logo' | 'favicon' }) =>
      uploadBrandAsset(file, assetType),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branding'] })
      toast.success(t('toast.brand_asset_uploaded', 'Asset uploaded successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteBrandAsset() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (assetType: 'logo' | 'favicon') => deleteBrandAsset(assetType),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['branding'] })
      toast.success(t('toast.brand_asset_deleted', 'Asset removed'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
