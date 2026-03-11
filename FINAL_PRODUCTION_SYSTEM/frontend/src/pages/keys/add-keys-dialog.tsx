import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Plus, ClipboardPaste, CheckCircle2, AlertCircle } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useAddKeys } from '@/hooks/use-keys'
import type { AddKeysResponse } from '@/api/keys'

const KEY_PATTERN = /^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/

interface AddKeysDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function AddKeysDialog({ open, onOpenChange }: AddKeysDialogProps) {
  const { t } = useTranslation()
  const addMutation = useAddKeys()

  // Shared state
  const [activeTab, setActiveTab] = useState<string>('single')
  const [result, setResult] = useState<AddKeysResponse | null>(null)
  const [error, setError] = useState('')

  // Single key state
  const [productKey, setProductKey] = useState('')
  const [oemId, setOemId] = useState('')
  const [rollSerial, setRollSerial] = useState('')

  // Bulk paste state
  const [keysText, setKeysText] = useState('')
  const [bulkOemId, setBulkOemId] = useState('')
  const [bulkStartPosition, setBulkStartPosition] = useState('1')

  const reset = () => {
    setProductKey('')
    setOemId('')
    setRollSerial('')
    setKeysText('')
    setBulkOemId('')
    setBulkStartPosition('1')
    setResult(null)
    setError('')
    addMutation.reset()
  }

  const handleOpenChange = (v: boolean) => {
    if (!v) reset()
    onOpenChange(v)
  }

  // Parse bulk text into lines
  const parsedLines = useMemo(() => {
    if (!keysText.trim()) return { valid: 0, invalid: 0, total: 0 }
    const lines = keysText
      .split('\n')
      .map((l) => l.trim().toUpperCase())
      .filter((l) => l.length > 0)
    const valid = lines.filter((l) => KEY_PATTERN.test(l)).length
    return { valid, invalid: lines.length - valid, total: lines.length }
  }, [keysText])

  const handleSingleSubmit = () => {
    setError('')
    const key = productKey.trim().toUpperCase()

    if (!KEY_PATTERN.test(key)) {
      setError(t('keys.error_key_format', 'Invalid key format. Expected: XXXXX-XXXXX-XXXXX-XXXXX-XXXXX'))
      return
    }
    if (!oemId.trim()) {
      setError(t('keys.error_oem_required', 'OEM Identifier is required'))
      return
    }
    if (!rollSerial.trim()) {
      setError(t('keys.error_roll_required', 'Roll Serial is required'))
      return
    }

    addMutation.mutate(
      [{ product_key: key, oem_identifier: oemId.trim(), roll_serial: rollSerial.trim() }],
      { onSuccess: (data) => setResult(data) }
    )
  }

  const handleBulkSubmit = () => {
    setError('')

    if (!bulkOemId.trim()) {
      setError(t('keys.error_oem_required', 'OEM Identifier is required'))
      return
    }

    const startPos = parseInt(bulkStartPosition, 10)
    if (isNaN(startPos) || startPos < 1) {
      setError(t('keys.error_start_position', 'Starting position must be 1 or greater'))
      return
    }

    const lines = keysText
      .split('\n')
      .map((l) => l.trim().toUpperCase())
      .filter((l) => l.length > 0)

    if (lines.length === 0) {
      setError(t('keys.error_no_keys', 'Please enter at least one key'))
      return
    }

    const keys = lines.map((key, index) => ({
      product_key: key,
      oem_identifier: bulkOemId.trim(),
      roll_serial: String(startPos + index),
    }))

    addMutation.mutate(keys, { onSuccess: (data) => setResult(data) })
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('keys.add_keys_title', 'Add OEM Keys')}</DialogTitle>
          <DialogDescription>
            {t('keys.add_keys_desc', 'Add one or more keys manually. Use the Single Key tab for individual entry or Bulk Paste for multiple keys.')}
          </DialogDescription>
        </DialogHeader>

