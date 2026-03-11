import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { UserPlus } from 'lucide-react'
import { PageHeader } from '@/components/page-header'
import { PageSearch } from '@/components/page-search'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { DataTable } from '@/components/data-table/data-table'
import { DataTablePagination } from '@/components/data-table/data-table-pagination'
import { ConfirmDialog } from '@/components/confirm-dialog'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { useTechnicians, useToggleTech, useDeleteTech, useResetPassword } from '@/hooks/use-technicians'
import { getTechColumns } from './columns'
import { AddTechDialog } from './components/add-dialog'
import { EditTechDialog } from './components/edit-dialog'
import type { TechnicianRow } from '@/api/technicians'

export function TechniciansPage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [addOpen, setAddOpen] = useState(false)
  const [editId, setEditId] = useState<number | null>(null)
  const [confirmAction, setConfirmAction] = useState<{ type: 'toggle' | 'delete'; tech: TechnicianRow } | null>(null)
  const [resetTarget, setResetTarget] = useState<TechnicianRow | null>(null)
  const [newPassword, setNewPassword] = useState('')

  const { data, isLoading } = useTechnicians({ page, search: search || undefined })
  const toggleMutation = useToggleTech()
  const deleteMutation = useDeleteTech()
  const resetMutation = useResetPassword()

  const columns = useMemo(
    () =>
      getTechColumns(t, {
        onEdit: (tech) => setEditId(tech.id),
        onToggle: (tech) => setConfirmAction({ type: 'toggle', tech }),
        onResetPassword: (tech) => {
          setResetTarget(tech)
          setNewPassword('')
        },
        onDelete: (tech) => setConfirmAction({ type: 'delete', tech }),
      }),
    [t]
  )

  const handleConfirm = () => {
    if (!confirmAction) return
    if (confirmAction.type === 'toggle') {
      toggleMutation.mutate(confirmAction.tech.id)
    } else {
      deleteMutation.mutate(confirmAction.tech.id)
    }
    setConfirmAction(null)
  }

  const handleResetPassword = () => {
    if (!resetTarget || newPassword.length < 8) return
    resetMutation.mutate(
      { id: resetTarget.id, new_password: newPassword },
      {
        onSuccess: () => {
          setResetTarget(null)
          setNewPassword('')
        },
      }
    )
  }

  return (
    <>
      <PageHeader
        title={t('nav.technicians', 'Technicians')}
        actions={
          <Button size="sm" onClick={() => setAddOpen(true)}>
            <UserPlus className="mr-2 h-4 w-4" />
            {t('tech.add', 'Add Technician')}
          </Button>
        }
      />
      <div className="flex-1 space-y-4 p-4 md:p-6 pt-0 md:pt-0">
        <div className="flex items-center gap-3">
          <PageSearch
            value={search}
            onChange={(v) => { setSearch(v); setPage(1) }}
            placeholder={t('tech.search_placeholder', 'Search technicians...')}
          />
        </div>

        <DataTable
          columns={columns}
          data={data?.technicians ?? []}
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

      <AddTechDialog open={addOpen} onOpenChange={setAddOpen} />

      <EditTechDialog
        techId={editId}
        open={editId !== null}
        onOpenChange={(open) => { if (!open) setEditId(null) }}
      />

      <ConfirmDialog
        open={!!confirmAction}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title={
          confirmAction?.type === 'delete'
            ? t('tech.confirm_delete', 'Delete Technician?')
            : t('tech.confirm_toggle', 'Toggle Status?')
        }
        description={
          confirmAction?.type === 'delete'
            ? t('tech.confirm_delete_desc', 'This will permanently remove this technician from the system.')
            : confirmAction?.tech.is_active
              ? t('tech.confirm_deactivate_desc', 'This will deactivate the technician account.')
              : t('tech.confirm_activate_desc', 'This will reactivate the technician account.')
        }
        confirmLabel={
          confirmAction?.type === 'delete'
            ? t('common.delete', 'Delete')
            : confirmAction?.tech.is_active
              ? t('tech.deactivate', 'Deactivate')
              : t('tech.activate', 'Activate')
        }
        variant={confirmAction?.type === 'delete' ? 'destructive' : 'default'}
        onConfirm={handleConfirm}
      />

      {/* Reset Password Dialog */}
      <Dialog
        open={resetTarget !== null}
        onOpenChange={(open) => {
          if (!open) {
            setResetTarget(null)
            setNewPassword('')
          }
        }}
      >
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>{t('tech.reset_password', 'Reset Password')}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              {t('tech.reset_password_desc', 'Set a new password for')} <strong>{resetTarget?.full_name}</strong>
            </p>
            <div className="space-y-2">
              <Label htmlFor="reset-password">{t('tech.new_password', 'New Password')}</Label>
              <Input
                id="reset-password"
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder={t('tech.password_placeholder', 'Min 8 characters')}
                minLength={8}
              />
            </div>
          </div>
          <DialogFooter>
            <Button
              onClick={handleResetPassword}
              disabled={newPassword.length < 8 || resetMutation.isPending}
            >
              {resetMutation.isPending
                ? t('common.saving', 'Saving...')
                : t('tech.reset_password', 'Reset Password')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
