import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import type { RecentActivity as RecentActivityType } from '@/types/api'

interface RecentActivityProps {
  activity?: RecentActivityType[]
}

function getActionVariant(action: string): 'default' | 'secondary' | 'destructive' | 'outline' {
  if (action.includes('SUCCESS') || action === 'PAGE_ACCESS') return 'secondary'
  if (action.includes('FAIL') || action.includes('DELETE') || action.includes('LOCK')) return 'destructive'
  return 'outline'
}

function formatTime(dateStr: string): string {
  const date = new Date(dateStr.replace(' ', 'T') + 'Z')
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  const mins = Math.floor(diff / 60000)
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)

  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return date.toLocaleDateString()
}

export function RecentActivity({ activity }: RecentActivityProps) {
  const { t } = useTranslation()

  if (!activity || activity.length === 0) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('dashboard.recent_activity', 'Recent Activity')}</CardTitle>
        </CardHeader>
        <CardContent className="text-sm text-muted-foreground">
          {t('dashboard.no_activity', 'No recent activity')}
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{t('dashboard.recent_activity', 'Recent Activity')}</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {activity.slice(0, 10).map((entry, i) => (
          <div key={i} className="flex items-center justify-between gap-3 text-sm">
            <div className="flex items-center gap-3 min-w-0">
              <Badge variant={getActionVariant(entry.action)} className="shrink-0 text-xs">
                {entry.action.replace(/_/g, ' ')}
              </Badge>
              <span className="truncate text-muted-foreground">{entry.description}</span>
            </div>
            <div className="flex items-center gap-2 shrink-0 text-xs text-muted-foreground">
              <span className="font-medium">{entry.username}</span>
              <span>{formatTime(entry.created_at)}</span>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
