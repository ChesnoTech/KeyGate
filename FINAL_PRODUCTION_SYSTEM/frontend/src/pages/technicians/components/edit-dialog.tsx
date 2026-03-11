import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import { Checkbox } from '@/components/ui/checkbox'
import { getTech } from '@/api/technicians'
import { useEditTech } from '@/hooks/use-technicians'

interface EditTechDialogProps {
  techId: number | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function EditTechDialog({ techId, open, onOpenChange }: EditTechDialogProps) {
  const { t } = useTranslation()
  const editMutation = useEditTech()

  const { data, isLoading } = useQuery({
    queryKey: ['technician-detail', techId],
    queryFn: () => getTech(techId!),
    enabled: open && techId !== null,
  })

  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [server, setServer] = useState('oem')
  const [language, setLanguage] = useState('en')
  const [isActive, setIsActive] = useState(true)
  const [error, setError] = useState('')

  const serverLabels: Record<string, string> = {
    oem: t('tech.server_oem', 'OEM'),
    alternative: t('tech.server_alternative', 'Alternative'),
  }

  const languageLabels: Record<string, string> = {
    en: t('tech.lang_en', 'English'),
    ru: t('tech.lang_ru', 'Russian'),
  }

  useEffect(() => {
    if (data?.technician) {
      const tech = data.technician
      setFullName(tech.full_name ?? '')
      setEmail(tech.email ?? '')
      setServer(tech.preferred_server ?? 'oem')
      setLanguage(tech.preferred_language ?? 'en')
      setIsActive(!!tech.is_active)
      setError('')
    }
  }, [data])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (techId === null) return
    setError('')

    editMutation.mutate(
      {
        id: techId,
        full_name: fullName,
        email,
        preferred_server: server,
        preferred_language: language,
        is_active: isActive,
      },
      {
        onSuccess: (res) => {
          if (res.success) {
            onOpenChange(false)
          } else {
            setError(res.error ?? t('tech.error_edit_failed', 'Failed to update technician'))
          }
        },
        onError: () => {
          setError(t('tech.error_edit_failed', 'Failed to update technician'))
        },
      }
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('tech.edit', 'Edit Technician')}</DialogTitle>
        </DialogHeader>
        {isLoading ? (
          <div className="py-8 text-center text-muted-foreground">
            {t('common.loading', 'Loading...')}
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="edit-tech-name">{t('tech.full_name', 'Full Name')}</Label>
              <Input
                id="edit-tech-name"
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="edit-tech-email">{t('tech.email', 'Email')}</Label>
              <Input
                id="edit-tech-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="space-y-2">
              <Label>{t('tech.preferred_server', 'Preferred Server')}</Label>
              <Select value={server} onValueChange={(v) => setServer(v ?? 'oem')}>
                <SelectTrigger className="w-full">
                  <span className="truncate">{serverLabels[server] ?? server}</span>
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="oem">{t('tech.server_oem', 'OEM')}</SelectItem>
                  <SelectItem value="alternative">{t('tech.server_alternative', 'Alternative')}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>{t('tech.preferred_language', 'Language')}</Label>
              <Select value={language} onValueChange={(v) => setLanguage(v ?? 'en')}>
                <SelectTrigger className="w-full">
                  <span className="truncate">{languageLabels[language] ?? language}</span>
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="en">{t('tech.lang_en', 'English')}</SelectItem>
                  <SelectItem value="ru">{t('tech.lang_ru', 'Russian')}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-2">
              <Checkbox
                id="edit-tech-active"
                checked={isActive}
                onCheckedChange={(checked) => setIsActive(Boolean(checked))}
              />
              <Label htmlFor="edit-tech-active">{t('tech.is_active', 'Active')}</Label>
            </div>

            {error && (
              <p className="text-sm text-destructive">{error}</p>
            )}

            <DialogFooter>
              <Button type="submit" disabled={editMutation.isPending}>
                {editMutation.isPending
                  ? t('common.saving', 'Saving...')
                  : t('common.save', 'Save')}
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  )
}
