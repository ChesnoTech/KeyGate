import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Key, Users, Activity, CheckCircle, XCircle, Clock, CalendarDays } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { DashboardStats } from '@/types/api'

interface StatsCardsProps {
  stats?: DashboardStats
  isLoading: boolean
}

export function StatsCards({ stats, isLoading }: StatsCardsProps) {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const topCards = [
    {
      title: t('dashboard.total_keys', 'Total Keys'),
      value: stats?.keys?.total ?? 0,
      subtitle: `${stats?.keys?.unused ?? 0} ${t('dashboard.unused', 'unused')}`,
      icon: Key,
      color: 'text-blue-500',
      href: '/keys',
    },
    {
      title: t('dashboard.good_keys', 'Good Keys'),
      value: stats?.keys?.good ?? 0,
      subtitle: `${stats?.keys?.allocated ?? 0} ${t('dashboard.allocated', 'allocated')}`,
      icon: CheckCircle,
      color: 'text-green-500',
      href: '/keys',
    },
    {
      title: t('dashboard.bad_keys', 'Bad Keys'),
      value: stats?.keys?.bad ?? 0,
      subtitle: `${stats?.keys?.retry ?? 0} ${t('dashboard.retry', 'retry')}`,
      icon: XCircle,
      color: 'text-red-500',
      href: '/keys',
    },
    {
      title: t('dashboard.technicians', 'Technicians'),
      value: stats?.technicians?.total ?? 0,
      subtitle: `${stats?.technicians?.active ?? 0} ${t('dashboard.active', 'active')}`,
      icon: Users,
      color: 'text-violet-500',
      href: '/technicians',
    },
  ]

  const bottomCards = [
    {
      title: t('dashboard.today_activations', 'Today'),
      value: stats?.activations?.today ?? 0,
      subtitle: t('dashboard.activations', 'activations'),
      icon: Activity,
      color: 'text-orange-500',
      href: '/history',
    },
    {
      title: t('dashboard.week_activations', 'This Week'),
      value: stats?.activations?.week ?? 0,
      subtitle: t('dashboard.mon_to_sun', 'Mon – Sun'),
      icon: CalendarDays,
      color: 'text-indigo-500',
      href: '/history',
    },
    {
      title: t('dashboard.month_activations', 'This Month'),
      value: stats?.activations?.month ?? 0,
      subtitle: t('dashboard.activations', 'activations'),
      icon: Clock,
      color: 'text-cyan-500',
      href: '/history',
    },
  ]

  const renderCard = (card: (typeof topCards)[number]) => (
    <Card
      key={card.title}
      className="cursor-pointer transition-colors hover:bg-accent/50"
      onClick={() => navigate(card.href)}
    >
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">
          {card.title}
        </CardTitle>
        <card.icon className={`h-4 w-4 ${card.color}`} />
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{card.value}</div>
        <p className="text-xs text-muted-foreground">{card.subtitle}</p>
      </CardContent>
    </Card>
  )

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Card key={i}>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-4 w-4" />
              </CardHeader>
              <CardContent>
                <Skeleton className="h-8 w-16 mb-1" />
                <Skeleton className="h-3 w-20" />
              </CardContent>
            </Card>
          ))}
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Card key={i}>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-4 w-4" />
              </CardHeader>
              <CardContent>
                <Skeleton className="h-8 w-16 mb-1" />
                <Skeleton className="h-3 w-20" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {topCards.map(renderCard)}
      </div>
      <div className="grid gap-4 md:grid-cols-3">
        {bottomCards.map(renderCard)}
      </div>
    </div>
  )
}
