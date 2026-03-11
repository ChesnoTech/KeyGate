import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getNotifications,
  markNotificationsRead,
  getPushPreferences,
  savePushPreferences,
  type PushPreferences,
} from '@/api/notifications'

export function useNotifications() {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: () => getNotifications(),
  })
}

export function useMarkRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids?: number[]) => markNotificationsRead(ids),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })
}

export function usePushPreferences() {
  return useQuery({
    queryKey: ['notifications', 'preferences'],
    queryFn: () => getPushPreferences(),
  })
}

export function useSavePushPreferences() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (prefs: PushPreferences) => savePushPreferences(prefs),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications', 'preferences'] })
      toast.success(t('toast.preferences_saved', 'Notification preferences saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
