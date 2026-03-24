import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  AlertTriangle,
  AlertCircle,
  CheckCircle2,
  Key,
  Upload,
  Save,
  Loader2,
  FileUp,
} from 'lucide-react'
import { useKeyPoolStatus, useSaveKeyPoolConfig, useDpkBatches, useImportDpkBatch } from '@/hooks/use-production'
import type { KeyPoolStatus, DpkBatch } from '@/api/production'

function PoolCard({ pool }: { pool: KeyPoolStatus }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState(false)
  const [low, setLow] = useState(pool.low_threshold)
  const [critical, setCritical] = useState(pool.critical_threshold)
  const saveMut = useSaveKeyPoolConfig()

  const alertIcon = pool.alert_level === 'critical'
    ? <AlertCircle className="h-5 w-5 text-red-500" />
    : pool.alert_level === 'low'
      ? <AlertTriangle className="h-5 w-5 text-yellow-500" />
      : <CheckCircle2 className="h-5 w-5 text-green-500" />

  const alertBg = pool.alert_level === 'critical'
    ? 'border-red-200 dark:border-red-800'
    : pool.alert_level === 'low'
      ? 'border-yellow-200 dark:border-yellow-800'
      : ''

  return (
    <Card className={alertBg}>
      <CardHeader className="pb-2">
        <div className="flex items-center justify-between">
          <CardTitle className="text-base flex items-center gap-2">
            <Key className="h-4 w-4" />
            {pool.product_edition}
          </CardTitle>
          {alertIcon}
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid grid-cols-4 gap-2 text-center">
          <div>
            <p className="text-2xl font-bold">{pool.total_keys}</p>
            <p className="text-xs text-muted-foreground">{t('pool.total', 'Total')}</p>
          </div>
          <div>
            <p className="text-2xl font-bold text-green-600">{pool.unused_keys}</p>
            <p className="text-xs text-muted-foreground">{t('pool.unused', 'Unused')}</p>
          </div>
          <div>
            <p className="text-2xl font-bold text-blue-600">{pool.allocated_keys}</p>
            <p className="text-xs text-muted-foreground">{t('pool.allocated', 'Allocated')}</p>
          </div>
          <div>
            <p className="text-2xl font-bold text-red-600">{pool.bad_keys}</p>
            <p className="text-xs text-muted-foreground">{t('pool.bad', 'Bad')}</p>
          </div>
        </div>

        {/* Progress bar */}
        <div className="w-full bg-muted rounded-full h-2">
          <div
            className={`h-2 rounded-full ${pool.alert_level === 'critical' ? 'bg-red-500' : pool.alert_level === 'low' ? 'bg-yellow-500' : 'bg-green-500'}`}
            style={{ width: `${pool.total_keys > 0 ? (pool.unused_keys / pool.total_keys) * 100 : 0}%` }}
          />
        </div>

        {editing ? (
          <div className="space-y-2 pt-2">
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label className="text-xs">{t('pool.low_threshold', 'Low Alert')}</Label>
                <Input type="number" value={low} onChange={e => setLow(parseInt(e.target.value) || 0)} className="h-8" />
              </div>
              <div>
                <Label className="text-xs">{t('pool.critical_threshold', 'Critical Alert')}</Label>
                <Input type="number" value={critical} onChange={e => setCritical(parseInt(e.target.value) || 0)} className="h-8" />
              </div>
            </div>
            <div className="flex gap-2">
              <Button size="sm" className="h-7" onClick={() => {
                saveMut.mutate({ product_edition: pool.product_edition, low_threshold: low, critical_threshold: critical }, { onSuccess: () => setEditing(false) })
              }} disabled={saveMut.isPending}>
                {saveMut.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="h-3 w-3" />}
              </Button>
              <Button size="sm" variant="outline" className="h-7" onClick={() => setEditing(false)}>
                {t('common.cancel', 'Cancel')}
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex items-center justify-between text-xs text-muted-foreground pt-1">
            <span>{t('pool.thresholds', 'Alerts')}: ⚠️ ≤{pool.low_threshold} &nbsp; 🔴 ≤{pool.critical_threshold}</span>
            <Button variant="ghost" size="sm" className="h-6 text-xs" onClick={() => setEditing(true)}>
              {t('common.edit', 'Edit')}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

export function KeyPoolPage() {
  const { t } = useTranslation()
  const poolQuery = useKeyPoolStatus()
  const batchesQuery = useDpkBatches()
  const importMut = useImportDpkBatch()

  const pools = poolQuery.data?.pools || []
  const batches = batchesQuery.data?.batches || []

  const [showImport, setShowImport] = useState(false)
  const [importFile, setImportFile] = useState<File | null>(null)
  const [batchName, setBatchName] = useState('')
  const [productEdition, setProductEdition] = useState('')

  const handleImport = () => {
    if (!importFile) return
    importMut.mutate({ file: importFile, batchName: batchName || 'Import ' + new Date().toISOString().split('T')[0], productEdition }, {
      onSuccess: () => {
        setShowImport(false)
        setImportFile(null)
        setBatchName('')
        setProductEdition('')
        poolQuery.refetch()
        batchesQuery.refetch()
      },
    })
  }

  return (
    <div className="flex-1 p-6 space-y-6 max-w-5xl">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('pool.title', 'Key Pool')}</h1>
        <Button onClick={() => setShowImport(true)}>
          <Upload className="mr-1 h-4 w-4" />
          {t('pool.import_keys', 'Import Keys')}
        </Button>
      </div>

      {/* Pool Cards */}
      {pools.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center text-muted-foreground">
            {t('empty.key_pool', 'No keys in the system yet. Import keys to get started.')}
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {pools.map(pool => <PoolCard key={pool.product_edition} pool={pool} />)}
        </div>
      )}

      {/* Import History */}
      {batches.length > 0 && (
        <>
          <h2 className="text-lg font-semibold pt-4">{t('pool.import_history', 'Import History')}</h2>
          <Card>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="py-3 px-4">{t('pool.col_batch', 'Batch')}</th>
                    <th className="py-3 px-4">{t('pool.col_edition', 'Edition')}</th>
                    <th className="py-3 px-4">{t('pool.col_imported', 'Imported')}</th>
                    <th className="py-3 px-4">{t('pool.col_dupes', 'Dupes')}</th>
                    <th className="py-3 px-4">{t('pool.col_status', 'Status')}</th>
                    <th className="py-3 px-4">{t('pool.col_date', 'Date')}</th>
                  </tr>
                </thead>
                <tbody>
                  {batches.map((b: DpkBatch) => (
                    <tr key={b.id} className="border-b last:border-0">
                      <td className="py-3 px-4 font-medium">{b.batch_name}</td>
                      <td className="py-3 px-4">{b.product_edition || '—'}</td>
                      <td className="py-3 px-4 text-green-600">{b.imported_keys}</td>
                      <td className="py-3 px-4 text-yellow-600">{b.duplicate_keys}</td>
                      <td className="py-3 px-4">
                        <Badge variant={b.import_status === 'completed' ? 'default' : 'secondary'}>
                          {b.import_status}
                        </Badge>
                      </td>
                      <td className="py-3 px-4 text-muted-foreground text-xs">{b.created_at}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </>
      )}

      {/* Import Modal */}
      {showImport && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-background rounded-lg shadow-lg w-full max-w-md p-6 space-y-4">
            <h3 className="text-lg font-semibold">{t('pool.import_keys', 'Import Keys')}</h3>
            <p className="text-sm text-muted-foreground">
              {t('pool.import_desc', 'Upload a CSV, TXT, or XML file with product keys (one per line or Microsoft DPK format).')}
            </p>
            <div>
              <Label>{t('pool.batch_name', 'Batch Name')}</Label>
              <Input value={batchName} onChange={e => setBatchName(e.target.value)} placeholder="Microsoft Order #12345" />
            </div>
            <div>
              <Label>{t('pool.product_edition', 'Product Edition')}</Label>
              <Input value={productEdition} onChange={e => setProductEdition(e.target.value)} placeholder="Windows 11 Pro" />
            </div>
            <div>
              <Label>{t('pool.key_file', 'Key File')}</Label>
              <input type="file" accept=".csv,.txt,.xml" className="block w-full text-sm" onChange={e => setImportFile(e.target.files?.[0] || null)} />
            </div>
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setShowImport(false)}>{t('common.cancel', 'Cancel')}</Button>
              <Button onClick={handleImport} disabled={!importFile || importMut.isPending}>
                {importMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                <FileUp className="mr-1 h-4 w-4" />
                {t('pool.import', 'Import')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
