import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Search,
  Fingerprint,
  Loader2,
  Unlink,
  AlertTriangle,
  CheckCircle2,
} from 'lucide-react'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { useHardwareBindings, useReleaseBinding } from '@/hooks/use-production'
import type { HardwareBinding } from '@/api/production'

function BindingStatusBadge({ status }: { status: string }) {
  const config: Record<string, { color: string; icon: React.ReactNode }> = {
    active: { color: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', icon: <CheckCircle2 className="h-3 w-3" /> },
    released: { color: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400', icon: <Unlink className="h-3 w-3" /> },
    conflict: { color: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', icon: <AlertTriangle className="h-3 w-3" /> },
  }
  const c = config[status] || config.active
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${c.color}`}>
      {c.icon}
      {status}
    </span>
  )
}

export function HardwareBindingsPage() {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')

  const bindingsQuery = useHardwareBindings(search ? { search } : undefined)
  const releaseMut = useReleaseBinding()

  const bindings = bindingsQuery.data?.bindings ?? []
  const conflicts = bindingsQuery.data?.conflicts ?? []

  return (
    <div className="flex-1 p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-2">
          <Fingerprint className="h-6 w-6" />
          {t('bindings.title', 'Hardware Bindings')}
        </h1>
        <p className="text-sm text-muted-foreground mt-1">
          {t('bindings.subtitle', 'Track which product keys are bound to which hardware')}
        </p>
      </div>

      {/* Conflicts warning */}
      {conflicts.length > 0 && (
        <Card className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm flex items-center gap-2 text-red-700 dark:text-red-400">
              <AlertTriangle className="h-4 w-4" />
              {t('bindings.conflicts', '{{count}} Binding Conflicts Detected', { count: conflicts.length })}
            </CardTitle>
          </CardHeader>
          <CardContent className="text-sm">
            <p className="text-red-600 dark:text-red-400 mb-2">
              {t('bindings.conflicts_desc', 'The following keys are bound to different hardware than where they were originally activated.')}
            </p>
            {conflicts.map((c) => (
              <div key={c.id} className="flex items-center justify-between py-1 border-b border-red-200/50 last:border-0">
                <div>
                  <span className="font-mono text-xs">{c.product_key?.replace(/./g, '*').slice(0, 20)}...</span>
                  <span className="text-xs text-red-500 ml-2">→ {c.device_fingerprint?.slice(0, 12)}</span>
                </div>
                <ReleaseButton id={c.id} onRelease={() => releaseMut.mutate(c.id)} isPending={releaseMut.isPending} />
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {/* Search */}
      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <Input
          placeholder={t('bindings.search', 'Search by fingerprint, serial, MAC...')}
          className="pl-9"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {/* Bindings table */}
      <Card>
        <CardContent className="p-0">
          {bindingsQuery.isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : bindings.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Fingerprint className="h-10 w-10 mx-auto mb-3 opacity-50" />
              <p>{t('empty.bindings', 'No hardware bindings yet')}</p>
              <p className="text-xs mt-1">{t('bindings.auto_created', 'Bindings are created automatically when keys are activated')}</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="text-left py-2 px-4">{t('bindings.col_fingerprint', 'Device Fingerprint')}</th>
                    <th className="text-left py-2 px-4">{t('bindings.col_mb_serial', 'MB Serial')}</th>
                    <th className="text-left py-2 px-4">{t('bindings.col_uuid', 'System UUID')}</th>
                    <th className="text-left py-2 px-4">{t('bindings.col_mac', 'MAC')}</th>
                    <th className="text-left py-2 px-4">{t('bindings.col_key', 'Product Key')}</th>
                    <th className="text-left py-2 px-4">{t('bindings.col_status', 'Status')}</th>
                    <th className="text-left py-2 px-4">{t('bindings.col_bound_at', 'Bound')}</th>
                    <th className="text-left py-2 px-4"></th>
                  </tr>
                </thead>
                <tbody>
                  {bindings.map((b: HardwareBinding) => (
                    <tr key={b.id} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="py-3 px-4 font-mono text-xs truncate max-w-[120px]">{b.device_fingerprint?.slice(0, 16)}</td>
                      <td className="py-3 px-4 font-mono text-xs">{b.motherboard_serial || '—'}</td>
                      <td className="py-3 px-4 font-mono text-xs truncate max-w-[100px]">{b.system_uuid || '—'}</td>
                      <td className="py-3 px-4 font-mono text-xs">{b.primary_mac_address || '—'}</td>
                      <td className="py-3 px-4 font-mono text-xs">{b.product_key?.slice(0, 5)}*****</td>
                      <td className="py-3 px-4"><BindingStatusBadge status={b.status} /></td>
                      <td className="py-3 px-4 text-muted-foreground">{b.bound_at}</td>
                      <td className="py-3 px-4">
                        {b.status === 'active' && (
                          <ReleaseButton id={b.id} onRelease={() => releaseMut.mutate(b.id)} isPending={releaseMut.isPending} />
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function ReleaseButton({ onRelease, isPending }: { id: number; onRelease: () => void; isPending: boolean }) {
  const { t } = useTranslation()
  return (
    <AlertDialog>
      <AlertDialogTrigger
        className="inline-flex items-center justify-center rounded-md text-xs font-medium bg-destructive text-destructive-foreground hover:bg-destructive/90 h-7 px-2 gap-1"
      >
        <Unlink className="h-3 w-3" />
        {t('bindings.release', 'Release')}
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{t('bindings.release_title', 'Release Hardware Binding?')}</AlertDialogTitle>
          <AlertDialogDescription>
            {t('bindings.release_desc', 'This will unbind the product key from this hardware. The key can then be used on different hardware.')}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
          <AlertDialogAction onClick={onRelease} disabled={isPending}>
            {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t('bindings.release_confirm', 'Yes, Release')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
