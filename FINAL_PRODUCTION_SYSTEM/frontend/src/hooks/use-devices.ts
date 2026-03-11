import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { listDevices, updateDeviceStatus, deleteDevice, type ListDevicesParams } from '@/api/devices'

export function useDevices(params: ListDevicesParams = {}) {
  return useQuery({
    queryKey: ['devices', params],
    queryFn: () => listDevices(params),
  })
}

export function useUpdateDeviceStatus() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ device_id, status, reason }: { device_id: number; status: string; reason?: string }) =>
      updateDeviceStatus(device_id, status, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['devices'] })
      toast.success(t('toast.device_updated', 'Device status updated'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteDevice() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (device_id: number) => deleteDevice(device_id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['devices'] })
      toast.success(t('toast.device_deleted', 'Device deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
