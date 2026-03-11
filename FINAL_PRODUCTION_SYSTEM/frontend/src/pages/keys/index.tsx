import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Download, Upload, Plus } from 'lucide-react'
import { PageHeader } from '@/components/page-header'
import { PageSearch } from '@/components/page-search'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import { DataTable } from '@/components/data-table/data-table'
import { DataTablePagination } from '@/components/data-table/data-table-pagination'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { useKeys, useRecycleKey, useDeleteKey } from '@/hooks/use-keys'
import { getKeyColumns } from './columns'
import { ImportKeysDialog } from './import-dialog'
import { AddKeysDialog } from './add-keys-dialog'
import type { OemKeyRow } from '@/api/keys'

export function KeysPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState('all')
  const [confirmAction, setConfirmAction] = useState<{ type: 'recycle' | 'delete'; key: OemKeyRow } | null>(null)
  const [importOpen, setImportOpen] = useState(false)
  const [addKeysOpen, setAddKeysOpen] = useState(false)

  const filterLabels: Record<string, string> = {
    all: t('common.all', 'All'),
    unused: t('keys.status_unused', 'Unused'),
    allocated: t('keys.status_allocated', 'Allocated'),
    good: t('keys.status_good', 'Good'),
    bad: t('keys.status_bad', 'Bad'),
    retry: t('keys.status_retry', 'Retry'),
  }

  const { data, isLoading } = useKeys({ page, search: search || undefined, filter: filter !== 'all' ? filter : undefined })
  const recycleMutation = useRecycleKey()
  const deleteMutation = useDeleteKey()

  const columns = useMemo(
    () =>
      getKeyColumns(t, {
        onRecycle: (key) => setConfirmAction({ type: 'recycle', key }),
        onDelete: (key) => setConfirmAction({ type: 'delete', key }),
      }),
    [t]
  )

  const handleConfirm = () => {
    if (!confirmAction) return
    if (confirmAction.type === 'recycle') {
      recycleMutation.mutate(confirmAction.key.id)
    } else {
      deleteMutation.mutate(confirmAction.key.id)
    }
    setConfirmAction(null)
  }

  const handleExport = () => {
    const url = `/activate/admin_v2.php?action=export_keys${filter !== 'all' ? `&filter=${filter}` : ''}`
    window.open(url, '_blank')
  }

  return (
    <>
      <PageHeader
        title={t('nav.keys')}
        actions={
          <div className="flex items-center gap-2">
            <Button size="sm" onClick={() => setAddKeysOpen(true)}>
              <Plus className="mr-2 h-4 w-4" />
              {t('keys.add_keys', 'Add Keys')}
            </Button>
            <Button variant="outline" size="sm" onClick={() => setImportOpen(true)}>
              <Upload className="mr-2 h-4 w-4" />
              {t('keys.import_csv', 'Import CSV')}
            </Button>
            <Button variant="outline" size="sm" onClick={handleExport}>
              <Download className="mr-2 h-4 w-4" />
              {t('keys.export', 'Export CSV')}
            </Button>
          </div>
        }
      />
      <div className="flex-1 space-y-4 p-4 md:p-6 pt-0 md:pt-0">
        <div className="flex items-center gap-3">
          <PageSearch
            value={search}
            onChange={(v) => { setSearch(v); setPage(1) }}
            placeholder={t('keys.search_placeholder', 'Search keys...')}
          />
          <Select value={filter} onValueChange={(v) => { setFilter(v ?? 'all'); setPage(1) }}>
            <SelectTrigger className="w-[160px]">
              <span className="truncate">{filterLabels[filter] ?? filter}</span>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t('common.all', 'All')}</SelectItem>
              <SelectItem value="unused">{t('keys.status_unused', 'Unused')}</SelectItem>
              <SelectItem value="allocated">{t('keys.status_allocated', 'Allocated')}</SelectItem>
              <SelectItem value="good">{t('keys.status_good', 'Good')}</SelectItem>
              <SelectItem value="bad">{t('keys.status_bad', 'Bad')}</SelectItem>
              <SelectItem value="retry">{t('keys.status_retry', 'Retry')}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <DataTable
          columns={columns}
          data={data?.keys ?? []}
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

      <ConfirmDialog
        open={!!confirmAction}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title={confirmAction?.type === 'delete' ? t('keys.confirm_delete', 'Delete Key?') : t('keys.confirm_recycle', 'Recycle Key?')}
        description={
          confirmAction?.type === 'delete'
            ? t('keys.confirm_delete_desc', 'This will permanently remove this key from the system.')
            : t('keys.confirm_recycle_desc', 'This will reset the key status back to unused.')
        }
        confirmLabel={confirmAction?.type === 'delete' ? t('common.delete', 'Delete') : t('keys.recycle', 'Recycle')}
        variant={confirmAction?.type === 'delete' ? 'destructive' : 'default'}
        onConfirm={handleConfirm}
      />

      <ImportKeysDialog open={importOpen} onOpenChange={setImportOpen} />
      <AddKeysDialog open={addKeysOpen} onOpenChange={setAddKeysOpen} />
    </>
  )
}
