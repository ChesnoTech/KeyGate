import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Search, ShieldCheck, AlertTriangle, XCircle, CheckCircle, Info, RefreshCw } from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { DataTable } from '@/components/data-table/data-table'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { useComplianceGrouped, useQcStats, useRecheckHistorical } from '@/hooks/use-compliance'
import { getGroupedComplianceColumns } from './columns'

export function ComplianceResultsPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [resultFilter, setResultFilter] = useState('')
  const [page, setPage] = useState(1)
  const [showRecheck, setShowRecheck] = useState(false)

  const { data, isLoading } = useComplianceGrouped({
    page,
    search: search || undefined,
    check_result: resultFilter || undefined,
  })
  const { data: statsData } = useQcStats()
  const recheckMutation = useRecheckHistorical()

  const columns = useMemo(() => getGroupedComplianceColumns(t), [t])

  const stats = statsData?.stats

  const statCards = [
    { label: t('compliance.stat_pass_rate', 'Pass Rate'), value: stats ? `${stats.pass_rate}%` : '\u2014', icon: CheckCircle, color: 'text-green-600' },
    { label: t('compliance.stat_warnings', 'Warnings'), value: stats?.warning_count ?? 0, icon: AlertTriangle, color: 'text-orange-500' },
    { label: t('compliance.stat_failures', 'Failures'), value: stats?.fail_count ?? 0, icon: XCircle, color: 'text-red-500' },
    { label: t('compliance.stat_blocking', 'Blocking'), value: stats?.unresolved_blocking ?? 0, icon: ShieldCheck, color: 'text-red-600' },
    { label: t('compliance.stat_total', 'Total Checks'), value: stats?.total_checks ?? 0, icon: Info, color: 'text-blue-500' },
  ]

  return (
    <>
      <AppHeader title={t('nav.compliance_results', 'Compliance Results')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-2xl font-bold tracking-tight">{t('nav.compliance_results', 'Compliance Results')}</h2>
          <Button variant="outline" onClick={() => setShowRecheck(true)}>
            <RefreshCw className="mr-2 h-4 w-4" />
            {t('compliance.recheck_historical', 'Recheck Historical')}
          </Button>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {statCards.map((card) => (
            <Card key={card.label} size="sm">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{card.label}</CardTitle>
                <card.icon className={`h-4 w-4 ${card.color}`} />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{card.value}</div>
              </CardContent>
            </Card>
          ))}
        </div>

        {stats && Array.isArray(stats.top_failing_boards) && stats.top_failing_boards.length > 0 && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm">{t('compliance.top_failing', 'Top Failing Boards')}</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex gap-3 flex-wrap">
                {stats.top_failing_boards.map((b, i) => (
                  <div key={i} className="text-xs bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded px-2 py-1">
                    <span className="font-medium">{b.motherboard_manufacturer} {b.motherboard_product}</span>
                    <span className="text-red-600 ml-2">({b.fail_count})</span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        <div className="flex items-center gap-3 flex-wrap">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t('compliance.search_order', 'Search by order number...')}
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
              className="pl-8"
            />
          </div>
          <Select value={resultFilter} onValueChange={(v) => { setResultFilter(!v || v === '__all__' ? '' : v); setPage(1) }}>
            <SelectTrigger className="w-[150px]">
              <SelectValue placeholder={t('compliance.all_results', 'All Results')} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{t('compliance.all_results', 'All Results')}</SelectItem>
              <SelectItem value="pass">{t('compliance.result_pass', 'Pass')}</SelectItem>
              <SelectItem value="warning">{t('compliance.result_warning', 'Warning')}</SelectItem>
              <SelectItem value="fail">{t('compliance.result_fail', 'Fail')}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <DataTable columns={columns} data={data?.results ?? []} isLoading={isLoading} />

        {(data?.total_pages ?? 1) > 1 && (
          <div className="flex items-center justify-center gap-2">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              {t('common.previous', 'Previous')}
            </Button>
            <span className="text-sm text-muted-foreground">
              {page} / {data?.total_pages ?? 1}
            </span>
            <Button variant="outline" size="sm" disabled={page >= (data?.total_pages ?? 1)} onClick={() => setPage(page + 1)}>
              {t('common.next', 'Next')}
            </Button>
          </div>
        )}
      </div>

      <ConfirmDialog
        open={showRecheck}
        onOpenChange={setShowRecheck}
        title={t('compliance.recheck_title', 'Recheck Historical Records?')}
        description={t('compliance.recheck_desc', 'This will re-run compliance checks on all historical hardware records using current rules. Existing results will be replaced.')}
        confirmLabel={t('compliance.recheck_confirm', 'Recheck All')}
        onConfirm={() => recheckMutation.mutate(undefined)}
      />
    </>
  )
}
