import { apiGet, apiPost } from './client'

export interface TechnicianRow {
  id: number
  technician_id: string
  full_name: string
  email: string
  is_active: number | boolean
  last_login: string | null
  created_at: string
  preferred_server: string
}

export interface TechnicianDetail {
  id: number
  technician_id: string
  full_name: string
  email: string
  is_active: number | boolean
  preferred_server: string
  preferred_language: string
}

export interface ListTechsParams {
  page?: number
  search?: string
}

export interface ListTechsResponse {
  success: boolean
  technicians: TechnicianRow[]
  total: number
  page: number
  pages: number
}

export function listTechs(params: ListTechsParams = {}) {
  return apiGet<ListTechsResponse>('list_techs', params as Record<string, string | number | boolean>)
}

export function getTech(id: number) {
  return apiGet<{ success: boolean; technician: TechnicianDetail }>('get_tech', { id })
}

export interface AddTechInput {
  technician_id: string
  password: string
  full_name: string
  email: string
  preferred_server: string
  preferred_language: string
}

export interface EditTechInput {
  id: number
  full_name: string
  email: string
  preferred_server: string
  preferred_language: string
  is_active: boolean
}

export function addTech(data: AddTechInput) {
  return apiPost<{ success: boolean; error?: string }>('add_tech', { ...data })
}

export function editTech(data: EditTechInput) {
  return apiPost<{ success: boolean; error?: string }>('edit_tech', { ...data })
}

export function toggleTech(id: number) {
  return apiPost<{ success: boolean }>('toggle_tech', { id })
}

export function resetPassword(id: number, new_password: string) {
  return apiPost<{ success: boolean }>('reset_password', { id, new_password })
}

export function deleteTech(id: number) {
  return apiPost<{ success: boolean }>('delete_tech', { id })
}
