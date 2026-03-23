import { apiGet, apiPostJson } from './client'

export interface TaskTemplate {
  id: number
  task_key: string
  task_name: string
  task_type: 'built_in' | 'custom'
  description: string | null
  default_code: string | null
  default_timeout_seconds: number
  default_on_failure: 'stop' | 'skip' | 'warn'
  is_system: number
  icon: string | null
  created_at: string
  updated_at: string
}

export interface ProductLineTask {
  id: number
  product_line_id: number
  task_template_id: number
  sort_order: number
  enabled: number
  custom_name: string | null
  custom_code: string | null
  custom_timeout_seconds: number | null
  custom_on_failure: 'stop' | 'skip' | 'warn' | null
  // Joined from task_templates
  task_key: string
  template_name: string
  task_type: 'built_in' | 'custom'
  template_description: string | null
  default_code: string | null
  default_timeout_seconds: number
  default_on_failure: 'stop' | 'skip' | 'warn'
  is_system: number
  icon: string | null
}

export interface TaskExecution {
  id: number
  activation_attempt_id: number | null
  product_line_id: number | null
  task_template_id: number
  task_key: string
  task_name: string
  status: 'pending' | 'running' | 'success' | 'failed' | 'skipped' | 'timeout'
  started_at: string | null
  completed_at: string | null
  duration_ms: number | null
  output: string | null
  error_message: string | null
  technician_id: number | null
  order_number: string | null
  created_at: string
}

// ── Task Templates ──

export function listTaskTemplates() {
  return apiGet<{ success: boolean; templates: TaskTemplate[] }>('list_task_templates')
}

export function saveTaskTemplate(data: Partial<TaskTemplate>) {
  return apiPostJson<{ success: boolean; id: number }>('save_task_template', data as Record<string, unknown>)
}

export function deleteTaskTemplate(id: number) {
  return apiPostJson<{ success: boolean }>('delete_task_template', { id })
}

// ── Product Line Tasks ──

export function getProductLineTasks(productLineId: number) {
  return apiGet<{ success: boolean; tasks: ProductLineTask[] }>('get_product_line_tasks', { product_line_id: productLineId })
}

export function saveProductLineTasks(productLineId: number, tasks: Partial<ProductLineTask>[]) {
  return apiPostJson<{ success: boolean; count: number }>('save_product_line_tasks', {
    product_line_id: productLineId,
    tasks,
  })
}

// ── Task Executions ──

export function listTaskExecutions(params?: { product_line_id?: number; limit?: number; offset?: number }) {
  return apiGet<{ success: boolean; executions: TaskExecution[] }>('list_task_executions', params as Record<string, string | number>)
}
