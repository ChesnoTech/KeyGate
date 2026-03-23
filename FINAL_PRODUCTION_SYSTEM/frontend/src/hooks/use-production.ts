import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  listBuildReports,
  getBuildReport,
  updateBuildReportShipping,
  getKeyPoolStatus,
  saveKeyPoolConfig,
  checkHardwareBinding,
  releaseHardwareBinding,
  importDpkBatch,
  listDpkBatches,
  listWorkOrders,
  getWorkOrder,
  saveWorkOrder,
  deleteWorkOrder,
} from '@/api/production'

// ── Build Reports ──

export function useBuildReports(params?: Record<string, string | number>) {
  return useQuery({
    queryKey: ['build-reports', params],
    queryFn: () => listBuildReports(params),
  })
}

export function useBuildReport(id: number) {
  return useQuery({
    queryKey: ['build-report', id],
    queryFn: () => getBuildReport(id),
    enabled: id > 0,
  })
}

export function useUpdateBuildReportShipping() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => updateBuildReportShipping(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['build-reports'] })
      toast.success(t('toast.shipping_updated', 'Shipping status updated'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── Key Pool ──

export function useKeyPoolStatus() {
  return useQuery({
    queryKey: ['key-pool'],
    queryFn: () => getKeyPoolStatus(),
    refetchInterval: 60_000,
  })
}

export function useSaveKeyPoolConfig() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => saveKeyPoolConfig(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['key-pool'] })
      toast.success(t('toast.pool_config_saved', 'Key pool config saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── Hardware Bindings ──

export function useHardwareBindings(params?: Record<string, string | number>) {
  return useQuery({
    queryKey: ['hardware-bindings', params],
    queryFn: () => checkHardwareBinding(params),
  })
}

export function useReleaseBinding() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => releaseHardwareBinding(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hardware-bindings'] })
      toast.success(t('toast.binding_released', 'Hardware binding released'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── DPK Import ──

export function useDpkBatches() {
  return useQuery({
    queryKey: ['dpk-batches'],
    queryFn: () => listDpkBatches(),
  })
}

export function useImportDpkBatch() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ file, batchName, productEdition }: { file: File; batchName: string; productEdition: string }) =>
      importDpkBatch(file, batchName, productEdition),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['dpk-batches'] })
      qc.invalidateQueries({ queryKey: ['key-pool'] })
      toast.success(t('toast.dpk_imported', 'Imported {{count}} keys', { count: data.imported }))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── Work Orders ──

export function useWorkOrders(params?: Record<string, string | number>) {
  return useQuery({
    queryKey: ['work-orders', params],
    queryFn: () => listWorkOrders(params),
  })
}

export function useWorkOrder(id: number) {
  return useQuery({
    queryKey: ['work-order', id],
    queryFn: () => getWorkOrder(id),
    enabled: id > 0,
  })
}

export function useSaveWorkOrder() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => saveWorkOrder(data),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['work-orders'] })
      toast.success(t('toast.work_order_saved', 'Work order {{num}} saved', { num: result.work_order_number }))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteWorkOrder() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => deleteWorkOrder(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['work-orders'] })
      toast.success(t('toast.work_order_deleted', 'Work order deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
