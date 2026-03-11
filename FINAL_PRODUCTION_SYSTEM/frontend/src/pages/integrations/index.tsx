import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Plug2, RefreshCw, Wifi, WifiOff, AlertTriangle, Settings2, Play } from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useIntegrations,
  useSaveIntegration,
  useTestIntegration,
  useRetryEvents,
} from '@/hooks/use-integrations'
import { IntegrationConfigDialog } from './config-dialog'
import type { IntegrationRow } from '@/api/integrations'

const statusConfig = {
  connected: { icon: Wifi, color: 'text-green-500', badge: 'default' as const },
  disconnected: { icon: WifiOff, color: 'text-muted-foreground', badge: 'secondary' as const },
  error: { icon: AlertTriangle, color: 'text-red-500', badge: 'destructive' as const },
}

export function IntegrationsPage() {
  const { t } = useTranslation()
  const { data, isLoading } = useIntegrations()
  const saveMutation = useSaveIntegration()
  const testMutation = useTestIntegration()
  const retryMutation = useRetryEvents()
  const [configOpen, setConfigOpen] = useState<string | null>(null)

  const integrations = data?.integrations ?? []

  const handleToggle = (intg: IntegrationRow, enabled: boolean) => {
    saveMutation.mutate({
      integration_key: intg.integration_key,
      enabled,
      config: intg.config,
    })
  }

  if (isLoading) {
    return (
      <>
        <AppHeader title={t('nav.integrations', 'Integrations')} />
        <div className="flex-1 space-y-4 p-4 md:p-6">
          <Skeleton className="h-8 w-48" />
          <div className="grid gap-4 md:grid-cols-2">
            {Array.from({ length: 2 }).map((_, i) => (
              <Card key={i}>
                <CardHeader><Skeleton className="h-6 w-32" /></CardHeader>
                <CardContent><Skeleton className="h-20 w-full" /></CardContent>
              </Card>
            ))}
          </div>
        </div>
      </>
    )
  }

  return (
    <>
      <AppHeader title={t('nav.integrations', 'Integrations')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">{t('integrations.title', 'Integrations')}</h2>
            <p className="text-sm text-muted-foreground">
              {t('integrations.desc', 'Connect external systems to sync data automatically.')}
            </p>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          {integrations.map((intg) => {
            const statusInfo = statusConfig[intg.status] || statusConfig.disconnected
            const StatusIcon = statusInfo.icon
            const failedCount = Number(intg.event_counts?.failed ?? 0)
            const pendingCount = Number(intg.event_counts?.pending ?? 0)

            return (
              <Card key={intg.integration_key}>
                <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                      <Plug2 className="h-5 w-5" />
                    </div>
                    <div>
                      <CardTitle className="text-base">{intg.display_name}</CardTitle>
                      <CardDescription className="text-xs">{intg.description}</CardDescription>
                    </div>
                  </div>
                  <Switch
                    checked={!!intg.enabled}
                    onCheckedChange={(checked) => handleToggle(intg, checked)}
                    disabled={saveMutation.isPending}
                  />
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="flex items-center gap-2">
                    <StatusIcon className={`h-4 w-4 ${statusInfo.color}`} />
                    <Badge variant={statusInfo.badge} className="text-xs">
                      {t(`integrations.status_${intg.status}`, intg.status)}
                    </Badge>
                    {intg.last_sync_at && (
                      <span className="text-xs text-muted-foreground ml-auto">
                        {t('integrations.last_sync', 'Last sync')}: {new Date(intg.last_sync_at).toLocaleString()}
                      </span>
                    )}
                  </div>

                  {intg.last_error && intg.status === 'error' && (
                    <p className="text-xs text-red-500 bg-red-50 dark:bg-red-950 rounded p-2">
                      {intg.last_error}
                    </p>
                  )}

                  {(failedCount > 0 || pendingCount > 0) && (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                      {failedCount > 0 && (
                        <Badge variant="destructive" className="text-[10px]">
                          {failedCount} {t('integrations.failed', 'failed')}
                        </Badge>
                      )}
                      {pendingCount > 0 && (
                        <Badge variant="outline" className="text-[10px]">
                          {pendingCount} {t('integrations.pending', 'pending')}
                        </Badge>
                      )}
                    </div>
                  )}

                  <div className="flex items-center gap-2 pt-1">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setConfigOpen(intg.integration_key)}
                    >
                      <Settings2 className="mr-1.5 h-3.5 w-3.5" />
                      {t('integrations.configure', 'Configure')}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => testMutation.mutate(intg.integration_key)}
                      disabled={testMutation.isPending}
                    >
                      <Play className="mr-1.5 h-3.5 w-3.5" />
                      {t('integrations.test', 'Test')}
                    </Button>
                    {failedCount > 0 && (
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => retryMutation.mutate(intg.integration_key)}
                        disabled={retryMutation.isPending}
                      >
                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                        {t('integrations.retry', 'Retry')}
                      </Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            )
          })}

          {integrations.length === 0 && (
            <div className="col-span-2 text-center py-12 text-muted-foreground">
              <Plug2 className="mx-auto h-12 w-12 mb-4 opacity-30" />
              <p>{t('integrations.none', 'No integrations available.')}</p>
            </div>
          )}
        </div>
      </div>

      <IntegrationConfigDialog
        integrationKey={configOpen}
        onOpenChange={(open) => { if (!open) setConfigOpen(null) }}
      />
    </>
  )
}
