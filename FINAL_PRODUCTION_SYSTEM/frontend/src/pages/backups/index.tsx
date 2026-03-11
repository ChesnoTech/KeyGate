import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Download, HardDrive } from 'lucide-react'
import { type ColumnDef } from '@tanstack/react-table'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { DataTable } from '@/components/data-table/data-table'
import { useBackups, useTriggerBackup } from '@/hooks/use-backups'
import type { BackupRow } from '@/api/backups'

const statusVariant: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  success: 'default',
  failed: 'destructive',
  partial: 'secondary',
}

export function BackupsPage() {
  const { t } = useTranslation()
  const { data, isLoading } = useBackups()
  const triggerMutation = useTriggerBackup()

  const columns = useMemo<ColumnDef<BackupRow>[]>(
    () => [
      {
        accessorKey: 'id',
        header: t('backups.col_id', 'ID'),
      },
      {
        accessorKey: 'backup_type',
        header: t('backups.col_type', 'Type'),
        cell: ({ row }) => (
          <Badge variant="outline">{row.original.backup_type}</Badge>
        ),
      },
      {
        accessorKey: 'backup_status',
        header: t('backups.col_status', 'Status'),
        cell: ({ row }) => (
          <Badge variant={statusVariant[row.original.backup_status] ?? 'outline'}>
            {row.original.backup_status}
          </Badge>
        ),
      },
      {
        accessorKey: 'backup_filename',
        header: t('backups.col_filename', 'Filename'),
        cell: ({ row }) => (
          <span className="font-mono text-xs">{row.original.backup_filename ?? '—'}</span>
        ),
      },
      {
        accessorKey: 'backup_size_mb',
        header: t('backups.col_size', 'Size'),
        cell: ({ row }) => {
          const mb = row.original.backup_size_mb
          return mb != null ? `${Number(mb).toFixed(2)} ${t('common.mb', 'MB')}` : '—'
        },
      },
      {
        accessorKey: 'tables_count',
        header: t('backups.col_tables', 'Tables'),
        cell: ({ row }) => row.original.tables_count ?? '—',
      },
      {
        accessorKey: 'rows_count',
        header: t('backups.col_rows', 'Rows'),
        cell: ({ row }) => row.original.rows_count?.toLocaleString() ?? '—',
      },
      {
        accessorKey: 'created_at',
        header: t('backups.col_created_at', 'Created At'),
      },
    ],
    [t]
  )

  return (
    <>
      <AppHeader title={t('nav.backups', 'Backups')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-2xl font-bold tracking-tight">{t('nav.backups', 'Backups')}</h2>
          <Button
            size="sm"
            onClick={() => triggerMutation.mutate()}
            disabled={triggerMutation.isPending}
          >
            {triggerMutation.isPending ? (
              <>
                <HardDrive className="mr-2 h-4 w-4 animate-spin" />
                {t('backups.triggering', 'Creating...')}
              </>
            ) : (
              <>
                <Download className="mr-2 h-4 w-4" />
                {t('backups.trigger', 'Trigger Manual Backup')}
              </>
            )}
          </Button>
        </div>

        <DataTable
          columns={columns}
          data={data?.backups ?? []}
          isLoading={isLoading}
          emptyMessage={t('empty.backups')}
        />
      </div>
    </>
  )
}
