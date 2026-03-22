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
  FileText,
  Send,
  Globe,
  CreditCard,
  Clock,
  Zap,
  Infinity,
  Check,
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

type Tab = 'plan' | 'payment' | 'key' | 'billing'

export function LicensePage() {
  const { t } = useTranslation()
  const [activeTab, setActiveTab] = useState<Tab>('plan')
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

  const tabs: { key: Tab; label: string; icon: React.ReactNode }[] = [
    { key: 'plan', label: t('sub.tab_plan', 'Plan & Usage'), icon: <Zap className="h-4 w-4" /> },
    { key: 'payment', label: t('sub.tab_payment', 'Payment'), icon: <CreditCard className="h-4 w-4" /> },
    { key: 'key', label: t('sub.tab_key', 'License Key'), icon: <Key className="h-4 w-4" /> },
    { key: 'billing', label: t('sub.tab_billing', 'Billing'), icon: <FileText className="h-4 w-4" /> },
  ]

  const isExpiringSoon = license?.expires_at &&
    new Date(license.expires_at).getTime() - Date.now() < 30 * 24 * 60 * 60 * 1000

  return (
    <div className="flex-1 p-6 space-y-6 max-w-5xl">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold">{t('sub.title', 'Subscription')}</h1>
        <p className="text-sm text-muted-foreground mt-1">
          {t('sub.description', 'Manage your KeyGate subscription, payment method, and license.')}
        </p>
      </div>

      {/* Current Plan Banner */}
      <Card className={license?.tier === 'pro' ? 'border-blue-200 dark:border-blue-800' : license?.tier === 'enterprise' ? 'border-purple-200 dark:border-purple-800' : ''}>
        <CardContent className="flex items-center justify-between py-4">
          <div className="flex items-center gap-3">
            {TIER_ICONS[license?.tier || 'community']}
            <div>
              <div className="flex items-center gap-2">
                <p className="font-semibold">{license?.label || 'Community'}</p>
                <span className={`px-2 py-0.5 rounded-full text-[10px] font-semibold ${TIER_COLORS[license?.tier || 'community']}`}>
                  {(license?.tier || 'community').toUpperCase()}
                </span>
              </div>
              <p className="text-xs text-muted-foreground">
                {license?.is_registered
                  ? t('sub.registered_to', 'Registered to {{email}}', { email: license.licensed_to || 'Unknown' })
                  : t('sub.free_tier', 'Free tier — limited to 1 technician and 50 keys')}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-4 text-sm">
            <div className="text-center">
              <p className="text-xs text-muted-foreground">{t('sub.technicians', 'Technicians')}</p>
              <p className="font-semibold">
                {usage?.technicians ?? 0} / {(license?.max_technicians ?? 1) > 9000 ? '∞' : license?.max_technicians ?? 1}
              </p>
            </div>
            <div className="text-center">
              <p className="text-xs text-muted-foreground">{t('sub.keys', 'Keys')}</p>
              <p className="font-semibold">
                {usage?.keys ?? 0} / {(license?.max_keys ?? 50) > 9000 ? '∞' : license?.max_keys ?? 50}
              </p>
            </div>
            {license?.expires_at && (
              <div className="text-center">
                <p className="text-xs text-muted-foreground">{t('sub.expires', 'Expires')}</p>
                <p className={`font-semibold ${isExpiringSoon ? 'text-orange-600' : ''}`}>
                  {new Date(license.expires_at).toLocaleDateString()}
                </p>
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Expiring Soon Warning */}
      {isExpiringSoon && (
        <div className="flex items-center gap-2 p-3 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 text-sm text-orange-700 dark:text-orange-400">
          <Clock className="h-4 w-4 shrink-0" />
          {t('sub.expiring_soon', 'Your subscription expires soon. Renew to keep Pro features.')}
          <Button variant="outline" size="sm" className="ml-auto" onClick={() => setActiveTab('payment')}>
            {t('sub.renew_now', 'Renew Now')}
          </Button>
        </div>
      )}

      {/* Tab Navigation */}
      <div className="flex gap-1 p-1 rounded-lg bg-muted">
        {tabs.map(tab => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className={`flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium transition-colors flex-1 justify-center ${
              activeTab === tab.key
                ? 'bg-background shadow text-foreground'
                : 'text-muted-foreground hover:text-foreground'
            }`}
          >
            {tab.icon}
            <span className="hidden sm:inline">{tab.label}</span>
          </button>
        ))}
      </div>

      {/* ═══ Tab: Plan & Usage ═══ */}
      {activeTab === 'plan' && (
        <div className="space-y-6">
          {/* Pricing Cards */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* Community */}
            <Card className={`relative ${license?.tier === 'community' ? 'ring-2 ring-primary' : ''}`}>
              {license?.tier === 'community' && (
                <div className="absolute -top-2.5 left-1/2 -translate-x-1/2">
                  <Badge className="bg-primary text-primary-foreground text-[10px]">{t('sub.current', 'Current')}</Badge>
                </div>
              )}
              <CardHeader className="text-center pb-2">
                <Shield className="h-8 w-8 mx-auto text-gray-500" />
                <CardTitle className="text-lg">Community</CardTitle>
                <div className="text-3xl font-bold">{t('sub.free', 'Free')}</div>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <PlanFeature text={t('sub.plan_1_tech', '1 technician')} />
                <PlanFeature text={t('sub.plan_50_keys', '50 OEM keys')} />
                <PlanFeature text={t('sub.plan_activation', 'Windows activation')} />
                <PlanFeature text={t('sub.plan_hardware', 'Hardware collection')} />
                <PlanFeature text={t('sub.plan_dashboard', 'Dashboard')} />
                <PlanFeature text={t('sub.plan_no_support', 'Community support')} muted />
              </CardContent>
            </Card>

            {/* Pro */}
            <Card className={`relative ${license?.tier === 'pro' ? 'ring-2 ring-blue-500' : 'border-blue-200 dark:border-blue-800'}`}>
              {license?.tier === 'pro' && (
                <div className="absolute -top-2.5 left-1/2 -translate-x-1/2">
                  <Badge className="bg-blue-600 text-white text-[10px]">{t('sub.current', 'Current')}</Badge>
                </div>
              )}
              <CardHeader className="text-center pb-2">
                <Crown className="h-8 w-8 mx-auto text-blue-600" />
                <CardTitle className="text-lg">Pro</CardTitle>
                <div className="text-3xl font-bold">$9<span className="text-sm font-normal text-muted-foreground">/{t('sub.month', 'mo')}</span></div>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <PlanFeature text={t('sub.plan_unlimited_techs', 'Unlimited technicians')} highlight />
                <PlanFeature text={t('sub.plan_unlimited_keys', 'Unlimited OEM keys')} highlight />
                <PlanFeature text={t('sub.plan_compliance', 'QC compliance')} />
                <PlanFeature text={t('sub.plan_integrations', 'Integrations (osTicket, 1C)')} />
                <PlanFeature text={t('sub.plan_backups', 'Automated backups')} />
                <PlanFeature text={t('sub.plan_branding', 'White-label branding')} />
                <PlanFeature text={t('sub.plan_upgrade', 'System upgrade wizard')} />
                <PlanFeature text={t('sub.plan_priority', 'Priority support')} />
                {license?.tier === 'community' && (
                  <Button className="w-full mt-3" onClick={() => setActiveTab('payment')}>
                    {t('sub.upgrade_to_pro', 'Upgrade to Pro')}
                  </Button>
                )}
              </CardContent>
            </Card>

            {/* Enterprise */}
            <Card className={`relative ${license?.tier === 'enterprise' ? 'ring-2 ring-purple-500' : ''}`}>
              {license?.tier === 'enterprise' && (
                <div className="absolute -top-2.5 left-1/2 -translate-x-1/2">
                  <Badge className="bg-purple-600 text-white text-[10px]">{t('sub.current', 'Current')}</Badge>
                </div>
              )}
              <CardHeader className="text-center pb-2">
                <Building2 className="h-8 w-8 mx-auto text-purple-600" />
                <CardTitle className="text-lg">Enterprise</CardTitle>
                <div className="text-3xl font-bold">$29<span className="text-sm font-normal text-muted-foreground">/{t('sub.month', 'mo')}</span></div>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                <PlanFeature text={t('sub.plan_everything_pro', 'Everything in Pro')} />
                <PlanFeature text={t('sub.plan_multi_site', 'Multi-site deployment')} highlight />
                <PlanFeature text={t('sub.plan_api', 'API access')} highlight />
                <PlanFeature text={t('sub.plan_sla', 'SLA & dedicated support')} />
                <PlanFeature text={t('sub.plan_custom', 'Custom integrations')} />
                {license?.tier !== 'enterprise' && (
                  <Button variant="outline" className="w-full mt-3" onClick={() => setActiveTab('payment')}>
                    {t('sub.upgrade_to_enterprise', 'Upgrade to Enterprise')}
                  </Button>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Feature Access */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <Sparkles className="h-4 w-4" />
                {t('sub.feature_access', 'Feature Access')}
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
        </div>
      )}

      {/* ═══ Tab: Payment ═══ */}
      {activeTab === 'payment' && (
        <div className="space-y-4">
          {/* International */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <Globe className="h-4 w-4" />
                {t('sub.intl_payment', 'International Payment')}
              </CardTitle>
              <CardDescription>
                {t('sub.intl_payment_desc', 'Pay with credit card, PayPal, or other international methods. Instant license delivery.')}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap gap-3">
                <Button asChild>
                  <a href="https://github.com/sponsors/ChesnoTech" target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="mr-2 h-4 w-4" />
                    {t('sub.pay_github', 'Pay via GitHub Sponsors')}
                  </a>
                </Button>
              </div>
              <p className="text-xs text-muted-foreground mt-3">
                {t('sub.intl_note', 'After payment, you will receive a license key via email. Paste it in the License Key tab.')}
              </p>
            </CardContent>
          </Card>

          {/* Russia / CIS */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <FileText className="h-4 w-4" />
                {t('sub.ru_payment', 'Russia & CIS — Invoice Payment')}
                <Badge variant="outline" className="text-[10px]">🇷🇺</Badge>
              </CardTitle>
              <CardDescription>
                {t('sub.ru_payment_desc', 'Due to international payment restrictions, Russian and CIS companies can purchase via bank transfer (wire transfer). We accept payments in RUB, USD, or EUR to our business account.')}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="p-3 rounded-lg border text-center">
                  <p className="text-xs text-muted-foreground">{t('sub.bank_transfer', 'Bank Transfer')}</p>
                  <p className="text-sm font-medium mt-1">RUB / USD / EUR</p>
                </div>
                <div className="p-3 rounded-lg border text-center">
                  <p className="text-xs text-muted-foreground">{t('sub.crypto', 'Cryptocurrency')}</p>
                  <p className="text-sm font-medium mt-1">USDT / BTC</p>
                </div>
                <div className="p-3 rounded-lg border text-center">
                  <p className="text-xs text-muted-foreground">{t('sub.intermediary', 'Intermediary')}</p>
                  <p className="text-sm font-medium mt-1">🇹🇷 / 🇦🇪</p>
                </div>
              </div>
              <Button
                variant="outline"
                onClick={() => {
                  const subject = encodeURIComponent('KeyGate Pro License — Invoice Request')
                  const body = encodeURIComponent(
                    `Company Name: \nCountry: \nContact Person: \nEmail: \n\nLicense Tier: Pro / Enterprise\nPreferred Payment Method: Bank Transfer / Crypto / Other\nPreferred Currency: RUB / USD / EUR\n\nInstance ID: ${license?.instance_id || 'N/A'}\n`
                  )
                  window.open(`mailto:sales@keygate.dev?subject=${subject}&body=${body}`, '_blank')
                }}
              >
                <Send className="mr-2 h-4 w-4" />
                {t('sub.request_invoice', 'Request Invoice')}
              </Button>
              <p className="text-xs text-muted-foreground">
                {t('sub.ru_note', 'After payment confirmation, we will send your license key within 1 business day.')}
              </p>
            </CardContent>
          </Card>
        </div>
      )}

      {/* ═══ Tab: License Key ═══ */}
      {activeTab === 'key' && (
        <div className="space-y-4">
          {/* Current Key Info */}
          {license?.is_registered && (
            <Card>
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  {TIER_ICONS[license.tier]}
                  {t('sub.active_license', 'Active License')}
                </CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                  <div>
                    <p className="text-xs text-muted-foreground">{t('sub.tier', 'Tier')}</p>
                    <p className="font-semibold">{license.label}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">{t('sub.licensed_to', 'Licensed To')}</p>
                    <p className="font-semibold">{license.licensed_to || '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">{t('sub.instance_id', 'Instance ID')}</p>
                    <p className="font-mono text-xs">{license.instance_id?.slice(0, 16)}...</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">{t('sub.expires', 'Expires')}</p>
                    <p className="font-semibold">{license.expires_at ? new Date(license.expires_at).toLocaleDateString() : '—'}</p>
                  </div>
                </div>

                <Separator className="my-4" />
                <AlertDialog>
                  <AlertDialogTrigger
                    className="inline-flex items-center justify-center rounded-md text-sm font-medium text-destructive hover:text-destructive/80 h-9 px-3 transition-colors"
                  >
                    {t('sub.deactivate', 'Deactivate License')}
                  </AlertDialogTrigger>
                  <AlertDialogContent>
                    <AlertDialogHeader>
                      <AlertDialogTitle>{t('sub.deactivate_title', 'Deactivate License?')}</AlertDialogTitle>
                      <AlertDialogDescription>
                        {t('sub.deactivate_desc', 'This will revert to Community tier. Pro features will be disabled.')}
                      </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                      <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                      <AlertDialogAction onClick={() => deactivateMut.mutate()}>
                        {t('sub.deactivate_confirm', 'Yes, Deactivate')}
                      </AlertDialogAction>
                    </AlertDialogFooter>
                  </AlertDialogContent>
                </AlertDialog>
              </CardContent>
            </Card>
          )}

          {/* Register New Key */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">{t('sub.register_key', 'Register License Key')}</CardTitle>
              <CardDescription>
                {t('sub.register_desc', 'Paste the JWT license key you received after payment.')}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              <textarea
                className="w-full h-24 p-3 text-xs font-mono bg-muted/50 border rounded-md resize-none focus:outline-none focus:ring-2 focus:ring-primary"
                placeholder="eyJhbGciOiJIUzI1NiIs..."
                value={licenseKey}
                onChange={(e) => setLicenseKey(e.target.value)}
              />
              <Button onClick={handleRegister} disabled={!licenseKey.trim() || registerMut.isPending}>
                {registerMut.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {t('sub.activate_key', 'Activate License')}
              </Button>
            </CardContent>
          </Card>

          {/* Dev Tools (localhost only) */}
          {(window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') && (
            <Card className="border-dashed border-yellow-300 dark:border-yellow-700">
              <CardHeader>
                <CardTitle className="text-base text-yellow-700 dark:text-yellow-400">
                  🧪 {t('sub.dev_tools', 'Development Tools')}
                </CardTitle>
                <CardDescription>
                  {t('sub.dev_desc', 'Generate test licenses for development. Only available on localhost.')}
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" onClick={() => handleGenerateDev('pro')} disabled={devGenMut.isPending}>
                    {t('sub.gen_pro', 'Generate Pro')}
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => handleGenerateDev('enterprise')} disabled={devGenMut.isPending}>
                    {t('sub.gen_enterprise', 'Generate Enterprise')}
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
                      onClick={() => navigator.clipboard.writeText(devLicenseKey)}
                    >
                      <Copy className="h-3 w-3" />
                    </Button>
                  </div>
                )}
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {/* ═══ Tab: Billing ═══ */}
      {activeTab === 'billing' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <Clock className="h-4 w-4" />
                {t('sub.billing_history', 'Billing History')}
              </CardTitle>
              <CardDescription>
                {t('sub.billing_desc', 'Your payment and license history.')}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {license?.is_registered ? (
                <div className="space-y-3">
                  {/* Current subscription entry */}
                  <div className="flex items-center justify-between p-3 rounded-lg border">
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-lg ${TIER_COLORS[license.tier]}`}>
                        {TIER_ICONS[license.tier]}
                      </div>
                      <div>
                        <p className="text-sm font-medium">{license.label}</p>
                        <p className="text-xs text-muted-foreground">
                          {t('sub.activated_on', 'Activated')} · {license.licensed_to}
                        </p>
                      </div>
                    </div>
                    <div className="text-right">
                      <Badge variant="outline" className="text-green-600 border-green-200">
                        {t('sub.status_active', 'Active')}
                      </Badge>
                      {license.expires_at && (
                        <p className="text-xs text-muted-foreground mt-1">
                          {t('sub.until', 'until')} {new Date(license.expires_at).toLocaleDateString()}
                        </p>
                      )}
                    </div>
                  </div>

                  <p className="text-xs text-muted-foreground text-center py-4">
                    {t('sub.billing_note', 'For detailed payment history, check your GitHub Sponsors dashboard or contact sales@keygate.dev')}
                  </p>
                </div>
              ) : (
                <div className="text-center py-8">
                  <CreditCard className="h-10 w-10 mx-auto text-muted-foreground mb-3" />
                  <p className="text-sm text-muted-foreground">
                    {t('sub.no_billing', 'No billing history. You are on the free Community tier.')}
                  </p>
                  <Button variant="outline" size="sm" className="mt-3" onClick={() => setActiveTab('payment')}>
                    {t('sub.upgrade_now', 'Upgrade Now')}
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Instance Info */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base flex items-center gap-2">
                <Database className="h-4 w-4" />
                {t('sub.instance_info', 'Instance Information')}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between py-1.5">
                <span className="text-muted-foreground">{t('sub.instance_id', 'Instance ID')}</span>
                <span className="font-mono text-xs">{license?.instance_id || '—'}</span>
              </div>
              <div className="flex justify-between py-1.5">
                <span className="text-muted-foreground">{t('sub.tier', 'Tier')}</span>
                <span>{license?.label || 'Community'}</span>
              </div>
              <div className="flex justify-between py-1.5">
                <span className="text-muted-foreground">{t('sub.max_techs', 'Max Technicians')}</span>
                <span>{(license?.max_technicians ?? 1) > 9000 ? '∞' : license?.max_technicians ?? 1}</span>
              </div>
              <div className="flex justify-between py-1.5">
                <span className="text-muted-foreground">{t('sub.max_keys', 'Max Keys')}</span>
                <span>{(license?.max_keys ?? 50) > 9000 ? '∞' : license?.max_keys ?? 50}</span>
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  )
}

// ── Helper Component ────────────────────────────────────────

function PlanFeature({ text, highlight, muted }: { text: string; highlight?: boolean; muted?: boolean }) {
  return (
    <div className="flex items-center gap-2">
      <Check className={`h-3.5 w-3.5 shrink-0 ${highlight ? 'text-blue-600' : muted ? 'text-muted-foreground' : 'text-green-600'}`} />
      <span className={muted ? 'text-muted-foreground' : ''}>{text}</span>
    </div>
  )
}
