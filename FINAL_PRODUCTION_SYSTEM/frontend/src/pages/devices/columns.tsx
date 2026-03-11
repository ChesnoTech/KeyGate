import type { ColumnDef } from '@tanstack/react-table'
import { MoreHorizontal, Ban, MapPin, ShieldAlert, Trash2 } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge } from '@/components/status-badge'
import type { UsbDeviceRow } from '@/api/devices'

interface ColumnActions {
  onDisable: (device: UsbDeviceRow) => void
  onMarkLost: (device: UsbDeviceRow) => void
  onMarkStolen: (device: UsbDeviceRow) => void
  onDelete: (device: UsbDeviceRow) => void
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getDeviceColumns(
  t: (key: string, defaultValue?: any) => string,
  actions: ColumnActions
): ColumnDef<UsbDeviceRow>[] {
  return [
    {
      accessorKey: 'device_name',
      header: t('devices.device_name', 'Device Name'),
    },
    {
      accessorKey: 'device_serial_number',
      header: t('devices.serial_number', 'Serial Number'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.device_serial_number}
        </code>
      ),
    },
    {
      accessorKey: 'full_name',
      header: t('devices.technician', 'Technician'),
      cell: ({ row }) => row.original.full_name || row.original.technician_id,
    },
    {
      accessorKey: 'device_manufacturer',
      header: t('devices.manufacturer', 'Manufacturer'),
      cell: ({ row }) => row.original.device_manufacturer || '\u2014',
    },
    {
      accessorKey: 'device_model',
      header: t('devices.model', 'Model'),
      cell: ({ row }) => row.original.device_model || '\u2014',
    },
    {
      accessorKey: 'device_status',
      header: t('devices.status', 'Status'),
      cell: ({ row }) => (
        <StatusBadge status={row.original.device_status} translationPrefix="devices.status_" />
      ),
    },
    {
      accessorKey: 'registered_date',
      header: t('devices.registered_date', 'Registered'),
      cell: ({ row }) => (
        <span className="whitespace-nowrap">{row.original.registered_date}</span>
      ),
    },
    {
      id: 'actions',
      cell: ({ row }) => {
        const device = row.original
        return (
          <DropdownMenu>
            <DropdownMenuTrigger className="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent">
              <MoreHorizontal className="h-4 w-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={() => actions.onDisable(device)}
                disabled={device.device_status === 'disabled'}
              >
                <Ban className="mr-2 h-4 w-4" />
                {t('devices.disable', 'Disable')}
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => actions.onMarkLost(device)}
                disabled={device.device_status === 'lost'}
              >
                <MapPin className="mr-2 h-4 w-4" />
                {t('devices.mark_lost', 'Mark Lost')}
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => actions.onMarkStolen(device)}
                disabled={device.device_status === 'stolen'}
              >
                <ShieldAlert className="mr-2 h-4 w-4" />
                {t('devices.mark_stolen', 'Mark Stolen')}
              </DropdownMenuItem>
              <DropdownMenuItem
                variant="destructive"
                onClick={() => actions.onDelete(device)}
              >
                <Trash2 className="mr-2 h-4 w-4" />
                {t('devices.delete', 'Delete')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )
      },
    },
  ]
}
