import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getUpgradeStatus,
  uploadUpgradePackage,
  runPreflight,
  runBackup,
  applyUpgrade,
  verifyUpgrade,
  rollbackUpgrade,
  getUpgradeHistory,
} from '@/api/upgrade'

export function useUpgradeStatus() {
  return useQuery({
    queryKey: ['upgrade-status'],
    queryFn: () => getUpgradeStatus(),
  })
}

export function useUpgradeHistory() {
  return useQuery({
    queryKey: ['upgrade-history'],
    queryFn: () => getUpgradeHistory(),
  })
}

export function useUploadPackage() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (file: File) => uploadUpgradePackage(file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['upgrade-status'] })
      toast.success(t('toast.upgrade_uploaded', 'Upgrade package uploaded'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useRunPreflight() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (upgradeId: number) => runPreflight(upgradeId),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['upgrade-status'] })
      if (data.all_passed) {
        toast.success(t('toast.preflight_complete', 'All pre-flight checks passed'))
      } else {
        toast.warning(t('upgrade.fix_and_retry', 'Some checks failed — fix issues and retry'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useRunBackup() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (upgradeId: number) => runBackup(upgradeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['upgrade-status'] })
      toast.success(t('toast.backup_created', 'Pre-upgrade backup created'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useApplyUpgrade() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (upgradeId: number) => applyUpgrade(upgradeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['upgrade-status'] })
      toast.success(t('toast.upgrade_applied', 'Upgrade applied successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useVerifyUpgrade() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (upgradeId: number) => verifyUpgrade(upgradeId),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['upgrade-status'] })
      qc.invalidateQueries({ queryKey: ['upgrade-history'] })
      if (data.all_passed) {
        toast.success(t('toast.upgrade_verified', 'Upgrade verified successfully'))
      } else {
        toast.warning(t('upgrade.verify_issues', 'Verification found issues'))
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useRollbackUpgrade() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (upgradeId: number) => rollbackUpgrade(upgradeId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['upgrade-status'] })
      qc.invalidateQueries({ queryKey: ['upgrade-history'] })
      toast.success(t('toast.rollback_complete', 'System rolled back successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
