import { useState, useCallback, useRef, useMemo } from 'react'
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DataTable } from '@/components/data-table/data-table'
import { useComplianceGrouped, useQcStats, useRecheckCount, useRecheckHistorical } from '@/hooks/use-compliance'
import { getGroupedComplianceColumns } from './columns'

// ── Recheck Dialog with batched progress ───────────────

function RecheckDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
  const { t } = useTranslation()
  const countMutation = useRecheckCount()
  const recheckMutation = useRecheckHistorical()

  const [phase, setPhase] = useState<'idle' | 'counting' | 'confirm' | 'running' | 'done'>('idle')
  const [totalCount, setTotalCount] = useState(0)
  const [processed, setProcessed] = useState(0)
  const [stats, setStats] = useState({ passed: 0, failed: 0, warnings: 0 })
  const abortRef = useRef(false)

  const reset = useCallback(() => {
    setPhase('idle')
    setTotalCount(0)
    setProcessed(0)
    setStats({ passed: 0, failed: 0, warnings: 0 })
    abortRef.current = false
  }, [])

  const handleOpen = useCallback((v: boolean) => {
    if (v) {
      reset()
      setPhase('counting')
      countMutation.mutate(undefined, {
        onSuccess: (res) => {
          setTotalCount(res.count)
          setPhase('confirm')
        },
        onError: () => setPhase('idle'),
      })
    } else {
      abortRef.current = true
    }
    onOpenChange(v)
  }, [countMutation, onOpenChange, reset])

  const runBatch = useCallback((afterId: number) => {
    if (abortRef.current) return
    recheckMutation.mutate({ batch_size: 50, after_id: afterId }, {
      onSuccess: (res) => {
        setProcessed(prev => prev + res.stats.rechecked)
        setStats(prev => ({
          passed: prev.passed + res.stats.passed,
          failed: prev.failed + res.stats.failed,
          warnings: prev.warnings + res.stats.warnings,
        }))
        if (res.stats.has_more && !abortRef.current) {
          // Continue next batch
          runBatch(res.stats.last_id)
        } else {
          setPhase('done')
        }
      },
      onError: () => setPhase('done'),
    })
  }, [recheckMutation])

  const startRecheck = useCallback(() => {
    setPhase('running')
    setProcessed(0)
    setStats({ passed: 0, failed: 0, warnings: 0 })
    abortRef.current = false
    runBatch(0)
  }, [runBatch])

  const handleStop = useCallback(() => {
    abortRef.current = true
    setPhase('done')
  }, [])

  const progressPct = totalCount > 0 ? Math.min(Math.round((processed / totalCount) * 100), 100) : 0

  return (
    <Dialog open={open} onOpenChange={handleOpen}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <RefreshCw className="h-5 w-5" />
            {t('compliance.recheck_title', 'Recheck Historical Records')}
          </DialogTitle>
          <DialogDescription>
            {phase === 'counting' && t('compliance.recheck_counting', 'Counting records...')}
            {phase === 'confirm' && t('compliance.recheck_confirm_desc', 'This will re-run all QC compliance checks on {{count}} hardware records using current rules. Existing results will be replaced. Records are processed in batches of 50.', { count: totalCount })}
            {phase === 'running' && t('compliance.recheck_running', 'Processing records in batches...')}
            {phase === 'done' && t('compliance.recheck_done', 'Recheck complete.')}
          </DialogDescription>
        </DialogHeader>

        {/* Progress */}
        {(phase === 'running' || phase === 'done') && (
          <div className="space-y-3">
            <div className="w-full bg-muted rounded-full h-3 overflow-hidden">
              <div
                className={`h-full rounded-full transition-all duration-300 ${phase === 'done' ? 'bg-green-500' : 'bg-primary'}`}
                style={{ width: `${progressPct}%` }}
              />
            </div>
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>{processed} / {totalCount}</span>
              <span>{progressPct}%</span>
            </div>
            <div className="grid grid-cols-3 gap-3 text-center">
              <div className="rounded-md border p-2">
                <div className="text-lg font-bold text-green-600">{stats.passed}</div>
                <div className="text-[10px] text-muted-foreground">{t('compliance.result_pass', 'Pass')}</div>
              </div>
              <div className="rounded-md border p-2">
                <div className="text-lg font-bold text-orange-500">{stats.warnings}</div>
                <div className="text-[10px] text-muted-foreground">{t('compliance.result_warning', 'Warning')}</div>
              </div>
              <div className="rounded-md border p-2">
                <div className="text-lg font-bold text-red-500">{stats.failed}</div>
                <div className="text-[10px] text-muted-foreground">{t('compliance.result_fail', 'Fail')}</div>
              </div>
            </div>
          </div>
        )}

        {/* Confirm: show record count warning */}
        {phase === 'confirm' && totalCount === 0 && (
          <p className="text-sm text-muted-foreground">{t('compliance.recheck_no_records', 'No hardware records found to recheck.')}</p>
        )}

        <DialogFooter>
          {phase === 'confirm' && totalCount > 0 && (
            <>
              <Button variant="outline" onClick={() => handleOpen(false)}>{t('common.cancel', 'Cancel')}</Button>
              <Button variant="destructive" onClick={startRecheck}>
                <RefreshCw className="mr-2 h-4 w-4" />
                {t('compliance.recheck_start', 'Recheck {{count}} Records', { count: totalCount })}
              </Button>
            </>
          )}
          {phase === 'confirm' && totalCount === 0 && (
            <Button variant="outline" onClick={() => handleOpen(false)}>{t('common.close', 'Close')}</Button>
          )}
          {phase === 'running' && (
            <Button variant="outline" onClick={handleStop}>
              {t('compliance.recheck_stop', 'Stop')}
            </Button>
          )}
          {phase === 'done' && (
            <Button onClick={() => handleOpen(false)}>{t('common.close', 'Close')}</Button>
          )}
          {phase === 'counting' && (
            <Button variant="outline" disabled>{t('common.loading', 'Loading...')}</Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Main Page ──────────────────────────────────────────

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

      <RecheckDialog open={showRecheck} onOpenChange={setShowRecheck} />
    </>
  )
}
