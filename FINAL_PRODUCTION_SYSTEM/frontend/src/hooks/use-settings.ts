import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getAltServerSettings,
  saveAltServerSettings,
  getOrderFieldSettings,
  saveOrderFieldSettings,
  type AltServerConfig,
  type OrderFieldConfig,
} from '@/api/settings'

export function useAltServerSettings() {
  return useQuery({
    queryKey: ['settings', 'alt-server'],
    queryFn: () => getAltServerSettings(),
  })
}

export function useSaveAltServerSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: AltServerConfig) => saveAltServerSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'alt-server'] })
      toast.success(t('toast.settings_saved', 'Settings saved successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useOrderFieldSettings() {
  return useQuery({
    queryKey: ['settings', 'order-fields'],
    queryFn: () => getOrderFieldSettings(),
  })
}

export function useSaveOrderFieldSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: OrderFieldConfig) => saveOrderFieldSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'order-fields'] })
      toast.success(t('toast.settings_saved', 'Settings saved successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
