import { Badge } from '@/components/ui/badge'
import { useTranslation } from 'react-i18next'

const statusConfig: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; className?: string }> = {
  unused: { variant: 'outline' },
  allocated: { variant: 'secondary' },
  good: { variant: 'default', className: 'bg-green-600 hover:bg-green-700' },
  bad: { variant: 'destructive' },
  retry: { variant: 'default', className: 'bg-orange-500 hover:bg-orange-600' },
  active: { variant: 'default', className: 'bg-green-600 hover:bg-green-700' },
  inactive: { variant: 'secondary' },
  success: { variant: 'default', className: 'bg-green-600 hover:bg-green-700' },
  failure: { variant: 'destructive' },
  failed: { variant: 'destructive' },
  pending: { variant: 'outline' },
  disabled: { variant: 'secondary' },
  lost: { variant: 'default', className: 'bg-orange-500 hover:bg-orange-600' },
  stolen: { variant: 'destructive' },
  login: { variant: 'default', className: 'bg-blue-500 hover:bg-blue-600' },
  logout: { variant: 'secondary' },
  create: { variant: 'default', className: 'bg-green-600 hover:bg-green-700' },
  update: { variant: 'default', className: 'bg-blue-500 hover:bg-blue-600' },
  delete: { variant: 'destructive' },
  import: { variant: 'default', className: 'bg-purple-500 hover:bg-purple-600' },
  export: { variant: 'outline' },
  pass: { variant: 'default', className: 'bg-green-600 hover:bg-green-700' },
  info: { variant: 'default', className: 'bg-blue-500 hover:bg-blue-600' },
  warning: { variant: 'default', className: 'bg-orange-500 hover:bg-orange-600' },
  fail: { variant: 'destructive' },
}

interface StatusBadgeProps {
  status: string
  translationPrefix?: string
}

export function StatusBadge({ status, translationPrefix = 'keys.status_' }: StatusBadgeProps) {
  const { t } = useTranslation()
  const config = statusConfig[status] ?? { variant: 'outline' as const }

  return (
    <Badge variant={config.variant} className={config.className}>
      {t(`${translationPrefix}${status}`, status)}
    </Badge>
  )
}
