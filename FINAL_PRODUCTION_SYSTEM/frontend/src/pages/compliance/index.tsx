import { useState, useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Save, Search, Settings2, Cpu, Factory } from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
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
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { DataTable } from '@/components/data-table/data-table'
import {
  useQcSettings,
  useSaveQcSettings,
  useMotherboards,
  useUpdateMotherboard,
  useManufacturers,
  useUpdateManufacturer,
} from '@/hooks/use-compliance'
import { getMotherboardColumns } from './columns'
import type { MotherboardRow, ManufacturerDefault } from '@/api/compliance'

const enforcementOptions = [
  { value: '0', labelKey: 'compliance.enforcement_0' },
  { value: '1', labelKey: 'compliance.enforcement_1' },
  { value: '2', labelKey: 'compliance.enforcement_2' },
  { value: '3', labelKey: 'compliance.enforcement_3' },
]

// ── Global Settings Tab ─────────────────────────────────

function GlobalSettingsTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useQcSettings()
  const saveMutation = useSaveQcSettings()

  const [form, setForm] = useState({
    qc_enabled: '0',
    default_bios_enforcement: '1',
    default_secure_boot_enforcement: '1',
    default_hackbgrt_enforcement: '1',
    blocking_prevents_key: '1',
  })

  useEffect(() => {
    if (data?.settings) {
      setForm({
        qc_enabled: data.settings.qc_enabled ?? '0',
        default_bios_enforcement: data.settings.default_bios_enforcement ?? '1',
        default_secure_boot_enforcement: data.settings.default_secure_boot_enforcement ?? '1',
        default_hackbgrt_enforcement: data.settings.default_hackbgrt_enforcement ?? '1',
        blocking_prevents_key: data.settings.blocking_prevents_key ?? '1',
      })
    }
  }, [data])

  if (isLoading) return <Skeleton className="h-64 w-full" />

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>{t('compliance.global_settings', 'Global Settings')}</CardTitle>
          <CardDescription>{t('compliance.global_settings_desc', 'Configure QC compliance engine defaults.')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="flex items-center justify-between">
            <div>
              <Label>{t('compliance.qc_enabled', 'QC Engine Enabled')}</Label>
              <p className="text-sm text-muted-foreground">{t('compliance.qc_enabled_desc', 'Enable hardware compliance checking on all activations.')}</p>
            </div>
            <Switch
              checked={form.qc_enabled === '1'}
              onCheckedChange={(v) => setForm({ ...form, qc_enabled: v ? '1' : '0' })}
            />
          </div>

          <div className="flex items-center justify-between">
            <div>
              <Label>{t('compliance.blocking_prevents_key', 'Blocking Prevents Activation')}</Label>
              <p className="text-sm text-muted-foreground">{t('compliance.blocking_prevents_key_desc', 'Refuse key distribution when blocking compliance issues exist.')}</p>
            </div>
            <Switch
              checked={form.blocking_prevents_key === '1'}
              onCheckedChange={(v) => setForm({ ...form, blocking_prevents_key: v ? '1' : '0' })}
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2">
              <Label>{t('compliance.default_bios_enforcement', 'Default BIOS Enforcement')}</Label>
              <Select value={form.default_bios_enforcement} onValueChange={(v) => v && setForm({ ...form, default_bios_enforcement: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {enforcementOptions.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.default_sb_enforcement', 'Default Secure Boot Enforcement')}</Label>
              <Select value={form.default_secure_boot_enforcement} onValueChange={(v) => v && setForm({ ...form, default_secure_boot_enforcement: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {enforcementOptions.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.default_hb_enforcement', 'Default Boot Logo Enforcement')}</Label>
              <Select value={form.default_hackbgrt_enforcement} onValueChange={(v) => v && setForm({ ...form, default_hackbgrt_enforcement: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {enforcementOptions.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
            <Save className="mr-2 h-4 w-4" />
            {t('common.save', 'Save')}
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}

// ── Motherboard Registry Tab ────────────────────────────

function MotherboardRegistryTab() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [mfrFilter, setMfrFilter] = useState('')
  const [page, setPage] = useState(1)
  const [editBoard, setEditBoard] = useState<MotherboardRow | null>(null)

  const { data, isLoading } = useMotherboards({ page, search: search || undefined, manufacturer: mfrFilter || undefined })
  const updateMutation = useUpdateMotherboard()

  const columns = useMemo(
    () => getMotherboardColumns(t, { onEdit: (board) => setEditBoard(board) }),
    [t]
  )

  const [editForm, setEditForm] = useState<Record<string, string>>({})

  useEffect(() => {
    if (editBoard) {
      setEditForm({
        secure_boot_required: editBoard.secure_boot_required?.toString() ?? '',
        secure_boot_enforcement: editBoard.secure_boot_enforcement?.toString() ?? '',
        min_bios_version: editBoard.min_bios_version ?? '',
        recommended_bios_version: editBoard.recommended_bios_version ?? '',
        bios_enforcement: editBoard.bios_enforcement?.toString() ?? '',
        hackbgrt_enforcement: editBoard.hackbgrt_enforcement?.toString() ?? '',
        notes: editBoard.notes ?? '',
      })
    }
  }, [editBoard])

  const handleSaveBoard = () => {
    if (!editBoard) return
    updateMutation.mutate({
      id: editBoard.id,
      secure_boot_required: editForm.secure_boot_required || null,
      secure_boot_enforcement: editForm.secure_boot_enforcement || null,
      min_bios_version: editForm.min_bios_version || null,
      recommended_bios_version: editForm.recommended_bios_version || null,
      bios_enforcement: editForm.bios_enforcement || null,
      hackbgrt_enforcement: editForm.hackbgrt_enforcement || null,
      notes: editForm.notes || null,
    }, { onSuccess: () => setEditBoard(null) })
  }

  const manufacturers = data?.manufacturers ?? []

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3 flex-wrap">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder={t('compliance.search_boards', 'Search motherboards...')}
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            className="pl-8"
          />
        </div>
        <Select value={mfrFilter} onValueChange={(v) => { setMfrFilter(!v || v === '__all__' ? '' : v); setPage(1) }}>
          <SelectTrigger className="w-[200px]">
            <SelectValue placeholder={t('compliance.all_manufacturers', 'All Manufacturers')} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">{t('compliance.all_manufacturers', 'All Manufacturers')}</SelectItem>
            {manufacturers.map((m) => (
              <SelectItem key={m} value={m}>{m}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable columns={columns} data={data?.motherboards ?? []} isLoading={isLoading} />

      {(data?.total_pages ?? 1) > 1 && (
        <div className="flex items-center justify-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t('common.previous', 'Previous')}
          </Button>
          <span className="text-sm text-muted-foreground">
            {page} / {data?.total_pages ?? 1}
          </span>
          <Button variant="outline" size="sm" disabled={page >= (data?.total_pages ?? 1)} onClick={() => setPage(page + 1)}>
            {t('common.next', 'Next')}
          </Button>
        </div>
      )}

      {/* Edit Motherboard Dialog */}
      <Dialog open={!!editBoard} onOpenChange={(open) => !open && setEditBoard(null)}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {t('compliance.edit_motherboard', 'Edit Motherboard Rules')}
              {editBoard && (
                <span className="block text-sm font-normal text-muted-foreground mt-1">
                  {editBoard.manufacturer} — {editBoard.product}
                </span>
              )}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-xs text-muted-foreground">
              {t('compliance.inherit_hint', 'Leave empty to inherit from manufacturer defaults or global settings.')}
            </p>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label>{t('compliance.secure_boot_required', 'Secure Boot Required')}</Label>
                <Select value={editForm.secure_boot_required ?? ''} onValueChange={(v) => setEditForm({ ...editForm, secure_boot_required: !v || v === '__inherit__' ? '' : v })}>
                  <SelectTrigger><SelectValue placeholder={t('compliance.inherit', 'Inherit')} /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__inherit__">{t('compliance.inherit', 'Inherit')}</SelectItem>
                    <SelectItem value="1">{t('common.yes', 'Yes')}</SelectItem>
                    <SelectItem value="0">{t('common.no', 'No')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>{t('compliance.sb_enforcement', 'Secure Boot Enforcement')}</Label>
                <Select value={editForm.secure_boot_enforcement ?? ''} onValueChange={(v) => setEditForm({ ...editForm, secure_boot_enforcement: !v || v === '__inherit__' ? '' : v })}>
                  <SelectTrigger><SelectValue placeholder={t('compliance.inherit', 'Inherit')} /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__inherit__">{t('compliance.inherit', 'Inherit')}</SelectItem>
                    {enforcementOptions.map((o) => (
                      <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>{t('compliance.min_bios', 'Min BIOS Version')}</Label>
                <Input
                  value={editForm.min_bios_version ?? ''}
                  onChange={(e) => setEditForm({ ...editForm, min_bios_version: e.target.value })}
                  placeholder={t('compliance.inherit', 'Inherit')}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('compliance.rec_bios', 'Recommended BIOS')}</Label>
                <Input
                  value={editForm.recommended_bios_version ?? ''}
                  onChange={(e) => setEditForm({ ...editForm, recommended_bios_version: e.target.value })}
                  placeholder={t('compliance.inherit', 'Inherit')}
                />
              </div>
              <div className="space-y-2">
                <Label>{t('compliance.bios_enforcement', 'BIOS Enforcement')}</Label>
                <Select value={editForm.bios_enforcement ?? ''} onValueChange={(v) => setEditForm({ ...editForm, bios_enforcement: !v || v === '__inherit__' ? '' : v })}>
                  <SelectTrigger><SelectValue placeholder={t('compliance.inherit', 'Inherit')} /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__inherit__">{t('compliance.inherit', 'Inherit')}</SelectItem>
                    {enforcementOptions.map((o) => (
                      <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>{t('compliance.hb_enforcement', 'Boot Logo Enforcement')}</Label>
                <Select value={editForm.hackbgrt_enforcement ?? ''} onValueChange={(v) => setEditForm({ ...editForm, hackbgrt_enforcement: !v || v === '__inherit__' ? '' : v })}>
                  <SelectTrigger><SelectValue placeholder={t('compliance.inherit', 'Inherit')} /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__inherit__">{t('compliance.inherit', 'Inherit')}</SelectItem>
                    {enforcementOptions.map((o) => (
                      <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.notes', 'Notes')}</Label>
              <Input
                value={editForm.notes ?? ''}
                onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
              />
            </div>
            {editBoard && Array.isArray(editBoard.known_bios_versions) && editBoard.known_bios_versions.length > 0 && (
              <div>
                <Label className="text-xs">{t('compliance.known_bios_versions', 'Known BIOS Versions')}</Label>
                <div className="flex gap-1 flex-wrap mt-1">
                  {editBoard.known_bios_versions.map((v) => (
                    <Badge key={v} variant="outline" className="text-xs">{v}</Badge>
                  ))}
                </div>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditBoard(null)}>
              {t('common.cancel', 'Cancel')}
            </Button>
            <Button onClick={handleSaveBoard} disabled={updateMutation.isPending}>
              <Save className="mr-2 h-4 w-4" />
              {t('common.save', 'Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

// ── Manufacturer Defaults Tab ───────────────────────────

function ManufacturerDefaultsTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useManufacturers()
  const updateMutation = useUpdateManufacturer()
  const [editMfr, setEditMfr] = useState<ManufacturerDefault | null>(null)
  const [newMfr, setNewMfr] = useState<string | null>(null)

  const [editForm, setEditForm] = useState({
    secure_boot_required: '1',
    secure_boot_enforcement: '1',
    min_bios_version: '',
    recommended_bios_version: '',
    bios_enforcement: '1',
    hackbgrt_enforcement: '1',
    notes: '',
  })

  useEffect(() => {
    if (editMfr) {
      setEditForm({
        secure_boot_required: editMfr.secure_boot_required?.toString() ?? '1',
        secure_boot_enforcement: editMfr.secure_boot_enforcement?.toString() ?? '1',
        min_bios_version: editMfr.min_bios_version ?? '',
        recommended_bios_version: editMfr.recommended_bios_version ?? '',
        bios_enforcement: editMfr.bios_enforcement?.toString() ?? '1',
        hackbgrt_enforcement: editMfr.hackbgrt_enforcement?.toString() ?? '1',
        notes: editMfr.notes ?? '',
      })
    } else if (newMfr) {
      setEditForm({
        secure_boot_required: '1',
        secure_boot_enforcement: '1',
        min_bios_version: '',
        recommended_bios_version: '',
        bios_enforcement: '1',
        hackbgrt_enforcement: '1',
        notes: '',
      })
    }
  }, [editMfr, newMfr])

  const handleSave = () => {
    const manufacturer = editMfr?.manufacturer ?? newMfr
    if (!manufacturer) return
    updateMutation.mutate(
      {
        manufacturer,
        ...editForm,
        min_bios_version: editForm.min_bios_version || null,
        recommended_bios_version: editForm.recommended_bios_version || null,
        notes: editForm.notes || null,
      },
      { onSuccess: () => { setEditMfr(null); setNewMfr(null) } }
    )
  }

  const isDialogOpen = !!editMfr || !!newMfr

  if (isLoading) return <Skeleton className="h-64 w-full" />

  const configured = data?.manufacturers ?? []
  const unconfigured = data?.unconfigured ?? []

  return (
    <div className="space-y-4">
      {unconfigured.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">{t('compliance.unconfigured_manufacturers', 'Unconfigured Manufacturers')}</CardTitle>
            <CardDescription>{t('compliance.unconfigured_desc', 'These manufacturers were detected but have no default rules.')}</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex gap-2 flex-wrap">
              {unconfigured.map((m) => (
                <Button key={m} variant="outline" size="sm" onClick={() => setNewMfr(m)}>
                  {m}
                </Button>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {configured.map((mfr) => (
          <Card key={mfr.id}>
            <CardHeader className="pb-2">
              <CardTitle className="text-base flex items-center justify-between">
                {mfr.manufacturer}
                <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => setEditMfr(mfr)}>
                  <Settings2 className="h-4 w-4" />
                </Button>
              </CardTitle>
            </CardHeader>
            <CardContent className="text-xs space-y-1">
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t('compliance.sb_enforcement', 'Secure Boot')}</span>
                <span>{t(`compliance.enforcement_${mfr.secure_boot_enforcement}`)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t('compliance.bios_enforcement', 'BIOS')}</span>
                <span>{t(`compliance.enforcement_${mfr.bios_enforcement}`)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t('compliance.hb_enforcement', 'Boot Logo')}</span>
                <span>{t(`compliance.enforcement_${mfr.hackbgrt_enforcement}`)}</span>
              </div>
              {mfr.min_bios_version && (
                <div className="flex justify-between">
                  <span className="text-muted-foreground">{t('compliance.min_bios', 'Min BIOS')}</span>
                  <code className="bg-muted px-1 rounded">{mfr.min_bios_version}</code>
                </div>
              )}
            </CardContent>
          </Card>
        ))}
      </div>

      {configured.length === 0 && unconfigured.length === 0 && (
        <div className="text-center py-12 text-muted-foreground">
          {t('compliance.no_manufacturers', 'No manufacturers detected yet. Motherboards will appear here after hardware collection.')}
        </div>
      )}

      {/* Edit/Create Manufacturer Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={(open) => { if (!open) { setEditMfr(null); setNewMfr(null) } }}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {editMfr
                ? t('compliance.edit_manufacturer', 'Edit Manufacturer Defaults')
                : t('compliance.configure_manufacturer', 'Configure Manufacturer')}
              <span className="block text-sm font-normal text-muted-foreground mt-1">
                {editMfr?.manufacturer ?? newMfr}
              </span>
            </DialogTitle>
          </DialogHeader>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label>{t('compliance.secure_boot_required', 'Secure Boot Required')}</Label>
              <Select value={editForm.secure_boot_required} onValueChange={(v) => v && setEditForm({ ...editForm, secure_boot_required: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="1">{t('common.yes', 'Yes')}</SelectItem>
                  <SelectItem value="0">{t('common.no', 'No')}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.sb_enforcement', 'Secure Boot Enforcement')}</Label>
              <Select value={editForm.secure_boot_enforcement} onValueChange={(v) => v && setEditForm({ ...editForm, secure_boot_enforcement: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {enforcementOptions.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.min_bios', 'Min BIOS Version')}</Label>
              <Input
                value={editForm.min_bios_version}
                onChange={(e) => setEditForm({ ...editForm, min_bios_version: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.rec_bios', 'Recommended BIOS')}</Label>
              <Input
                value={editForm.recommended_bios_version}
                onChange={(e) => setEditForm({ ...editForm, recommended_bios_version: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.bios_enforcement', 'BIOS Enforcement')}</Label>
              <Select value={editForm.bios_enforcement} onValueChange={(v) => v && setEditForm({ ...editForm, bios_enforcement: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {enforcementOptions.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>{t('compliance.hb_enforcement', 'Boot Logo Enforcement')}</Label>
              <Select value={editForm.hackbgrt_enforcement} onValueChange={(v) => v && setEditForm({ ...editForm, hackbgrt_enforcement: v })}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {enforcementOptions.map((o) => (
                    <SelectItem key={o.value} value={o.value}>{t(o.labelKey)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="space-y-2">
            <Label>{t('compliance.notes', 'Notes')}</Label>
            <Input
              value={editForm.notes}
              onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setEditMfr(null); setNewMfr(null) }}>
              {t('common.cancel', 'Cancel')}
            </Button>
            <Button onClick={handleSave} disabled={updateMutation.isPending}>
              <Save className="mr-2 h-4 w-4" />
              {t('common.save', 'Save')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

// ── Main Page ───────────────────────────────────────────

export function CompliancePage() {
  const { t } = useTranslation()

  return (
    <>
      <AppHeader title={t('nav.compliance', 'QC Compliance')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <h2 className="text-2xl font-bold tracking-tight">{t('nav.compliance', 'QC Compliance')}</h2>

        <Tabs defaultValue="settings">
          <TabsList>
            <TabsTrigger value="settings">
              <Settings2 className="mr-1.5 h-4 w-4" />
              {t('compliance.tab_settings', 'Settings')}
            </TabsTrigger>
            <TabsTrigger value="motherboards">
              <Cpu className="mr-1.5 h-4 w-4" />
              {t('compliance.tab_motherboards', 'Motherboards')}
            </TabsTrigger>
            <TabsTrigger value="manufacturers">
              <Factory className="mr-1.5 h-4 w-4" />
              {t('compliance.tab_manufacturers', 'Manufacturers')}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="settings" className="mt-4">
            <GlobalSettingsTab />
          </TabsContent>
          <TabsContent value="motherboards" className="mt-4">
            <MotherboardRegistryTab />
          </TabsContent>
          <TabsContent value="manufacturers" className="mt-4">
            <ManufacturerDefaultsTab />
          </TabsContent>
        </Tabs>
      </div>
    </>
  )
}
