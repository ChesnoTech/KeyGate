import type { ColumnDef } from '@tanstack/react-table'
import { MoreHorizontal, RotateCcw, Trash2 } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge } from '@/components/status-badge'
import type { OemKeyRow } from '@/api/keys'

interface ColumnActions {
  onRecycle: (key: OemKeyRow) => void
  onDelete: (key: OemKeyRow) => void
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getKeyColumns(
  t: (key: string, defaultValue?: any) => string,
  actions: ColumnActions
): ColumnDef<OemKeyRow>[] {
  return [
    {
      accessorKey: 'id',
      header: 'ID',
      cell: ({ row }) => <span className="text-muted-foreground">#{row.original.id}</span>,
    },
    {
      accessorKey: 'product_key',
      header: t('keys.product_key', 'Product Key'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.product_key}
        </code>
      ),
    },
    {
      accessorKey: 'oem_identifier',
      header: t('keys.oem_identifier', 'OEM ID'),
    },
    {
      accessorKey: 'roll_serial',
      header: t('keys.roll_serial', 'Roll Serial'),
    },
    {
      accessorKey: 'key_status',
      header: t('keys.status', 'Status'),
      cell: ({ row }) => <StatusBadge status={row.original.key_status} />,
    },
    {
      accessorKey: 'order_number',
      header: t('keys.order_number', 'Order #'),
      cell: ({ row }) => row.original.order_number || '—',
    },
    {
      accessorKey: 'created_at',
      header: t('keys.date_added', 'Date Added'),
      cell: ({ row }) => {
        if (!row.original.created_at) return '—'
        const d = new Date(row.original.created_at)
        return isNaN(d.getTime())
          ? row.original.created_at
          : d.toLocaleString(undefined, {
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
            })
      },
    },
    {
      accessorKey: 'last_use_date',
      header: t('keys.last_used', 'Last Used'),
      cell: ({ row }) => {
        if (!row.original.last_use_date) return '—'
        return `${row.original.last_use_date} ${row.original.last_use_time ?? ''}`
      },
    },
    {
      id: 'actions',
      cell: ({ row }) => {
        const key = row.original
        return (
          <DropdownMenu>
            <DropdownMenuTrigger className="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent">
              <MoreHorizontal className="h-4 w-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={() => actions.onRecycle(key)}
                disabled={key.key_status === 'unused'}
              >
                <RotateCcw className="mr-2 h-4 w-4" />
                {t('keys.recycle', 'Recycle')}
              </DropdownMenuItem>
              <DropdownMenuItem
                variant="destructive"
                onClick={() => actions.onDelete(key)}
              >
                <Trash2 className="mr-2 h-4 w-4" />
                {t('keys.delete', 'Delete')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )
      },
    },
  ]
}
