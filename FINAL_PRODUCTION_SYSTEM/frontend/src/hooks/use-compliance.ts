import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getQcSettings,
  saveQcSettings,
  listMotherboards,
  updateMotherboard,
  listManufacturers,
  updateManufacturer,
  listComplianceResults,
  listComplianceGrouped,
  getQcStats,
  type ListMotherboardsParams,
  type ListComplianceResultsParams,
  type ListComplianceGroupedParams,
  type QcGlobalSettings,
  type UpdateMotherboardInput,
  type UpdateManufacturerInput,
} from '@/api/compliance'

export function useQcSettings() {
  return useQuery({
    queryKey: ['compliance', 'settings'],
    queryFn: getQcSettings,
  })
}

export function useSaveQcSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (settings: Partial<QcGlobalSettings>) => saveQcSettings(settings),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['compliance', 'settings'] })
      toast.success(t('toast.qc_settings_saved', 'QC settings saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useMotherboards(params: ListMotherboardsParams = {}) {
  return useQuery({
    queryKey: ['compliance', 'motherboards', params],
    queryFn: () => listMotherboards(params),
  })
}

export function useUpdateMotherboard() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: UpdateMotherboardInput) => updateMotherboard(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['compliance', 'motherboards'] })
      toast.success(t('toast.motherboard_updated', 'Motherboard rule updated'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useManufacturers() {
  return useQuery({
    queryKey: ['compliance', 'manufacturers'],
    queryFn: listManufacturers,
  })
}

export function useUpdateManufacturer() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: UpdateManufacturerInput) => updateManufacturer(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['compliance', 'manufacturers'] })
      qc.invalidateQueries({ queryKey: ['compliance', 'motherboards'] })
      toast.success(t('toast.manufacturer_updated', 'Manufacturer rule updated'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useComplianceResults(params: ListComplianceResultsParams = {}) {
  return useQuery({
    queryKey: ['compliance', 'results', params],
    queryFn: () => listComplianceResults(params),
  })
}

export function useComplianceGrouped(params: ListComplianceGroupedParams = {}) {
  return useQuery({
    queryKey: ['compliance', 'grouped', params],
    queryFn: () => listComplianceGrouped(params),
  })
}


export function useQcStats() {
  return useQuery({
    queryKey: ['compliance', 'stats'],
    queryFn: getQcStats,
  })
}
