import type { ColumnDef } from '@tanstack/react-table'
import { MoreHorizontal, Pencil } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { MotherboardRow, ComplianceResult, GroupedComplianceRow, CheckSummary } from '@/api/compliance'

const enforcementLabels: Record<number, { label: string; color: string }> = {
  0: { label: 'Disabled', color: 'bg-gray-400' },
  1: { label: 'Info', color: 'bg-blue-500' },
  2: { label: 'Warning', color: 'bg-orange-500' },
  3: { label: 'Blocking', color: 'bg-red-600' },
}

function EnforcementBadge({ level, t }: { level: number; t: (k: string, d?: string) => string }) {
  const cfg = enforcementLabels[level] ?? enforcementLabels[0]
  return (
    <Badge variant="secondary" className={`${cfg.color} text-white text-[10px] px-1.5 py-0`}>
      {t(`compliance.enforcement_${level}`, cfg.label)}
    </Badge>
  )
}

const resultColors: Record<string, string> = {
  pass: 'bg-green-600',
  info: 'bg-blue-500',
  warning: 'bg-orange-500',
  fail: 'bg-red-600',
}

function ResultBadge({ result, t }: { result: string; t: (k: string, d?: string) => string }) {
  const color = resultColors[result] ?? 'bg-gray-400'
  return (
    <Badge variant="secondary" className={`${color} text-white text-[10px] px-1.5 py-0`}>
      {t(`compliance.result_${result}`, result)}
    </Badge>
  )
}

// ── Motherboard Table Columns ───────────────────────────

interface MbColumnActions {
  onEdit: (board: MotherboardRow) => void
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getMotherboardColumns(
  t: (key: string, defaultValue?: any) => string,
  actions: MbColumnActions
): ColumnDef<MotherboardRow>[] {
  return [
    {
      accessorKey: 'manufacturer',
      header: t('compliance.manufacturer', 'Manufacturer'),
    },
    {
      accessorKey: 'product',
      header: t('compliance.product', 'Product'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.product}
        </code>
      ),
    },
    {
      accessorKey: 'times_seen',
      header: t('compliance.times_seen', 'Seen'),
      cell: ({ row }) => row.original.times_seen,
    },
    {
      accessorKey: 'last_seen_at',
      header: t('compliance.last_seen', 'Last Seen'),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-xs">
          {row.original.last_seen_at?.replace('T', ' ').slice(0, 16) ?? '\u2014'}
        </span>
      ),
    },
    {
      id: 'enf_sb',
      header: t('compliance.col_sb', 'SB'),
      cell: ({ row }) => <EnforcementBadge level={row.original.effective_secure_boot_enforcement} t={t} />,
    },
    {
      id: 'enf_bios',
      header: t('compliance.col_bios_enf', 'BIOS'),
      cell: ({ row }) => <EnforcementBadge level={row.original.effective_bios_enforcement} t={t} />,
    },
    {
      id: 'enf_bl',
      header: t('compliance.col_bl', 'BL'),
      cell: ({ row }) => <EnforcementBadge level={row.original.effective_hackbgrt_enforcement} t={t} />,
    },
    {
      id: 'enf_part',
      header: t('compliance.col_part', 'Part'),
      cell: ({ row }) => <EnforcementBadge level={row.original.effective_partition_enforcement} t={t} />,
    },
    {
      id: 'enf_drv',
      header: t('compliance.col_drv', 'Drv'),
      cell: ({ row }) => <EnforcementBadge level={row.original.effective_missing_drivers_enforcement} t={t} />,
    },
    {
      id: 'bios_versions',
      header: t('compliance.known_bios', 'Known BIOS'),
      cell: ({ row }) => {
        const versions = row.original.known_bios_versions ?? []
        if (versions.length === 0) return <span className="text-muted-foreground">{'\u2014'}</span>
        return (
          <div className="flex gap-1 flex-wrap">
            {versions.slice(0, 3).map((v) => (
              <Badge key={v} variant="outline" className="text-[10px] px-1 py-0">{v}</Badge>
            ))}
            {versions.length > 3 && (
              <Badge variant="outline" className="text-[10px] px-1 py-0">+{versions.length - 3}</Badge>
            )}
          </div>
        )
      },
    },
    {
      id: 'actions',
      cell: ({ row }) => (
        <DropdownMenu>
          <DropdownMenuTrigger className="inline-flex items-center justify-center rounded-md h-8 w-8 hover:bg-accent">
            <MoreHorizontal className="h-4 w-4" />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => actions.onEdit(row.original)}>
              <Pencil className="mr-2 h-4 w-4" />
              {t('compliance.edit_rules', 'Edit Rules')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ]
}

// ── Compliance Results Table Columns ────────────────────

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getComplianceResultColumns(
  t: (key: string, defaultValue?: any) => string
): ColumnDef<ComplianceResult>[] {
  return [
    {
      accessorKey: 'order_number',
      header: t('compliance.order_number', 'Order #'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.order_number}
        </code>
      ),
    },
    {
      id: 'board',
      header: t('compliance.motherboard', 'Motherboard'),
      cell: ({ row }) => {
        const r = row.original
        return r.motherboard_manufacturer
          ? `${r.motherboard_manufacturer} ${r.motherboard_product ?? ''}`
          : '\u2014'
      },
    },
    {
      accessorKey: 'check_type',
      header: t('compliance.check_type', 'Check'),
      cell: ({ row }) => t(`compliance.type_${row.original.check_type}`, row.original.check_type),
    },
    {
      accessorKey: 'check_result',
      header: t('compliance.result', 'Result'),
      cell: ({ row }) => <ResultBadge result={row.original.check_result} t={t} />,
    },
    {
      id: 'expected_actual',
      header: t('compliance.expected_actual', 'Expected / Actual'),
      cell: ({ row }) => {
        const r = row.original
        return (
          <span className="text-xs">
            {r.expected_value ?? '\u2014'} / {r.actual_value ?? '\u2014'}
          </span>
        )
      },
    },
    {
      accessorKey: 'message',
      header: t('compliance.message', 'Message'),
      cell: ({ row }) => (
        <span className="text-xs max-w-[200px] truncate block">
          {row.original.message ?? '\u2014'}
        </span>
      ),
    },
    {
      accessorKey: 'rule_source',
      header: t('compliance.rule_source', 'Source'),
      cell: ({ row }) => t(`compliance.source_${row.original.rule_source}`, row.original.rule_source),
    },
    {
      accessorKey: 'checked_at',
      header: t('compliance.checked_at', 'Checked'),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-xs">
          {row.original.checked_at?.replace('T', ' ').slice(0, 16) ?? '\u2014'}
        </span>
      ),
    },
  ]
}

