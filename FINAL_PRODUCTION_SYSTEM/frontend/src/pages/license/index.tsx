import { useState } from 'react'
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
  Shield,
  Key,
  Users,
  Database,
  CheckCircle2,
  XCircle,
  Crown,
  Sparkles,
  Building2,
  Copy,
  Loader2,
  ExternalLink,
} from 'lucide-react'
import {
  useLicenseStatus,
  useRegisterLicense,
  useDeactivateLicense,
  useGenerateDevLicense,
} from '@/hooks/use-license'

const TIER_COLORS: Record<string, string> = {
  community: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
  pro: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  enterprise: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
}

const TIER_ICONS: Record<string, React.ReactNode> = {
  community: <Shield className="h-5 w-5" />,
  pro: <Crown className="h-5 w-5" />,
  enterprise: <Building2 className="h-5 w-5" />,
}

export function LicensePage() {
  const { t } = useTranslation()
  const [licenseKey, setLicenseKey] = useState('')
  const [devLicenseKey, setDevLicenseKey] = useState('')

  const statusQuery = useLicenseStatus()
  const registerMut = useRegisterLicense()
  const deactivateMut = useDeactivateLicense()
  const devGenMut = useGenerateDevLicense()

  const license = statusQuery.data?.license
  const usage = statusQuery.data?.usage

  const handleRegister = async () => {
    if (!licenseKey.trim()) return
    await registerMut.mutateAsync(licenseKey.trim())
    setLicenseKey('')
  }

  const handleGenerateDev = async (tier: string) => {
    const result = await devGenMut.mutateAsync(tier)
    if (result.license_key) {
      setDevLicenseKey(result.license_key)
    }
  }

  if (statusQuery.isLoading) {
    return (
      <div className="flex-1 p-6 flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex-1 p-6 space-y-6 max-w-4xl">
      <div>
        <h1 className="text-2xl font-bold">{t('license.title', 'License')}</h1>
        <p className="text-sm text-muted-foreground mt-1">
          {t('license.description', 'Manage your KeyGate license and feature access.')}
        </p>
      </div>

      {/* Current License Status */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            {TIER_ICONS[license?.tier || 'community']}
            {t('license.current_license', 'Current License')}
            {license && (
              <span className={`px-2.5 py-0.5 rounded-full text-xs font-semibold ${TIER_COLORS[license.tier]}`}>
                {license.label}
              </span>
            )}
          </CardTitle>
          <CardDescription>
            {license?.is_registered
              ? t('license.registered_to', 'Registered to {{email}}', { email: license.licensed_to || 'Unknown' })
              : t('license.not_registered', 'No license registered — using Community tier')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div className="flex items-center gap-2">
              <Users className="h-4 w-4 text-muted-foreground" />
              <div>
                <p className="text-xs text-muted-foreground">{t('license.technicians', 'Technicians')}</p>
                <p className="font-semibold text-sm">
                  {usage?.technicians ?? 0} / {license?.max_technicians === 9999 || license?.max_technicians === 99999 ? '∞' : license?.max_technicians ?? 1}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Key className="h-4 w-4 text-muted-foreground" />
              <div>
                <p className="text-xs text-muted-foreground">{t('license.keys', 'OEM Keys')}</p>
                <p className="font-semibold text-sm">
                  {usage?.keys ?? 0} / {(license?.max_keys ?? 50) > 9000 ? '∞' : license?.max_keys ?? 50}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Database className="h-4 w-4 text-muted-foreground" />
              <div>
                <p className="text-xs text-muted-foreground">{t('license.instance', 'Instance ID')}</p>
                <p className="font-mono text-xs">{license?.instance_id?.slice(0, 12) ?? '—'}...</p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Shield className="h-4 w-4 text-muted-foreground" />
              <div>
                <p className="text-xs text-muted-foreground">{t('license.expires', 'Expires')}</p>
                <p className="font-semibold text-sm">{license?.expires_at ? new Date(license.expires_at).toLocaleDateString() : '—'}</p>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Feature Comparison */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base flex items-center gap-2">
            <Sparkles className="h-4 w-4" />
            {t('license.features', 'Feature Access')}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-2">
            {Object.entries(license?.features || {}).map(([feature, enabled]) => (
              <div key={feature} className="flex items-center justify-between py-1.5 px-3 rounded bg-muted/50">
                <span className="text-sm capitalize">{feature.replace(/_/g, ' ')}</span>
                {enabled ? (
                  <CheckCircle2 className="h-4 w-4 text-green-600" />
                ) : (
                  <div className="flex items-center gap-1.5">
                    <XCircle className="h-4 w-4 text-muted-foreground" />
                    <Badge variant="outline" className="text-[10px]">Pro</Badge>
                  </div>
                )}
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Register License */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('license.register', 'Register License Key')}</CardTitle>
          <CardDescription>
            {t('license.register_desc', 'Paste your license key to unlock Pro or Enterprise features.')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <textarea
            className="w-full h-24 p-3 text-xs font-mono bg-muted/50 border rounded-md resize-none focus:outline-none focus:ring-2 focus:ring-primary"
            placeholder={t('license.key_placeholder', 'eyJhbGciOiJIUzI1NiIs...')}
            value={licenseKey}
            onChange={(e) => setLicenseKey(e.target.value)}
          />
          <div className="flex gap-2">
            <Button onClick={handleRegister} disabled={!licenseKey.trim() || registerMut.isPending}>
              {registerMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t('license.activate', 'Activate License')}
            </Button>
            <Button variant="outline" asChild>
              <a href="https://github.com/sponsors/ChesnoTech" target="_blank" rel="noopener noreferrer">
                <ExternalLink className="mr-2 h-4 w-4" />
                {t('license.get_pro', 'Get Pro License')}
              </a>
            </Button>
          </div>

          {license?.is_registered && license.tier !== 'community' && (
            <>
              <Separator />
              <AlertDialog>
                <AlertDialogTrigger
                  className="inline-flex items-center justify-center rounded-md text-sm font-medium text-destructive hover:text-destructive/80 h-9 px-3 transition-colors"
                >
                  {t('license.deactivate', 'Deactivate License')}
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>{t('license.deactivate_title', 'Deactivate License?')}</AlertDialogTitle>
                    <AlertDialogDescription>
                      {t('license.deactivate_desc', 'This will revert to Community tier. Pro features will be disabled.')}
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                    <AlertDialogAction onClick={() => deactivateMut.mutate()}>
                      {t('license.deactivate_confirm', 'Yes, Deactivate')}
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            </>
          )}
        </CardContent>
      </Card>

      {/* Dev License Generator (localhost only) */}
      {(window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') && (
        <Card className="border-dashed border-yellow-300 dark:border-yellow-700">
          <CardHeader>
            <CardTitle className="text-base text-yellow-700 dark:text-yellow-400">
              🧪 {t('license.dev_tools', 'Development Tools')}
            </CardTitle>
            <CardDescription>
              {t('license.dev_desc', 'Generate test licenses for development. Only available on localhost.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => handleGenerateDev('pro')} disabled={devGenMut.isPending}>
                {t('license.gen_pro', 'Generate Pro')}
              </Button>
              <Button variant="outline" size="sm" onClick={() => handleGenerateDev('enterprise')} disabled={devGenMut.isPending}>
                {t('license.gen_enterprise', 'Generate Enterprise')}
              </Button>
            </div>
            {devLicenseKey && (
              <div className="relative">
                <textarea
                  readOnly
                  className="w-full h-16 p-3 text-xs font-mono bg-yellow-50 dark:bg-yellow-900/20 border rounded-md resize-none"
                  value={devLicenseKey}
                />
                <Button
                  variant="ghost"
                  size="icon"
                  className="absolute top-1 right-1 h-6 w-6"
                  onClick={() => {
                    navigator.clipboard.writeText(devLicenseKey)
                  }}
                >
                  <Copy className="h-3 w-3" />
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
