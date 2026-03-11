import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  listTechs,
  addTech,
  editTech,
  toggleTech,
  resetPassword,
  deleteTech,
  type ListTechsParams,
  type AddTechInput,
  type EditTechInput,
} from '@/api/technicians'

export function useTechnicians(params: ListTechsParams = {}) {
  return useQuery({
    queryKey: ['technicians', params],
    queryFn: () => listTechs(params),
  })
}

export function useAddTech() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: AddTechInput) => addTech(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['technicians'] })
      toast.success(t('toast.technician_added', 'Technician added successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useEditTech() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: EditTechInput) => editTech(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['technicians'] })
      toast.success(t('toast.technician_updated', 'Technician updated successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useToggleTech() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => toggleTech(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['technicians'] })
      toast.success(t('toast.technician_toggled', 'Technician status updated'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useResetPassword() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ id, new_password }: { id: number; new_password: string }) =>
      resetPassword(id, new_password),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['technicians'] })
      toast.success(t('toast.password_reset', 'Password reset successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteTech() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => deleteTech(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['technicians'] })
      toast.success(t('toast.technician_deleted', 'Technician deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
