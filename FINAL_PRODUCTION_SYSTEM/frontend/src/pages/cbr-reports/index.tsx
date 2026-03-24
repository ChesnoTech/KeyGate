import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Search,
  FileText,
  Download,
  Truck,
  Loader2,
  CheckCircle2,
  XCircle,
  Clock,
} from 'lucide-react'
import { useBuildReports, useUpdateBuildReportShipping } from '@/hooks/use-production'
import type { BuildReport } from '@/api/production'

function StatusBadge({ status }: { status: string }) {
  const colors: Record<string, string> = {
    activated: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    not_attempted: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
  }
  const icons: Record<string, React.ReactNode> = {
    activated: <CheckCircle2 className="h-3 w-3" />,
    failed: <XCircle className="h-3 w-3" />,
    pending: <Clock className="h-3 w-3" />,
  }
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${colors[status] || colors.pending}`}>
      {icons[status]}
      {status.replace('_', ' ')}
    </span>
  )
}

function ShippingBadge({ status }: { status: string }) {
  const colors: Record<string, string> = {
    building: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    testing: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    ready: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    shipped: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    returned: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  }
  return (
    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${colors[status] || 'bg-muted'}`}>
      {status}
    </span>
  )
}

export function CbrReportsPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [selectedReport, setSelectedReport] = useState<BuildReport | null>(null)

  const reportsQuery = useBuildReports(search ? { search } : undefined)
  const updateShipping = useUpdateBuildReportShipping()

  const reports = reportsQuery.data?.reports ?? []

  const handleShippingUpdate = (reportId: number, status: string) => {
    updateShipping.mutate({ id: reportId, shipping_status: status })
  }

  return (
    <div className="flex-1 p-6 space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold flex items-center gap-2">
          <FileText className="h-6 w-6" />
          {t('cbr.title', 'Computer Build Reports')}
        </h1>
      </div>

      {/* Search */}
      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <Input
          placeholder={t('cbr.search', 'Search by order, serial, fingerprint...')}
          className="pl-9"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {/* Report detail panel */}
      {selectedReport && (
        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <CardTitle className="text-base">
                {t('cbr.report_detail', 'Report')}: {selectedReport.report_uuid?.slice(0, 8)}
              </CardTitle>
              <Button variant="ghost" size="sm" onClick={() => setSelectedReport(null)}>
                {t('common.close', 'Close')}
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <span className="text-muted-foreground">{t('cbr.order', 'Order')}</span>
                <p className="font-medium">{selectedReport.order_number || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.batch', 'Batch')}</span>
                <p className="font-medium">{selectedReport.batch_number || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.activation', 'Activation')}</span>
                <p><StatusBadge status={selectedReport.activation_status} /></p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.shipping', 'Shipping')}</span>
                <p><ShippingBadge status={selectedReport.shipping_status} /></p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.motherboard', 'Motherboard')}</span>
                <p className="font-medium">{selectedReport.motherboard_manufacturer} {selectedReport.motherboard_model}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.serial', 'MB Serial')}</span>
                <p className="font-mono text-xs">{selectedReport.motherboard_serial || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.cpu', 'CPU')}</span>
                <p className="font-medium">{selectedReport.cpu_model || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.ram', 'RAM')}</span>
                <p className="font-medium">{selectedReport.ram_total_gb ? `${selectedReport.ram_total_gb} GB` : '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.gpu', 'GPU')}</span>
                <p className="font-medium">{selectedReport.gpu_model || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.os', 'OS')}</span>
                <p className="font-medium">{selectedReport.os_version || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.key', 'Product Key')}</span>
                <p className="font-mono text-xs">{selectedReport.product_key_masked || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.fingerprint', 'HW Fingerprint')}</span>
                <p className="font-mono text-xs truncate">{selectedReport.device_fingerprint || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.technician', 'Technician')}</span>
                <p className="font-medium">{selectedReport.technician_name || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.product_line', 'Product Line')}</span>
                <p className="font-medium">{selectedReport.product_line_name || '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.qc', 'QC Status')}</span>
                <p>{selectedReport.qc_passed === 1 ? '✓ Passed' : selectedReport.qc_passed === 0 ? '✗ Failed' : '—'}</p>
              </div>
              <div>
                <span className="text-muted-foreground">{t('cbr.date', 'Date')}</span>
                <p className="font-medium">{selectedReport.created_at}</p>
              </div>
            </div>

            {/* Shipping actions */}
            <div className="flex items-center gap-2 mt-4 pt-4 border-t">
              <Truck className="h-4 w-4 text-muted-foreground" />
              <span className="text-sm font-medium">{t('cbr.update_shipping', 'Update Shipping')}:</span>
              {['building', 'testing', 'ready', 'shipped'].map((s) => (
                <Button
                  key={s}
                  variant={selectedReport.shipping_status === s ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => handleShippingUpdate(selectedReport.id, s)}
                  disabled={updateShipping.isPending}
                >
                  {s}
                </Button>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Reports table */}
      <Card>
        <CardContent className="p-0">
          {reportsQuery.isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : reports.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <FileText className="h-10 w-10 mx-auto mb-3 opacity-50" />
              <p>{t('empty.cbr', 'No build reports yet')}</p>
              <p className="text-xs mt-1">{t('cbr.auto_generated', 'Reports are generated automatically during activation')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="text-left py-2 px-4">{t('cbr.col_uuid', 'Report ID')}</th>
                    <th className="text-left py-2 px-4">{t('cbr.col_order', 'Order')}</th>
                    <th className="text-left py-2 px-4">{t('cbr.col_mb', 'Motherboard')}</th>
                    <th className="text-left py-2 px-4">{t('cbr.col_activation', 'Activation')}</th>
                    <th className="text-left py-2 px-4">{t('cbr.col_shipping', 'Shipping')}</th>
                    <th className="text-left py-2 px-4">{t('cbr.col_technician', 'Tech')}</th>
                    <th className="text-left py-2 px-4">{t('cbr.col_date', 'Date')}</th>
                    <th className="text-left py-2 px-4"></th>
                  </tr>
                </thead>
                <tbody>
                  {reports.map((r) => (
                    <tr
                      key={r.id}
                      className="border-b last:border-0 hover:bg-muted/30 cursor-pointer"
                      onClick={() => setSelectedReport(r)}
                    >
                      <td className="py-3 px-4 font-mono text-xs">{r.report_uuid?.slice(0, 8)}</td>
                      <td className="py-3 px-4">{r.order_number || '—'}</td>
                      <td className="py-3 px-4 text-xs">
                        {r.motherboard_manufacturer} {r.motherboard_model}
                      </td>
                      <td className="py-3 px-4"><StatusBadge status={r.activation_status} /></td>
                      <td className="py-3 px-4"><ShippingBadge status={r.shipping_status} /></td>
                      <td className="py-3 px-4">{r.technician_name || '—'}</td>
                      <td className="py-3 px-4 text-muted-foreground">{r.created_at}</td>
                      <td className="py-3 px-4">
                        <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation() }}>
                          <Download className="h-3.5 w-3.5" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
