import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2, ShieldCheck, ShieldOff, Usb } from 'lucide-react'
import { type ColumnDef } from '@tanstack/react-table'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { DataTable } from '@/components/data-table/data-table'
import { ConfirmDialog } from '@/components/confirm-dialog'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { useTrustedNetworks, useAddTrustedNetwork, useDeleteTrustedNetwork } from '@/hooks/use-security'
import type { TrustedNetworkRow } from '@/api/security'

export function NetworksPage() {
  const { t } = useTranslation()
  const { data, isLoading } = useTrustedNetworks()
  const addMutation = useAddTrustedNetwork()
  const deleteMutation = useDeleteTrustedNetwork()

  const [addOpen, setAddOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<TrustedNetworkRow | null>(null)
  const [form, setForm] = useState({
    network_name: '',
    ip_range: '',
    bypass_2fa: false,
    allow_usb_auth: false,
    description: '',
  })

  const resetForm = () => {
    setForm({
      network_name: '',
      ip_range: '',
      bypass_2fa: false,
      allow_usb_auth: false,
      description: '',
    })
  }

  const handleAdd = () => {
    addMutation.mutate(form, {
      onSuccess: () => {
        setAddOpen(false)
        resetForm()
      },
    })
  }

  const handleDelete = () => {
    if (!deleteTarget) return
    deleteMutation.mutate(deleteTarget.id)
    setDeleteTarget(null)
  }

  const columns = useMemo<ColumnDef<TrustedNetworkRow>[]>(
    () => [
      {
        accessorKey: 'network_name',
        header: t('networks.col_name', 'Name'),
      },
      {
        accessorKey: 'ip_range',
        header: t('networks.col_ip_range', 'IP Range'),
      },
      {
        accessorKey: 'bypass_2fa',
        header: t('networks.col_bypass_2fa', 'Bypass 2FA'),
        cell: ({ row }) =>
          row.original.bypass_2fa ? (
            <ShieldOff className="h-4 w-4 text-orange-500" />
          ) : (
            <ShieldCheck className="h-4 w-4 text-muted-foreground" />
          ),
      },
      {
        accessorKey: 'allow_usb_auth',
        header: t('networks.col_allow_usb', 'Allow USB Auth'),
        cell: ({ row }) =>
          row.original.allow_usb_auth ? (
            <Usb className="h-4 w-4 text-green-600" />
          ) : (
            <span className="text-muted-foreground">-</span>
          ),
      },
      {
        accessorKey: 'created_by_username',
        header: t('networks.col_created_by', 'Created By'),
      },
      {
        accessorKey: 'created_at',
        header: t('networks.col_created_at', 'Created At'),
      },
      {
        id: 'actions',
        header: t('common.actions', 'Actions'),
        cell: ({ row }) => (
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={() => setDeleteTarget(row.original)}
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        ),
      },
    ],
    [t]
  )

  return (
    <>
      <AppHeader title={t('nav.networks', 'Trusted Networks')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-2xl font-bold tracking-tight">{t('nav.networks', 'Trusted Networks')}</h2>
          <Button size="sm" onClick={() => setAddOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            {t('networks.add', 'Add Network')}
          </Button>
        </div>

        <DataTable
          columns={columns}
          data={data?.networks ?? []}
          isLoading={isLoading}
          emptyMessage={t('empty.networks')}
        />
      </div>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t('networks.add_title', 'Add Trusted Network')}</DialogTitle>
            <DialogDescription>
              {t('networks.add_desc', 'Define a trusted network with CIDR notation.')}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="network_name">{t('networks.field_name', 'Network Name')}</Label>
              <Input
                id="network_name"
                value={form.network_name}
                onChange={(e) => setForm({ ...form, network_name: e.target.value })}
                placeholder={t('networks.field_name_placeholder', 'Office LAN')}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="ip_range">{t('networks.field_ip_range', 'IP Range (CIDR)')}</Label>
              <Input
                id="ip_range"
                value={form.ip_range}
                onChange={(e) => setForm({ ...form, ip_range: e.target.value })}
                placeholder="192.168.1.0/24"
              />
            </div>
            <div className="flex items-center justify-between">
              <Label htmlFor="bypass_2fa">{t('networks.field_bypass_2fa', 'Bypass 2FA')}</Label>
              <Switch
                id="bypass_2fa"
                checked={form.bypass_2fa}
                onCheckedChange={(checked) => setForm({ ...form, bypass_2fa: checked })}
              />
            </div>
            <div className="flex items-center justify-between">
              <Label htmlFor="allow_usb_auth">{t('networks.field_allow_usb', 'Allow USB Auth')}</Label>
              <Switch
                id="allow_usb_auth"
                checked={form.allow_usb_auth}
                onCheckedChange={(checked) => setForm({ ...form, allow_usb_auth: checked })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="description">{t('networks.field_description', 'Description')}</Label>
              <Textarea
                id="description"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                placeholder={t('networks.field_description_placeholder', 'Optional description...')}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAddOpen(false)}>
              {t('common.cancel', 'Cancel')}
            </Button>
            <Button onClick={handleAdd} disabled={addMutation.isPending || !form.network_name || !form.ip_range}>
              {addMutation.isPending ? t('common.saving', 'Saving...') : t('common.save', 'Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={!!deleteTarget}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t('networks.confirm_delete', 'Delete Network?')}
        description={t('networks.confirm_delete_desc', 'This will permanently remove this trusted network.')}
        confirmLabel={t('common.delete', 'Delete')}
        variant="destructive"
        onConfirm={handleDelete}
      />
    </>
  )
}