        {!result ? (
          <Tabs value={activeTab} onValueChange={(v) => { setActiveTab(v as string); setError('') }}>
            <TabsList className="w-full">
              <TabsTrigger value="single" className="flex-1 gap-1.5">
                <Plus className="h-3.5 w-3.5" />
                {t('keys.tab_single', 'Single Key')}
              </TabsTrigger>
              <TabsTrigger value="bulk" className="flex-1 gap-1.5">
                <ClipboardPaste className="h-3.5 w-3.5" />
                {t('keys.tab_bulk', 'Bulk Paste')}
              </TabsTrigger>
            </TabsList>

            {/* Single Key Tab */}
            <TabsContent value="single">
              <div className="space-y-3 pt-2">
                <div className="space-y-1.5">
                  <Label>{t('keys.field_product_key', 'Product Key')}</Label>
                  <Input
                    value={productKey}
                    onChange={(e) => setProductKey(e.target.value.toUpperCase())}
                    placeholder={t('keys.field_product_key_placeholder', 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX')}
                    maxLength={29}
                    className="font-mono"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>{t('keys.field_oem_id', 'OEM Identifier')}</Label>
                  <Input
                    value={oemId}
                    onChange={(e) => setOemId(e.target.value)}
                    placeholder={t('keys.field_oem_id_placeholder', 'e.g., DELL-OEM-01')}
                    maxLength={20}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>{t('keys.field_roll_serial', 'Roll Serial')}</Label>
                  <Input
                    value={rollSerial}
                    onChange={(e) => setRollSerial(e.target.value)}
                    placeholder={t('keys.field_roll_serial_placeholder', 'Position in roll (e.g., 1)')}
                    maxLength={20}
                  />
                  <p className="text-[11px] text-muted-foreground">
                    {t('keys.roll_serial_hint', 'Key position within the batch/roll (starting from 1)')}
                  </p>
                </div>
              </div>
            </TabsContent>

            {/* Bulk Paste Tab */}
            <TabsContent value="bulk">
              <div className="space-y-3 pt-2">
                <div className="space-y-1.5">
                  <Label>{t('keys.bulk_keys_label', 'Product Keys (one per line)')}</Label>
                  <Textarea
                    value={keysText}
                    onChange={(e) => setKeysText(e.target.value)}
                    placeholder={t('keys.bulk_keys_placeholder', 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX\nYYYYY-YYYYY-YYYYY-YYYYY-YYYYY\n...')}
                    className="min-h-28 font-mono text-xs"
                  />
                  {parsedLines.total > 0 && (
                    <div className="flex items-center gap-3 text-xs">
                      <span className="text-muted-foreground">
                        {t('keys.bulk_line_count', '{{count}} key(s) detected', { count: parsedLines.total })}
                      </span>
                      {parsedLines.invalid > 0 && (
                        <span className="text-destructive">
                          {t('keys.bulk_invalid_lines', '{{count}} invalid line(s)', { count: parsedLines.invalid })}
                        </span>
                      )}
                    </div>
                  )}
                </div>

                <div className="rounded-lg border bg-muted/30 p-3 space-y-3">
                  <p className="text-xs font-medium text-muted-foreground">
                    {t('keys.bulk_shared_fields', 'Shared fields for all keys')}
                  </p>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                      <Label className="text-xs">{t('keys.field_oem_id', 'OEM Identifier')}</Label>
                      <Input
                        value={bulkOemId}
                        onChange={(e) => setBulkOemId(e.target.value)}
                        placeholder={t('keys.field_oem_id_placeholder', 'e.g., DELL-OEM-01')}
                        maxLength={20}
                      />
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">{t('keys.bulk_start_position', 'Starting Roll Position')}</Label>
                      <Input
                        type="number"
                        min={1}
                        value={bulkStartPosition}
                        onChange={(e) => setBulkStartPosition(e.target.value)}
                        placeholder="1"
                      />
                      <p className="text-[11px] text-muted-foreground">
                        {t('keys.bulk_start_position_hint', 'Auto-increments: {{start}}, {{next}}, {{after}}...', {
                          start: Number(bulkStartPosition) || 1,
                          next: (Number(bulkStartPosition) || 1) + 1,
                          after: (Number(bulkStartPosition) || 1) + 2,
                        })}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </TabsContent>

            {/* Error */}
            {error && (
              <p className="text-sm text-destructive">{error}</p>
            )}
          </Tabs>
        ) : (
          /* Results */
          <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
            <div className="flex items-center gap-2 text-sm font-medium">
              <CheckCircle2 className="h-4 w-4 text-green-600" />
              {t('keys.add_success', 'Keys Added Successfully!')}
            </div>

            <div className="grid grid-cols-2 gap-3 text-center">
              <ResultStat
                label={t('keys.add_result_imported', 'Added')}
                value={result.imported}
                variant="success"
              />
              <ResultStat
                label={t('keys.add_result_skipped', 'Skipped')}
                value={result.skipped}
                variant="muted"
              />
            </div>

            {result.errors.length > 0 && (
              <div className="space-y-1">
                <div className="flex items-center gap-1.5 text-xs font-medium text-destructive">
                  <AlertCircle className="h-3.5 w-3.5" />
                  {t('keys.import_errors', 'Errors')} ({result.errors.length})
                </div>
                <ul className="max-h-24 overflow-y-auto space-y-0.5 text-xs text-muted-foreground">
                  {result.errors.map((err, i) => (
                    <li key={i} className="truncate">{err}</li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)}>
            {result ? t('common.close', 'Close') : t('common.cancel', 'Cancel')}
          </Button>
          {!result && activeTab === 'single' && (
            <Button
              onClick={handleSingleSubmit}
              disabled={!productKey.trim() || !oemId.trim() || !rollSerial.trim() || addMutation.isPending}
            >
              {addMutation.isPending ? (
                <>
                  <span className="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" />
                  {t('common.saving', 'Saving...')}
                </>
              ) : (
                <>
                  <Plus className="mr-2 h-4 w-4" />
                  {t('keys.add_key_btn', 'Add Key')}
                </>
              )}
            </Button>
          )}
          {!result && activeTab === 'bulk' && (
            <Button
              onClick={handleBulkSubmit}
              disabled={parsedLines.total === 0 || !bulkOemId.trim() || !bulkStartPosition.trim() || addMutation.isPending}
            >
              {addMutation.isPending ? (
                <>
                  <span className="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" />
                  {t('common.saving', 'Saving...')}
                </>
              ) : (
                <>
                  <ClipboardPaste className="mr-2 h-4 w-4" />
                  {t('keys.add_keys_btn', 'Add {{count}} Key(s)', { count: parsedLines.total })}
                </>
              )}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function ResultStat({
  label,
  value,
  variant,
}: {
  label: string
  value: number
  variant: 'success' | 'muted'
}) {
  const colorMap = {
    success: 'text-green-600',
    muted: 'text-muted-foreground',
  }
  return (
    <div>
      <div className={`text-xl font-bold tabular-nums ${colorMap[variant]}`}>{value}</div>
      <div className="text-xs text-muted-foreground">{label}</div>
    </div>
  )
}
