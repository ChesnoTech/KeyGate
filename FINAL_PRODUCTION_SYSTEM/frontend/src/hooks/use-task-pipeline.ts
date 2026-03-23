import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  listTaskTemplates,
  saveTaskTemplate,
  deleteTaskTemplate,
  getProductLineTasks,
  saveProductLineTasks,
  listTaskExecutions,
} from '@/api/task-pipeline'
import type { ProductLineTask, TaskTemplate } from '@/api/task-pipeline'

export function useTaskTemplates() {
  return useQuery({
    queryKey: ['task-templates'],
    queryFn: () => listTaskTemplates(),
  })
}

export function useSaveTaskTemplate() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: Partial<TaskTemplate>) => saveTaskTemplate(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['task-templates'] })
      toast.success(t('toast.task_template_saved', 'Task template saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteTaskTemplate() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => deleteTaskTemplate(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['task-templates'] })
      toast.success(t('toast.task_template_deleted', 'Task template deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useProductLineTasks(productLineId: number) {
  return useQuery({
    queryKey: ['product-line-tasks', productLineId],
    queryFn: () => getProductLineTasks(productLineId),
    enabled: productLineId > 0,
  })
}

export function useSaveProductLineTasks() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ productLineId, tasks }: { productLineId: number; tasks: Partial<ProductLineTask>[] }) =>
      saveProductLineTasks(productLineId, tasks),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: ['product-line-tasks', vars.productLineId] })
      toast.success(t('toast.pipeline_saved', 'Task pipeline saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useTaskExecutions(productLineId?: number) {
  return useQuery({
    queryKey: ['task-executions', productLineId],
    queryFn: () => listTaskExecutions(productLineId ? { product_line_id: productLineId } : undefined),
  })
}
