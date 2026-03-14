import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, Pencil, Trash2, Package, HardDrive, ChevronRight, GripVertical } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
  useProductLines,
  useProductLine,
  useSaveProductLine,
  useDeleteProductLine,
  useSaveProductVariant,
  useDeleteProductVariant,
} from '@/hooks/use-product-lines'
import type { SaveProductLineInput, SaveProductVariantInput, PartitionTemplate } from '@/api/product-lines'

const ENFORCEMENT_LABELS: Record<number, { label: string; color: string }> = {
  0: { label: 'product_lines.enforcement_ignore', color: 'secondary' },
  1: { label: 'product_lines.enforcement_info', color: 'default' },
  2: { label: 'product_lines.enforcement_warning', color: 'outline' },
  3: { label: 'product_lines.enforcement_blocking', color: 'destructive' },
}

function formatMB(mb: number): string {
  if (mb >= 1024) return `${(mb / 1024).toFixed(1)} GB`
  return `${mb} MB`
}

// ── Product Line Dialog ────────────────────────────────

function LineDialog({
  open,
  onOpenChange,
  initial,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  initial?: { id?: number; name: string; order_pattern: string; description: string; enforcement_level: number }
}) {
  const { t } = useTranslation()
  const saveLine = useSaveProductLine()
  const [form, setForm] = useState(initial ?? { name: '', order_pattern: '', description: '', enforcement_level: 2 })

  const handleSave = () => {
    const data: SaveProductLineInput = {
      ...(initial?.id ? { id: initial.id } : {}),
      name: form.name,
      order_pattern: form.order_pattern,
      description: form.description || undefined,
      enforcement_level: form.enforcement_level,
    }
    saveLine.mutate(data, { onSuccess: () => onOpenChange(false) })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{initial?.id ? t('product_lines.edit_line', 'Edit Product Line') : t('product_lines.add_line', 'Add Product Line')}</DialogTitle>
          <DialogDescription>{t('product_lines.line_dialog_desc', 'Define a product line with its order number pattern.')}</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-2">
            <Label>{t('product_lines.name', 'Name')}</Label>
            <Input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="RTX Series" />
          </div>
          <div className="space-y-2">
            <Label>{t('product_lines.order_pattern', 'Order Pattern')}</Label>
            <Input value={form.order_pattern} onChange={e => setForm(f => ({ ...f, order_pattern: e.target.value }))} placeholder="ЭЛ00-######" />
            <p className="text-xs text-muted-foreground">{t('product_lines.pattern_hint', 'Order number prefix (e.g. ЭЛ00-######, ЛЕ00-######). Use # for digits, * for any characters.')}</p>
          </div>
          <div className="space-y-2">
            <Label>{t('product_lines.enforcement', 'Enforcement Level')}</Label>
            <Select value={String(form.enforcement_level)} onValueChange={v => setForm(f => ({ ...f, enforcement_level: Number(v) }))}>
              <SelectTrigger><SelectValue>{t(ENFORCEMENT_LABELS[form.enforcement_level]?.label)}</SelectValue></SelectTrigger>
              <SelectContent>
                {[0, 1, 2, 3].map(n => (
                  <SelectItem key={n} value={String(n)}>{t(ENFORCEMENT_LABELS[n].label)}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>{t('product_lines.description', 'Description')}</Label>
            <Textarea value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} rows={2} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>{t('common.cancel', 'Cancel')}</Button>
          <Button onClick={handleSave} disabled={saveLine.isPending || !form.name || !form.order_pattern}>{t('common.save', 'Save')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Variant Dialog ─────────────────────────────────────

interface PartitionRow {
  partition_order: number
  partition_name: string
  partition_type: string
  expected_size_mb: number
  tolerance_percent: number
  is_flexible: boolean
}

const DEFAULT_PARTITIONS: PartitionRow[] = [
  { partition_order: 1, partition_name: 'EFI', partition_type: 'EFI System', expected_size_mb: 260, tolerance_percent: 1, is_flexible: false },
  { partition_order: 2, partition_name: 'MSR', partition_type: 'Microsoft Reserved', expected_size_mb: 16, tolerance_percent: 1, is_flexible: false },
  { partition_order: 3, partition_name: 'OS', partition_type: 'NTFS', expected_size_mb: 250000, tolerance_percent: 1, is_flexible: false },
  { partition_order: 4, partition_name: 'Recovery', partition_type: 'Recovery', expected_size_mb: 1500, tolerance_percent: 5, is_flexible: false },
  { partition_order: 5, partition_name: 'Data', partition_type: 'NTFS', expected_size_mb: 700000, tolerance_percent: 1, is_flexible: true },
  { partition_order: 6, partition_name: 'BIOS', partition_type: 'FAT32', expected_size_mb: 200, tolerance_percent: 1, is_flexible: false },
]

function VariantDialog({
  open,
  onOpenChange,
  lineId,
  initial,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  lineId: number
  initial?: { id?: number; name: string; disk_size_min_mb: number; disk_size_max_mb: number; partitions: PartitionTemplate[] }
}) {
  const { t } = useTranslation()
  const saveVariant = useSaveProductVariant()

  const [name, setName] = useState(initial?.name ?? '')
  const [diskMin, setDiskMin] = useState(initial?.disk_size_min_mb ?? 476000)
  const [diskMax, setDiskMax] = useState(initial?.disk_size_max_mb ?? 530000)
  const [partitions, setPartitions] = useState<PartitionRow[]>(
    initial?.partitions?.length
      ? initial.partitions.map(p => ({
          partition_order: p.partition_order,
          partition_name: p.partition_name,
          partition_type: p.partition_type ?? '',
          expected_size_mb: p.expected_size_mb,
          tolerance_percent: p.tolerance_percent,
          is_flexible: p.is_flexible === 1,
        }))
      : DEFAULT_PARTITIONS
  )

  const updatePartition = (idx: number, field: keyof PartitionRow, value: string | number | boolean) => {
    setPartitions(prev => prev.map((p, i) => i === idx ? { ...p, [field]: value } : p))
  }

  const addPartition = () => {
    setPartitions(prev => [...prev, {
      partition_order: prev.length + 1,
      partition_name: '',
      partition_type: '',
      expected_size_mb: 0,
      tolerance_percent: 1,
      is_flexible: false,
    }])
  }

  const removePartition = (idx: number) => {
    setPartitions(prev => prev.filter((_, i) => i !== idx).map((p, i) => ({ ...p, partition_order: i + 1 })))
  }

  const totalMB = partitions.reduce((s, p) => s + p.expected_size_mb, 0)

  const handleSave = () => {
    const data: SaveProductVariantInput = {
      ...(initial?.id ? { id: initial.id } : {}),
      line_id: lineId,
      name,
      disk_size_min_mb: diskMin,
      disk_size_max_mb: diskMax,
      partitions: partitions.map((p, i) => ({
        partition_order: i + 1,
        partition_name: p.partition_name,
        partition_type: p.partition_type || undefined,
        expected_size_mb: p.expected_size_mb,
        tolerance_percent: p.tolerance_percent,
        is_flexible: p.is_flexible,
      })),
    }
    saveVariant.mutate(data, { onSuccess: () => onOpenChange(false) })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{initial?.id ? t('product_lines.edit_variant', 'Edit Variant') : t('product_lines.add_variant', 'Add Variant')}</DialogTitle>
          <DialogDescription>{t('product_lines.variant_dialog_desc', 'Configure disk size range and partition layout template.')}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {/* Variant info */}
          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-2">
              <Label>{t('product_lines.variant_name', 'Variant Name')}</Label>
              <Input value={name} onChange={e => setName(e.target.value)} placeholder="1TB" />
            </div>
            <div className="space-y-2">
              <Label>{t('product_lines.disk_min_mb', 'Disk Min (MB)')}</Label>
              <Input type="number" value={diskMin} onChange={e => setDiskMin(Number(e.target.value))} />
            </div>
            <div className="space-y-2">
              <Label>{t('product_lines.disk_max_mb', 'Disk Max (MB)')}</Label>
              <Input type="number" value={diskMax} onChange={e => setDiskMax(Number(e.target.value))} />
            </div>
          </div>

          <p className="text-xs text-muted-foreground">
            {t('product_lines.disk_range_hint', 'Range: {{min}} — {{max}}', { min: formatMB(diskMin), max: formatMB(diskMax) })}
          </p>

          <Separator />

          {/* Partitions */}
          <div className="flex items-center justify-between">
            <h4 className="text-sm font-medium">{t('product_lines.partitions', 'Partitions')}</h4>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground">{t('product_lines.total', 'Total')}: {formatMB(totalMB)}</span>
              <Button size="sm" variant="outline" onClick={addPartition}><Plus className="h-3 w-3 mr-1" />{t('product_lines.add_partition', 'Add')}</Button>
            </div>
          </div>

          <div className="space-y-2">
            {/* Header */}
            <div className="grid grid-cols-[16px_1fr_1fr_100px_80px_60px_32px] gap-2 text-xs text-muted-foreground font-medium px-1">
              <span>#</span>
              <span>{t('product_lines.part_name', 'Name')}</span>
              <span>{t('product_lines.part_type', 'Type')}</span>
              <span>{t('product_lines.part_size', 'Size (MB)')}</span>
              <span>{t('product_lines.part_tolerance', 'Tol. %')}</span>
              <span>{t('product_lines.part_flexible', 'Flex')}</span>
              <span></span>
            </div>

            {partitions.map((p, idx) => (
              <div key={idx} className="grid grid-cols-[16px_1fr_1fr_100px_80px_60px_32px] gap-2 items-center">
                <GripVertical className="h-3 w-3 text-muted-foreground" />
                <Input
                  value={p.partition_name}
                  onChange={e => updatePartition(idx, 'partition_name', e.target.value)}
                  className="h-8 text-sm"
                  placeholder="EFI"
                />
                <Input
                  value={p.partition_type}
                  onChange={e => updatePartition(idx, 'partition_type', e.target.value)}
                  className="h-8 text-sm"
                  placeholder="NTFS"
                />
                <Input
                  type="number"
                  value={p.expected_size_mb}
                  onChange={e => updatePartition(idx, 'expected_size_mb', Number(e.target.value))}
                  className="h-8 text-sm"
                />
                <Input
                  type="number"
                  value={p.tolerance_percent}
                  onChange={e => updatePartition(idx, 'tolerance_percent', Number(e.target.value))}
                  className="h-8 text-sm"
                  step="0.5"
                />
                <div className="flex justify-center">
                  <Switch
                    checked={p.is_flexible}
                    onCheckedChange={v => updatePartition(idx, 'is_flexible', v)}
                  />
                </div>
                <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => removePartition(idx)}>
                  <Trash2 className="h-3 w-3" />
                </Button>
              </div>
            ))}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>{t('common.cancel', 'Cancel')}</Button>
          <Button onClick={handleSave} disabled={saveVariant.isPending || !name || partitions.length === 0}>{t('common.save', 'Save')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Main Page ──────────────────────────────────────────

export function ProductLinesPage() {
  const { t } = useTranslation()
  const { data: linesData, isLoading } = useProductLines()
  const [selectedLineId, setSelectedLineId] = useState<number | null>(null)
  const { data: lineDetail } = useProductLine(selectedLineId)

  const [lineDialogOpen, setLineDialogOpen] = useState(false)
  const [editingLine, setEditingLine] = useState<{ id?: number; name: string; order_pattern: string; description: string; enforcement_level: number } | undefined>()
  const [variantDialogOpen, setVariantDialogOpen] = useState(false)
  const [editingVariant, setEditingVariant] = useState<{ id?: number; name: string; disk_size_min_mb: number; disk_size_max_mb: number; partitions: PartitionTemplate[] } | undefined>()

  const [deleteLineId, setDeleteLineId] = useState<number | null>(null)
  const [deleteVariantId, setDeleteVariantId] = useState<number | null>(null)
  const deleteLine = useDeleteProductLine()
  const deleteVariant = useDeleteProductVariant()

  const lines = linesData?.lines ?? []
  const detail = lineDetail?.line

  const openAddLine = () => { setEditingLine(undefined); setLineDialogOpen(true) }
  const openEditLine = (line: typeof lines[0]) => {
    setEditingLine({ id: line.id, name: line.name, order_pattern: line.order_pattern, description: line.description ?? '', enforcement_level: line.enforcement_level })
    setLineDialogOpen(true)
  }

  const openAddVariant = () => { setEditingVariant(undefined); setVariantDialogOpen(true) }
  const openEditVariant = (v: NonNullable<typeof detail>['variants'][0]) => {
    setEditingVariant({ id: v.id, name: v.name, disk_size_min_mb: v.disk_size_min_mb, disk_size_max_mb: v.disk_size_max_mb, partitions: v.partitions })
    setVariantDialogOpen(true)
  }

  return (
    <div className="flex-1 p-6 space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">{t('product_lines.title', 'Product Lines')}</h1>
          <p className="text-sm text-muted-foreground">{t('product_lines.subtitle', 'Manage product lines, variants, and partition layout templates for QC checks.')}</p>
        </div>
        <Button onClick={openAddLine}><Plus className="h-4 w-4 mr-2" />{t('product_lines.add_line', 'Add Product Line')}</Button>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Left Panel: Product Lines list */}
        <Card className="lg:col-span-1">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">{t('product_lines.lines_list', 'Product Lines')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 p-2">
            {isLoading ? (
              <p className="text-sm text-muted-foreground p-4">{t('common.loading', 'Loading...')}</p>
            ) : lines.length === 0 ? (
              <p className="text-sm text-muted-foreground p-4">{t('product_lines.no_lines', 'No product lines configured.')}</p>
            ) : (
              lines.map(line => (
                <div
                  key={line.id}
                  className={`flex items-center justify-between p-3 rounded-md cursor-pointer transition-colors hover:bg-accent ${selectedLineId === line.id ? 'bg-accent' : ''}`}
                  onClick={() => setSelectedLineId(line.id)}
                >
                  <div className="flex items-center gap-3 min-w-0">
                    <Package className="h-4 w-4 text-muted-foreground shrink-0" />
                    <div className="min-w-0">
                      <div className="text-sm font-medium truncate">{line.name}</div>
                      <div className="text-xs text-muted-foreground font-mono">{line.order_pattern}</div>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Badge variant={ENFORCEMENT_LABELS[line.enforcement_level]?.color as 'secondary' | 'default' | 'outline' | 'destructive'}>
                      {t(ENFORCEMENT_LABELS[line.enforcement_level]?.label)}
                    </Badge>
                    {line.variant_count !== undefined && (
                      <span className="text-xs text-muted-foreground">{line.variant_count}</span>
                    )}
                    <ChevronRight className="h-4 w-4 text-muted-foreground" />
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>

        {/* Right Panel: Selected line detail */}
        <Card className="lg:col-span-2">
          {!selectedLineId ? (
            <CardContent className="flex items-center justify-center h-64 text-muted-foreground text-sm">
              {t('product_lines.select_line', 'Select a product line to view its variants and partition templates.')}
            </CardContent>
          ) : !detail ? (
            <CardContent className="flex items-center justify-center h-64 text-muted-foreground text-sm">
              {t('common.loading', 'Loading...')}
            </CardContent>
          ) : (
            <>
              <CardHeader className="pb-2">
                <div className="flex items-center justify-between">
                  <div>
                    <CardTitle>{detail.name}</CardTitle>
                    <p className="text-sm text-muted-foreground mt-1">
                      {t('product_lines.pattern', 'Pattern')}: <code className="bg-muted px-1 rounded">{detail.order_pattern}</code>
                      {detail.description && <span className="ml-2">— {detail.description}</span>}
                    </p>
                  </div>
                  <div className="flex gap-1">
                    <Button size="sm" variant="outline" onClick={() => openEditLine(detail)}>
                      <Pencil className="h-3 w-3 mr-1" />{t('common.edit', 'Edit')}
                    </Button>
                    <Button size="sm" variant="outline" className="text-destructive" onClick={() => setDeleteLineId(detail.id)}>
                      <Trash2 className="h-3 w-3" />
                    </Button>
                  </div>
                </div>
              </CardHeader>

              <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-medium">{t('product_lines.variants', 'Variants')}</h3>
                  <Button size="sm" onClick={openAddVariant}><Plus className="h-3 w-3 mr-1" />{t('product_lines.add_variant', 'Add Variant')}</Button>
                </div>

                {detail.variants.length === 0 ? (
                  <p className="text-sm text-muted-foreground py-4">{t('product_lines.no_variants', 'No variants configured for this line.')}</p>
                ) : (
                  detail.variants.map(variant => (
                    <Card key={variant.id} className="border-dashed">
                      <CardContent className="p-4 space-y-3">
                        <div className="flex items-center justify-between">
                          <div className="flex items-center gap-2">
                            <HardDrive className="h-4 w-4 text-muted-foreground" />
                            <span className="font-medium text-sm">{variant.name}</span>
                            <span className="text-xs text-muted-foreground">
                              ({formatMB(variant.disk_size_min_mb)} — {formatMB(variant.disk_size_max_mb)})
                            </span>
                          </div>
                          <div className="flex gap-1">
                            <Button size="sm" variant="ghost" onClick={() => openEditVariant(variant)}>
                              <Pencil className="h-3 w-3" />
                            </Button>
                            <Button size="sm" variant="ghost" className="text-destructive" onClick={() => setDeleteVariantId(variant.id)}>
                              <Trash2 className="h-3 w-3" />
                            </Button>
                          </div>
                        </div>

                        {/* Partition table */}
                        {variant.partitions.length > 0 && (
                          <div className="rounded-md border">
                            <table className="w-full text-xs">
                              <thead>
                                <tr className="border-b bg-muted/50">
                                  <th className="p-2 text-left font-medium">#</th>
                                  <th className="p-2 text-left font-medium">{t('product_lines.part_name', 'Name')}</th>
                                  <th className="p-2 text-left font-medium">{t('product_lines.part_type', 'Type')}</th>
                                  <th className="p-2 text-right font-medium">{t('product_lines.part_size', 'Size')}</th>
                                  <th className="p-2 text-right font-medium">{t('product_lines.part_tolerance', 'Tol. %')}</th>
                                  <th className="p-2 text-center font-medium">{t('product_lines.part_flexible', 'Flex')}</th>
                                </tr>
                              </thead>
                              <tbody>
                                {variant.partitions.map(part => (
                                  <tr key={part.id} className="border-b last:border-0">
                                    <td className="p-2 text-muted-foreground">{part.partition_order}</td>
                                    <td className="p-2 font-medium">{part.partition_name}</td>
                                    <td className="p-2 text-muted-foreground">{part.partition_type}</td>
                                    <td className="p-2 text-right">{formatMB(part.expected_size_mb)}</td>
                                    <td className="p-2 text-right">{part.tolerance_percent}%</td>
                                    <td className="p-2 text-center">{part.is_flexible ? '~' : ''}</td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        )}
                      </CardContent>
                    </Card>
                  ))
                )}
              </CardContent>
            </>
          )}
        </Card>
      </div>

      {/* Dialogs */}
      <LineDialog open={lineDialogOpen} onOpenChange={setLineDialogOpen} initial={editingLine} />

      {selectedLineId && (
        <VariantDialog
          open={variantDialogOpen}
          onOpenChange={setVariantDialogOpen}
          lineId={selectedLineId}
          initial={editingVariant}
        />
      )}

      {/* Delete confirmations */}
      <AlertDialog open={deleteLineId !== null} onOpenChange={() => setDeleteLineId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('product_lines.confirm_delete_line', 'Delete Product Line?')}</AlertDialogTitle>
            <AlertDialogDescription>{t('product_lines.confirm_delete_line_desc', 'This will deactivate the product line and all its variants. This action can be undone.')}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
            <AlertDialogAction onClick={() => { if (deleteLineId) { deleteLine.mutate(deleteLineId); if (selectedLineId === deleteLineId) setSelectedLineId(null); setDeleteLineId(null) } }}>
              {t('common.delete', 'Delete')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={deleteVariantId !== null} onOpenChange={() => setDeleteVariantId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('product_lines.confirm_delete_variant', 'Delete Variant?')}</AlertDialogTitle>
            <AlertDialogDescription>{t('product_lines.confirm_delete_variant_desc', 'This will deactivate this variant and its partition template.')}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
            <AlertDialogAction onClick={() => { if (deleteVariantId) { deleteVariant.mutate(deleteVariantId); setDeleteVariantId(null) } }}>
              {t('common.delete', 'Delete')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
