import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getLicenseStatus,
  registerLicense,
  deactivateLicense,
  generateDevLicense,
  claimLicense,
  migrateLegacyLicense,
} from '@/api/license'

export function useLicenseStatus() {
  return useQuery({
    queryKey: ['license-status'],
    queryFn: () => getLicenseStatus(),
    staleTime: 60_000 * 5,
  })
}

export function useRegisterLicense() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (key: string) => registerLicense(key),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['license-status'] })
      if (data.success) {
        toast.success(data.message || t('license.registered', 'License registered'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeactivateLicense() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: () => deactivateLicense(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['license-status'] })
      toast.success(t('license.deactivated', 'License deactivated'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useGenerateDevLicense() {
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ tier, devToken }: { tier: string; devToken: string }) =>
      generateDevLicense(tier, devToken),
    onSuccess: (data) => {
      if (data.success) {
        toast.success(data.message || t('license.dev_generated', 'Dev license generated'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useClaimLicense() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ email, sponsorLogin }: { email: string; sponsorLogin?: string }) =>
      claimLicense(email, sponsorLogin),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['license-status'] })
      if (data.success) {
        toast.success(data.message || t('license.claimed', 'License claimed and activated'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useMigrateLegacyLicense() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (key: string) => migrateLegacyLicense(key),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['license-status'] })
      if (data.success) {
        toast.success(data.message || t('license.migrated', 'License migrated to RS256'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
