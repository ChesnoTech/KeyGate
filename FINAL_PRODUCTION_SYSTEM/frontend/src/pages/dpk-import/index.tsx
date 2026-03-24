import { useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Upload,
  Package,
  Loader2,
  CheckCircle2,
  XCircle,
  Clock,
  FileText,
} from 'lucide-react'
import { useDpkBatches, useImportDpkBatch } from '@/hooks/use-production'
import type { DpkBatch } from '@/api/production'

function BatchStatusBadge({ status }: { status: string }) {
  const config: Record<string, { color: string; icon: React.ReactNode }> = {
    completed: { color: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', icon: <CheckCircle2 className="h-3 w-3" /> },
    failed: { color: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', icon: <XCircle className="h-3 w-3" /> },
    pending: { color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', icon: <Clock className="h-3 w-3" /> },
    processing: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400', icon: <Loader2 className="h-3 w-3 animate-spin" /> },
  }
  const c = config[status] || config.pending
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${c.color}`}>
      {c.icon}
      {status}
    </span>
  )
}

export function DpkImportPage() {
  const { t } = useTranslation()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [batchName, setBatchName] = useState('')
  const [productEdition, setProductEdition] = useState('')
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [lastResult, setLastResult] = useState<{ imported: number; duplicates: number; failed: number } | null>(null)

  const batchesQuery = useDpkBatches()
  const importMut = useImportDpkBatch()

  const batches = batchesQuery.data?.batches ?? []

  const handleImport = async () => {
    if (!selectedFile || !batchName) return
    const result = await importMut.mutateAsync({
      file: selectedFile,
      batchName,
      productEdition,
    })
    setLastResult({ imported: result.imported, duplicates: result.duplicates, failed: result.failed })
    setSelectedFile(null)
    setBatchName('')
    setProductEdition('')
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  return (
    <div className="flex-1 p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-2">
          <Package className="h-6 w-6" />
          {t('dpk.title', 'DPK Batch Import')}
        </h1>
        <p className="text-sm text-muted-foreground mt-1">
          {t('dpk.subtitle', 'Import OEM product keys from Microsoft DPK delivery files or CSV')}
        </p>
      </div>

      {/* Import form */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base flex items-center gap-2">
            <Upload className="h-4 w-4" />
            {t('dpk.import_new', 'Import New Batch')}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <Label>{t('dpk.batch_name', 'Batch Name')} *</Label>
              <Input
                value={batchName}
                onChange={(e) => setBatchName(e.target.value)}
                placeholder={t('dpk.batch_name_placeholder', 'e.g. Microsoft-2026-Q1')}
              />
            </div>
            <div>
              <Label>{t('dpk.product_edition', 'Product Edition')}</Label>
              <Input
                value={productEdition}
                onChange={(e) => setProductEdition(e.target.value)}
                placeholder={t('dpk.edition_placeholder', 'e.g. Windows 11 Pro OEM')}
              />
            </div>
            <div>
              <Label>{t('dpk.file', 'Key File')} *</Label>
              <Input
                ref={fileInputRef}
                type="file"
                accept=".csv,.txt,.xml"
                onChange={(e) => setSelectedFile(e.target.files?.[0] || null)}
              />
            </div>
          </div>

          <div className="bg-muted/50 rounded-lg p-3 text-sm">
            <p className="font-medium">{t('dpk.format_info', 'Supported formats')}:</p>
            <ul className="mt-1 space-y-0.5 text-muted-foreground text-xs">
              <li>• CSV: {t('dpk.format_csv', 'One key per line, or columns: key,edition,type')}</li>
              <li>• TXT: {t('dpk.format_txt', 'One key per line (XXXXX-XXXXX-XXXXX-XXXXX-XXXXX)')}</li>
              <li>• XML: {t('dpk.format_xml', 'Microsoft DPK delivery XML format')}</li>
            </ul>
          </div>

          <div className="flex items-center gap-3">
            <Button
              onClick={handleImport}
              disabled={!selectedFile || !batchName || importMut.isPending}
            >
              {importMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t('dpk.import_btn', 'Import Keys')}
            </Button>

            {lastResult && (
              <div className="flex items-center gap-4 text-sm">
                <span className="text-green-600 flex items-center gap-1">
                  <CheckCircle2 className="h-4 w-4" />
                  {lastResult.imported} {t('dpk.imported', 'imported')}
                </span>
                {lastResult.duplicates > 0 && (
                  <span className="text-yellow-600">
                    {lastResult.duplicates} {t('dpk.duplicates', 'duplicates')}
                  </span>
                )}
                {lastResult.failed > 0 && (
                  <span className="text-red-600">
                    {lastResult.failed} {t('dpk.failed', 'failed')}
                  </span>
                )}
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Batch history */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('dpk.history', 'Import History')}</CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {batchesQuery.isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : batches.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <FileText className="h-10 w-10 mx-auto mb-3 opacity-50" />
              <p>{t('empty.dpk', 'No DPK batches imported yet')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="text-left py-2 px-4">{t('dpk.col_name', 'Batch Name')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_edition', 'Edition')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_total', 'Total')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_imported', 'Imported')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_dupes', 'Dupes')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_status', 'Status')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_source', 'Source')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_by', 'By')}</th>
                    <th className="text-left py-2 px-4">{t('dpk.col_date', 'Date')}</th>
                  </tr>
                </thead>
                <tbody>
                  {batches.map((b: DpkBatch) => (
                    <tr key={b.id} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="py-3 px-4 font-medium">{b.batch_name}</td>
                      <td className="py-3 px-4">{b.product_edition || '—'}</td>
                      <td className="py-3 px-4">{b.total_keys}</td>
                      <td className="py-3 px-4 text-green-600">{b.imported_keys}</td>
                      <td className="py-3 px-4 text-yellow-600">{b.duplicate_keys}</td>
                      <td className="py-3 px-4"><BatchStatusBadge status={b.import_status} /></td>
                      <td className="py-3 px-4 text-xs">{b.source_filename || b.import_source}</td>
                      <td className="py-3 px-4">{b.imported_by_username || '—'}</td>
                      <td className="py-3 px-4 text-muted-foreground">{b.created_at}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
