import type { ColumnDef } from '@tanstack/react-table'
import { MoreHorizontal, Pencil } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Badge } from '@/components/ui/badge'
import type { MotherboardRow, ComplianceResult } from '@/api/compliance'

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
      id: 'enforcement',
      header: t('compliance.enforcement', 'Enforcement'),
      cell: ({ row }) => {
        const r = row.original
        return (
          <div className="flex gap-1 flex-wrap">
            <span className="text-[10px] text-muted-foreground mr-0.5">SB:</span>
            <EnforcementBadge level={r.effective_secure_boot_enforcement} t={t} />
            <span className="text-[10px] text-muted-foreground mr-0.5">BIOS:</span>
            <EnforcementBadge level={r.effective_bios_enforcement} t={t} />
            <span className="text-[10px] text-muted-foreground mr-0.5">HB:</span>
            <EnforcementBadge level={r.effective_hackbgrt_enforcement} t={t} />
          </div>
        )
      },
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
