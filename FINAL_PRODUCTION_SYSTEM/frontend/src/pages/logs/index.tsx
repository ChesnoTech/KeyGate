import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '@/components/page-header'
import { PageSearch } from '@/components/page-search'
import { DataTable } from '@/components/data-table/data-table'
import { DataTablePagination } from '@/components/data-table/data-table-pagination'
import { useLogs } from '@/hooks/use-logs'
import { getLogColumns } from './columns'

export function LogsPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')

  const { data, isLoading } = useLogs({
    page,
    search: search || undefined,
  })

  const columns = useMemo(() => getLogColumns(t), [t])

  return (
    <>
      <PageHeader title={t('nav.logs', 'Logs')} />
      <div className="flex-1 space-y-4 p-4 md:p-6 pt-0 md:pt-0">
        <div className="flex items-center gap-3">
          <PageSearch
            value={search}
            onChange={(v) => { setSearch(v); setPage(1) }}
            placeholder={t('logs.search_placeholder', 'Search logs...')}
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.logs ?? []}
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
    </>
  )
}
