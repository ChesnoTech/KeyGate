import { useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Upload, Download, FileText, CheckCircle2, AlertCircle, XCircle } from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { useImportKeys } from '@/hooks/use-keys'
import type { ImportKeysResponse } from '@/api/keys'

interface ImportKeysDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function ImportKeysDialog({ open, onOpenChange }: ImportKeysDialogProps) {
  const { t } = useTranslation()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportKeysResponse | null>(null)
  const importMutation = useImportKeys()

  const reset = () => {
    setSelectedFile(null)
    setResult(null)
    importMutation.reset()
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const handleOpenChange = (v: boolean) => {
    if (!v) reset()
    onOpenChange(v)
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] ?? null
    setSelectedFile(file)
    setResult(null)
    importMutation.reset()
  }

  const handleImport = () => {
    if (!selectedFile) return
    importMutation.mutate(selectedFile, {
      onSuccess: (data) => setResult(data),
    })
  }

  const totalProcessed = result
    ? result.imported + result.updated + result.skipped
    : 0

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('keys.import_title', 'Import Keys from CSV')}</DialogTitle>
          <DialogDescription>
            {t('keys.import_formats', 'Supported formats: Standard (Key, OEM ID, Status) or Comprehensive (with usage history)')}
          </DialogDescription>
          <button
            type="button"
            className="mt-1 text-xs text-primary hover:underline inline-flex items-center gap-1"
            onClick={() => {
              const csv = 'ProductKey,OEMIdentifier,RollSerial,Status\nXXXXX-XXXXX-XXXXX-XXXXX-XXXXX,OEM-001,1,unused\nYYYYY-YYYYY-YYYYY-YYYYY-YYYYY,OEM-001,2,unused'
              const blob = new Blob([csv], { type: 'text/csv' })
              const url = URL.createObjectURL(blob)
              const a = document.createElement('a')
              a.href = url
              a.download = 'oem_keys_template.csv'
              a.click()
              URL.revokeObjectURL(url)
            }}
          >
            <Download className="h-3 w-3" />
            {t('keys.download_template', 'Download CSV template')}
          </button>
        </DialogHeader>

        {/* File input */}
        <div className="space-y-3">
          <label className="text-sm font-medium">{t('keys.import_file', 'CSV File*')}</label>
          <div
            className="relative flex items-center gap-3 rounded-lg border-2 border-dashed border-muted-foreground/25 p-4 transition-colors hover:border-muted-foreground/40 cursor-pointer"
            onClick={() => fileInputRef.current?.click()}
          >
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted">
              {selectedFile ? (
                <FileText className="h-5 w-5 text-primary" />
              ) : (
                <Upload className="h-5 w-5 text-muted-foreground" />
              )}
            </div>
            <div className="min-w-0 flex-1">
              {selectedFile ? (
                <>
                  <p className="text-sm font-medium truncate">{selectedFile.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {(selectedFile.size / 1024).toFixed(1)} KB
                  </p>
                </>
              ) : (
                <>
                  <p className="text-sm font-medium text-muted-foreground">
                    {t('common.click_to_select', 'Click to select file')}
                  </p>
                  <p className="text-xs text-muted-foreground">.csv</p>
                </>
              )}
            </div>
            {selectedFile && (
              <Button
                variant="ghost"
                size="icon-sm"
                className="shrink-0"
                onClick={(e) => {
                  e.stopPropagation()
                  setSelectedFile(null)
                  setResult(null)
                  if (fileInputRef.current) fileInputRef.current.value = ''
                }}
              >
                <XCircle className="h-4 w-4" />
              </Button>
            )}
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv"
              onChange={handleFileChange}
              className="sr-only"
            />
          </div>
        </div>

        {/* Results */}
        {result && (
          <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
            <div className="flex items-center gap-2 text-sm font-medium">
              <CheckCircle2 className="h-4 w-4 text-green-600" />
              {t('keys.import_success', 'Import Successful!')}
            </div>

            <div className="grid grid-cols-3 gap-3 text-center">
              <ResultStat
                label={t('keys.import_imported', 'Imported')}
                value={result.imported}
                variant="success"
              />
              <ResultStat
                label={t('keys.import_updated', 'Updated')}
                value={result.updated}
                variant="info"
              />
              <ResultStat
                label={t('keys.import_skipped', 'Skipped')}
                value={result.skipped}
                variant="muted"
              />
            </div>

            {totalProcessed > 0 && (
              <p className="text-xs text-muted-foreground text-center">
                {totalProcessed} {t('keys.import_total_processed', 'total rows processed')}
              </p>
            )}

            {result.errors.length > 0 && (
              <div className="space-y-1">
                <div className="flex items-center gap-1.5 text-xs font-medium text-destructive">
                  <AlertCircle className="h-3.5 w-3.5" />
                  {t('keys.import_errors', 'Errors')} ({result.errors.length})
                </div>
                <ul className="max-h-24 overflow-y-auto space-y-0.5 text-xs text-muted-foreground">
                  {result.errors.map((err, i) => (
                    <li key={i} className="truncate">
                      {err}
                    </li>
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
          {!result && (
            <Button
              onClick={handleImport}
              disabled={!selectedFile || importMutation.isPending}
            >
              {importMutation.isPending ? (
                <>
                  <span className="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" />
                  {t('common.uploading', 'Uploading...')}
                </>
              ) : (
                <>
                  <Upload className="mr-2 h-4 w-4" />
                  {t('keys.import_csv', 'Import CSV')}
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
  variant: 'success' | 'info' | 'muted'
}) {
  const colorMap = {
    success: 'text-green-600',
    info: 'text-blue-600',
    muted: 'text-muted-foreground',
  }
  return (
    <div>
      <div className={`text-xl font-bold tabular-nums ${colorMap[variant]}`}>{value}</div>
      <div className="text-xs text-muted-foreground">{label}</div>
    </div>
  )
}
