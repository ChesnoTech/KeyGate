import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import {
  Plus,
  Search,
  Save,
  Loader2,
  Package,
  Truck,
  Clock,
  CheckCircle2,
  XCircle,
} from 'lucide-react'
import { useWorkOrders, useSaveWorkOrder, useDeleteWorkOrder } from '@/hooks/use-production'
import type { WorkOrder } from '@/api/production'

const STATUS_CONFIG: Record<string, { color: string; icon: typeof Clock }> = {
  draft: { color: 'bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400', icon: Clock },
  queued: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400', icon: Clock },
  in_progress: { color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', icon: Package },
  completed: { color: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', icon: CheckCircle2 },
  shipped: { color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400', icon: Truck },
  cancelled: { color: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', icon: XCircle },
}

const PRIORITY_COLORS: Record<string, string> = {
  low: 'text-gray-500',
  normal: 'text-blue-500',
  high: 'text-orange-500',
  urgent: 'text-red-500',
}

function StatusBadge({ status }: { status: string }) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG.draft
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${cfg.color}`}>
      <cfg.icon className="h-3 w-3" />
      {status.replace('_', ' ')}
    </span>
  )
}

export function WorkOrdersPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [showForm, setShowForm] = useState(false)
  const [editingOrder, setEditingOrder] = useState<Partial<WorkOrder> | null>(null)

  const ordersQuery = useWorkOrders({
    ...(search && { search }),
    ...(statusFilter && { status: statusFilter }),
  })
  const saveMut = useSaveWorkOrder()
  const deleteMut = useDeleteWorkOrder()

  const orders = ordersQuery.data?.work_orders || []

  const [form, setForm] = useState<Record<string, string | number>>({})

  const openNewOrder = () => {
    setEditingOrder(null)
    setForm({ quantity: 1, status: 'draft', priority: 'normal' })
    setShowForm(true)
  }

  const openEditOrder = (order: WorkOrder) => {
    setEditingOrder(order)
    setForm({
      id: order.id,
      work_order_number: order.work_order_number,
      batch_number: order.batch_number || '',
      customer_name: order.customer_name || '',
      customer_email: order.customer_email || '',
      customer_phone: order.customer_phone || '',
      customer_order_ref: order.customer_order_ref || '',
      quantity: order.quantity,
      status: order.status,
      priority: order.priority,
      due_date: order.due_date || '',
      shipping_method: order.shipping_method || '',
      shipping_tracking: order.shipping_tracking || '',
      shipping_address: order.shipping_address || '',
      internal_notes: order.internal_notes || '',
      customer_notes: order.customer_notes || '',
    })
    setShowForm(true)
  }

  const handleSave = () => {
    saveMut.mutate(form as Record<string, unknown>, {
      onSuccess: () => {
        setShowForm(false)
        ordersQuery.refetch()
      },
    })
  }

  return (
    <div className="flex-1 p-6 space-y-6 max-w-6xl">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('work_orders.title', 'Work Orders')}</h1>
        <Button onClick={openNewOrder}>
          <Plus className="mr-1 h-4 w-4" />
          {t('work_orders.new', 'New Work Order')}
        </Button>
      </div>

      {/* Filters */}
      <div className="flex gap-3">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            className="pl-9"
            placeholder={t('work_orders.search', 'Search orders...')}
            value={search}
            onChange={e => setSearch(e.target.value)}
          />
        </div>
        <select
          className="h-10 rounded-md border px-3 bg-background text-sm"
          value={statusFilter}
          onChange={e => setStatusFilter(e.target.value)}
        >
          <option value="">{t('common.all_statuses', 'All Statuses')}</option>
          <option value="draft">Draft</option>
          <option value="queued">Queued</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
          <option value="shipped">Shipped</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      {/* Orders Table */}
      <Card>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="py-3 px-4">{t('work_orders.col_number', 'WO #')}</th>
                  <th className="py-3 px-4">{t('work_orders.col_customer', 'Customer')}</th>
                  <th className="py-3 px-4">{t('work_orders.col_qty', 'Qty')}</th>
                  <th className="py-3 px-4">{t('work_orders.col_status', 'Status')}</th>
                  <th className="py-3 px-4">{t('work_orders.col_priority', 'Priority')}</th>
                  <th className="py-3 px-4">{t('work_orders.col_due', 'Due')}</th>
                  <th className="py-3 px-4">{t('work_orders.col_created', 'Created')}</th>
                  <th className="py-3 px-4"></th>
                </tr>
              </thead>
              <tbody>
                {orders.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-muted-foreground">
                      {t('empty.work_orders', 'No work orders yet')}
                    </td>
                  </tr>
                ) : (
                  orders.map((order: WorkOrder) => (
                    <tr key={order.id} className="border-b last:border-0 hover:bg-muted/50 cursor-pointer" onClick={() => openEditOrder(order)}>
                      <td className="py-3 px-4 font-mono text-xs">{order.work_order_number}</td>
                      <td className="py-3 px-4">{order.customer_name || '—'}</td>
                      <td className="py-3 px-4">{order.completed_quantity}/{order.quantity}</td>
                      <td className="py-3 px-4"><StatusBadge status={order.status} /></td>
                      <td className="py-3 px-4">
                        <span className={`text-xs font-medium ${PRIORITY_COLORS[order.priority] || ''}`}>
                          {order.priority}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-muted-foreground text-xs">{order.due_date || '—'}</td>
                      <td className="py-3 px-4 text-muted-foreground text-xs">{order.created_at?.split(' ')[0]}</td>
                      <td className="py-3 px-4">
                        {['draft', 'cancelled'].includes(order.status) && (
                          <Button variant="ghost" size="sm" className="h-7 px-2 text-red-500"
                            onClick={e => { e.stopPropagation(); deleteMut.mutate(order.id) }}>
                            <XCircle className="h-3.5 w-3.5" />
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Create/Edit Form Modal */}
      {showForm && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-background rounded-lg shadow-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="p-6 space-y-4">
              <h3 className="text-lg font-semibold">
                {editingOrder ? t('work_orders.edit', 'Edit Work Order') : t('work_orders.new', 'New Work Order')}
              </h3>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>{t('work_orders.customer_name', 'Customer Name')}</Label>
                  <Input value={form.customer_name || ''} onChange={e => setForm({ ...form, customer_name: e.target.value })} />
                </div>
                <div>
                  <Label>{t('work_orders.customer_email', 'Customer Email')}</Label>
                  <Input value={form.customer_email || ''} onChange={e => setForm({ ...form, customer_email: e.target.value })} />
                </div>
                <div>
                  <Label>{t('work_orders.customer_ref', 'Customer PO #')}</Label>
                  <Input value={form.customer_order_ref || ''} onChange={e => setForm({ ...form, customer_order_ref: e.target.value })} />
                </div>
                <div>
                  <Label>{t('work_orders.batch', 'Batch Number')}</Label>
                  <Input value={form.batch_number || ''} onChange={e => setForm({ ...form, batch_number: e.target.value })} />
                </div>
                <div>
                  <Label>{t('work_orders.quantity', 'Quantity')}</Label>
                  <Input type="number" min={1} value={form.quantity || 1} onChange={e => setForm({ ...form, quantity: parseInt(e.target.value) || 1 })} />
                </div>
                <div>
                  <Label>{t('work_orders.due_date', 'Due Date')}</Label>
                  <Input type="date" value={form.due_date || ''} onChange={e => setForm({ ...form, due_date: e.target.value })} />
                </div>
                <div>
                  <Label>{t('work_orders.priority', 'Priority')}</Label>
                  <select className="w-full h-10 rounded-md border px-3 bg-background text-sm" value={form.priority || 'normal'} onChange={e => setForm({ ...form, priority: e.target.value })}>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                  </select>
                </div>
                <div>
                  <Label>{t('work_orders.status', 'Status')}</Label>
                  <select className="w-full h-10 rounded-md border px-3 bg-background text-sm" value={form.status || 'draft'} onChange={e => setForm({ ...form, status: e.target.value })}>
                    <option value="draft">Draft</option>
                    <option value="queued">Queued</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="shipped">Shipped</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
              </div>

              <Separator />

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>{t('work_orders.shipping_method', 'Shipping Method')}</Label>
                  <Input value={form.shipping_method || ''} onChange={e => setForm({ ...form, shipping_method: e.target.value })} />
                </div>
                <div>
                  <Label>{t('work_orders.shipping_tracking', 'Tracking Number')}</Label>
                  <Input value={form.shipping_tracking || ''} onChange={e => setForm({ ...form, shipping_tracking: e.target.value })} />
                </div>
              </div>

              <div>
                <Label>{t('work_orders.internal_notes', 'Internal Notes')}</Label>
                <textarea className="w-full min-h-[80px] p-3 rounded-md border bg-background text-sm resize-y" value={form.internal_notes || ''} onChange={e => setForm({ ...form, internal_notes: e.target.value })} />
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <Button variant="outline" onClick={() => setShowForm(false)}>{t('common.cancel', 'Cancel')}</Button>
                <Button onClick={handleSave} disabled={saveMut.isPending}>
                  {saveMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  <Save className="mr-1 h-4 w-4" />
                  {t('common.save', 'Save')}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
