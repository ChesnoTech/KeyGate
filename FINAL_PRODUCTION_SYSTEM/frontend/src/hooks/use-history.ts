import { useQuery } from '@tanstack/react-query'
import { listHistory, type ListHistoryParams } from '@/api/history'

export function useHistory(params: ListHistoryParams = {}) {
  return useQuery({
    queryKey: ['history', params],
    queryFn: () => listHistory(params),
  })
}
