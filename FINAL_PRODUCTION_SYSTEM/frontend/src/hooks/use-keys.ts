import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { listKeys, recycleKey, deleteKey, importKeys, addKeys, type ListKeysParams, type AddKeyInput } from '@/api/keys'

export function useKeys(params: ListKeysParams = {}) {
  return useQuery({
    queryKey: ['keys', params],
    queryFn: () => listKeys(params),
  })
}

export function useRecycleKey() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (keyId: number) => recycleKey(keyId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['keys'] })
      toast.success(t('toast.key_recycled', 'Key recycled successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteKey() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (keyId: number) => deleteKey(keyId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['keys'] })
      toast.success(t('toast.key_deleted', 'Key deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useImportKeys() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (file: File) => importKeys(file),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['keys'] })
      toast.success(t('toast.csv_imported', 'CSV imported successfully'))
      return data
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useAddKeys() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (keys: AddKeyInput[]) => addKeys(keys),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['keys'] })
      if (data.imported > 0) {
        toast.success(t('toast.keys_added', '{{count}} key(s) added successfully', { count: data.imported }))
      }
      return data
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
