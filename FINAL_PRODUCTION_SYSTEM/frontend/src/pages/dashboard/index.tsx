import { useTranslation } from 'react-i18next'
import { AppHeader } from '@/components/layout/app-header'
import { useDashboardStats } from '@/hooks/use-dashboard'
import { StatsCards } from './components/stats-cards'
import { ActivationTrendsChart } from './components/activation-trends-chart'
import { KeyDistributionChart } from './components/key-distribution-chart'
import { TechnicianChart } from './components/technician-chart'
import { RecentActivity } from './components/recent-activity'

export function DashboardPage() {
  const { t } = useTranslation()
  const { data: stats, isLoading } = useDashboardStats()

  return (
    <>
      <AppHeader title={t('nav.dashboard')} />
      <div className="flex-1 space-y-6 p-4 md:p-6">
        <h2 className="text-2xl font-bold tracking-tight">{t('nav.dashboard')}</h2>

        <StatsCards stats={stats} isLoading={isLoading} />

        <ActivationTrendsChart data={stats?.daily_trend} />

        <div className="grid gap-6 md:grid-cols-2">
          <KeyDistributionChart stats={stats} />
          <TechnicianChart stats={stats} />
        </div>

        <RecentActivity activity={stats?.recent_activity} />
      </div>
    </>
  )
}
