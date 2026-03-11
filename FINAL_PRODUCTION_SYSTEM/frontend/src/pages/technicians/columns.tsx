import type { ColumnDef } from '@tanstack/react-table'
import { MoreHorizontal, Pencil, ToggleLeft, KeyRound, Trash2 } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import type { TechnicianRow } from '@/api/technicians'

interface ColumnActions {
  onEdit: (tech: TechnicianRow) => void
  onToggle: (tech: TechnicianRow) => void
  onResetPassword: (tech: TechnicianRow) => void
  onDelete: (tech: TechnicianRow) => void
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getTechColumns(
  t: (key: string, defaultValue?: any) => string,
  actions: ColumnActions
): ColumnDef<TechnicianRow>[] {
  return [
    {
      accessorKey: 'id',
      header: 'ID',
      cell: ({ row }) => <span className="text-muted-foreground">#{row.original.id}</span>,
    },
    {
      accessorKey: 'technician_id',
      header: t('tech.technician_id', 'Technician ID'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.technician_id}
        </code>
      ),
    },
    {
      accessorKey: 'full_name',
      header: t('tech.full_name', 'Full Name'),
    },
    {
      accessorKey: 'email',
      header: t('tech.email', 'Email'),
    },
    {
      accessorKey: 'is_active',
      header: t('tech.status', 'Status'),
      cell: ({ row }) => (
        <StatusBadge
          status={row.original.is_active ? 'active' : 'inactive'}
          translationPrefix="tech.status_"
        />
      ),
    },
    {
      accessorKey: 'preferred_server',
      header: t('tech.preferred_server', 'Server'),
      cell: ({ row }) => (
        <Badge variant="outline">{row.original.preferred_server}</Badge>
      ),
    },
    {
      accessorKey: 'last_login',
      header: t('tech.last_login', 'Last Login'),
      cell: ({ row }) => row.original.last_login || '—',
    },
    {
      id: 'actions',
      cell: ({ row }) => {
        const tech = row.original
        return (
          <DropdownMenu>
            <DropdownMenuTrigger className="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent">
              <MoreHorizontal className="h-4 w-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => actions.onEdit(tech)}>
                <Pencil className="mr-2 h-4 w-4" />
                {t('tech.edit', 'Edit')}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => actions.onToggle(tech)}>
                <ToggleLeft className="mr-2 h-4 w-4" />
                {tech.is_active
                  ? t('tech.deactivate', 'Deactivate')
                  : t('tech.activate', 'Activate')}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => actions.onResetPassword(tech)}>
                <KeyRound className="mr-2 h-4 w-4" />
                {t('tech.reset_password', 'Reset Password')}
              </DropdownMenuItem>
              <DropdownMenuItem
                variant="destructive"
                onClick={() => actions.onDelete(tech)}
              >
                <Trash2 className="mr-2 h-4 w-4" />
                {t('tech.delete', 'Delete')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )
      },
    },
  ]
}
