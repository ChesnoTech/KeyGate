import { useState, useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '@/components/page-header'
import { PageSearch } from '@/components/page-search'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import { DataTable } from '@/components/data-table/data-table'
import { DataTablePagination } from '@/components/data-table/data-table-pagination'
import { useHistory } from '@/hooks/use-history'
import { getHistoryColumns } from './columns'
import { HardwareDialog } from './hardware-dialog'

export function HistoryPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState('all')
  const [hwActivationId, setHwActivationId] = useState<number | null>(null)
  const [hwDialogOpen, setHwDialogOpen] = useState(false)

  const filterLabels: Record<string, string> = {
    all: t('common.all', 'All'),
    success: t('history.status_success', 'Success'),
    failed: t('history.status_failed', 'Failed'),
  }

  const { data, isLoading } = useHistory({
    page,
    search: search || undefined,
    filter: filter !== 'all' ? filter : undefined,
  })

  const onViewHardware = useCallback((activationId: number) => {
    setHwActivationId(activationId)
    setHwDialogOpen(true)
  }, [])

  const columns = useMemo(() => getHistoryColumns(t, onViewHardware), [t, onViewHardware])

  return (
    <>
      <PageHeader title={t('nav.history', 'History')} />
      <div className="flex-1 space-y-4 p-4 md:p-6 pt-0 md:pt-0">
        <div className="flex items-center gap-3">
          <PageSearch
            value={search}
            onChange={(v) => { setSearch(v); setPage(1) }}
            placeholder={t('history.search_placeholder', 'Search history...')}
          />
          <Select value={filter} onValueChange={(v) => { setFilter(v ?? 'all'); setPage(1) }}>
            <SelectTrigger className="w-[160px]">
              <span className="truncate">{filterLabels[filter] ?? filter}</span>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t('common.all', 'All')}</SelectItem>
              <SelectItem value="success">{t('history.status_success', 'Success')}</SelectItem>
              <SelectItem value="failed">{t('history.status_failed', 'Failed')}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <DataTable
          columns={columns}
          data={data?.history ?? []}
          isLoading={isLoading}
        />

        {data && (
          <DataTablePagination
            page={data.page}
            totalPages={data.pages}
            total={data.total}
            onPageChange={setPage}
          />
        )}
      </div>

      <HardwareDialog
        activationId={hwActivationId}
        open={hwDialogOpen}
        onOpenChange={setHwDialogOpen}
      />
    </>
  )
}
