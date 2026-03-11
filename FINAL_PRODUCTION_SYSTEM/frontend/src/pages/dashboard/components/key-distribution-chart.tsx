import { useTranslation } from 'react-i18next'
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip, Legend } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { DashboardStats } from '@/types/api'

interface KeyDistributionChartProps {
  stats?: DashboardStats
}

const COLORS = [
  'oklch(0.65 0.19 145)',   // good - green
  'oklch(0.55 0.15 250)',   // allocated - blue
  'oklch(0.70 0.15 85)',    // unused - yellow
  'oklch(0.60 0.20 25)',    // bad - red
  'oklch(0.65 0.15 50)',    // retry - orange
]

export function KeyDistributionChart({ stats }: KeyDistributionChartProps) {
  const { t } = useTranslation()

  if (!stats) return null

  const data = [
    { name: t('keys.status_good', 'Good'), value: stats.keys.good },
    { name: t('keys.status_allocated', 'Allocated'), value: stats.keys.allocated },
    { name: t('keys.status_unused', 'Unused'), value: stats.keys.unused },
    { name: t('keys.status_bad', 'Bad'), value: stats.keys.bad },
    { name: t('keys.status_retry', 'Retry'), value: stats.keys.retry },
  ].filter(d => d.value > 0)

  if (data.length === 0) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('dashboard.key_distribution', 'Key Distribution')}</CardTitle>
        </CardHeader>
        <CardContent className="flex items-center justify-center h-[250px] text-muted-foreground text-sm">
          {t('dashboard.no_data', 'No data available')}
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{t('dashboard.key_distribution', 'Key Distribution')}</CardTitle>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={250}>
          <PieChart>
            <Pie
              data={data}
              cx="50%"
              cy="50%"
              innerRadius={55}
              outerRadius={90}
              paddingAngle={3}
              dataKey="value"
              label={({ name, percent }) => `${name} ${((percent ?? 0) * 100).toFixed(0)}%`}
              labelLine={false}
            >
              {data.map((_, index) => (
                <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
              ))}
            </Pie>
            <Tooltip />
            <Legend />
          </PieChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  )
}
