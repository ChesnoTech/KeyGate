import { useTranslation } from 'react-i18next'
import { SidebarTrigger } from '@/components/ui/sidebar'
import { Separator } from '@/components/ui/separator'

export function AppHeader({ title }: { title?: string }) {
  const { t } = useTranslation()

  return (
    <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
      <SidebarTrigger className="-ml-1" />
      <Separator orientation="vertical" className="mr-2 h-4" />
      <h1 className="text-sm font-medium">
        {title ?? t('dashboard.title')}
      </h1>
    </header>
  )
}
