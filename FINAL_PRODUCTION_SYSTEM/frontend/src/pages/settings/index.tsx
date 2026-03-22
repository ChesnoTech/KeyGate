import { useState, useEffect, useMemo, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Save, Upload, Trash2, RotateCcw, Palette, Timer, Mail, Send, CheckCircle2, XCircle, Eye, EyeOff, Server, Monitor, Wifi, Clock, ToggleLeft, KeyRound } from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  useAltServerSettings,
  useSaveAltServerSettings,
  useOrderFieldSettings,
  useSaveOrderFieldSettings,
  useSessionSettings,
  useSaveSessionSettings,
  useSmtpSettings,
  useSaveSmtpSettings,
  useTestSmtpConnection,
  useClientConfigSettings,
  useSaveClientConfigSettings,
} from '@/hooks/use-settings'
import {
  useBranding,
  useSaveBranding,
  useUploadBrandAsset,
  useDeleteBrandAsset,
} from '@/hooks/use-branding'
import type { AltServerConfig, OrderFieldConfig, SessionConfig, SmtpConfig, ClientConfig } from '@/api/settings'
import type { BrandingConfig } from '@/api/branding'

// Labels are now provided via i18n (settings.script_type_cmd / settings.script_type_powershell)

const charTypeOptions = [
  { value: 'digits_only', labelKey: 'settings.order_char_digits_only' },
  { value: 'alphanumeric', labelKey: 'settings.order_char_alphanumeric' },
  { value: 'alphanumeric_dash', labelKey: 'settings.order_char_alphanumeric_dash' },
  { value: 'custom', labelKey: 'settings.order_char_custom' },
]

function buildPreviewPattern(form: OrderFieldConfig): string {
  const min = Math.max(1, Number(form.order_field_min_length) || 1)
  const max = Math.max(min, Number(form.order_field_max_length) || 10)
  switch (form.order_field_char_type) {
    case 'digits_only':
      return `/^[0-9]{${min},${max}}$/`
    case 'alphanumeric':
      return `/^[A-Za-z0-9]{${min},${max}}$/`
    case 'alphanumeric_dash':
      return `/^[A-Za-z0-9_-]{${min},${max}}$/`
    case 'custom':
      return form.order_field_custom_regex || '(not set)'
    default:
      return `/^[A-Za-z0-9]{${min},${max}}$/`
  }
}

function generateExamples(form: OrderFieldConfig): { valid: string; invalid: string } {
  const min = Math.max(1, Number(form.order_field_min_length) || 1)
  switch (form.order_field_char_type) {
    case 'digits_only':
      return { valid: '1'.repeat(min), invalid: 'ABC' }
    case 'alphanumeric':
      return { valid: 'A1B2C'.substring(0, min).padEnd(min, '0'), invalid: 'a-b_c!' }
    case 'alphanumeric_dash':
      return { valid: 'A-1_B'.substring(0, min).padEnd(min, '0'), invalid: 'a b@c!' }
    case 'custom':
      return { valid: '(depends on regex)', invalid: '(depends on regex)' }
    default:
      return { valid: 'ABC12', invalid: '---' }
  }
}

