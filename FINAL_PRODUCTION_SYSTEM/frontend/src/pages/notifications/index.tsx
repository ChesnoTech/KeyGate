import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Bell, CheckCheck, ExternalLink } from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import {
  useNotifications,
  useMarkRead,
  usePushPreferences,
  useSavePushPreferences,
} from '@/hooks/use-notifications'
import type { PushPreferences } from '@/api/notifications'

const PUSH_CATEGORIES = [
  'security',
  'keys',
  'technicians',
  'system',
  'devices',
  'activation',
] as const

export function NotificationsPage() {
  const { t } = useTranslation()
  const { data: notifData, isLoading: notifLoading } = useNotifications()
  const markReadMutation = useMarkRead()
  const { data: prefData, isLoading: prefLoading } = usePushPreferences()
  const savePrefMutation = useSavePushPreferences()

  const [prefs, setPrefs] = useState<PushPreferences>({
    security: true,
    keys: true,
    technicians: true,
    system: true,
    devices: true,
    activation: true,
  })

  useEffect(() => {
    if (prefData?.preferences) {
      setPrefs(prefData.preferences)
    }
  }, [prefData])

  const handleTogglePref = (category: keyof PushPreferences) => {
    const updated = { ...prefs, [category]: !prefs[category] }
    setPrefs(updated)
    savePrefMutation.mutate(updated)
  }

  const handleMarkAllRead = () => {
    markReadMutation.mutate(undefined)
  }

  return (
    <>
      <AppHeader title={t('nav.notifications', 'Notifications')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <h2 className="text-2xl font-bold tracking-tight">{t('nav.notifications', 'Notifications')}</h2>

        {/* Push Preferences Card */}
        <Card>
          <CardHeader>
            <CardTitle>{t('notifications.prefs_title', 'Push Preferences')}</CardTitle>
            <CardDescription>
              {t('notifications.prefs_desc', 'Choose which notification categories you want to receive.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {prefLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 6 }).map((_, i) => (
                  <Skeleton key={i} className="h-6 w-full" />
                ))}
              </div>
            ) : (
              <div className="space-y-4">
                {PUSH_CATEGORIES.map((cat) => (
                  <div key={cat} className="flex items-center justify-between">
                    <Label htmlFor={`pref-${cat}`} className="capitalize">
                      {t(`notifications.category_${cat}`, cat)}
                    </Label>
                    <Switch
                      id={`pref-${cat}`}
                      checked={prefs[cat]}
                      onCheckedChange={() => handleTogglePref(cat)}
                    />
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Separator />

        {/* Notifications List */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <h3 className="text-lg font-semibold">{t('notifications.list_title', 'Recent Notifications')}</h3>
              {notifData && notifData.unread_count > 0 && (
                <Badge variant="secondary">{notifData.unread_count}</Badge>
              )}
            </div>
            <Button
              variant="outline"
              size="sm"
              onClick={handleMarkAllRead}
              disabled={markReadMutation.isPending || !notifData?.unread_count}
            >
              <CheckCheck className="mr-2 h-4 w-4" />
              {t('notifications.mark_all_read', 'Mark All Read')}
            </Button>
          </div>

          {notifLoading ? (
            <div className="space-y-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <Card key={i}>
                  <CardContent className="py-4">
                    <Skeleton className="h-5 w-48 mb-2" />
                    <Skeleton className="h-4 w-full" />
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : notifData?.notifications.length ? (
            <div className="space-y-3">
              {notifData.notifications.map((notif) => (
                <Card
                  key={notif.id}
                  className={notif.is_read ? 'opacity-60' : ''}
                >
                  <CardContent className="py-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex items-start gap-3 flex-1">
                        <Bell className={`h-5 w-5 mt-0.5 shrink-0 ${notif.is_read ? 'text-muted-foreground' : 'text-primary'}`} />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-1">
                            <p className={`text-sm font-medium ${notif.is_read ? '' : 'font-semibold'}`}>
                              {t(notif.title_key, notif.title_key)}
                            </p>
                            <Badge variant="outline" className="text-[10px] px-1 py-0">
                              {notif.category}
                            </Badge>
                          </div>
                          <p className="text-sm text-muted-foreground">{notif.body}</p>
                          <p className="text-xs text-muted-foreground mt-1">{notif.created_at}</p>
                        </div>
                      </div>
                      {notif.action_url && (
                        <a href={notif.action_url} target="_blank" rel="noopener noreferrer">
                          <Button variant="ghost" size="icon-sm">
                            <ExternalLink className="h-4 w-4" />
                          </Button>
                        </a>
                      )}
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : (
            <Card>
              <CardContent className="py-8 text-center">
                <Bell className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                <p className="text-sm text-muted-foreground">
                  {t('notifications.empty', 'No notifications yet.')}
                </p>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </>
  )
}
