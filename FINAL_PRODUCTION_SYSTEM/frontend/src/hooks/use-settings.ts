import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import {
  getAltServerSettings,
  saveAltServerSettings,
  getOrderFieldSettings,
  saveOrderFieldSettings,
  getSessionSettings,
  saveSessionSettings,
  getLanguageSettings,
  saveLanguageSettings,
  getSmtpSettings,
  saveSmtpSettings,
  testSmtpConnection,
  getClientConfigSettings,
  saveClientConfigSettings,
  type AltServerConfig,
  type OrderFieldConfig,
  type SessionConfig,
  type SmtpConfig,
  type ClientConfig,
} from '@/api/settings'

export function useAltServerSettings() {
  return useQuery({
    queryKey: ['settings', 'alt-server'],
    queryFn: () => getAltServerSettings(),
  })
}

export function useSaveAltServerSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: AltServerConfig) => saveAltServerSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'alt-server'] })
      toast.success(t('toast.settings_saved', 'Settings saved successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useOrderFieldSettings() {
  return useQuery({
    queryKey: ['settings', 'order-fields'],
    queryFn: () => getOrderFieldSettings(),
  })
}

export function useSaveOrderFieldSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: OrderFieldConfig) => saveOrderFieldSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'order-fields'] })
      toast.success(t('toast.settings_saved', 'Settings saved successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useSessionSettings() {
  return useQuery({
    queryKey: ['settings', 'session'],
    queryFn: () => getSessionSettings(),
  })
}

export function useSaveSessionSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: SessionConfig) => saveSessionSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'session'] })
      toast.success(t('settings.session_saved', 'Session settings saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── SMTP / Email ──────────────────────────────────────────────────

export function useSmtpSettings() {
  return useQuery({
    queryKey: ['settings', 'smtp'],
    queryFn: () => getSmtpSettings(),
  })
}

export function useSaveSmtpSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: SmtpConfig) => saveSmtpSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'smtp'] })
      toast.success(t('settings.smtp_saved', 'Email settings saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── Client Configuration ──────────────────────────────────────────

export function useClientConfigSettings() {
  return useQuery({
    queryKey: ['settings', 'client-config'],
    queryFn: () => getClientConfigSettings(),
  })
}

export function useSaveClientConfigSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (config: ClientConfig) => saveClientConfigSettings(config),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'client-config'] })
      toast.success(t('toast.settings_saved', 'Settings saved successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

export function useTestSmtpConnection() {
  const { t } = useTranslation()
  return useMutation({
    mutationFn: (params: Record<string, unknown>) => testSmtpConnection(params),
    onSuccess: (data) => {
      toast.success(data.message || t('settings.smtp_test_ok', 'Test email sent successfully'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}

// ── Language Settings ────────────────────────────────────────────

export function useLanguageSettings() {
  return useQuery({
    queryKey: ['settings', 'languages'],
    queryFn: () => getLanguageSettings(),
  })
}

export function useSaveLanguageSettings() {
  const qc = useQueryClient()
  const { t } = useTranslation()
  return useMutation({
    mutationFn: ({ enabled, defaultLang }: { enabled: string[]; defaultLang: string }) =>
      saveLanguageSettings(enabled, defaultLang),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['settings', 'languages'] })
      toast.success(t('settings.languages_saved', 'Language settings saved'))
    },
    onError: (e: Error) => toast.error(e.message),
  })
}
