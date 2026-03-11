import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  listIntegrations,
  getIntegration,
  saveIntegration,
  testIntegration,
  retryIntegrationEvents,
} from '@/api/integrations'

export function useIntegrations() {
  return useQuery({
    queryKey: ['integrations'],
    queryFn: () => listIntegrations(),
  })
}

export function useIntegration(key: string | null) {
  return useQuery({
    queryKey: ['integrations', 'detail', key],
    queryFn: () => getIntegration(key!),
    enabled: !!key,
  })
}

export function useSaveIntegration() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: saveIntegration,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['integrations'] })
      qc.invalidateQueries({ queryKey: ['integrations', 'detail'] })
      toast.success(t('toast.integration_saved', 'Integration settings saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useTestIntegration() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (key: string) => testIntegration(key),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['integrations'] })
      qc.invalidateQueries({ queryKey: ['integrations', 'detail'] })
      if (data.success) {
        toast.success(data.message || 'Connection successful')
      } else {
        toast.error(data.error || 'Connection failed')
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useRetryEvents() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (key: string) => retryIntegrationEvents(key),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['integrations'] })
      qc.invalidateQueries({ queryKey: ['integrations', 'detail'] })
      if (data.success) {
        toast.success(
          t('toast.events_retried', 'Retried {{retried}}, succeeded {{succeeded}}', {
            retried: data.retried,
            succeeded: data.succeeded,
          })
        )
      }
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
