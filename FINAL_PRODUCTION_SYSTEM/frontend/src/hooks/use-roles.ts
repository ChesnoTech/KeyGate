import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  listRoles,
  getRole,
  listPermissions,
  createRole,
  updateRole,
  deleteRole,
  type CreateRoleData,
  type UpdateRoleData,
} from '@/api/roles'

export function useRoles(roleType?: string) {
  return useQuery({
    queryKey: ['roles', roleType],
    queryFn: () => listRoles(roleType),
  })
}

export function useRole(roleId: number | null) {
  return useQuery({
    queryKey: ['roles', 'detail', roleId],
    queryFn: () => getRole(roleId!),
    enabled: roleId !== null,
  })
}

export function usePermissions() {
  return useQuery({
    queryKey: ['roles', 'permissions'],
    queryFn: () => listPermissions(),
  })
}

export function useCreateRole() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: CreateRoleData) => createRole(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['roles'] })
      toast.success(t('toast.role_created', 'Role created successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useUpdateRole() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: UpdateRoleData) => updateRole(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['roles'] })
      qc.invalidateQueries({ queryKey: ['roles', 'detail'] })
      toast.success(t('toast.role_updated', 'Role updated successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteRole() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (roleId: number) => deleteRole(roleId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['roles'] })
      toast.success(t('toast.role_deleted', 'Role deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
