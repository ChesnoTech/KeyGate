import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getProductLines,
  getProductLine,
  saveProductLine,
  deleteProductLine,
  saveProductVariant,
  deleteProductVariant,
  type SaveProductLineInput,
  type SaveProductVariantInput,
} from '@/api/product-lines'

export function useProductLines() {
  return useQuery({
    queryKey: ['product-lines'],
    queryFn: getProductLines,
  })
}

export function useProductLine(id: number | null) {
  return useQuery({
    queryKey: ['product-lines', id],
    queryFn: () => getProductLine(id!),
    enabled: id !== null,
  })
}

export function useSaveProductLine() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: SaveProductLineInput) => saveProductLine(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['product-lines'] })
      toast.success(t('product_lines.saved', 'Product line saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteProductLine() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => deleteProductLine(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['product-lines'] })
      toast.success(t('product_lines.deleted', 'Product line deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useSaveProductVariant() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (data: SaveProductVariantInput) => saveProductVariant(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['product-lines'] })
      toast.success(t('product_lines.variant_saved', 'Product variant saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useDeleteProductVariant() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (id: number) => deleteProductVariant(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['product-lines'] })
      toast.success(t('product_lines.variant_deleted', 'Product variant deleted'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
