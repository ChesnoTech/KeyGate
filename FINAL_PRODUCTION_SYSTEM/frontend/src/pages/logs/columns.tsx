import type { ColumnDef } from '@tanstack/react-table'
import { StatusBadge } from '@/components/status-badge'
import type { LogRow } from '@/api/logs'

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getLogColumns(
  t: (key: string, defaultValue?: any) => string
): ColumnDef<LogRow>[] {
  return [
    {
      accessorKey: 'created_at',
      header: t('logs.timestamp', 'Timestamp'),
      cell: ({ row }) => (
        <span className="whitespace-nowrap">{row.original.created_at}</span>
      ),
    },
    {
      accessorKey: 'username',
      header: t('logs.user', 'User'),
    },
    {
      accessorKey: 'action',
      header: t('logs.action', 'Action'),
      cell: ({ row }) => (
        <StatusBadge status={row.original.action} translationPrefix="logs.action_" />
      ),
    },
    {
      accessorKey: 'description',
      header: t('logs.description', 'Description'),
      cell: ({ row }) => (
        <span className="max-w-[300px] truncate block">{row.original.description}</span>
      ),
    },
    {
      accessorKey: 'ip_address',
      header: t('logs.ip_address', 'IP Address'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.ip_address}
        </code>
      ),
    },
  ]
}
