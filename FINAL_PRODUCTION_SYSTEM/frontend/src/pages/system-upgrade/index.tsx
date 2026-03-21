import { useState, useRef, useCallback, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import {
  Upload,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  Database,
  Play,
  RotateCcw,
  Loader2,
  Shield,
  HardDrive,
  FileCheck,
  Server,
  Package,
  ArrowRight,
  Info,
} from 'lucide-react'
import {
  useUpgradeStatus,
  useUploadPackage,
  useRunPreflight,
  useRunBackup,
  useApplyUpgrade,
  useVerifyUpgrade,
  useRollbackUpgrade,
  useUpgradeHistory,
} from '@/hooks/use-upgrade'
import type { PreflightCheck, UpgradeManifest, UpgradeHistoryRow } from '@/api/upgrade'

// ── Step indicator ──────────────────────────────────────────

const STEPS = ['upload', 'preflight', 'backup', 'apply', 'verify'] as const
type Step = (typeof STEPS)[number]

function StepIndicator({ current, completed }: { current: Step; completed: Set<Step> }) {
  const { t } = useTranslation()
  const labels: Record<Step, string> = {
    upload: t('upgrade.step_upload', 'Upload'),
    preflight: t('upgrade.step_preflight', 'Pre-flight'),
    backup: t('upgrade.step_backup', 'Backup'),
    apply: t('upgrade.step_apply', 'Apply'),
    verify: t('upgrade.step_verify', 'Verify'),
  }
  const icons: Record<Step, React.ReactNode> = {
    upload: <Upload className="h-4 w-4" />,
    preflight: <Shield className="h-4 w-4" />,
    backup: <Database className="h-4 w-4" />,
    apply: <Play className="h-4 w-4" />,
    verify: <FileCheck className="h-4 w-4" />,
  }

  return (
    <div className="flex items-center justify-between gap-2 mb-6">
      {STEPS.map((step, i) => {
        const isActive = step === current
        const isDone = completed.has(step)
        return (
          <div key={step} className="flex items-center gap-2 flex-1">
            <div
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${
                isDone
                  ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                  : isActive
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground'
              }`}
            >
              {isDone ? <CheckCircle2 className="h-3.5 w-3.5" /> : icons[step]}
              <span className="hidden sm:inline">{labels[step]}</span>
              <span className="sm:hidden">{i + 1}</span>
            </div>
            {i < STEPS.length - 1 && (
              <ArrowRight className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
            )}
          </div>
        )
      })}
    </div>
  )
}

// ── Check result row ────────────────────────────────────────

function CheckRow({ check }: { check: PreflightCheck }) {
  const icon =
    check.status === 'pass' ? (
      <CheckCircle2 className="h-4 w-4 text-green-600" />
    ) : check.status === 'fail' ? (
      <XCircle className="h-4 w-4 text-red-600" />
    ) : (
      <AlertTriangle className="h-4 w-4 text-yellow-600" />
    )

  return (
    <div className="flex items-center justify-between py-2 px-3 rounded-md bg-muted/50">
      <div className="flex items-center gap-2">
        {icon}
        <span className="text-sm font-medium">{check.name}</span>
        {check.required && (
          <Badge variant="outline" className="text-[10px] px-1 py-0">
            required
          </Badge>
        )}
      </div>
      <span className="text-xs text-muted-foreground max-w-[50%] text-right">{check.message}</span>
    </div>
  )
}

// ── Status badge for history ────────────────────────────────

function StatusBadge({ status }: { status: string }) {
  const colorMap: Record<string, string> = {
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    rolled_back: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    pending: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    preflight: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    backing_up: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    upgrading: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    verifying: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
  }
  return (
    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${colorMap[status] || 'bg-muted'}`}>
      {status.replace('_', ' ')}
    </span>
  )
}

// ── Main Page ───────────────────────────────────────────────

export function SystemUpgradePage() {
  const { t } = useTranslation()
  const fileInputRef = useRef<HTMLInputElement>(null)

  // State
  const [currentStep, setCurrentStep] = useState<Step>('upload')
  const [completedSteps, setCompletedSteps] = useState<Set<Step>>(new Set())
  const [upgradeId, setUpgradeId] = useState<number | null>(null)
  const [manifest, setManifest] = useState<UpgradeManifest | null>(null)
  const [preflightChecks, setPreflightChecks] = useState<PreflightCheck[]>([])
  const [preflightPassed, setPreflightPassed] = useState(false)
  const [backupInfo, setBackupInfo] = useState<{ db: string; files: string } | null>(null)
  const [applyResults, setApplyResults] = useState<{
    migrations: { file: string; status: string }[]
    files: { action: string; target: string; status: string }[]
  } | null>(null)
  const [verifyChecks, setVerifyChecks] = useState<PreflightCheck[]>([])
  const [upgradeComplete, setUpgradeComplete] = useState(false)

  // Queries & Mutations
  const statusQuery = useUpgradeStatus()
  const historyQuery = useUpgradeHistory()
  const uploadMut = useUploadPackage()
  const preflightMut = useRunPreflight()
  const backupMut = useRunBackup()
  const applyMut = useApplyUpgrade()
  const verifyMut = useVerifyUpgrade()
  const rollbackMut = useRollbackUpgrade()

  const statusData = statusQuery.data?.data

  // Resume an active upgrade on load
  const activeUpgrade = statusData?.active_upgrade
  useMemo(() => {
    if (activeUpgrade && !upgradeId) {
      setUpgradeId(activeUpgrade.id)
      const s = activeUpgrade.status
      if (s === 'pending') setCurrentStep('upload')
      else if (s === 'preflight') setCurrentStep('preflight')
      else if (s === 'backing_up') setCurrentStep('backup')
      else if (s === 'upgrading') setCurrentStep('apply')
      else if (s === 'verifying') setCurrentStep('verify')

      // Mark completed steps
      const done = new Set<Step>()
      if (s !== 'pending') done.add('upload')
      if (['backing_up', 'upgrading', 'verifying'].includes(s)) done.add('preflight')
      if (['upgrading', 'verifying'].includes(s)) done.add('backup')
      if (s === 'verifying') done.add('apply')
      setCompletedSteps(done)

      // Parse manifest if available
      if (activeUpgrade.manifest_json) {
        try {
          setManifest(
            typeof activeUpgrade.manifest_json === 'string'
              ? JSON.parse(activeUpgrade.manifest_json)
              : activeUpgrade.manifest_json
          )
        } catch { /* ignore */ }
      }
    }
  }, [activeUpgrade?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const markComplete = useCallback((step: Step) => {
    setCompletedSteps((prev) => new Set([...prev, step]))
  }, [])

  // ── Handlers ──

  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    const result = await uploadMut.mutateAsync(file)
    setUpgradeId(result.upgrade_id)
    setManifest(result.manifest)
    markComplete('upload')
    setCurrentStep('preflight')
  }

  const handlePreflight = async () => {
    if (!upgradeId) return
    const result = await preflightMut.mutateAsync(upgradeId)
    setPreflightChecks(result.checks)
    setPreflightPassed(result.all_passed)
    if (result.all_passed) {
      markComplete('preflight')
      setCurrentStep('backup')
    }
  }

  const handleBackup = async () => {
    if (!upgradeId) return
    const result = await backupMut.mutateAsync(upgradeId)
    setBackupInfo({
      db: `${result.db_backup.filename} (${result.db_backup.size_mb} MB)`,
      files: `${result.file_backup.filename} (${result.file_backup.size_mb} MB)`,
    })
    markComplete('backup')
    setCurrentStep('apply')
  }

  const handleApply = async () => {
    if (!upgradeId) return
    const result = await applyMut.mutateAsync(upgradeId)
    setApplyResults({
      migrations: result.migrations_applied,
      files: result.files_changed,
    })
    markComplete('apply')
    setCurrentStep('verify')
  }

  const handleVerify = async () => {
    if (!upgradeId) return
    const result = await verifyMut.mutateAsync(upgradeId)
    setVerifyChecks(result.checks)
    if (result.all_passed) {
      markComplete('verify')
      setUpgradeComplete(true)
    }
  }

  const handleRollback = async () => {
    if (!upgradeId) return
    await rollbackMut.mutateAsync(upgradeId)
    // Reset wizard state
    setUpgradeId(null)
    setManifest(null)
    setCurrentStep('upload')
    setCompletedSteps(new Set())
    setPreflightChecks([])
    setPreflightPassed(false)
    setBackupInfo(null)
    setApplyResults(null)
    setVerifyChecks([])
    setUpgradeComplete(false)
    statusQuery.refetch()
    historyQuery.refetch()
  }

  const anyLoading =
    uploadMut.isPending ||
    preflightMut.isPending ||
    backupMut.isPending ||
    applyMut.isPending ||
    verifyMut.isPending ||
    rollbackMut.isPending

  return (
    <div className="flex-1 p-6 space-y-6 max-w-4xl">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold">{t('upgrade.title', 'System Upgrade')}</h1>
        {statusData && (
          <p className="text-sm text-muted-foreground mt-1">
            {t('upgrade.current_version', 'Current Version')}: <strong>v{statusData.current_version}</strong>
            &nbsp;·&nbsp;PHP {statusData.php_version}
            &nbsp;·&nbsp;MariaDB {statusData.mariadb_version}
            &nbsp;·&nbsp;{statusData.disk_free_mb} MB free
          </p>
        )}
      </div>

      {/* Step Indicator */}
      <StepIndicator current={currentStep} completed={completedSteps} />

      {/* Success Banner */}
      {upgradeComplete && (
        <Card className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20">
          <CardContent className="flex items-center gap-3 py-4">
            <CheckCircle2 className="h-8 w-8 text-green-600" />
            <div>
              <p className="font-semibold text-green-700 dark:text-green-400">
                {t('upgrade.upgrade_complete', 'System upgraded to version {{version}}', {
                  version: manifest?.version,
                })}
              </p>
              <p className="text-sm text-green-600 dark:text-green-500">
                {t('upgrade.all_verified', 'All verification checks passed.')}
              </p>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Step 1: Upload */}
      {currentStep === 'upload' && !upgradeComplete && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Package className="h-5 w-5" />
              {t('upgrade.step_upload', 'Upload Package')}
            </CardTitle>
            <CardDescription>
              {t('upgrade.upload_desc', 'Upload a .zip upgrade package containing manifest.json, migrations, and file updates.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <input
              ref={fileInputRef}
              type="file"
              accept=".zip"
              className="hidden"
              onChange={handleFileSelect}
            />
            <div
              className="border-2 border-dashed rounded-lg p-12 text-center cursor-pointer hover:border-primary/50 hover:bg-muted/50 transition-colors"
              onClick={() => fileInputRef.current?.click()}
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => {
                e.preventDefault()
                const file = e.dataTransfer.files[0]
                if (file?.name.endsWith('.zip')) {
                  uploadMut.mutate(file, {
                    onSuccess: (result) => {
                      setUpgradeId(result.upgrade_id)
                      setManifest(result.manifest)
                      markComplete('upload')
                      setCurrentStep('preflight')
                    },
                  })
                }
              }}
            >
              {uploadMut.isPending ? (
                <Loader2 className="h-10 w-10 mx-auto text-primary animate-spin" />
              ) : (
                <Upload className="h-10 w-10 mx-auto text-muted-foreground" />
              )}
              <p className="mt-3 text-sm font-medium">
                {t('upgrade.upload_hint', 'Drop a .zip upgrade package here, or click to browse')}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">
                {t('upgrade.upload_format', 'ZIP must contain manifest.json at root level')}
              </p>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Manifest Info (shown after upload) */}
      {manifest && currentStep !== 'upload' && !upgradeComplete && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm flex items-center gap-2">
              <Info className="h-4 w-4" />
              {t('upgrade.manifest_info', 'Package Information')}
            </CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div>
              <span className="text-muted-foreground">{t('upgrade.version_target', 'Target')}</span>
              <p className="font-semibold">v{manifest.version}</p>
            </div>
            <div>
              <span className="text-muted-foreground">{t('upgrade.release_date', 'Release')}</span>
              <p className="font-semibold">{manifest.release_date}</p>
            </div>
            <div>
              <span className="text-muted-foreground">{t('upgrade.migrations_count', 'Migrations')}</span>
              <p className="font-semibold">{manifest.migrations?.length ?? 0}</p>
            </div>
            <div>
              <span className="text-muted-foreground">{t('upgrade.files_count', 'File Changes')}</span>
              <p className="font-semibold">{manifest.files?.length ?? 0}</p>
            </div>
            {manifest.description && (
              <div className="col-span-2 sm:col-span-4">
                <span className="text-muted-foreground">{t('common.description', 'Description')}</span>
                <p className="text-sm">{manifest.description}</p>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Step 2: Pre-flight */}
      {currentStep === 'preflight' && !upgradeComplete && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Shield className="h-5 w-5" />
              {t('upgrade.step_preflight', 'Pre-flight Checks')}
            </CardTitle>
            <CardDescription>
              {t('upgrade.preflight_desc', 'Verify system compatibility before proceeding.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {preflightChecks.length > 0 && (
              <div className="space-y-1.5">
                {preflightChecks.map((check, i) => (
                  <CheckRow key={i} check={check} />
                ))}
              </div>
            )}

            <div className="flex gap-2 pt-2">
              <Button onClick={handlePreflight} disabled={anyLoading}>
                {preflightMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {preflightChecks.length > 0
                  ? t('upgrade.rerun_checks', 'Re-run Checks')
                  : t('upgrade.run_preflight', 'Run Pre-flight Checks')}
              </Button>
              {preflightPassed && (
                <Button variant="default" onClick={() => setCurrentStep('backup')}>
                  {t('upgrade.proceed_backup', 'Proceed to Backup')} <ArrowRight className="ml-1 h-4 w-4" />
                </Button>
              )}
            </div>

            {preflightChecks.length > 0 && !preflightPassed && (
              <p className="text-sm text-red-600 flex items-center gap-1">
                <XCircle className="h-4 w-4" />
                {t('upgrade.fix_and_retry', 'Fix issues above and re-run checks')}
              </p>
            )}
          </CardContent>
        </Card>
      )}

      {/* Step 3: Backup */}
      {currentStep === 'backup' && !upgradeComplete && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Database className="h-5 w-5" />
              {t('upgrade.step_backup', 'Create Backup')}
            </CardTitle>
            <CardDescription>
              {t('upgrade.backup_desc', 'Full database and file backup before any changes are made.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {backupInfo && (
              <div className="space-y-2 bg-green-50 dark:bg-green-900/20 rounded-lg p-3">
                <div className="flex items-center gap-2 text-sm">
                  <HardDrive className="h-4 w-4 text-green-600" />
                  <span className="font-medium">DB:</span>
                  <span className="text-muted-foreground">{backupInfo.db}</span>
                </div>
                <div className="flex items-center gap-2 text-sm">
                  <Server className="h-4 w-4 text-green-600" />
                  <span className="font-medium">Files:</span>
                  <span className="text-muted-foreground">{backupInfo.files}</span>
                </div>
              </div>
            )}

            <div className="flex gap-2">
              {!backupInfo && (
                <Button onClick={handleBackup} disabled={anyLoading}>
                  {backupMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  {backupMut.isPending
                    ? t('upgrade.backup_in_progress', 'Creating backup...')
                    : t('upgrade.create_backup', 'Create Backup')}
                </Button>
              )}
              {backupInfo && (
                <Button variant="default" onClick={() => setCurrentStep('apply')}>
                  {t('upgrade.proceed_apply', 'Proceed to Apply')} <ArrowRight className="ml-1 h-4 w-4" />
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Step 4: Apply */}
      {currentStep === 'apply' && !upgradeComplete && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Play className="h-5 w-5" />
              {t('upgrade.step_apply', 'Apply Upgrade')}
            </CardTitle>
            <CardDescription>
              {t('upgrade.apply_desc', 'Run database migrations and update application files.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {!applyResults && (
              <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3 text-sm">
                <p className="flex items-center gap-2 font-medium text-yellow-700 dark:text-yellow-400">
                  <AlertTriangle className="h-4 w-4" />
                  {t('upgrade.apply_warning', 'This will modify database schema and application files.')}
                </p>
                <p className="mt-1 text-yellow-600 dark:text-yellow-500">
                  {t('upgrade.apply_ensure', 'Ensure no technicians are actively using the system.')}
                </p>
              </div>
            )}

            {applyResults && (
              <div className="space-y-2">
                <p className="text-sm font-medium">
                  {t('upgrade.migrations_applied', 'Migrations')}: {applyResults.migrations.length}
                </p>
                {applyResults.migrations.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 text-xs pl-3">
                    {m.status === 'applied' ? (
                      <CheckCircle2 className="h-3 w-3 text-green-600" />
                    ) : (
                      <AlertTriangle className="h-3 w-3 text-yellow-600" />
                    )}
                    <span>{m.file}</span>
                    <Badge variant="outline" className="text-[10px]">{m.status}</Badge>
                  </div>
                ))}
                <p className="text-sm font-medium mt-3">
                  {t('upgrade.files_updated', 'Files Updated')}: {applyResults.files.length}
                </p>
                {applyResults.files.slice(0, 10).map((f, i) => (
                  <div key={i} className="flex items-center gap-2 text-xs pl-3">
                    <CheckCircle2 className="h-3 w-3 text-green-600" />
                    <span>{f.target}</span>
                    <Badge variant="outline" className="text-[10px]">{f.action}</Badge>
                  </div>
                ))}
                {applyResults.files.length > 10 && (
                  <p className="text-xs text-muted-foreground pl-3">
                    +{applyResults.files.length - 10} more files
                  </p>
                )}
              </div>
            )}

            <div className="flex gap-2">
              {!applyResults && (
                <AlertDialog>
                  <AlertDialogTrigger
                    className="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-10 px-4 py-2"
                  >
                    {applyMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    {t('upgrade.apply_upgrade', 'Apply Upgrade')}
                  </AlertDialogTrigger>
                  <AlertDialogContent>
                    <AlertDialogHeader>
                      <AlertDialogTitle>{t('upgrade.apply_confirm_title', 'Apply System Upgrade?')}</AlertDialogTitle>
                      <AlertDialogDescription>
                        {t('upgrade.apply_confirm', 'This will modify database schema and application files. A full backup has been created. Continue?')}
                      </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                      <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                      <AlertDialogAction onClick={handleApply}>
                        {t('upgrade.apply_upgrade', 'Apply Upgrade')}
                      </AlertDialogAction>
                    </AlertDialogFooter>
                  </AlertDialogContent>
                </AlertDialog>
              )}
              {applyResults && (
                <Button variant="default" onClick={() => setCurrentStep('verify')}>
                  {t('upgrade.proceed_verify', 'Proceed to Verify')} <ArrowRight className="ml-1 h-4 w-4" />
                </Button>
              )}
              {/* Rollback available if apply failed */}
              {applyMut.isError && (
                <RollbackButton onRollback={handleRollback} isPending={rollbackMut.isPending} disabled={anyLoading} />
              )}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Step 5: Verify */}
      {currentStep === 'verify' && !upgradeComplete && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileCheck className="h-5 w-5" />
              {t('upgrade.step_verify', 'Post-Upgrade Verification')}
            </CardTitle>
            <CardDescription>
              {t('upgrade.verify_desc', 'Verify that the upgrade was applied correctly.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {verifyChecks.length > 0 && (
              <div className="space-y-1.5">
                {verifyChecks.map((check, i) => (
                  <CheckRow key={i} check={check} />
                ))}
              </div>
            )}

            <div className="flex gap-2">
              <Button onClick={handleVerify} disabled={anyLoading}>
                {verifyMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {verifyChecks.length > 0
                  ? t('upgrade.rerun_verify', 'Re-run Verification')
                  : t('upgrade.run_verify', 'Run Verification')}
              </Button>
              <RollbackButton onRollback={handleRollback} isPending={rollbackMut.isPending} disabled={anyLoading} />
            </div>
          </CardContent>
        </Card>
      )}

      {/* Upgrade History */}
      <Separator />
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('upgrade.history', 'Upgrade History')}</CardTitle>
        </CardHeader>
        <CardContent>
          {historyQuery.data?.upgrades && historyQuery.data.upgrades.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="py-2 pr-3">#</th>
                    <th className="py-2 pr-3">{t('upgrade.col_from', 'From')}</th>
                    <th className="py-2 pr-3">{t('upgrade.col_to', 'To')}</th>
                    <th className="py-2 pr-3">{t('upgrade.col_status', 'Status')}</th>
                    <th className="py-2 pr-3">{t('upgrade.col_date', 'Date')}</th>
                    <th className="py-2">{t('upgrade.col_admin', 'Admin')}</th>
                  </tr>
                </thead>
                <tbody>
                  {historyQuery.data.upgrades.map((row: UpgradeHistoryRow) => (
                    <tr key={row.id} className="border-b last:border-0">
                      <td className="py-2 pr-3">{row.id}</td>
                      <td className="py-2 pr-3">v{row.from_version}</td>
                      <td className="py-2 pr-3">v{row.to_version}</td>
                      <td className="py-2 pr-3">
                        <StatusBadge status={row.status} />
                      </td>
                      <td className="py-2 pr-3 text-muted-foreground">
                        {row.completed_at || row.rolled_back_at || row.created_at}
                      </td>
                      <td className="py-2 text-muted-foreground">{row.admin_username}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground text-center py-6">
              {t('empty.upgrade_history', 'No upgrade history')}
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

// ── Rollback Button with confirmation ───────────────────────

function RollbackButton({
  onRollback,
  isPending,
}: {
  onRollback: () => void
  isPending: boolean
  disabled?: boolean
}) {
  const { t } = useTranslation()
  return (
    <AlertDialog>
      <AlertDialogTrigger
        className="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-destructive text-destructive-foreground hover:bg-destructive/90 h-9 px-3 gap-1"
      >
        {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
        <RotateCcw className="mr-1 h-4 w-4" />
        {t('upgrade.rollback', 'Rollback')}
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t('upgrade.rollback_title', 'Rollback System?')}</AlertDialogTitle>
          <AlertDialogDescription>
            {t('upgrade.rollback_confirm', 'This will restore the database and files from the pre-upgrade backup. All changes from this upgrade will be reverted.')}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
          <AlertDialogAction onClick={onRollback} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
            {t('upgrade.rollback_confirm_btn', 'Yes, Rollback')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
