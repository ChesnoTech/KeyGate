import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/api/client'
import type { DashboardStats } from '@/types/api'

export function useDashboardStats() {
  return useQuery({
    queryKey: ['dashboard', 'stats'],
    queryFn: () => apiGet<{ success: boolean; stats: DashboardStats }>('get_stats'),
    select: (data) => data.stats,
    refetchInterval: 30_000,
  })
}
