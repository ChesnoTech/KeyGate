import { useState } from 'react'
import { useTranslation } from 'react-i18next'
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
import { useAddTech } from '@/hooks/use-technicians'

interface AddTechDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function AddTechDialog({ open, onOpenChange }: AddTechDialogProps) {
  const { t } = useTranslation()
  const addMutation = useAddTech()

  const [techId, setTechId] = useState('')
  const [password, setPassword] = useState('')
  const [fullName, setFullName] = useState('')
  const [email, setEmail] = useState('')
  const [server, setServer] = useState('oem')
  const [language, setLanguage] = useState('en')
  const [error, setError] = useState('')

  const serverLabels: Record<string, string> = {
    oem: t('tech.server_oem', 'OEM'),
    alternative: t('tech.server_alternative', 'Alternative'),
  }

  const languageLabels: Record<string, string> = {
    en: t('tech.lang_en', 'English'),
    ru: t('tech.lang_ru', 'Russian'),
  }

  const resetForm = () => {
    setTechId('')
    setPassword('')
    setFullName('')
    setEmail('')
    setServer('oem')
    setLanguage('en')
    setError('')
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setError('')

    if (techId.length < 5) {
      setError(t('tech.error_id_length', 'Technician ID must be at least 5 characters'))
      return
    }
    if (password.length < 8) {
      setError(t('tech.error_password_length', 'Password must be at least 8 characters'))
      return
    }

    addMutation.mutate(
      {
        technician_id: techId,
        password,
        full_name: fullName,
        email,
        preferred_server: server,
        preferred_language: language,
      },
      {
        onSuccess: (res) => {
          if (res.success) {
            resetForm()
            onOpenChange(false)
          } else {
            setError(res.error ?? t('tech.error_add_failed', 'Failed to add technician'))
          }
        },
        onError: () => {
          setError(t('tech.error_add_failed', 'Failed to add technician'))
        },
      }
    )
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(nextOpen) => {
        if (!nextOpen) resetForm()
        onOpenChange(nextOpen)
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('tech.add', 'Add Technician')}</DialogTitle>
        </DialogHeader>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="add-tech-id">{t('tech.technician_id', 'Technician ID')}</Label>
            <Input
              id="add-tech-id"
              value={techId}
              onChange={(e) => setTechId(e.target.value)}
              placeholder={t('tech.id_placeholder', 'e.g. TECH1')}
              required
              minLength={5}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="add-tech-password">{t('tech.password', 'Password')}</Label>
            <Input
              id="add-tech-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder={t('tech.password_placeholder', 'Min 8 characters')}
              required
              minLength={8}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="add-tech-name">{t('tech.full_name', 'Full Name')}</Label>
            <Input
              id="add-tech-name"
              value={fullName}
              onChange={(e) => setFullName(e.target.value)}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="add-tech-email">{t('tech.email', 'Email')}</Label>
            <Input
              id="add-tech-email"
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

          {error && (
            <p className="text-sm text-destructive">{error}</p>
          )}

          <DialogFooter>
            <Button type="submit" disabled={addMutation.isPending}>
              {addMutation.isPending
                ? t('common.saving', 'Saving...')
                : t('tech.add', 'Add Technician')}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
