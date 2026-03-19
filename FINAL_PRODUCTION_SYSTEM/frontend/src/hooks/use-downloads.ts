import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  listClientResources,
  uploadClientResource,
  deleteClientResource,
} from '@/api/downloads'

export function useClientResources() {
  return useQuery({
    queryKey: ['client-resources'],
    queryFn: listClientResources,
  })
}

export function useUploadResource() {
  const qc = useQueryClient()
  const { t } = useTranslation()

  return useMutation({
    mutationFn: ({ resourceKey, file }: { resourceKey: string; file: File }) =>
      uploadClientResource(resourceKey, file),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client-resources'] })
      toast.success(t('downloads.upload_success', 'Resource uploaded successfully'))
    },
    onError: (err: Error) => {
      toast.error(err.message)
    },
  })
}

export function useDeleteResource() {
  const qc = useQueryClient()
  const { t } = useTranslation()

  return useMutation({
    mutationFn: (resourceKey: string) => deleteClientResource(resourceKey),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client-resources'] })
      toast.success(t('downloads.delete_success', 'Resource deleted'))
    },
    onError: (err: Error) => {
      toast.error(err.message)
    },
  })
}
