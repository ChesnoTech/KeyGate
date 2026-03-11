import { apiGet } from './client'

export interface LogRow {
  created_at: string
  username: string
  action: string
  description: string
  ip_address: string
}

export interface ListLogsParams {
  page?: number
  search?: string
}

export interface ListLogsResponse {
  success: boolean
  logs: LogRow[]
  total: number
  page: number
  pages: number
}

export function listLogs(params: ListLogsParams = {}) {
  return apiGet<ListLogsResponse>('list_logs', params as Record<string, string | number | boolean>)
}
