import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  get2faStatus,
  listTrustedNetworks,
  addTrustedNetwork,
  deleteTrustedNetwork,
  type AddTrustedNetworkData,
} from '@/api/security'

export function use2faStatus() {
  return useQuery({
    queryKey: ['security', '2fa'],
    queryFn: () => get2faStatus(),
  })
}

export function useTrustedNetworks() {
  return useQuery({
    queryKey: ['security', 'networks'],
    queryFn: () => listTrustedNetworks(),
  })
}

export function useAddTrustedNetwork() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: AddTrustedNetworkData) => addTrustedNetwork(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['security', 'networks'] })
      toast.success(t('toast.network_added', 'Trusted network added'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteTrustedNetwork() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (networkId: number) => deleteTrustedNetwork(networkId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['security', 'networks'] })
      toast.success(t('toast.network_deleted', 'Network removed'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
