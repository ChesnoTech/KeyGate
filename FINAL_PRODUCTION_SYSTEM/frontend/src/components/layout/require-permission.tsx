import type { ReactNode } from 'react'
import { useAuth } from '@/hooks/use-auth'
import { useTranslation } from 'react-i18next'
import { ShieldX } from 'lucide-react'

interface RequirePermissionProps {
  permission: string
  children: ReactNode
}

export function RequirePermission({ permission, children }: RequirePermissionProps) {
  const { hasPermission } = useAuth()
  const { t } = useTranslation()

  if (!hasPermission(permission)) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-4 p-8 text-center">
        <ShieldX className="h-16 w-16 text-muted-foreground/50" />
        <h2 className="text-xl font-semibold">
          {t('errors.access_denied', 'Access Denied')}
        </h2>
        <p className="text-muted-foreground max-w-md">
          {t(
            'errors.insufficient_permissions',
            'You do not have the required permissions to access this page. Contact your administrator if you believe this is an error.'
          )}
        </p>
      </div>
    )
  }

  return <>{children}</>
}
