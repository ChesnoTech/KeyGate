import { useState, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import {
  GripVertical,
  Plus,
  Trash2,
  Save,
  ChevronDown,
  ChevronUp,
  Play,
  Pause,
  Settings2,
  Terminal,
  Loader2,
  Cpu,
  ShieldCheck,
  Key,
  Wifi,
  Fingerprint,
  Upload,
  FileCheck,
  Zap,
  Moon,
  Clock,
  PenLine,
  PackagePlus,
  Gauge,
  type LucideIcon,
} from 'lucide-react'
import {
  useTaskTemplates,
  useSaveTaskTemplate,
  useDeleteTaskTemplate,
  useProductLineTasks,
  useSaveProductLineTasks,
} from '@/hooks/use-task-pipeline'
import type { TaskTemplate, ProductLineTask } from '@/api/task-pipeline'

// Icon lookup map
const ICON_MAP: Record<string, LucideIcon> = {
  Cpu, ShieldCheck, Key, Wifi, Fingerprint, Upload, FileCheck,
  Zap, Moon, Clock, PenLine, PackagePlus, Gauge, Terminal,
  Settings2, Play,
}

function TaskIcon({ name, className }: { name: string | null; className?: string }) {
  const Icon = ICON_MAP[name || ''] || Terminal
  return <Icon className={className || 'h-4 w-4'} />
}

// ── Task Template Editor Dialog ─────────────────────────────

function TemplateEditor({
  template,
  onSave,
  onClose,
  isPending,
}: {
  template: Partial<TaskTemplate> | null
  onSave: (data: Partial<TaskTemplate>) => void
  onClose: () => void
  isPending: boolean
}) {
  const { t } = useTranslation()
  const [form, setForm] = useState<Partial<TaskTemplate>>(template || {
    task_key: '',
    task_name: '',
    task_type: 'custom',
    description: '',
    default_code: '',
    default_timeout_seconds: 60,
    default_on_failure: 'stop',
    icon: 'Terminal',
  })

  const isSystem = !!template?.is_system

  return (
    <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
      <div className="bg-background rounded-lg shadow-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div className="p-6 space-y-4">
          <h3 className="text-lg font-semibold">
            {template?.id ? t('pipeline.edit_template', 'Edit Task Template') : t('pipeline.new_template', 'New Task Template')}
          </h3>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>{t('pipeline.task_key', 'Task Key')}</Label>
              <Input
                value={form.task_key || ''}
                onChange={e => setForm({ ...form, task_key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '') })}
                disabled={isSystem}
                placeholder="my_custom_task"
              />
            </div>
            <div>
              <Label>{t('pipeline.task_name', 'Display Name')}</Label>
              <Input
                value={form.task_name || ''}
                onChange={e => setForm({ ...form, task_name: e.target.value })}
                placeholder="My Custom Task"
              />
            </div>
          </div>

          <div>
            <Label>{t('common.description', 'Description')}</Label>
            <Input
              value={form.description || ''}
              onChange={e => setForm({ ...form, description: e.target.value })}
              placeholder="What this task does..."
            />
          </div>

          {!isSystem && (
            <div>
              <Label>{t('pipeline.code', 'PowerShell Code')}</Label>
              <textarea
                className="w-full min-h-[150px] p-3 rounded-md border bg-muted font-mono text-sm resize-y"
                value={form.default_code || ''}
                onChange={e => setForm({ ...form, default_code: e.target.value })}
                placeholder="# Your PowerShell code here&#10;Write-Host 'Hello from task'"
              />
            </div>
          )}

          <div className="grid grid-cols-3 gap-4">
            <div>
              <Label>{t('pipeline.timeout', 'Timeout (sec)')}</Label>
              <Input
                type="number"
                value={form.default_timeout_seconds || 60}
                onChange={e => setForm({ ...form, default_timeout_seconds: parseInt(e.target.value) || 60 })}
              />
            </div>
            <div>
              <Label>{t('pipeline.on_failure', 'On Failure')}</Label>
              <select
                className="w-full h-10 rounded-md border px-3 bg-background text-sm"
                value={form.default_on_failure || 'stop'}
                onChange={e => setForm({ ...form, default_on_failure: e.target.value as 'stop' | 'skip' | 'warn' })}
              >
                <option value="stop">{t('pipeline.failure_stop', 'Stop Pipeline')}</option>
                <option value="skip">{t('pipeline.failure_skip', 'Skip & Continue')}</option>
                <option value="warn">{t('pipeline.failure_warn', 'Warn & Continue')}</option>
              </select>
            </div>
            <div>
              <Label>{t('pipeline.icon', 'Icon')}</Label>
              <select
                className="w-full h-10 rounded-md border px-3 bg-background text-sm"
                value={form.icon || 'Terminal'}
                onChange={e => setForm({ ...form, icon: e.target.value })}
              >
                {Object.keys(ICON_MAP).map(name => (
                  <option key={name} value={name}>{name}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={onClose}>{t('common.cancel', 'Cancel')}</Button>
            <Button onClick={() => onSave(form)} disabled={isPending}>
              {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              <Save className="mr-1 h-4 w-4" />
              {t('common.save', 'Save')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Pipeline Task Row (in product line editor) ──────────────

function PipelineTaskRow({
  task,
  index,
  total,
  onMoveUp,
  onMoveDown,
  onToggle,
  onRemove,
  onEditOverrides,
}: {
  task: ProductLineTask
  index: number
  total: number
  onMoveUp: () => void
  onMoveDown: () => void
  onToggle: () => void
  onRemove: () => void
  onEditOverrides: () => void
}) {
  const { t } = useTranslation()
  const displayName = task.custom_name || task.template_name
  const failureAction = task.custom_on_failure || task.default_on_failure
  const timeout = task.custom_timeout_seconds || task.default_timeout_seconds

  return (
    <div className={`flex items-center gap-3 p-3 rounded-lg border ${task.enabled ? 'bg-background' : 'bg-muted/50 opacity-60'}`}>
      <div className="flex flex-col gap-0.5">
        <button onClick={onMoveUp} disabled={index === 0} className="text-muted-foreground hover:text-foreground disabled:opacity-30">
          <ChevronUp className="h-3.5 w-3.5" />
        </button>
        <GripVertical className="h-3.5 w-3.5 text-muted-foreground" />
        <button onClick={onMoveDown} disabled={index === total - 1} className="text-muted-foreground hover:text-foreground disabled:opacity-30">
          <ChevronDown className="h-3.5 w-3.5" />
        </button>
      </div>

      <TaskIcon name={task.icon} className="h-5 w-5 text-muted-foreground shrink-0" />

      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium truncate">{displayName}</span>
          <Badge variant="outline" className="text-[10px] px-1">
            {task.task_type === 'built_in' ? t('pipeline.built_in', 'Built-in') : t('pipeline.custom', 'Custom')}
          </Badge>
          {task.custom_code && (
            <Badge variant="secondary" className="text-[10px] px-1">
              {t('pipeline.overridden', 'Overridden')}
            </Badge>
          )}
        </div>
        <div className="flex items-center gap-3 text-xs text-muted-foreground mt-0.5">
          <span>{task.task_key}</span>
          <span>·</span>
          <span>{timeout}s</span>
          <span>·</span>
          <span className={failureAction === 'stop' ? 'text-red-500' : failureAction === 'warn' ? 'text-yellow-500' : 'text-green-500'}>
            {failureAction}
          </span>
        </div>
      </div>

      <div className="flex items-center gap-1">
        <button onClick={onEditOverrides} className="p-1.5 rounded hover:bg-muted" title={t('pipeline.customize', 'Customize')}>
          <Settings2 className="h-4 w-4 text-muted-foreground" />
        </button>
        <button onClick={onToggle} className="p-1.5 rounded hover:bg-muted" title={task.enabled ? t('common.disable', 'Disable') : t('common.enable', 'Enable')}>
          {task.enabled ? <Play className="h-4 w-4 text-green-500" /> : <Pause className="h-4 w-4 text-muted-foreground" />}
        </button>
        <button onClick={onRemove} className="p-1.5 rounded hover:bg-muted" title={t('common.remove', 'Remove')}>
          <Trash2 className="h-4 w-4 text-red-500" />
        </button>
      </div>
    </div>
  )
}

// ── Main Page ───────────────────────────────────────────────

export function TaskPipelinePage() {
  const { t } = useTranslation()
  const [searchParams] = useSearchParams()
  const productLineId = parseInt(searchParams.get('product_line_id') || '0')
  const productLineName = searchParams.get('name') || `Product Line #${productLineId}`

  // State
  const [editingTemplate, setEditingTemplate] = useState<Partial<TaskTemplate> | null>(null)
  const [showTemplateEditor, setShowTemplateEditor] = useState(false)
  const [localTasks, setLocalTasks] = useState<ProductLineTask[] | null>(null)
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false)

  // Queries
  const templatesQuery = useTaskTemplates()
  const pipelineQuery = useProductLineTasks(productLineId)
  const saveTemplateMut = useSaveTaskTemplate()
  const deleteTemplateMut = useDeleteTaskTemplate()
  const savePipelineMut = useSaveProductLineTasks()

  const templates = templatesQuery.data?.templates || []
  const serverTasks = pipelineQuery.data?.tasks || []
  const tasks = localTasks || serverTasks

  // Sync server tasks to local on first load
  if (!localTasks && serverTasks.length > 0 && !hasUnsavedChanges) {
    // Don't use setState during render — use effect-like pattern
  }

  const updateLocalTasks = useCallback((updater: (prev: ProductLineTask[]) => ProductLineTask[]) => {
    setLocalTasks(prev => {
      const updated = updater(prev || serverTasks)
      setHasUnsavedChanges(true)
      return updated
    })
  }, [serverTasks])

  const handleMoveUp = (index: number) => {
    updateLocalTasks(prev => {
      const arr = [...prev]
      if (index > 0) [arr[index - 1], arr[index]] = [arr[index], arr[index - 1]]
      return arr.map((t, i) => ({ ...t, sort_order: i }))
    })
  }

  const handleMoveDown = (index: number) => {
    updateLocalTasks(prev => {
      const arr = [...prev]
      if (index < arr.length - 1) [arr[index], arr[index + 1]] = [arr[index + 1], arr[index]]
      return arr.map((t, i) => ({ ...t, sort_order: i }))
    })
  }

  const handleToggle = (index: number) => {
    updateLocalTasks(prev => prev.map((t, i) => i === index ? { ...t, enabled: t.enabled ? 0 : 1 } : t))
  }

  const handleRemove = (index: number) => {
    updateLocalTasks(prev => prev.filter((_, i) => i !== index).map((t, i) => ({ ...t, sort_order: i })))
  }

  const handleAddTask = (template: TaskTemplate) => {
    const alreadyAdded = (localTasks || serverTasks).some(t => t.task_template_id === template.id)
    if (alreadyAdded) return

    const newTask: ProductLineTask = {
      id: 0,
      product_line_id: productLineId,
      task_template_id: template.id,
      sort_order: (localTasks || serverTasks).length,
      enabled: 1,
      custom_name: null,
      custom_code: null,
      custom_timeout_seconds: null,
      custom_on_failure: null,
      task_key: template.task_key,
      template_name: template.task_name,
      task_type: template.task_type,
      template_description: template.description,
      default_code: template.default_code,
      default_timeout_seconds: template.default_timeout_seconds,
      default_on_failure: template.default_on_failure,
      is_system: template.is_system,
      icon: template.icon,
    }

    updateLocalTasks(prev => [...prev, newTask])
  }

  const handleSavePipeline = () => {
    if (!localTasks) return
    savePipelineMut.mutate(
      { productLineId, tasks: localTasks },
      {
        onSuccess: () => {
          setHasUnsavedChanges(false)
          pipelineQuery.refetch()
        },
      }
    )
  }

  const handleSaveTemplate = (data: Partial<TaskTemplate>) => {
    saveTemplateMut.mutate(data, {
      onSuccess: () => {
        setShowTemplateEditor(false)
        setEditingTemplate(null)
      },
    })
  }

  // Available templates not yet in pipeline
  const usedTemplateIds = new Set(tasks.map(t => t.task_template_id))
  const availableTemplates = templates.filter(t => !usedTemplateIds.has(t.id))

  return (
    <div className="flex-1 p-6 space-y-6 max-w-5xl">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('pipeline.title', 'Task Pipeline')}</h1>
          {productLineId > 0 && (
            <p className="text-sm text-muted-foreground mt-1">
              {t('pipeline.for_product_line', 'Configuring pipeline for')}: <strong>{productLineName}</strong>
            </p>
          )}
        </div>
        {hasUnsavedChanges && (
          <Button onClick={handleSavePipeline} disabled={savePipelineMut.isPending}>
            {savePipelineMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            <Save className="mr-1 h-4 w-4" />
            {t('pipeline.save_pipeline', 'Save Pipeline')}
          </Button>
        )}
      </div>

      {/* Pipeline Editor (if product line selected) */}
      {productLineId > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">{t('pipeline.active_tasks', 'Active Task Pipeline')}</CardTitle>
            <CardDescription>
              {t('pipeline.drag_hint', 'Reorder tasks using arrows. Tasks run top-to-bottom during activation.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-2">
            {tasks.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-8">
                {t('pipeline.no_tasks', 'No tasks configured. Add tasks from the library below.')}
              </p>
            ) : (
              tasks.map((task, i) => (
                <PipelineTaskRow
                  key={`${task.task_template_id}-${i}`}
                  task={task}
                  index={i}
                  total={tasks.length}
                  onMoveUp={() => handleMoveUp(i)}
                  onMoveDown={() => handleMoveDown(i)}
                  onToggle={() => handleToggle(i)}
                  onRemove={() => handleRemove(i)}
                  onEditOverrides={() => {
                    // TODO: open override editor
                  }}
                />
              ))
            )}
          </CardContent>
        </Card>
      )}

      {/* Add Tasks from Library */}
      {productLineId > 0 && availableTemplates.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">{t('pipeline.add_task', 'Add Task')}</CardTitle>
            <CardDescription>{t('pipeline.add_hint', 'Click a task to add it to the pipeline')}</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
              {availableTemplates.map(tmpl => (
                <button
                  key={tmpl.id}
                  onClick={() => handleAddTask(tmpl)}
                  className="flex items-center gap-2 p-3 rounded-lg border hover:bg-muted/50 transition-colors text-left"
                >
                  <TaskIcon name={tmpl.icon} className="h-4 w-4 text-muted-foreground" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium truncate">{tmpl.task_name}</p>
                    <p className="text-xs text-muted-foreground truncate">{tmpl.task_key}</p>
                  </div>
                  <Plus className="h-4 w-4 ml-auto text-muted-foreground" />
                </button>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <Separator />

      {/* Task Template Library */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <div>
            <CardTitle className="text-base">{t('pipeline.template_library', 'Task Template Library')}</CardTitle>
            <CardDescription>{t('pipeline.library_desc', 'Global task templates available for all product lines')}</CardDescription>
          </div>
          <Button size="sm" onClick={() => { setEditingTemplate(null); setShowTemplateEditor(true) }}>
            <Plus className="mr-1 h-4 w-4" />
            {t('pipeline.new_template', 'New Template')}
          </Button>
        </CardHeader>
        <CardContent>
          <div className="space-y-2">
            {templates.map(tmpl => (
              <div key={tmpl.id} className="flex items-center gap-3 p-3 rounded-lg border bg-background">
                <TaskIcon name={tmpl.icon} className="h-5 w-5 text-muted-foreground shrink-0" />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">{tmpl.task_name}</span>
                    <Badge variant="outline" className="text-[10px] px-1">
                      {tmpl.task_type === 'built_in' ? 'Built-in' : 'Custom'}
                    </Badge>
                    {tmpl.is_system === 1 && (
                      <Badge variant="secondary" className="text-[10px] px-1">System</Badge>
                    )}
                  </div>
                  <p className="text-xs text-muted-foreground truncate mt-0.5">{tmpl.description}</p>
                </div>
                <div className="flex items-center gap-1">
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2"
                    onClick={() => { setEditingTemplate(tmpl); setShowTemplateEditor(true) }}
                  >
                    <Settings2 className="h-3.5 w-3.5" />
                  </Button>
                  {!tmpl.is_system && (
                    <AlertDialog>
                      <AlertDialogTrigger
                        className="inline-flex items-center justify-center rounded-md h-7 px-2 text-sm hover:bg-muted"
                      >
                        <Trash2 className="h-3.5 w-3.5 text-red-500" />
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>{t('pipeline.delete_template_title', 'Delete Task Template?')}</AlertDialogTitle>
                          <AlertDialogDescription>
                            {t('pipeline.delete_template_desc', 'This will remove the template and unassign it from all product lines.')}
                          </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                          <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                          <AlertDialogAction onClick={() => deleteTemplateMut.mutate(tmpl.id)}>
                            {t('common.delete', 'Delete')}
                          </AlertDialogAction>
                        </AlertDialogFooter>
                      </AlertDialogContent>
                    </AlertDialog>
                  )}
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Template Editor Modal */}
      {showTemplateEditor && (
        <TemplateEditor
          template={editingTemplate}
          onSave={handleSaveTemplate}
          onClose={() => { setShowTemplateEditor(false); setEditingTemplate(null) }}
          isPending={saveTemplateMut.isPending}
        />
      )}
    </div>
  )
}
