import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  Cpu,
  HardDrive,
  MemoryStick,
  Monitor,
  CircuitBoard,
  Network,
  Shield,
  Server,
  Fingerprint,
} from 'lucide-react'
import { XIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  getHardware,
  type HardwareInfo,
  type RamModule,
  type VideoCard,
  type StorageDevice,
  type DiskPartition,
  type NetworkAdapter,
} from '@/api/history'
import { formatBiosDate, parseBiosVersion } from '@/lib/bios-version'

function parseJson<T>(value: string | null): T[] {
  if (!value) return []
  try {
    const parsed = JSON.parse(value)
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  if (value === null || value === undefined || value === '') return null
  return (
    <div className="flex justify-between gap-4 py-1">
      <span className="text-muted-foreground text-xs whitespace-nowrap">{label}</span>
      <span className="text-xs font-medium text-right">{value}</span>
    </div>
  )
}

function Section({
  icon: Icon,
  title,
  children,
}: {
  icon: React.ElementType
  title: string
  children: React.ReactNode
}) {
  return (
    <div className="space-y-1">
      <div className="flex items-center gap-2 pb-1 border-b border-border/50">
        <Icon className="h-4 w-4 text-primary" />
        <h4 className="text-sm font-semibold">{title}</h4>
      </div>
      <div className="pl-6">{children}</div>
    </div>
  )
}

function HardwareContent({ hw }: { hw: HardwareInfo }) {
  const { t } = useTranslation()
  const ramModules = parseJson<RamModule>(hw.ram_modules)
  const videoCards = parseJson<VideoCard>(hw.video_cards)
  const storageDevices = parseJson<StorageDevice>(hw.storage_devices)
  const diskPartitions = parseJson<DiskPartition>(hw.disk_partitions)
  const networkAdapters = parseJson<NetworkAdapter>(hw.network_adapters)

  return (
    <div className="space-y-4 max-h-[70vh] overflow-y-auto pr-1">
      {/* Quick overview cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
        {hw.cpu_name && (
          <div className="rounded-lg border bg-muted/30 p-2 text-center">
            <Cpu className="h-4 w-4 mx-auto mb-1 text-blue-500" />
            <div className="text-[10px] text-muted-foreground">{t('hw.cpu', 'CPU')}</div>
            <div className="text-xs font-medium truncate" title={hw.cpu_name}>
              {hw.cpu_name.replace(/\(.*?\)/g, '').trim()}
            </div>
          </div>
        )}
        {hw.ram_total_capacity_gb && (
          <div className="rounded-lg border bg-muted/30 p-2 text-center">
            <MemoryStick className="h-4 w-4 mx-auto mb-1 text-green-500" />
            <div className="text-[10px] text-muted-foreground">{t('hw.ram', 'RAM')}</div>
            <div className="text-xs font-medium">{hw.ram_total_capacity_gb} GB</div>
          </div>
        )}
        {storageDevices.length > 0 && (
          <div className="rounded-lg border bg-muted/30 p-2 text-center">
            <HardDrive className="h-4 w-4 mx-auto mb-1 text-orange-500" />
            <div className="text-[10px] text-muted-foreground">{t('hw.storage', 'Storage')}</div>
            <div className="text-xs font-medium">
              {storageDevices.reduce((sum, d) => sum + (d.size_gb ?? 0), 0).toFixed(0)} GB
            </div>
          </div>
        )}
        {videoCards.length > 0 && (
          <div className="rounded-lg border bg-muted/30 p-2 text-center">
            <Monitor className="h-4 w-4 mx-auto mb-1 text-purple-500" />
            <div className="text-[10px] text-muted-foreground">{t('hw.gpu', 'GPU')}</div>
            <div className="text-xs font-medium truncate" title={videoCards[0].name ?? ''}>
              {videoCards[0].name?.replace(/NVIDIA |AMD |Intel /i, '') ?? '\u2014'}
            </div>
          </div>
        )}
      </div>

      {/* System Identity */}
      {(hw.system_manufacturer || hw.computer_name) && (
        <Section icon={Server} title={t('hw.system', 'System')}>
          <InfoRow label={t('hw.computer_name', 'Computer')} value={hw.computer_name} />
          <InfoRow label={t('hw.manufacturer', 'Manufacturer')} value={hw.system_manufacturer} />
          <InfoRow label={t('hw.product', 'Product')} value={hw.system_product_name} />
          <InfoRow label={t('hw.system_serial', 'Serial')} value={hw.system_serial} />
          <InfoRow label={t('hw.uuid', 'UUID')} value={hw.system_uuid} />
          {hw.chassis_type && (
            <InfoRow label={t('hw.chassis', 'Chassis')} value={`${hw.chassis_manufacturer ?? ''} ${hw.chassis_type ?? ''}`.trim()} />
          )}
          <InfoRow label={t('hw.chassis_serial', 'Chassis Serial')} value={hw.chassis_serial} />
        </Section>
      )}

      {/* OS */}
      {hw.os_name && (
        <Section icon={Monitor} title={t('hw.os', 'Operating System')}>
          <InfoRow label={t('hw.os_name', 'OS')} value={hw.os_name} />
          <InfoRow label={t('hw.os_version', 'Version')} value={hw.os_version} />
          <InfoRow label={t('hw.os_arch', 'Architecture')} value={hw.os_architecture} />
          <InfoRow label={t('hw.os_build', 'Build')} value={hw.os_build_number} />
          <InfoRow label={t('hw.os_serial', 'OS Serial')} value={hw.os_serial_number} />
          <InfoRow label={t('hw.os_install', 'Installed')} value={hw.os_install_date} />
          <InfoRow
            label={t('hw.secure_boot', 'Secure Boot')}
            value={
              hw.secure_boot_enabled !== null ? (
                <Badge variant={hw.secure_boot_enabled ? 'default' : 'destructive'} className="text-[10px] px-1.5 py-0">
                  {hw.secure_boot_enabled ? t('hw.enabled', 'Enabled') : t('hw.disabled', 'Disabled')}
                </Badge>
              ) : null
            }
          />
        </Section>
      )}

      {/* Motherboard & BIOS */}
      {hw.motherboard_manufacturer && (
        <Section icon={CircuitBoard} title={t('hw.motherboard', 'Motherboard & BIOS')}>
          <InfoRow label={t('hw.mb_manufacturer', 'Manufacturer')} value={hw.motherboard_manufacturer} />
          <InfoRow label={t('hw.mb_product', 'Product')} value={hw.motherboard_product} />
          <InfoRow label={t('hw.mb_serial', 'Serial Number')} value={hw.motherboard_serial} />
          <InfoRow label={t('hw.mb_version', 'Version')} value={hw.motherboard_version} />
          <InfoRow label={t('hw.bios_vendor', 'BIOS Vendor')} value={hw.bios_manufacturer} />
          <InfoRow label={t('hw.bios_version', 'BIOS Version')} value={(() => {
            const parsed = parseBiosVersion(
              hw.bios_version,
              hw.bios_manufacturer,
              hw.motherboard_manufacturer,
              hw.system_manufacturer,
            )
            if (!parsed || parsed.brand === 'unknown') return hw.bios_version
            return (
              <span className="flex items-center gap-1.5">
                <span>{hw.bios_version}</span>
                {parsed.beta && (
                  <Badge variant="outline" className="text-[9px] px-1 py-0 text-yellow-600 border-yellow-400">
                    beta
                  </Badge>
                )}
              </span>
            )
          })()} />
          <InfoRow label={t('hw.bios_serial', 'BIOS Serial')} value={hw.bios_serial_number} />
          <InfoRow
            label={t('hw.bios_date', 'BIOS Date')}
            value={formatBiosDate(hw.bios_release_date) ?? hw.bios_release_date}
          />
        </Section>
      )}

      {/* CPU */}
      {hw.cpu_name && (
        <Section icon={Cpu} title={t('hw.cpu_details', 'Processor')}>
          <InfoRow label={t('hw.cpu_name', 'Name')} value={hw.cpu_name} />
          <InfoRow label={t('hw.cpu_manufacturer', 'Vendor')} value={hw.cpu_manufacturer} />
          <InfoRow
            label={t('hw.cpu_cores', 'Cores / Threads')}
            value={hw.cpu_cores ? `${hw.cpu_cores}C / ${hw.cpu_logical_processors ?? '?'}T` : null}
          />
          <InfoRow
            label={t('hw.cpu_clock', 'Clock')}
            value={hw.cpu_max_clock_speed ? `${hw.cpu_max_clock_speed} MHz` : null}
          />
        </Section>
      )}

      {/* RAM */}
      {hw.ram_total_capacity_gb && (
        <Section icon={MemoryStick} title={t('hw.memory', 'Memory')}>
          <InfoRow label={t('hw.ram_total', 'Total')} value={`${hw.ram_total_capacity_gb} GB`} />
          <InfoRow
            label={t('hw.ram_slots', 'Slots')}
            value={hw.ram_slots_used != null ? `${hw.ram_slots_used} / ${hw.ram_slots_total}` : null}
          />
          {ramModules.length > 0 && (
            <div className="mt-1 overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-muted-foreground">
                    <th className="text-left font-normal py-0.5">{t('hw.module', 'Module')}</th>
                    <th className="text-right font-normal py-0.5">{t('hw.capacity', 'Size')}</th>
                    <th className="text-right font-normal py-0.5">{t('hw.speed', 'Speed')}</th>
                    <th className="text-right font-normal py-0.5">{t('hw.part_number', 'Part #')}</th>
                    <th className="text-right font-normal py-0.5">{t('hw.serial', 'Serial')}</th>
                  </tr>
                </thead>
                <tbody>
                  {ramModules.map((m, i) => (
                    <tr key={i} className="border-t border-border/30">
                      <td className="py-0.5">{m.manufacturer ?? 'Unknown'}</td>
                      <td className="text-right py-0.5">{m.capacity_gb ?? '?'} GB</td>
                      <td className="text-right py-0.5">{m.speed_mhz ?? '?'} MHz</td>
                      <td className="text-right py-0.5 text-muted-foreground font-mono text-[10px]">{m.part_number ?? '\u2014'}</td>
                      <td className="text-right py-0.5 text-muted-foreground font-mono text-[10px]">{m.serial_number ?? '\u2014'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Section>
      )}

      {/* Storage */}
      {storageDevices.length > 0 && (
        <Section icon={HardDrive} title={t('hw.storage_details', 'Storage')}>
          {storageDevices.map((d, i) => (
            <div key={i} className="py-1 text-xs border-b border-border/20 last:border-0">
              <div className="flex items-center justify-between">
                <span className="truncate flex-1 font-medium" title={d.model ?? ''}>
                  {d.model ?? 'Unknown'}
                </span>
                <span className="text-muted-foreground ml-2 whitespace-nowrap">
                  {d.size_gb?.toFixed(0) ?? '?'} GB {d.interface_type ? `(${d.interface_type})` : ''}
                </span>
              </div>
              {(d.serial_number || d.media_type) && (
                <div className="flex gap-3 mt-0.5 text-[10px] text-muted-foreground">
                  {d.serial_number && (
                    <span>{t('hw.serial', 'Serial')}: <code className="font-mono bg-muted px-0.5 rounded">{d.serial_number}</code></span>
                  )}
                  {d.media_type && <span>{d.media_type}</span>}
                </div>
              )}
            </div>
          ))}
          {diskPartitions.length > 0 && (
            <div className="mt-1 pt-1 border-t border-border/30">
              <div className="text-[10px] text-muted-foreground mb-0.5">{t('hw.partitions', 'Partitions')}</div>
              {diskPartitions.map((p, i) => (
                <div key={i} className="flex justify-between text-xs py-0.5">
                  <span>{p.drive_letter} ({p.file_system})</span>
                  <span className="text-muted-foreground">{p.size_gb?.toFixed(0) ?? '?'} GB</span>
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* Network */}
      {(hw.primary_mac_address || hw.local_ip || networkAdapters.length > 0) && (
        <Section icon={Network} title={t('hw.network', 'Network')}>
          <InfoRow label={t('hw.mac', 'MAC')} value={hw.primary_mac_address} />
          <InfoRow label={t('hw.local_ip', 'Local IP')} value={hw.local_ip} />
          <InfoRow label={t('hw.public_ip', 'Public IP')} value={hw.public_ip} />
          {networkAdapters.length > 0 && (
            <div className="mt-1 pt-1 border-t border-border/30">
              <div className="text-[10px] text-muted-foreground mb-0.5">{t('hw.adapters', 'Adapters')}</div>
              {networkAdapters.map((a, i) => (
                <div key={i} className="text-xs py-0.5">
                  <div className="font-medium">{a.name}</div>
                  <div className="flex gap-3 text-[10px] text-muted-foreground">
                    {a.ip_address && <span>IP: {a.ip_address}</span>}
                    {a.mac_address && <span>MAC: <code className="font-mono bg-muted px-0.5 rounded">{a.mac_address}</code></span>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Section>
      )}

      {/* TPM & Security */}
      {(hw.tpm_present !== null || hw.device_fingerprint) && (
        <Section icon={Shield} title={t('hw.security', 'Security')}>
          {hw.tpm_present !== null && (
            <InfoRow
              label="TPM"
              value={
                <Badge variant={hw.tpm_present ? 'default' : 'secondary'} className="text-[10px] px-1.5 py-0">
                  {hw.tpm_present
                    ? `${t('hw.present', 'Present')}${hw.tpm_version ? ` v${hw.tpm_version}` : ''}`
                    : t('hw.not_present', 'Not Present')}
                </Badge>
              }
            />
          )}
          <InfoRow label={t('hw.tpm_vendor', 'TPM Vendor')} value={hw.tpm_manufacturer} />
          {hw.device_fingerprint && (
            <InfoRow
              label={t('hw.fingerprint', 'Fingerprint')}
              value={
                <code className="text-[10px] bg-muted px-1 rounded" title={hw.device_fingerprint}>
                  {hw.device_fingerprint.substring(0, 16)}...
                </code>
              }
            />
          )}
        </Section>
      )}

      {/* GPU */}
      {videoCards.length > 0 && (
        <Section icon={Monitor} title={t('hw.gpu_details', 'Graphics')}>
          {videoCards.map((v, i) => (
            <div key={i}>
              <InfoRow label={t('hw.gpu_name', 'GPU')} value={v.name} />
              <InfoRow label={t('hw.gpu_driver', 'Driver')} value={v.driver_version} />
              {v.adapter_ram && <InfoRow label={t('hw.gpu_vram', 'VRAM')} value={v.adapter_ram} />}
            </div>
          ))}
        </Section>
      )}

      {/* Metadata */}
      <div className="pt-2 border-t text-[10px] text-muted-foreground flex flex-wrap gap-x-4 gap-y-0.5">
        {hw.collected_at && <span>{t('hw.collected', 'Collected')}: {hw.collected_at}</span>}
        {hw.collection_method && <span>{t('hw.method', 'Method')}: {hw.collection_method}</span>}
      </div>
    </div>
  )
}

interface HardwareDialogProps {
  activationId: number | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function HardwareDialog({ activationId, open, onOpenChange }: HardwareDialogProps) {
  const { t } = useTranslation()

  const { data, isLoading, error } = useQuery({
    queryKey: ['hardware', activationId],
    queryFn: () => getHardware(activationId!),
    enabled: open && activationId !== null,
  })

  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onOpenChange(false)
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [open, onOpenChange])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label={t('hw.title', 'Hardware Details')}>
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/50 backdrop-blur-xs"
        onClick={() => onOpenChange(false)}
      />
      {/* Panel */}
      <div className="fixed top-1/2 left-1/2 z-50 w-full max-w-2xl max-h-[85vh] -translate-x-1/2 -translate-y-1/2 rounded-xl bg-background p-4 ring-1 ring-foreground/10 shadow-lg">
        {/* Header */}
        <div className="flex items-center justify-between mb-3">
          <div className="flex items-center gap-2">
            <Fingerprint className="h-5 w-5" />
            <h2 className="text-base font-medium">{t('hw.title', 'Hardware Details')}</h2>
            {data?.hardware?.computer_name && (
              <Badge variant="outline" className="ml-1 text-xs">
                {data.hardware.computer_name}
              </Badge>
            )}
          </div>
          <Button variant="ghost" size="icon-sm" onClick={() => onOpenChange(false)}>
            <XIcon />
            <span className="sr-only">Close</span>
          </Button>
        </div>

        {isLoading && (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
          </div>
        )}

        {error && (
          <div className="text-center py-8 text-destructive text-sm">
            {error instanceof Error ? error.message : t('hw.error', 'Failed to load hardware info')}
          </div>
        )}

        {data?.hardware && <HardwareContent hw={data.hardware} />}

        {data && !data.hardware && !data.success && (
          <div className="text-center py-8 text-muted-foreground text-sm">
            {data.error ?? t('hw.not_found', 'No hardware data found for this activation')}
          </div>
        )}
      </div>
    </div>
  )
}
