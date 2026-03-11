import { useTranslation } from 'react-i18next'
import {
  ShieldCheck,
  ShieldOff,
  KeyRound,
  CalendarDays,
  ExternalLink,
  Info,
  Smartphone,
  ShieldAlert,
} from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { use2faStatus } from '@/hooks/use-security'

export function TwoFaPage() {
  const { t } = useTranslation()
  const { data, isLoading, isError } = use2faStatus()

  const handleManageInPanel = () => {
    window.open('/activate/secure-admin.php', '_blank')
  }

  return (
    <>
      <AppHeader title={t('nav.two_fa', '2FA Settings')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <h2 className="text-2xl font-bold tracking-tight">
            {t('nav.two_fa', '2FA Settings')}
          </h2>
          <Button variant="outline" size="sm" onClick={handleManageInPanel}>
            <ExternalLink className="mr-2 h-4 w-4" />
            {t('two_fa.manage_in_panel', 'Manage in Admin Panel')}
          </Button>
        </div>

        {/* Status Card */}
        <Card>
          <CardHeader>
            <CardTitle>{t('two_fa.status_title', 'Two-Factor Authentication')}</CardTitle>
            <CardDescription>
              {t(
                'two_fa.status_desc_v2',
                'Protect your account with an additional layer of security using a TOTP authenticator app.'
              )}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-4">
                <Skeleton className="h-6 w-48" />
                <Skeleton className="h-6 w-64" />
                <Skeleton className="h-6 w-40" />
              </div>
            ) : isError ? (
              <div className="flex items-center gap-3 rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-900 dark:bg-orange-950/30">
                <ShieldAlert className="h-5 w-5 shrink-0 text-orange-600" />
                <div>
                  <p className="text-sm font-medium">
                    {t('two_fa.not_available', '2FA Not Available')}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'two_fa.not_available_desc',
                      'The 2FA module is not configured on this server. Contact your administrator.'
                    )}
                  </p>
                </div>
              </div>
            ) : data ? (
              <div className="space-y-6">
                {/* Status indicator */}
                <div className="flex items-center gap-3">
                  {data.enabled ? (
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                      <ShieldCheck className="h-6 w-6 text-green-600" />
                    </div>
                  ) : (
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                      <ShieldOff className="h-6 w-6 text-muted-foreground" />
                    </div>
                  )}
                  <div>
                    <p className="text-sm font-medium">{t('two_fa.label_status', 'Status')}</p>
                    <Badge
                      variant={data.enabled ? 'default' : 'secondary'}
                      className={data.enabled ? 'bg-green-600 hover:bg-green-700' : ''}
                    >
                      {data.enabled
                        ? t('two_fa.enabled', 'Enabled')
                        : t('two_fa.disabled', 'Disabled')}
                    </Badge>
                  </div>
                </div>

                {/* Verified at */}
                {data.verified_at && (
                  <div className="flex items-center gap-3">
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                      <CalendarDays className="h-6 w-6 text-muted-foreground" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">
                        {t('two_fa.label_verified', 'Verified At')}
                      </p>
                      <p className="text-sm text-muted-foreground">{data.verified_at}</p>
                    </div>
                  </div>
                )}

                {/* Backup codes */}
                <div className="flex items-center gap-3">
                  <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <KeyRound className="h-6 w-6 text-muted-foreground" />
                  </div>
                  <div>
                    <p className="text-sm font-medium">
                      {t('two_fa.label_backup_codes', 'Backup Codes Remaining')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {data.backup_codes_remaining ?? 0}
                    </p>
                  </div>
                </div>

                {/* Action button */}
                <div className="pt-2">
                  <Button onClick={handleManageInPanel}>
                    <ExternalLink className="mr-2 h-4 w-4" />
                    {data.enabled
                      ? t('two_fa.btn_manage', 'Manage 2FA Settings')
                      : t('two_fa.btn_enable', 'Enable 2FA')}
                  </Button>
                </div>
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">
                {t('two_fa.no_data', 'Unable to load 2FA status.')}
              </p>
            )}
          </CardContent>
        </Card>

        {/* Info Card */}
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t('two_fa.how_it_works_title', 'How 2FA Works')}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 sm:grid-cols-3">
              <div className="flex gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                  <Smartphone className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <p className="text-sm font-medium">
                    {t('two_fa.step1_title', 'Install App')}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'two_fa.step1_desc',
                      'Use Google Authenticator, Authy, or any TOTP-compatible app'
                    )}
                  </p>
                </div>
              </div>

              <div className="flex gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                  <KeyRound className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <p className="text-sm font-medium">
                    {t('two_fa.step2_title', 'Scan QR Code')}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t('two_fa.step2_desc', 'Link your account by scanning the setup QR code')}
                  </p>
                </div>
              </div>

              <div className="flex gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                  <Info className="h-5 w-5 text-primary" />
                </div>
                <div>
                  <p className="text-sm font-medium">
                    {t('two_fa.step3_title', 'Enter Code')}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'two_fa.step3_desc',
                      'Enter the 6-digit code from your app each time you log in'
                    )}
                  </p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>
    </>
  )
}
