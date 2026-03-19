import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from 'recharts'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import type { DailyTrend } from '@/types/api'

interface ActivationTrendsChartProps {
  data?: DailyTrend[]
}

type RangeOption = '7' | '14' | '30' | '90' | '180' | '365' | 'all'

export function ActivationTrendsChart({ data }: ActivationTrendsChartProps) {
  const { t } = useTranslation()
  const [range, setRange] = useState<RangeOption>('30')

  if (!data || data.length === 0) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t('dashboard.activation_trends', 'Activation Trends')}
          </CardTitle>
        </CardHeader>
        <CardContent className="flex items-center justify-center h-[300px] text-muted-foreground text-sm">
          {t('dashboard.no_data', 'No data available')}
        </CardContent>
      </Card>
    )
  }

  const rangeLabels: Record<RangeOption, string> = {
    '7': t('dashboard.last_7_days', 'Last 7 days'),
    '14': t('dashboard.last_14_days', 'Last 14 days'),
    '30': t('dashboard.last_30_days', 'Last 30 days'),
    '90': t('dashboard.last_90_days', 'Last 90 days'),
    '180': t('dashboard.last_6_months', 'Last 6 months'),
    '365': t('dashboard.last_year', 'Last year'),
    'all': t('dashboard.all_time', 'All time'),
  }

  const filtered = range === 'all' ? data : data.slice(-parseInt(range))

  const formatDate = (dateStr: unknown) => {
    const d = new Date(String(dateStr) + 'T00:00:00')
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <CardTitle className="text-base">
          {t('dashboard.activation_trends', 'Activation Trends')}
        </CardTitle>
        <Select value={range} onValueChange={(v) => setRange((v ?? '30') as RangeOption)}>
          <SelectTrigger className="w-[140px] h-8 text-xs">
            <span className="truncate">{rangeLabels[range]}</span>
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="7">{rangeLabels['7']}</SelectItem>
            <SelectItem value="14">{rangeLabels['14']}</SelectItem>
            <SelectItem value="30">{rangeLabels['30']}</SelectItem>
            <SelectItem value="90">{rangeLabels['90']}</SelectItem>
            <SelectItem value="180">{rangeLabels['180']}</SelectItem>
            <SelectItem value="365">{rangeLabels['365']}</SelectItem>
            <SelectItem value="all">{rangeLabels['all']}</SelectItem>
          </SelectContent>
        </Select>
      </CardHeader>
      <CardContent>
        <ResponsiveContainer width="100%" height={300}>
          <AreaChart data={filtered} margin={{ top: 5, right: 10, left: 0, bottom: 0 }}>
            <defs>
              <linearGradient id="gradSuccess" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="oklch(0.65 0.19 145)" stopOpacity={0.3} />
                <stop offset="95%" stopColor="oklch(0.65 0.19 145)" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="gradFailure" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="oklch(0.60 0.20 25)" stopOpacity={0.3} />
                <stop offset="95%" stopColor="oklch(0.60 0.20 25)" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
            <XAxis
              dataKey="date"
              tickFormatter={formatDate}
              tick={{ fontSize: 11 }}
              className="fill-muted-foreground"
              interval={filtered.length <= 7 ? 0 : filtered.length <= 14 ? 1 : filtered.length <= 30 ? 2 : 'preserveStartEnd'}
            />
            <YAxis
              allowDecimals={false}
              tick={{ fontSize: 11 }}
              className="fill-muted-foreground"
              width={35}
            />
            <Tooltip
              labelFormatter={formatDate}
              contentStyle={{
                backgroundColor: 'hsl(var(--card))',
                border: '1px solid hsl(var(--border))',
                borderRadius: '6px',
                fontSize: '12px',
              }}
            />
            <Legend />
            <Area
              type="monotone"
              dataKey="successes"
              name={t('dashboard.successful', 'Successful')}
              stroke="oklch(0.65 0.19 145)"
              fill="url(#gradSuccess)"
              strokeWidth={2}
            />
            <Area
              type="monotone"
              dataKey="failures"
              name={t('dashboard.failed', 'Failed')}
              stroke="oklch(0.60 0.20 25)"
              fill="url(#gradFailure)"
              strokeWidth={2}
            />
          </AreaChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  )
}