export function SettingsPage() {
  const { t } = useTranslation()

  // Alt server settings
  const { data, isLoading } = useAltServerSettings()
  const saveMutation = useSaveAltServerSettings()

  const [form, setForm] = useState<AltServerConfig>({
    alt_server_enabled: false,
    alt_server_script_path: '',
    alt_server_pre_command: '',
    alt_server_script_args: '',
    alt_server_script_type: 'powershell',
    alt_server_timeout: 120,
    alt_server_prompt_technician: false,
    alt_server_auto_failover: false,
    alt_server_verify_activation: false,
  })

  useEffect(() => {
    if (data?.config) {
      setForm({
        alt_server_enabled: data.config.alt_server_enabled ?? false,
        alt_server_script_path: data.config.alt_server_script_path ?? '',
        alt_server_pre_command: data.config.alt_server_pre_command ?? '',
        alt_server_script_args: data.config.alt_server_script_args ?? '',
        alt_server_script_type: data.config.alt_server_script_type ?? 'powershell',
        alt_server_timeout: data.config.alt_server_timeout ?? 120,
        alt_server_prompt_technician: data.config.alt_server_prompt_technician ?? false,
        alt_server_auto_failover: data.config.alt_server_auto_failover ?? false,
        alt_server_verify_activation: data.config.alt_server_verify_activation ?? false,
      })
    }
  }, [data])

  const handleSave = () => {
    saveMutation.mutate(form)
  }

  // Order field settings
  const { data: orderData, isLoading: orderLoading } = useOrderFieldSettings()
  const saveOrderMutation = useSaveOrderFieldSettings()

  const [orderForm, setOrderForm] = useState<OrderFieldConfig>({
    order_field_label_en: 'Order Number',
    order_field_label_ru: 'Номер заказа',
    order_field_prompt_en: 'Enter order number',
    order_field_prompt_ru: 'Введите номер заказа',
    order_field_min_length: '5',
    order_field_max_length: '10',
    order_field_char_type: 'alphanumeric',
    order_field_custom_regex: '',
  })

  useEffect(() => {
    if (orderData?.config) {
      setOrderForm(orderData.config)
    }
  }, [orderData])

  const previewPattern = useMemo(() => buildPreviewPattern(orderForm), [orderForm])
  const examples = useMemo(() => generateExamples(orderForm), [orderForm])

  const handleOrderSave = () => {
    saveOrderMutation.mutate(orderForm)
  }

  // Branding settings
  const { data: brandData, isLoading: brandLoading } = useBranding()
  const saveBrandMutation = useSaveBranding()
  const uploadAssetMutation = useUploadBrandAsset()
  const deleteAssetMutation = useDeleteBrandAsset()

  const [brandForm, setBrandForm] = useState<Partial<BrandingConfig>>({
    brand_company_name: 'KeyGate',
    brand_app_version: 'System v2.0',
    brand_login_title: '',
    brand_login_subtitle: '',
    brand_primary_color: '',
    brand_sidebar_color: '',
    brand_accent_color: '',
  })

  useEffect(() => {
    if (brandData?.config) {
      setBrandForm(brandData.config)
    }
  }, [brandData])

  const logoFileRef = useRef<HTMLInputElement>(null)
  const faviconFileRef = useRef<HTMLInputElement>(null)

  const handleBrandSave = () => {
    saveBrandMutation.mutate(brandForm)
  }

  const handleAssetUpload = (assetType: 'logo' | 'favicon', file: File) => {
    uploadAssetMutation.mutate({ file, assetType })
  }

  const handleResetColors = () => {
    setBrandForm((prev) => ({
      ...prev,
      brand_primary_color: '',
      brand_sidebar_color: '',
      brand_accent_color: '',
    }))
  }

  // Session settings
  const { data: sessionData, isLoading: sessionLoading } = useSessionSettings()
  const saveSessionMutation = useSaveSessionSettings()

  const [sessionForm, setSessionForm] = useState<SessionConfig>({
    admin_session_timeout_minutes: 30,
    admin_max_failed_logins: 3,
    admin_lockout_duration_minutes: 30,
    admin_force_password_change_days: 90,
  })

  useEffect(() => {
    if (sessionData?.config) {
      setSessionForm(sessionData.config)
    }
  }, [sessionData])

  const handleSessionSave = () => {
    saveSessionMutation.mutate(sessionForm)
  }

  // SMTP / Email settings
  const { data: smtpData, isLoading: smtpLoading } = useSmtpSettings()
  const saveSmtpMutation = useSaveSmtpSettings()
  const testSmtpMutation = useTestSmtpConnection()

  const [smtpForm, setSmtpForm] = useState<SmtpConfig>({
    smtp_enabled: false,
    smtp_server: '',
    smtp_port: 587,
    smtp_encryption: 'tls',
    smtp_username: '',
    smtp_password: '',
    smtp_auth: true,
    email_from: '',
    email_from_name: '',
    email_to: '',
    email_on_activation_fail: true,
    email_on_key_exhausted: true,
    email_on_daily_summary: false,
  })
  const [showSmtpPassword, setShowSmtpPassword] = useState(false)
  const [testRecipient, setTestRecipient] = useState('')

  useEffect(() => {
    if (smtpData?.config) {
      const c = smtpData.config
      setSmtpForm({
        smtp_enabled: c.smtp_enabled === true || c.smtp_enabled === '1' as unknown as boolean,
        smtp_server: c.smtp_server ?? '',
        smtp_port: Number(c.smtp_port) || 587,
        smtp_encryption: c.smtp_encryption ?? 'tls',
        smtp_username: c.smtp_username ?? '',
        smtp_password: c.smtp_password ?? '',
        smtp_auth: c.smtp_auth === true || c.smtp_auth === '1' as unknown as boolean,
        email_from: c.email_from ?? '',
        email_from_name: c.email_from_name ?? '',
        email_to: c.email_to ?? '',
        email_on_activation_fail: c.email_on_activation_fail === true || c.email_on_activation_fail === '1' as unknown as boolean,
        email_on_key_exhausted: c.email_on_key_exhausted === true || c.email_on_key_exhausted === '1' as unknown as boolean,
        email_on_daily_summary: c.email_on_daily_summary === true || c.email_on_daily_summary === '1' as unknown as boolean,
      })
    }
  }, [smtpData])

  const handleSmtpSave = () => {
    saveSmtpMutation.mutate(smtpForm)
  }

  const handleSmtpTest = () => {
    testSmtpMutation.mutate({
      ...smtpForm,
      test_recipient: testRecipient || smtpForm.email_to,
    } as unknown as Record<string, unknown>)
  }

  const smtpPresets: Record<string, { server: string; port: number; encryption: string }> = {
    gmail:    { server: 'smtp.gmail.com',       port: 587, encryption: 'tls' },
    outlook:  { server: 'smtp.office365.com',   port: 587, encryption: 'tls' },
    yahoo:    { server: 'smtp.mail.yahoo.com',  port: 465, encryption: 'ssl' },
    zoho:     { server: 'smtp.zoho.com',        port: 587, encryption: 'tls' },
    sendgrid: { server: 'smtp.sendgrid.net',    port: 587, encryption: 'tls' },
    mailgun:  { server: 'smtp.mailgun.org',     port: 587, encryption: 'tls' },
    yandex:   { server: 'smtp.yandex.com',      port: 465, encryption: 'ssl' },
    custom:   { server: '',                     port: 587, encryption: 'tls' },
  }

  const applySmtpPreset = (preset: string) => {
    const p = smtpPresets[preset]
    if (p) {
      setSmtpForm((prev) => ({
        ...prev,
        smtp_server: p.server,
        smtp_port: p.port,
        smtp_encryption: p.encryption,
      }))
    }
  }

  return (
    <>
      <AppHeader title={t('nav.settings', 'Settings')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <h2 className="text-2xl font-bold tracking-tight">{t('nav.settings', 'Settings')}</h2>

        <Tabs defaultValue="server">
          <TabsList>
            <TabsTrigger value="server">
              <Server className="mr-1.5 h-4 w-4" />
              {t('settings.tab_server', 'Server Settings')}
            </TabsTrigger>
            <TabsTrigger value="client">
              <Monitor className="mr-1.5 h-4 w-4" />
              {t('settings.tab_client', 'Client Configuration')}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="server" className="mt-4 space-y-4">

        {/* Branding Card */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <Palette className="h-5 w-5 text-primary" />
              <div>
                <CardTitle>{t('settings.branding_title', 'Branding')}</CardTitle>
                <CardDescription>
                  {t('settings.branding_desc', 'Customize the look and feel of the application.')}
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            {brandLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-8 w-full" />
                ))}
              </div>
            ) : (
              <div className="space-y-6">
                {/* Company Info */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t('settings.brand_company_name', 'Company Name')}</Label>
                    <Input
                      value={brandForm.brand_company_name || ''}
                      onChange={(e) => setBrandForm({ ...brandForm, brand_company_name: e.target.value })}
                      placeholder="KeyGate"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>{t('settings.brand_app_version', 'Version Label')}</Label>
                    <Input
                      value={brandForm.brand_app_version || ''}
                      onChange={(e) => setBrandForm({ ...brandForm, brand_app_version: e.target.value })}
                      placeholder="System v2.0"
                    />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t('settings.brand_login_title', 'Login Title')}</Label>
                    <Input
                      value={brandForm.brand_login_title || ''}
                      onChange={(e) => setBrandForm({ ...brandForm, brand_login_title: e.target.value })}
                      placeholder={t('login.title', 'Secure Admin')}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>{t('settings.brand_login_subtitle', 'Login Subtitle')}</Label>
                    <Input
                      value={brandForm.brand_login_subtitle || ''}
                      onChange={(e) => setBrandForm({ ...brandForm, brand_login_subtitle: e.target.value })}
                      placeholder={t('login.subtitle', 'KeyGate')}
                    />
                  </div>
                </div>

                <Separator />

                {/* Logo & Favicon */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t('settings.brand_logo', 'Logo')}</Label>
                    <div className="flex items-center gap-3">
                      {brandData?.config?.brand_logo_path ? (
                        <img
                          src={`/activate/${brandData.config.brand_logo_path}`}
                          alt="Logo"
                          className="h-10 w-10 rounded border object-contain"
                        />
                      ) : (
                        <div className="flex h-10 w-10 items-center justify-center rounded border bg-muted text-xs text-muted-foreground">
                          —
                        </div>
                      )}
                      <input
                        ref={logoFileRef}
                        type="file"
                        accept="image/png,image/jpeg,image/svg+xml,image/webp"
                        className="hidden"
                        onChange={(e) => {
                          const f = e.target.files?.[0]
                          if (f) handleAssetUpload('logo', f)
                          e.target.value = ''
                        }}
                      />
                      <Button variant="outline" size="sm" onClick={() => logoFileRef.current?.click()}>
                        <Upload className="mr-1.5 h-3.5 w-3.5" />
                        {t('settings.brand_upload', 'Upload')}
                      </Button>
                      {brandData?.config?.brand_logo_path && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => deleteAssetMutation.mutate('logo')}
                        >
                          <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                          {t('settings.brand_delete', 'Remove')}
                        </Button>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                      PNG, JPG, SVG, WebP. Max 2MB.
                    </p>
                  </div>
                  <div className="space-y-2">
                    <Label>{t('settings.brand_favicon', 'Favicon')}</Label>
                    <div className="flex items-center gap-3">
                      {brandData?.config?.brand_favicon_path ? (
                        <img
                          src={`/activate/${brandData.config.brand_favicon_path}`}
                          alt="Favicon"
                          className="h-10 w-10 rounded border object-contain"
                        />
                      ) : (
                        <div className="flex h-10 w-10 items-center justify-center rounded border bg-muted text-xs text-muted-foreground">
                          —
                        </div>
                      )}
                      <input
                        ref={faviconFileRef}
                        type="file"
                        accept="image/png,image/x-icon,image/svg+xml"
                        className="hidden"
                        onChange={(e) => {
                          const f = e.target.files?.[0]
                          if (f) handleAssetUpload('favicon', f)
                          e.target.value = ''
                        }}
                      />
                      <Button variant="outline" size="sm" onClick={() => faviconFileRef.current?.click()}>
                        <Upload className="mr-1.5 h-3.5 w-3.5" />
                        {t('settings.brand_upload', 'Upload')}
                      </Button>
                      {brandData?.config?.brand_favicon_path && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => deleteAssetMutation.mutate('favicon')}
                        >
                          <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                          {t('settings.brand_delete', 'Remove')}
                        </Button>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                      PNG, ICO, SVG. Max 2MB.
                    </p>
                  </div>
                </div>

                <Separator />

                {/* Custom Colors */}
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <Label className="text-sm font-medium">{t('settings.brand_colors', 'Custom Colors')}</Label>
                    <Button variant="ghost" size="sm" onClick={handleResetColors}>
                      <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                      {t('settings.brand_reset_colors', 'Reset')}
                    </Button>
                  </div>
                  <div className="grid grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label className="text-xs">{t('settings.brand_primary_color', 'Primary')}</Label>
                      <div className="flex items-center gap-2">
                        <div
                          className="h-8 w-8 rounded border cursor-pointer"
                          style={{ backgroundColor: brandForm.brand_primary_color || '#6366f1' }}
                          onClick={() => {
                            const input = document.getElementById('brand_primary_picker') as HTMLInputElement
                            input?.click()
                          }}
                        />
                        <input
                          id="brand_primary_picker"
                          type="color"
                          value={brandForm.brand_primary_color || '#6366f1'}
                          onChange={(e) => setBrandForm({ ...brandForm, brand_primary_color: e.target.value })}
                          className="sr-only"
                        />
                        <Input
                          value={brandForm.brand_primary_color || ''}
                          onChange={(e) => setBrandForm({ ...brandForm, brand_primary_color: e.target.value })}
                          placeholder="#6366f1"
                          className="flex-1 text-xs"
                        />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label className="text-xs">{t('settings.brand_sidebar_color', 'Sidebar')}</Label>
                      <div className="flex items-center gap-2">
                        <div
                          className="h-8 w-8 rounded border cursor-pointer"
                          style={{ backgroundColor: brandForm.brand_sidebar_color || '#1e293b' }}
                          onClick={() => {
                            const input = document.getElementById('brand_sidebar_picker') as HTMLInputElement
                            input?.click()
                          }}
                        />
                        <input
                          id="brand_sidebar_picker"
                          type="color"
                          value={brandForm.brand_sidebar_color || '#1e293b'}
                          onChange={(e) => setBrandForm({ ...brandForm, brand_sidebar_color: e.target.value })}
                          className="sr-only"
                        />
                        <Input
                          value={brandForm.brand_sidebar_color || ''}
                          onChange={(e) => setBrandForm({ ...brandForm, brand_sidebar_color: e.target.value })}
                          placeholder="#1e293b"
                          className="flex-1 text-xs"
                        />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label className="text-xs">{t('settings.brand_accent_color', 'Accent')}</Label>
                      <div className="flex items-center gap-2">
                        <div
                          className="h-8 w-8 rounded border cursor-pointer"
                          style={{ backgroundColor: brandForm.brand_accent_color || '#f59e0b' }}
                          onClick={() => {
                            const input = document.getElementById('brand_accent_picker') as HTMLInputElement
                            input?.click()
                          }}
                        />
                        <input
                          id="brand_accent_picker"
                          type="color"
                          value={brandForm.brand_accent_color || '#f59e0b'}
                          onChange={(e) => setBrandForm({ ...brandForm, brand_accent_color: e.target.value })}
                          className="sr-only"
                        />
                        <Input
                          value={brandForm.brand_accent_color || ''}
                          onChange={(e) => setBrandForm({ ...brandForm, brand_accent_color: e.target.value })}
                          placeholder="#f59e0b"
                          className="flex-1 text-xs"
                        />
                      </div>
                    </div>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {t('settings.brand_colors_hint', 'Leave empty to use default theme colors.')}
                  </p>
                </div>

                <Button onClick={handleBrandSave} disabled={saveBrandMutation.isPending}>
                  <Save className="mr-2 h-4 w-4" />
                  {saveBrandMutation.isPending ? t('common.saving', 'Saving...') : t('common.save', 'Save')}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Session Settings Card */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <Timer className="h-5 w-5 text-primary" />
              <div>
                <CardTitle>{t('settings.session_title', 'Session Settings')}</CardTitle>
                <CardDescription>
                  {t('settings.session_desc', 'Configure admin session timeout and inactivity limits.')}
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            {sessionLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 3 }).map((_, i) => (
                  <Skeleton key={i} className="h-8 w-full" />
                ))}
              </div>
            ) : (
              <div className="space-y-6">
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t('settings.session_timeout', 'Session Timeout (minutes)')}</Label>
                    <Input
                      type="number"
                      min={5}
                      max={1440}
                      value={sessionForm.admin_session_timeout_minutes}
                      onChange={(e) => setSessionForm({ ...sessionForm, admin_session_timeout_minutes: Number(e.target.value) })}
                    />
                    <p className="text-xs text-muted-foreground">
                      {t('settings.session_timeout_desc', 'Maximum session lifetime before forced re-login.')}
                    </p>
                  </div>
                  <div className="space-y-2">
                    <Label>{t('settings.max_failed_logins', 'Max Failed Login Attempts')}</Label>
                    <Input
                      type="number"
                      min={1}
                      max={20}
                      value={sessionForm.admin_max_failed_logins}
                      onChange={(e) => setSessionForm({ ...sessionForm, admin_max_failed_logins: Number(e.target.value) })}
                    />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t('settings.lockout_duration', 'Lockout Duration (minutes)')}</Label>
                    <Input
                      type="number"
                      min={1}
                      max={1440}
                      value={sessionForm.admin_lockout_duration_minutes}
                      onChange={(e) => setSessionForm({ ...sessionForm, admin_lockout_duration_minutes: Number(e.target.value) })}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>{t('settings.password_change_days', 'Force Password Change (days)')}</Label>
                    <Input
                      type="number"
                      min={0}
                      max={365}
                      value={sessionForm.admin_force_password_change_days}
                      onChange={(e) => setSessionForm({ ...sessionForm, admin_force_password_change_days: Number(e.target.value) })}
                    />
                    <p className="text-xs text-muted-foreground">
                      {t('settings.password_change_days_desc', '0 = never require password change')}
                    </p>
                  </div>
                </div>
                <Button onClick={handleSessionSave} disabled={saveSessionMutation.isPending}>
                  <Save className="mr-2 h-4 w-4" />
                  {saveSessionMutation.isPending ? t('common.saving', 'Saving...') : t('common.save', 'Save')}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Order Field Configuration Card */}
        <Card>
          <CardHeader>
            <CardTitle>{t('settings.order_field_title', 'Order Number Field')}</CardTitle>
            <CardDescription>
              {t('settings.order_field_desc', 'Configure the order number field label, validation rules, and length constraints.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {orderLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-8 w-full" />
                ))}
              </div>
            ) : (
              <div className="space-y-6">
                {/* Labels */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="order_label_en">
                      {t('settings.order_label_en', 'Label (English)')}
                    </Label>
                    <Input
                      id="order_label_en"
                      value={orderForm.order_field_label_en}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_label_en: e.target.value })}
                      placeholder="Order Number"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="order_label_ru">
                      {t('settings.order_label_ru', 'Label (Russian)')}
                    </Label>
                    <Input
                      id="order_label_ru"
                      value={orderForm.order_field_label_ru}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_label_ru: e.target.value })}
                      placeholder="Номер заказа"
                    />
                  </div>
                </div>

                {/* Prompts */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="order_prompt_en">
                      {t('settings.order_prompt_en', 'Prompt (English)')}
                    </Label>
                    <Input
                      id="order_prompt_en"
                      value={orderForm.order_field_prompt_en}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_prompt_en: e.target.value })}
                      placeholder="Enter order number"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="order_prompt_ru">
                      {t('settings.order_prompt_ru', 'Prompt (Russian)')}
                    </Label>
                    <Input
                      id="order_prompt_ru"
                      value={orderForm.order_field_prompt_ru}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_prompt_ru: e.target.value })}
                      placeholder="Введите номер заказа"
                    />
                  </div>
                </div>

                {/* Character Type */}
                <div className="space-y-2">
                  <Label>{t('settings.order_char_type', 'Character Type')}</Label>
                  <Select
                    value={orderForm.order_field_char_type}
                    onValueChange={(v) => v && setOrderForm({ ...orderForm, order_field_char_type: v })}
                  >
                    <SelectTrigger className="w-full">
                      <span className="truncate">
                        {t(
                          charTypeOptions.find((o) => o.value === orderForm.order_field_char_type)?.labelKey ?? '',
                          orderForm.order_field_char_type
                        )}
                      </span>
                    </SelectTrigger>
                    <SelectContent>
                      {charTypeOptions.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>
                          {t(opt.labelKey, opt.value)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Custom Regex (conditional) */}
                {orderForm.order_field_char_type === 'custom' && (
                  <div className="space-y-2">
                    <Label htmlFor="order_custom_regex">
                      {t('settings.order_custom_regex', 'Custom Regex Pattern')}
                    </Label>
                    <Input
                      id="order_custom_regex"
                      value={orderForm.order_field_custom_regex}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_custom_regex: e.target.value })}
                      placeholder="/^[A-Z]{2}\d{4}$/"
                      className="font-mono text-sm"
                    />
                    <p className="text-xs text-muted-foreground">
                      {t('settings.order_custom_regex_hint', 'PHP regex with delimiters, e.g. /^[A-Z]{2}\\d{4}$/')}
                    </p>
                  </div>
                )}

                {/* Min/Max Length */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="order_min_length">
                      {t('settings.order_min_length', 'Minimum Length')}
                    </Label>
                    <Input
                      id="order_min_length"
                      type="number"
                      value={orderForm.order_field_min_length}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_min_length: e.target.value })}
                      min={1}
                      max={50}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="order_max_length">
                      {t('settings.order_max_length', 'Maximum Length')}
                    </Label>
                    <Input
                      id="order_max_length"
                      type="number"
                      value={orderForm.order_field_max_length}
                      onChange={(e) => setOrderForm({ ...orderForm, order_field_max_length: e.target.value })}
                      min={1}
                      max={50}
                    />
                  </div>
                </div>

                {/* Preview */}
                <div className="rounded-md border bg-muted/50 p-4 space-y-2">
                  <Label className="text-sm font-medium">
                    {t('settings.order_preview', 'Validation Preview')}
                  </Label>
                  <div className="text-sm font-mono text-muted-foreground break-all">
                    {previewPattern}
                  </div>
                  <div className="flex gap-4 text-xs">
                    <span className="text-green-600">
                      {t('settings.order_preview_valid', 'Valid')}: {examples.valid}
                    </span>
                    <span className="text-red-600">
                      {t('settings.order_preview_invalid', 'Invalid')}: {examples.invalid}
                    </span>
                  </div>
                </div>

                <Button onClick={handleOrderSave} disabled={saveOrderMutation.isPending}>
                  <Save className="mr-2 h-4 w-4" />
                  {saveOrderMutation.isPending
                    ? t('common.saving', 'Saving...')
                    : t('common.save', 'Save')}
                </Button>

                {saveOrderMutation.isError && (
                  <p className="text-sm text-destructive">
                    {(saveOrderMutation.error as Error)?.message ?? t('common.error', 'An error occurred')}
                  </p>
                )}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Alt Server Card */}
        <Card>
          <CardHeader>
            <CardTitle>{t('settings.alt_server_title', 'Alternative Server Settings')}</CardTitle>
            <CardDescription>
              {t('settings.alt_server_desc', 'Configure an alternative activation server for failover or secondary processing.')}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 6 }).map((_, i) => (
                  <Skeleton key={i} className="h-8 w-full" />
                ))}
              </div>
            ) : (
              <div className="space-y-6">
                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="alt_server_enabled">{t('settings.field_enabled', 'Enabled')}</Label>
                    <p className="text-xs text-muted-foreground">
                      {t('settings.field_enabled_desc', 'Enable or disable the alternative server.')}
                    </p>
                  </div>
                  <Switch
                    id="alt_server_enabled"
                    checked={form.alt_server_enabled}
                    onCheckedChange={(checked) => setForm({ ...form, alt_server_enabled: checked })}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="alt_server_script_path">{t('settings.field_script_path', 'Script Path')}</Label>
                  <Input
                    id="alt_server_script_path"
                    value={form.alt_server_script_path}
                    onChange={(e) => setForm({ ...form, alt_server_script_path: e.target.value })}
                    placeholder="C:\Scripts\activate.ps1"
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="alt_server_pre_command">{t('settings.field_pre_command', 'Pre-Command')}</Label>
                  <Input
                    id="alt_server_pre_command"
                    value={form.alt_server_pre_command}
                    onChange={(e) => setForm({ ...form, alt_server_pre_command: e.target.value })}
                    placeholder={t('settings.field_pre_command_placeholder', 'Command to run before script...')}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="alt_server_script_args">{t('settings.field_script_args', 'Script Arguments')}</Label>
                  <Input
                    id="alt_server_script_args"
                    value={form.alt_server_script_args}
                    onChange={(e) => setForm({ ...form, alt_server_script_args: e.target.value })}
                    placeholder={t('settings.field_script_args_placeholder', 'Additional arguments...')}
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>{t('settings.field_script_type', 'Script Type')}</Label>
                    <Select
                      value={form.alt_server_script_type}
                      onValueChange={(v) => v && setForm({ ...form, alt_server_script_type: v })}
                    >
                      <SelectTrigger className="w-full">
                        <span className="truncate">
                          {t(`settings.script_type_${form.alt_server_script_type}`, form.alt_server_script_type)}
                        </span>
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="cmd">{t('settings.script_type_cmd', 'CMD')}</SelectItem>
                        <SelectItem value="powershell">{t('settings.script_type_powershell', 'PowerShell')}</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="alt_server_timeout">{t('settings.field_timeout', 'Timeout (seconds)')}</Label>
                    <Input
                      id="alt_server_timeout"
                      type="number"
                      value={form.alt_server_timeout}
                      onChange={(e) => setForm({ ...form, alt_server_timeout: Number(e.target.value) })}
                      min={10}
                      max={600}
                    />
                  </div>
                </div>

                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="alt_server_prompt_technician">
                      {t('settings.field_prompt_technician', 'Prompt Technician')}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t('settings.field_prompt_technician_desc', 'Ask the technician before switching to the alt server.')}
                    </p>
                  </div>
                  <Switch
                    id="alt_server_prompt_technician"
                    checked={form.alt_server_prompt_technician}
                    onCheckedChange={(checked) =>
                      setForm({ ...form, alt_server_prompt_technician: checked })
                    }
                  />
                </div>

                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="alt_server_auto_failover">
                      {t('settings.field_auto_failover', 'Auto Failover')}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t('settings.field_auto_failover_desc', 'Automatically switch to alt server if the primary fails.')}
                    </p>
                  </div>
                  <Switch
                    id="alt_server_auto_failover"
                    checked={form.alt_server_auto_failover}
                    onCheckedChange={(checked) =>
                      setForm({ ...form, alt_server_auto_failover: checked })
                    }
                  />
                </div>

                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="alt_server_verify_activation">
                      {t('settings.field_verify_activation', 'Verify Activation')}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t('settings.field_verify_activation_desc', 'Verify activation result after alt server script runs.')}
                    </p>
                  </div>
                  <Switch
                    id="alt_server_verify_activation"
                    checked={form.alt_server_verify_activation}
                    onCheckedChange={(checked) =>
                      setForm({ ...form, alt_server_verify_activation: checked })
                    }
                  />
                </div>

                <Button onClick={handleSave} disabled={saveMutation.isPending}>
                  <Save className="mr-2 h-4 w-4" />
                  {saveMutation.isPending
                    ? t('common.saving', 'Saving...')
                    : t('common.save', 'Save')}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>

        {/* ── SMTP / Email Settings Card ────────────────────────── */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <Mail className="h-5 w-5 text-primary" />
              <div>
                <CardTitle>{t('settings.smtp_title', 'Email / SMTP')}</CardTitle>
                <CardDescription>
                  {t('settings.smtp_desc', 'Configure email delivery for notifications and alerts.')}
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            {smtpLoading ? (
              <div className="space-y-4">
                {Array.from({ length: 6 }).map((_, i) => (
                  <Skeleton key={i} className="h-8 w-full" />
                ))}
              </div>
            ) : (
              <div className="space-y-6">
                {/* Enable toggle */}
                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="smtp_enabled">
                      {t('settings.smtp_enabled', 'Enable Email Notifications')}
                    </Label>
                    <p className="text-xs text-muted-foreground">
                      {t('settings.smtp_enabled_desc', 'Send email alerts for activation failures, key exhaustion, etc.')}
                    </p>
                  </div>
                  <Switch
                    id="smtp_enabled"
                    checked={smtpForm.smtp_enabled}
                    onCheckedChange={(checked) => setSmtpForm({ ...smtpForm, smtp_enabled: checked })}
                  />
                </div>

                <Separator />

                {/* Provider Preset */}
                <div className="space-y-2">
                  <Label>{t('settings.smtp_provider', 'Provider Preset')}</Label>
                  <div className="flex flex-wrap gap-2">
                    {Object.keys(smtpPresets).map((key) => (
                      <Button
                        key={key}
                        variant={smtpForm.smtp_server === smtpPresets[key].server && smtpPresets[key].server !== '' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => applySmtpPreset(key)}
                      >
                        {key.charAt(0).toUpperCase() + key.slice(1)}
                      </Button>
                    ))}
                  </div>
                </div>

                {/* Server Settings */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                  <div className="space-y-2">
                    <Label htmlFor="smtp_server">{t('settings.smtp_server', 'SMTP Server')}</Label>
                    <Input
                      id="smtp_server"
                      value={smtpForm.smtp_server}
                      onChange={(e) => setSmtpForm({ ...smtpForm, smtp_server: e.target.value })}
                      placeholder="smtp.gmail.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="smtp_port">{t('settings.smtp_port', 'Port')}</Label>
                    <Input
                      id="smtp_port"
                      type="number"
                      value={smtpForm.smtp_port}
                      onChange={(e) => setSmtpForm({ ...smtpForm, smtp_port: Number(e.target.value) })}
                      min={1}
                      max={65535}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="smtp_encryption">{t('settings.smtp_encryption', 'Encryption')}</Label>
                    <Select
                      value={smtpForm.smtp_encryption}
                      onValueChange={(val) => val && setSmtpForm({ ...smtpForm, smtp_encryption: val })}
                    >
                      <SelectTrigger id="smtp_encryption">
                        {smtpForm.smtp_encryption === 'tls' ? 'STARTTLS' : smtpForm.smtp_encryption === 'ssl' ? 'SSL/TLS' : 'None'}
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="tls">STARTTLS (port 587)</SelectItem>
                        <SelectItem value="ssl">SSL/TLS (port 465)</SelectItem>
                        <SelectItem value="none">{t('settings.smtp_enc_none', 'None (not recommended)')}</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {/* Authentication */}
                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="smtp_auth">{t('settings.smtp_auth', 'Require Authentication')}</Label>
                    <p className="text-xs text-muted-foreground">
                      {t('settings.smtp_auth_desc', 'Most SMTP servers require username/password authentication.')}
                    </p>
                  </div>
                  <Switch
                    id="smtp_auth"
                    checked={smtpForm.smtp_auth}
                    onCheckedChange={(checked) => setSmtpForm({ ...smtpForm, smtp_auth: checked })}
                  />
                </div>

                {smtpForm.smtp_auth && (
                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                      <Label htmlFor="smtp_username">{t('settings.smtp_username', 'Username')}</Label>
                      <Input
                        id="smtp_username"
                        value={smtpForm.smtp_username}
                        onChange={(e) => setSmtpForm({ ...smtpForm, smtp_username: e.target.value })}
                        placeholder="your-email@gmail.com"
                        autoComplete="off"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="smtp_password">{t('settings.smtp_password', 'Password')}</Label>
                      <div className="relative">
                        <Input
                          id="smtp_password"
                          type={showSmtpPassword ? 'text' : 'password'}
                          value={smtpForm.smtp_password}
                          onChange={(e) => setSmtpForm({ ...smtpForm, smtp_password: e.target.value })}
                          placeholder={smtpData?.config?.smtp_password_set ? '(encrypted — leave blank to keep)' : 'App password or SMTP password'}
                          autoComplete="new-password"
                          className="pr-10"
                        />
                        <button
                          type="button"
                          onClick={() => setShowSmtpPassword(!showSmtpPassword)}
                          className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                          tabIndex={-1}
                        >
                          {showSmtpPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </button>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        {t('settings.smtp_password_hint', 'For Gmail, use an App Password. Stored encrypted at rest (AES-256-GCM).')}
                      </p>
                    </div>
                  </div>
                )}

                <Separator />

                {/* Sender / Recipient */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                  <div className="space-y-2">
                    <Label htmlFor="email_from">{t('settings.smtp_from', 'From Address')}</Label>
                    <Input
                      id="email_from"
                      type="email"
                      value={smtpForm.email_from}
                      onChange={(e) => setSmtpForm({ ...smtpForm, email_from: e.target.value })}
                      placeholder="notifications@company.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="email_from_name">{t('settings.smtp_from_name', 'From Name')}</Label>
                    <Input
                      id="email_from_name"
                      value={smtpForm.email_from_name}
                      onChange={(e) => setSmtpForm({ ...smtpForm, email_from_name: e.target.value })}
                      placeholder="KeyGate"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="email_to">{t('settings.smtp_to', 'Default Recipient')}</Label>
                    <Input
                      id="email_to"
                      type="email"
                      value={smtpForm.email_to}
                      onChange={(e) => setSmtpForm({ ...smtpForm, email_to: e.target.value })}
                      placeholder="admin@company.com"
                    />
                  </div>
                </div>

                <Separator />

                {/* Notification Triggers */}
                <div>
                  <Label className="text-base font-medium">{t('settings.smtp_triggers', 'Notification Triggers')}</Label>
                  <div className="mt-3 space-y-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <Label htmlFor="email_on_activation_fail">{t('settings.smtp_on_fail', 'Activation Failure')}</Label>
                        <p className="text-xs text-muted-foreground">{t('settings.smtp_on_fail_desc', 'Send alert when a key activation fails.')}</p>
                      </div>
                      <Switch
                        id="email_on_activation_fail"
                        checked={smtpForm.email_on_activation_fail}
                        onCheckedChange={(checked) => setSmtpForm({ ...smtpForm, email_on_activation_fail: checked })}
                      />
                    </div>
                    <div className="flex items-center justify-between">
                      <div>
                        <Label htmlFor="email_on_key_exhausted">{t('settings.smtp_on_exhausted', 'Keys Exhausted')}</Label>
                        <p className="text-xs text-muted-foreground">{t('settings.smtp_on_exhausted_desc', 'Send alert when available keys run out.')}</p>
                      </div>
                      <Switch
                        id="email_on_key_exhausted"
                        checked={smtpForm.email_on_key_exhausted}
                        onCheckedChange={(checked) => setSmtpForm({ ...smtpForm, email_on_key_exhausted: checked })}
                      />
                    </div>
                    <div className="flex items-center justify-between">
                      <div>
                        <Label htmlFor="email_on_daily_summary">{t('settings.smtp_on_summary', 'Daily Summary')}</Label>
                        <p className="text-xs text-muted-foreground">{t('settings.smtp_on_summary_desc', 'Send a daily digest of activation activity.')}</p>
                      </div>
                      <Switch
                        id="email_on_daily_summary"
                        checked={smtpForm.email_on_daily_summary}
                        onCheckedChange={(checked) => setSmtpForm({ ...smtpForm, email_on_daily_summary: checked })}
                      />
                    </div>
                  </div>
                </div>

                <Separator />

                {/* Actions: Save + Test */}
                <div className="flex flex-wrap items-end gap-4">
                  <Button onClick={handleSmtpSave} disabled={saveSmtpMutation.isPending}>
                    <Save className="mr-2 h-4 w-4" />
                    {saveSmtpMutation.isPending ? t('common.saving', 'Saving...') : t('common.save', 'Save')}
                  </Button>

                  <div className="flex items-end gap-2">
                    <div className="space-y-1">
                      <Label className="text-xs">{t('settings.smtp_test_recipient', 'Test Recipient')}</Label>
                      <Input
                        type="email"
                        value={testRecipient}
                        onChange={(e) => setTestRecipient(e.target.value)}
                        placeholder={smtpForm.email_to || 'admin@company.com'}
                        className="h-9 w-60"
                      />
                    </div>
                    <Button
                      variant="outline"
                      onClick={handleSmtpTest}
                      disabled={testSmtpMutation.isPending || (!smtpForm.smtp_server)}
                    >
                      <Send className="mr-2 h-4 w-4" />
                      {testSmtpMutation.isPending
                        ? t('settings.smtp_testing', 'Sending...')
                        : t('settings.smtp_test', 'Send Test Email')}
                    </Button>
                  </div>
                </div>

                {/* Test result feedback */}
                {testSmtpMutation.isSuccess && (
                  <div className="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-950/30 dark:text-green-400">
                    <CheckCircle2 className="h-4 w-4" />
                    {testSmtpMutation.data?.message || t('settings.smtp_test_ok', 'Test email sent successfully')}
                  </div>
                )}
                {testSmtpMutation.isError && (
                  <div className="flex items-center gap-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                    <XCircle className="h-4 w-4" />
                    {testSmtpMutation.error?.message || t('settings.smtp_test_fail', 'Test failed')}
                  </div>
                )}
              </div>
            )}
          </CardContent>
        </Card>

          </TabsContent>

          <TabsContent value="client" className="mt-4 space-y-4">
            <ClientConfigTab />
          </TabsContent>

        </Tabs>
      </div>
    </>
  )
}

// ── Client Configuration Tab ────────────────────────────────────

const CLIENT_CONFIG_DEFAULTS: ClientConfig = {
  client_task_wsus_cleanup: '1',
  client_task_security_hardening: '1',
  client_task_edrive_format: '1',
  client_task_ps7_install: '1',
  client_task_self_update: '1',
  client_activation_delay_seconds: '10',
  client_max_retry_attempts: '5',
  client_max_check_iterations: '6',
  client_check_delay_base: '5',
  client_net_threshold_1: '60',
  client_net_threshold_2: '100',
  client_net_threshold_3: '200',
  client_net_threshold_4: '400',
  client_net_threshold_5: '800',
  client_net_multiplier_1: '0.6',
  client_net_multiplier_2: '0.8',
  client_net_multiplier_3: '1.0',
  client_net_multiplier_4: '1.6',
  client_net_multiplier_5: '2.5',
  client_net_max_multiplier: '2.5',
  client_net_ping_samples: '3',
  client_net_test_endpoint_1: 'https://activation.sls.microsoft.com',
  client_net_test_endpoint_2: 'https://go.microsoft.com',
  client_net_test_endpoint_3: 'https://dns.msftncsi.com',
  client_max_keys_to_try: '3',
  client_key_exhaustion_action: 'failover',
  client_retry_cooldown_seconds: '60',
  client_network_error_retries: '4',
  client_network_reconnect_wait: '30',
  client_server_busy_delay: '30',
  client_skip_key_on_invalid: '1',
  client_skip_key_on_service_error: '0',
}

const TASK_TOGGLES = [
  { key: 'client_task_wsus_cleanup', labelKey: 'settings.task_wsus_cleanup', descKey: 'settings.task_wsus_cleanup_desc' },
  { key: 'client_task_security_hardening', labelKey: 'settings.task_security_hardening', descKey: 'settings.task_security_hardening_desc' },
  { key: 'client_task_edrive_format', labelKey: 'settings.task_edrive_format', descKey: 'settings.task_edrive_format_desc' },
  { key: 'client_task_ps7_install', labelKey: 'settings.task_ps7_install', descKey: 'settings.task_ps7_install_desc' },
  { key: 'client_task_self_update', labelKey: 'settings.task_self_update', descKey: 'settings.task_self_update_desc' },
] as const

function ClientConfigTab() {
  const { t } = useTranslation()
  const { data, isLoading } = useClientConfigSettings()
  const saveMut = useSaveClientConfigSettings()
  const [form, setForm] = useState<ClientConfig>(CLIENT_CONFIG_DEFAULTS)

  useEffect(() => {
    if (data?.config) setForm(data.config)
  }, [data])

  const update = (key: keyof ClientConfig, value: string) => {
    setForm(prev => ({ ...prev, [key]: value }))
  }

  const handleSave = () => saveMut.mutate(form)

  if (isLoading) {
    return <div className="space-y-4"><Skeleton className="h-48" /><Skeleton className="h-48" /><Skeleton className="h-48" /></div>
  }

  return (
    <>
      {/* Pre-Activation Tasks */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <ToggleLeft className="h-5 w-5 text-primary" />
            <div>
              <CardTitle>{t('settings.client_tasks_title', 'Pre-Activation Tasks')}</CardTitle>
              <CardDescription>{t('settings.client_tasks_desc', 'Toggle which tasks the launcher runs before activation.')}</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {TASK_TOGGLES.map(({ key, labelKey, descKey }) => (
            <div key={key} className="flex items-center justify-between py-2">
              <div>
                <Label className="text-sm font-medium">{t(labelKey)}</Label>
                <p className="text-xs text-muted-foreground">{t(descKey)}</p>
              </div>
              <Switch
                checked={form[key as keyof ClientConfig] === '1'}
                onCheckedChange={(checked) => update(key as keyof ClientConfig, checked ? '1' : '0')}
              />
            </div>
          ))}
          <Separator />
          <Button onClick={handleSave} disabled={saveMut.isPending} size="sm">
            <Save className="mr-1.5 h-4 w-4" />
            {t('common.save', 'Save')}
          </Button>
        </CardContent>
      </Card>

      {/* Activation Timing */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Clock className="h-5 w-5 text-primary" />
            <div>
              <CardTitle>{t('settings.client_timing_title', 'Activation Timing')}</CardTitle>
              <CardDescription>{t('settings.client_timing_desc', 'Configure base delays and retry behavior for activation.')}</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>{t('settings.activation_delay', 'Base Activation Delay (sec)')}</Label>
              <Input type="number" min={1} max={120} value={form.client_activation_delay_seconds} onChange={e => update('client_activation_delay_seconds', e.target.value)} />
            </div>
            <div>
              <Label>{t('settings.max_retries', 'Max Retry Attempts')}</Label>
              <Input type="number" min={1} max={20} value={form.client_max_retry_attempts} onChange={e => update('client_max_retry_attempts', e.target.value)} />
            </div>
            <div>
              <Label>{t('settings.max_check_iterations', 'Max Check Iterations')}</Label>
              <Input type="number" min={1} max={20} value={form.client_max_check_iterations} onChange={e => update('client_max_check_iterations', e.target.value)} />
            </div>
            <div>
              <Label>{t('settings.check_delay_base', 'Check Delay Base (sec)')}</Label>
              <Input type="number" min={1} max={60} value={form.client_check_delay_base} onChange={e => update('client_check_delay_base', e.target.value)} />
            </div>
          </div>
          <Separator className="my-4" />
          <Button onClick={handleSave} disabled={saveMut.isPending} size="sm">
            <Save className="mr-1.5 h-4 w-4" />
            {t('common.save', 'Save')}
          </Button>
        </CardContent>
      </Card>

      {/* Network Diagnostics */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <Wifi className="h-5 w-5 text-primary" />
            <div>
              <CardTitle>{t('settings.client_network_title', 'Network Diagnostics')}</CardTitle>
              <CardDescription>{t('settings.client_network_desc', 'Configure latency thresholds and multipliers for adaptive timing.')}</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {/* Threshold/Multiplier Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="py-2 pr-3">{t('settings.net_tier', 'Tier')}</th>
                  <th className="py-2 pr-3">{t('settings.net_threshold', 'Latency Threshold (ms)')}</th>
                  <th className="py-2">{t('settings.net_multiplier', 'Multiplier')}</th>
                </tr>
              </thead>
              <tbody>
                {[1, 2, 3, 4, 5].map(i => (
                  <tr key={i} className="border-b last:border-0">
                    <td className="py-2 pr-3 font-medium">{i}</td>
                    <td className="py-2 pr-3">
                      <Input type="number" min={1} className="w-28" value={form[`client_net_threshold_${i}` as keyof ClientConfig]} onChange={e => update(`client_net_threshold_${i}` as keyof ClientConfig, e.target.value)} />
                    </td>
                    <td className="py-2">
                      <Input type="number" step={0.1} min={0.1} max={10} className="w-28" value={form[`client_net_multiplier_${i}` as keyof ClientConfig]} onChange={e => update(`client_net_multiplier_${i}` as keyof ClientConfig, e.target.value)} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid grid-cols-2 gap-4 mt-4">
            <div>
              <Label>{t('settings.net_max_multiplier', 'Max Multiplier')}</Label>
              <Input type="number" step={0.1} min={0.1} max={10} value={form.client_net_max_multiplier} onChange={e => update('client_net_max_multiplier', e.target.value)} />
            </div>
            <div>
              <Label>{t('settings.net_ping_samples', 'Ping Samples')}</Label>
              <Input type="number" min={1} max={10} value={form.client_net_ping_samples} onChange={e => update('client_net_ping_samples', e.target.value)} />
            </div>
          </div>

          <Separator className="my-4" />

          <div className="space-y-3">
            {[1, 2, 3].map(i => (
              <div key={i}>
                <Label>{t('settings.net_test_endpoint', 'Test Endpoint')} {i}</Label>
                <Input value={form[`client_net_test_endpoint_${i}` as keyof ClientConfig]} onChange={e => update(`client_net_test_endpoint_${i}` as keyof ClientConfig, e.target.value)} placeholder="https://..." />
              </div>
            ))}
          </div>

          <Separator className="my-4" />
          <Button onClick={handleSave} disabled={saveMut.isPending} size="sm">
            <Save className="mr-1.5 h-4 w-4" />
            {t('common.save', 'Save')}
          </Button>
        </CardContent>
      </Card>

      {/* Key Retry & Fallback */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <KeyRound className="h-5 w-5 text-primary" />
            <div>
              <CardTitle>{t('settings.client_retry_title', 'Key Retry & Fallback')}</CardTitle>
              <CardDescription>{t('settings.client_retry_desc', 'Configure how the system handles activation failures and key exhaustion.')}</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>{t('settings.max_keys_to_try', 'Max Keys to Try')}</Label>
              <Input type="number" min={1} max={10} value={form.client_max_keys_to_try} onChange={e => update('client_max_keys_to_try', e.target.value)} />
              <p className="text-xs text-muted-foreground mt-1">{t('settings.max_keys_to_try_desc', 'Number of different keys to request before giving up')}</p>
            </div>
            <div>
              <Label>{t('settings.key_exhaustion_action', 'When All Keys Fail')}</Label>
              <select
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                value={form.client_key_exhaustion_action}
                onChange={e => update('client_key_exhaustion_action', e.target.value)}
              >
                <option value="stop">{t('settings.exhaustion_stop', 'Stop with error')}</option>
                <option value="failover">{t('settings.exhaustion_failover', 'Auto-failover to alt server')}</option>
                <option value="retry_loop">{t('settings.exhaustion_retry_loop', 'Retry from beginning after cooldown')}</option>
              </select>
            </div>
            <div>
              <Label>{t('settings.retry_cooldown', 'Retry Cooldown (sec)')}</Label>
              <Input type="number" min={10} max={600} value={form.client_retry_cooldown_seconds} onChange={e => update('client_retry_cooldown_seconds', e.target.value)} />
              <p className="text-xs text-muted-foreground mt-1">{t('settings.retry_cooldown_desc', 'Wait time before retry_loop restarts')}</p>
            </div>
            <div>
              <Label>{t('settings.server_busy_delay', 'Server Busy Delay (sec)')}</Label>
              <Input type="number" min={5} max={300} value={form.client_server_busy_delay} onChange={e => update('client_server_busy_delay', e.target.value)} />
              <p className="text-xs text-muted-foreground mt-1">{t('settings.server_busy_delay_desc', 'Wait when Microsoft servers are throttling')}</p>
            </div>
          </div>

          <Separator className="my-4" />

          <h4 className="text-sm font-medium mb-3">{t('settings.error_strategies', 'Error-Specific Strategies')}</h4>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label>{t('settings.network_error_retries', 'Network Error Extra Retries')}</Label>
              <Input type="number" min={0} max={10} value={form.client_network_error_retries} onChange={e => update('client_network_error_retries', e.target.value)} />
              <p className="text-xs text-muted-foreground mt-1">{t('settings.network_error_retries_desc', 'Extra reconnection attempts on network errors')}</p>
            </div>
            <div>
              <Label>{t('settings.network_reconnect_wait', 'Reconnect Wait (sec)')}</Label>
              <Input type="number" min={5} max={120} value={form.client_network_reconnect_wait} onChange={e => update('client_network_reconnect_wait', e.target.value)} />
              <p className="text-xs text-muted-foreground mt-1">{t('settings.network_reconnect_wait_desc', 'Wait between internet reconnection checks')}</p>
            </div>
          </div>

          <div className="space-y-4 mt-4">
            <div className="flex items-center justify-between py-2">
              <div>
                <Label className="text-sm font-medium">{t('settings.skip_key_on_invalid', 'Skip Key on Invalid Error')}</Label>
                <p className="text-xs text-muted-foreground">{t('settings.skip_key_on_invalid_desc', 'Immediately try next key when current key is blocked/invalid')}</p>
              </div>
              <Switch
                checked={form.client_skip_key_on_invalid === '1'}
                onCheckedChange={(checked) => update('client_skip_key_on_invalid', checked ? '1' : '0')}
              />
            </div>
            <div className="flex items-center justify-between py-2">
              <div>
                <Label className="text-sm font-medium">{t('settings.skip_key_on_service', 'Skip Key on Service Error')}</Label>
                <p className="text-xs text-muted-foreground">{t('settings.skip_key_on_service_desc', 'Try next key instead of retrying on Windows service errors')}</p>
              </div>
              <Switch
                checked={form.client_skip_key_on_service_error === '1'}
                onCheckedChange={(checked) => update('client_skip_key_on_service_error', checked ? '1' : '0')}
              />
            </div>
          </div>

          <Separator className="my-4" />
          <Button onClick={handleSave} disabled={saveMut.isPending} size="sm">
            <Save className="mr-1.5 h-4 w-4" />
            {t('common.save', 'Save')}
          </Button>
        </CardContent>
      </Card>
    </>
  )
}