// ── Grouped Compliance Results (one row per order) ──────

/** Parse partition check message into structured lines */
function parsePartitionMessage(message: string): { header: string; lines: { name: string; ok: boolean; detail: string }[] } | null {
  const lines = message.split('\n').filter(Boolean)
  if (lines.length < 2) return null
  const header = lines[0]
  const parsed = lines.slice(1).map((line) => {
    // e.g. "#1 EFI: OK (260 MB, deviation 0% within 1%)"
    //      "#3 OS: FAIL (150000 MB, deviation 25% exceeds 1%)"
    const m = line.match(/^#\d+\s+(.+?):\s+(OK|FAIL)\s+\((.+)\)$/)
    if (!m) return null
    return { name: m[1], ok: m[2] === 'OK', detail: m[3] }
  }).filter(Boolean) as { name: string; ok: boolean; detail: string }[]
  return parsed.length > 0 ? { header, lines: parsed } : null
}

function PartitionTooltip({ check, label }: { check: CheckSummary; label: string; t?: (k: string, d?: string) => string }) {
  const parsed = check.message ? parsePartitionMessage(check.message) : null

  // If it's a simple pass message or unparseable, show simple tooltip
  if (!parsed) {
    return (
      <SimpleTooltip check={check} label={label} />
    )
  }

  return (
    <div className="space-y-1.5 max-w-sm">
      <p className="font-medium text-xs">{label}</p>
      <p className="text-[11px] opacity-80">{parsed.header}</p>
      <table className="w-full text-[10px]">
        <tbody>
          {parsed.lines.map((line, i) => (
            <tr key={i} className={line.ok ? 'text-green-400' : 'text-red-400'}>
              <td className="pr-2 py-0.5 font-medium whitespace-nowrap">
                {line.ok ? '✓' : '✗'} {line.name}
              </td>
              <td className="py-0.5 opacity-80">{line.detail}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Parse missing drivers message into structured list */
function parseDriversMessage(message: string): { header: string; devices: string[] } | null {
  const lines = message.split('\n').filter(Boolean)
  if (lines.length < 2) return null
  return { header: lines[0], devices: lines.slice(1) }
}

function DriversTooltip({ check, label }: { check: CheckSummary; label: string }) {
  const parsed = check.message ? parseDriversMessage(check.message) : null

  if (!parsed) {
    return <SimpleTooltip check={check} label={label} />
  }

  return (
    <div className="space-y-1.5 max-w-sm">
      <p className="font-medium text-xs">{label}</p>
      <p className="text-[11px] opacity-80">{parsed.header}</p>
      <ul className="text-[10px] space-y-0.5">
        {parsed.devices.map((device, i) => (
          <li key={i} className="text-red-400 flex items-start gap-1">
            <span className="shrink-0">✗</span>
            <span className="opacity-90">{device}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

function SimpleTooltip({ check, label }: { check: CheckSummary; label: string }) {
  // Clean message: remove raw JSON from display
  const msg = check.message
  return (
    <div className="space-y-1 max-w-xs">
      <p className="font-medium text-xs">{label}</p>
      {msg && <p className="text-[11px] opacity-80">{msg}</p>}
      {check.expected_value && check.actual_value
        && !check.expected_value.startsWith('{') && !check.expected_value.startsWith('[') && (
        <p className="text-[10px] opacity-60">
          {check.expected_value} → {check.actual_value}
        </p>
      )}
    </div>
  )
}

function CheckCell({ check, label, checkType, t }: { check?: CheckSummary; label: string; checkType?: string; t: (k: string, d?: string) => string }) {
  if (!check) {
    return <span className="text-muted-foreground text-[10px]">{'\u2014'}</span>
  }
  const color = resultColors[check.result] ?? 'bg-gray-400'
  return (
    <Tooltip>
      <TooltipTrigger className="cursor-help">
        <Badge variant="secondary" className={`${color} text-white text-[10px] px-1.5 py-0`}>
          {t(`compliance.result_${check.result}`, check.result)}
        </Badge>
      </TooltipTrigger>
      <TooltipContent side="bottom" className="p-3">
        {checkType === 'partition_layout'
          ? <PartitionTooltip check={check} label={label} t={t} />
          : checkType === 'missing_drivers'
            ? <DriversTooltip check={check} label={label} />
            : <SimpleTooltip check={check} label={label} />
        }
      </TooltipContent>
    </Tooltip>
  )
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export function getGroupedComplianceColumns(
  t: (key: string, defaultValue?: any) => string
): ColumnDef<GroupedComplianceRow>[] {
  return [
    {
      accessorKey: 'order_number',
      header: t('compliance.order_number', 'Order #'),
      cell: ({ row }) => (
        <code className="text-xs bg-muted px-1.5 py-0.5 rounded font-mono">
          {row.original.order_number}
        </code>
      ),
    },
    {
      id: 'board',
      header: t('compliance.motherboard', 'Motherboard'),
      cell: ({ row }) => {
        const r = row.original
        return r.motherboard_manufacturer
          ? <span className="text-xs">{r.motherboard_manufacturer} {r.motherboard_product ?? ''}</span>
          : <span className="text-muted-foreground">{'\u2014'}</span>
      },
    },
    {
      id: 'overall',
      header: t('compliance.overall', 'Overall'),
      cell: ({ row }) => {
        const color = resultColors[row.original.worst_result] ?? 'bg-gray-400'
        return (
          <Badge variant="secondary" className={`${color} text-white text-[10px] px-1.5 py-0`}>
            {t(`compliance.result_${row.original.worst_result}`, row.original.worst_result)}
          </Badge>
        )
      },
    },
    {
      id: 'secure_boot',
      header: t('compliance.col_secure_boot', 'Secure Boot'),
      cell: ({ row }) => (
        <CheckCell
          check={row.original.checks['secure_boot']}
          label={t('compliance.type_secure_boot', 'Secure Boot')}
          checkType="secure_boot"
          t={t}
        />
      ),
    },
    {
      id: 'bios_version',
      header: t('compliance.col_bios', 'BIOS'),
      cell: ({ row }) => (
        <CheckCell
          check={row.original.checks['bios_version']}
          label={t('compliance.type_bios_version', 'BIOS Version')}
          checkType="bios_version"
          t={t}
        />
      ),
    },
    {
      id: 'hackbgrt',
      header: t('compliance.col_boot_logo', 'Boot Logo'),
      cell: ({ row }) => (
        <CheckCell
          check={row.original.checks['hackbgrt_boot_priority']}
          label={t('compliance.type_hackbgrt_boot_priority', 'Boot Logo Verification')}
          checkType="hackbgrt_boot_priority"
          t={t}
        />
      ),
    },
    {
      id: 'partition_layout',
      header: t('compliance.col_partitions', 'Partitions'),
      cell: ({ row }) => (
        <CheckCell
          check={row.original.checks['partition_layout']}
          label={t('compliance.type_partition_layout', 'Partition Layout')}
          checkType="partition_layout"
          t={t}
        />
      ),
    },
    {
      id: 'missing_drivers',
      header: t('compliance.col_drivers', 'Drivers'),
      cell: ({ row }) => (
        <CheckCell
          check={row.original.checks['missing_drivers']}
          label={t('compliance.type_missing_drivers', 'Missing Drivers')}
          checkType="missing_drivers"
          t={t}
        />
      ),
    },
    {
      accessorKey: 'checked_at',
      header: t('compliance.checked_at', 'Checked'),
      cell: ({ row }) => (
        <span className="whitespace-nowrap text-xs">
          {row.original.checked_at?.replace('T', ' ').slice(0, 16) ?? '\u2014'}
        </span>
      ),
    },
  ]
}
