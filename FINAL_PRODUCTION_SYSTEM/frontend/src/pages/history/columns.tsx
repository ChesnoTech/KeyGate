import type { ColumnDef } from '@tanstack/react-table'
import { HardDrive } from 'lucide-react'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import type { HistoryRow } from '@/api/history'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getHistoryColumns(
  t: (key: string, defaultValue?: any) => string,
  onViewHardware?: (activationId: number) => void
): ColumnDef<HistoryRow>[] {
  return [
    {
      accessorKey: 'id',
      header: 'ID',
      cell: ({ row }) => <span className="text-muted-foreground">#{row.original.id}</span>,
    },
    {
      id: 'datetime',
      header: t('history.date_time', 'Date / Time'),
      cell: ({ row }) => (
        <span className="whitespace-nowrap">
          {row.original.attempted_date} {row.original.attempted_time}
        </span>
      ),
    },
    {
      accessorKey: 'technician_id',
      header: t('history.technician', 'Technician'),
    },
    {
      accessorKey: 'order_number',
      header: t('history.order_number', 'Order #'),
      cell: ({ row }) => row.original.order_number || '\u2014',
    },
    {
      accessorKey: 'product_key',
      header: t('history.product_key', 'Product Key'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.product_key}
        </code>
      ),
    },
    {
      accessorKey: 'attempt_result',
      header: t('history.result', 'Result'),
      cell: ({ row }) => (
        <StatusBadge status={row.original.attempt_result} translationPrefix="history.status_" />
      ),
    },
    {
      accessorKey: 'activation_server',
      header: t('history.server', 'Server'),
      cell: ({ row }) => row.original.activation_server || '\u2014',
    },
    {
      accessorKey: 'notes',
      header: t('history.notes', 'Notes'),
      cell: ({ row }) => row.original.notes || '\u2014',
    },
    {
      accessorKey: 'hardware_collected',
      header: t('history.hardware', 'HW'),
      cell: ({ row }) =>
        row.original.hardware_collected ? (
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={() => onViewHardware?.(row.original.id)}
            title={t('hw.view', 'View hardware details')}
          >
            <HardDrive className="h-4 w-4 text-green-600" />
          </Button>
        ) : (
          <span className="text-muted-foreground">{'\u2014'}</span>
        ),
    },
  ]
}
