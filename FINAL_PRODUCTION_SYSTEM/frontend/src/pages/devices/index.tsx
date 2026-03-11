import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Search, Usb, Ban, MapPin, ShieldAlert } from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DataTable } from '@/components/data-table/data-table'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { useDevices, useUpdateDeviceStatus, useDeleteDevice } from '@/hooks/use-devices'
import { getDeviceColumns } from './columns'
import type { UsbDeviceRow } from '@/api/devices'

type DeviceAction =
  | { type: 'disable'; device: UsbDeviceRow }
  | { type: 'lost'; device: UsbDeviceRow }
  | { type: 'stolen'; device: UsbDeviceRow }
  | { type: 'delete'; device: UsbDeviceRow }

export function DevicesPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [confirmAction, setConfirmAction] = useState<DeviceAction | null>(null)

  const { data, isLoading } = useDevices({
    search: search || undefined,
  })
  const updateStatusMutation = useUpdateDeviceStatus()
  const deleteMutation = useDeleteDevice()

  const columns = useMemo(
    () =>
      getDeviceColumns(t, {
        onDisable: (device) => setConfirmAction({ type: 'disable', device }),
        onMarkLost: (device) => setConfirmAction({ type: 'lost', device }),
        onMarkStolen: (device) => setConfirmAction({ type: 'stolen', device }),
        onDelete: (device) => setConfirmAction({ type: 'delete', device }),
      }),
    [t]
  )

  const handleConfirm = () => {
    if (!confirmAction) return
    if (confirmAction.type === 'delete') {
      deleteMutation.mutate(confirmAction.device.device_id)
    } else {
      updateStatusMutation.mutate({
        device_id: confirmAction.device.device_id,
        status: confirmAction.type === 'disable' ? 'disabled' : confirmAction.type,
      })
    }
    setConfirmAction(null)
  }

  const confirmTitle = (): string => {
    if (!confirmAction) return ''
    switch (confirmAction.type) {
      case 'disable': return t('devices.confirm_disable', 'Disable Device?')
      case 'lost': return t('devices.confirm_lost', 'Mark as Lost?')
      case 'stolen': return t('devices.confirm_stolen', 'Mark as Stolen?')
      case 'delete': return t('devices.confirm_delete', 'Delete Device?')
    }
  }

  const confirmDescription = (): string => {
    if (!confirmAction) return ''
    switch (confirmAction.type) {
      case 'disable': return t('devices.confirm_disable_desc', 'This device will be disabled and can no longer be used for activation.')
      case 'lost': return t('devices.confirm_lost_desc', 'This device will be marked as lost. It will be blocked from activation.')
      case 'stolen': return t('devices.confirm_stolen_desc', 'This device will be marked as stolen. It will be blocked from activation.')
      case 'delete': return t('devices.confirm_delete_desc', 'This will permanently remove this device from the system.')
    }
  }

  const stats = data?.stats

  const statCards = [
    { label: t('devices.stat_active', 'Active'), value: stats?.active ?? 0, icon: Usb, color: 'text-green-600' },
    { label: t('devices.stat_disabled', 'Disabled'), value: stats?.disabled ?? 0, icon: Ban, color: 'text-muted-foreground' },
    { label: t('devices.stat_lost', 'Lost'), value: stats?.lost ?? 0, icon: MapPin, color: 'text-orange-500' },
    { label: t('devices.stat_stolen', 'Stolen'), value: stats?.stolen ?? 0, icon: ShieldAlert, color: 'text-red-500' },
  ]

  return (
    <>
      <AppHeader title={t('nav.devices', 'Devices')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <h2 className="text-2xl font-bold tracking-tight">{t('nav.devices', 'Devices')}</h2>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {statCards.map((card) => (
            <Card key={card.label} size="sm">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{card.label}</CardTitle>
                <card.icon className={`h-4 w-4 ${card.color}`} />
              </CardHeader>
              <CardContent>
                <div className="text-2xl font-bold">{card.value}</div>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="flex items-center gap-3">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder={t('devices.search_placeholder', 'Search devices...')}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="pl-8"
            />
          </div>
        </div>

        <DataTable
          columns={columns}
          data={data?.devices ?? []}
          isLoading={isLoading}
          emptyMessage={t('empty.devices')}
        />
      </div>

      <ConfirmDialog
        open={!!confirmAction}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title={confirmTitle()}
        description={confirmDescription()}
        confirmLabel={
          confirmAction?.type === 'delete'
            ? t('common.delete', 'Delete')
            : t('common.confirm', 'Confirm')
        }
        variant={confirmAction?.type === 'delete' ? 'destructive' : 'default'}
        onConfirm={handleConfirm}
      />
    </>
  )
}
