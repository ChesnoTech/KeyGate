import { useQuery } from '@tanstack/react-query'
import { listLogs, type ListLogsParams } from '@/api/logs'

export function useLogs(params: ListLogsParams = {}) {
  return useQuery({
    queryKey: ['logs', params],
    queryFn: () => listLogs(params),
  })
}
