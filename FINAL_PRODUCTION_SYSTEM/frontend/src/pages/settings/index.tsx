import { useState, useEffect, useMemo, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Save, Upload, Trash2, RotateCcw, Palette } from 'lucide-react'
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
import {
  useAltServerSettings,
  useSaveAltServerSettings,
  useOrderFieldSettings,
  useSaveOrderFieldSettings,
} from '@/hooks/use-settings'
import {
  useBranding,
  useSaveBranding,
  useUploadBrandAsset,
  useDeleteBrandAsset,
} from '@/hooks/use-branding'
import type { AltServerConfig, OrderFieldConfig } from '@/api/settings'
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
    brand_company_name: 'OEM Activation',
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

  return (
    <>
      <AppHeader title={t('nav.settings', 'Settings')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <h2 className="text-2xl font-bold tracking-tight">{t('nav.settings', 'Settings')}</h2>

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
                      placeholder="OEM Activation"
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
                      placeholder={t('login.subtitle', 'OEM Activation System')}
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
      </div>
    </>
  )
}
