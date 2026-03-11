import { AppHeader } from '@/components/layout/app-header'

interface PageHeaderProps {
  /** Title shown in both the sidebar breadcrumb and the page heading. */
  title: string
  /** Optional actions rendered to the right of the heading (buttons, etc.). */
  actions?: React.ReactNode
}

/**
 * Standard page header: AppHeader breadcrumb + h2 title row.
 * Optionally renders action buttons to the right of the title.
 */
export function PageHeader({ title, actions }: PageHeaderProps) {
  return (
    <>
      <AppHeader title={title} />
      <div className="flex items-center justify-between px-4 pt-4 md:px-6 md:pt-6">
        <h2 className="text-2xl font-bold tracking-tight">{title}</h2>
        {actions}
      </div>
    </>
  )
}
