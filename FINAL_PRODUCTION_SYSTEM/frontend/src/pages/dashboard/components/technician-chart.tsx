import { useTranslation } from 'react-i18next'
import { BarChart, Bar, XAxis, YAxis, ResponsiveContainer, Tooltip } from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { DashboardStats } from '@/types/api'

interface TechnicianChartProps {
  stats?: DashboardStats
}

export function TechnicianChart({ stats }: TechnicianChartProps) {
  const { t } = useTranslation()

  if (!stats) return null

  const data = [
    {
      name: t('tech.active', 'Active'),
      value: stats.technicians.active,
      fill: 'oklch(0.65 0.19 145)',
    },
    {
      name: t('tech.inactive', 'Inactive'),
      value: stats.technicians.inactive,
      fill: 'oklch(0.55 0.12 0)',
    },
  ]

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{t('dashboard.technician_overview', 'Technicians')}</CardTitle>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={250}>
          <BarChart data={data} layout="vertical" margin={{ left: 10, right: 20 }}>
            <XAxis type="number" allowDecimals={false} />
            <YAxis type="category" dataKey="name" width={70} />
            <Tooltip />
            <Bar dataKey="value" radius={[0, 4, 4, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  )
}
