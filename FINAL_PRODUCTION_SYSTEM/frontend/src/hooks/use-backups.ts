import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { listBackups, triggerManualBackup } from '@/api/backups'

export function useBackups() {
  return useQuery({
    queryKey: ['backups'],
    queryFn: () => listBackups(),
  })
}

export function useTriggerBackup() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: () => triggerManualBackup(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['backups'] })
      toast.success(t('toast.backup_triggered', 'Backup triggered successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
