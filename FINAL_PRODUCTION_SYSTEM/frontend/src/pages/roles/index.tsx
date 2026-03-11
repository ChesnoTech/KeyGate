import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2, Lock } from 'lucide-react'
import { type ColumnDef } from '@tanstack/react-table'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { DataTable } from '@/components/data-table/data-table'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { useRoles, useCreateRole, useUpdateRole, useDeleteRole } from '@/hooks/use-roles'
import { RoleDialog } from './components/role-dialog'
import type { RoleRow, CreateRoleData, UpdateRoleData } from '@/api/roles'

export function RolesPage() {
  const { t } = useTranslation()
  const { data, isLoading } = useRoles()
  const createMutation = useCreateRole()
  const updateMutation = useUpdateRole()
  const deleteMutation = useDeleteRole()

  const [dialogOpen, setDialogOpen] = useState(false)
  const [editRole, setEditRole] = useState<RoleRow | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<RoleRow | null>(null)

  const handleOpenCreate = () => {
    setEditRole(null)
    setDialogOpen(true)
  }

  const handleOpenEdit = (role: RoleRow) => {
    setEditRole(role)
    setDialogOpen(true)
  }

  const handleSubmit = (data: CreateRoleData | UpdateRoleData) => {
    if ('role_id' in data) {
      updateMutation.mutate(data, {
        onSuccess: () => setDialogOpen(false),
      })
    } else {
      createMutation.mutate(data, {
        onSuccess: () => setDialogOpen(false),
      })
    }
  }

  const handleDelete = () => {
    if (!deleteTarget) return
    deleteMutation.mutate(deleteTarget.id)
    setDeleteTarget(null)
  }

  const columns = useMemo<ColumnDef<RoleRow>[]>(
    () => [
      {
        id: 'color',
        header: '',
        cell: ({ row }) => (
          <div
            className="h-3 w-3 rounded-full"
            style={{ backgroundColor: row.original.color || '#6366f1' }}
          />
        ),
        size: 32,
      },
      {
        accessorKey: 'display_name',
        header: t('roles.col_display_name', 'Display Name'),
      },
      {
        accessorKey: 'role_name',
        header: t('roles.col_role_name', 'Role Name'),
        cell: ({ row }) => (
          <span className="font-mono text-xs">{row.original.role_name}</span>
        ),
      },
      {
        accessorKey: 'role_type',
        header: t('roles.col_type', 'Type'),
        cell: ({ row }) => (
          <Badge variant={row.original.role_type === 'admin' ? 'default' : 'secondary'}>
            {row.original.role_type}
          </Badge>
        ),
      },
      {
        id: 'permissions_count',
        header: t('roles.col_permissions', 'Permissions'),
        cell: ({ row }) => {
          const r = row.original as unknown as Record<string, unknown>
          const count = typeof r.permission_count === 'number'
            ? r.permission_count
            : Array.isArray(row.original.permissions)
              ? row.original.permissions.filter((p) => p.granted).length
              : 0
          return <span className="text-sm">{count}</span>
        },
      },
      {
        id: 'system',
        header: t('roles.col_system', 'System'),
        cell: ({ row }) =>
          row.original.is_system_role ? (
            <Lock className="h-4 w-4 text-muted-foreground" />
          ) : null,
      },
      {
        id: 'actions',
        header: t('common.actions', 'Actions'),
        cell: ({ row }) => (
          <div className="flex items-center gap-1">
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={() => handleOpenEdit(row.original)}
              disabled={row.original.is_system_role}
            >
              <Pencil className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              onClick={() => setDeleteTarget(row.original)}
              disabled={row.original.is_system_role}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </div>
        ),
      },
    ],
    [t]
  )

  return (
    <>
      <AppHeader title={t('nav.roles', 'Roles & Permissions')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-2xl font-bold tracking-tight">{t('nav.roles', 'Roles & Permissions')}</h2>
          <Button size="sm" onClick={handleOpenCreate}>
            <Plus className="mr-2 h-4 w-4" />
            {t('roles.create', 'Create Role')}
          </Button>
        </div>

        <DataTable
          columns={columns}
          data={data?.roles ?? []}
          isLoading={isLoading}
          emptyMessage={t('empty.roles')}
        />
      </div>

      <RoleDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        role={editRole}
        onSubmit={handleSubmit}
        isPending={createMutation.isPending || updateMutation.isPending}
      />

      <ConfirmDialog
        open={!!deleteTarget}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t('roles.confirm_delete', 'Delete Role?')}
        description={t('roles.confirm_delete_desc', 'This will permanently remove this role. Users assigned to this role will lose their permissions.')}
        confirmLabel={t('common.delete', 'Delete')}
        variant="destructive"
        onConfirm={handleDelete}
      />
    </>
  )
}
