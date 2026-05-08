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
  redetectHardware,
  rebindLicense,
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

// P1: re-detect server hardware fingerprint (force refresh of cached value).
export function useRedetectHardware() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: () => redetectHardware(),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['license-status'] })
      if (data.success) {
        toast.success(t('license.hw_redetected', 'Hardware fingerprint re-detected'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// P1: rebind license to current host's hardware fingerprint.
// Worker enforces 3 rebinds per rolling 365 days.
export function useRebindLicense() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (reason?: string) => rebindLicense(reason),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['license-status'] })
      if (data.success) {
        toast.success(data.message || t('license.rebound', 'License rebound to current hardware'))
      } else {
        toast.error(data.error || t('license.rebind_failed', 'Rebind failed'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
