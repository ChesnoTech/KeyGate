import { apiGet, apiPost } from './client'

export interface PermissionItem {
  id: number
  permission_key: string
  description: string
  is_dangerous: boolean
  granted?: boolean
}

export interface PermissionCategory {
  id: number
  category_key: string
  display_name: string
  icon: string | null
  sort_order: number
  permissions: PermissionItem[]
}

export interface RoleRow {
  id: number
  role_name: string
  display_name: string
  description: string
  role_type: string
  is_system_role: boolean
  color: string
  created_at: string
  permissions: Array<{
    id: number
    permission_key: string
    is_dangerous: boolean
    granted: boolean
  }>
}

export interface ListRolesResponse {
  success: boolean
  roles: RoleRow[]
}

export interface GetRoleResponse {
  success: boolean
  role: RoleRow
}

export interface ListPermissionsResponse {
  success: boolean
  categories: PermissionCategory[]
}

export interface CreateRoleData {
  role_name: string
  display_name: string
  description: string
  role_type: string
  color: string
  permission_ids: number[]
}

export interface UpdateRoleData extends CreateRoleData {
  role_id: number
}

export function listRoles(roleType?: string) {
  return apiGet<ListRolesResponse>('acl_list_roles', roleType ? { role_type: roleType } : undefined)
}

export function getRole(roleId: number) {
  return apiGet<GetRoleResponse>('acl_get_role', { role_id: roleId })
}

export function listPermissions() {
  return apiGet<ListPermissionsResponse>('acl_list_permissions')
}

export function createRole(data: CreateRoleData) {
  return apiPost<{ success: boolean; error?: string }>('acl_create_role', {
    role_name: data.role_name,
    display_name: data.display_name,
    description: data.description,
    role_type: data.role_type,
    color: data.color,
    permission_ids: data.permission_ids.join(','),
  })
}

export function updateRole(data: UpdateRoleData) {
  return apiPost<{ success: boolean; error?: string }>('acl_update_role', {
    role_id: data.role_id,
    role_name: data.role_name,
    display_name: data.display_name,
    description: data.description,
    role_type: data.role_type,
    color: data.color,
    permission_ids: data.permission_ids.join(','),
  })
}

export function deleteRole(roleId: number) {
  return apiPost<{ success: boolean; error?: string }>('acl_delete_role', {
    role_id: roleId,
  })
}
