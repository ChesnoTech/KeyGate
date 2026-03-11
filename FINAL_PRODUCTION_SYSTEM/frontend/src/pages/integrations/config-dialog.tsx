import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Separator } from '@/components/ui/separator'
import { Play } from 'lucide-react'
import { useIntegration, useSaveIntegration, useTestIntegration } from '@/hooks/use-integrations'

interface IntegrationConfigDialogProps {
  integrationKey: string | null
  onOpenChange: (open: boolean) => void
}

export function IntegrationConfigDialog({ integrationKey, onOpenChange }: IntegrationConfigDialogProps) {
  const { t } = useTranslation()
  const { data } = useIntegration(integrationKey)
  const saveMutation = useSaveIntegration()
  const testMutation = useTestIntegration()

  const [config, setConfig] = useState<Record<string, unknown>>({})
  const [enabled, setEnabled] = useState(false)

  useEffect(() => {
    if (data?.integration) {
      setConfig(data.integration.config || {})
      setEnabled(!!data.integration.enabled)
    }
  }, [data])

  const handleSave = () => {
    if (!integrationKey) return
    saveMutation.mutate(
      { integration_key: integrationKey, enabled, config },
      { onSuccess: () => onOpenChange(false) }
    )
  }

  const updateField = (key: string, value: unknown) => {
    setConfig((prev) => ({ ...prev, [key]: value }))
  }

  const intg = data?.integration

  return (
    <Dialog open={!!integrationKey} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {t('integrations.configure_title', 'Configure {{name}}', { name: intg?.display_name || '' })}
          </DialogTitle>
          <DialogDescription>
            {intg?.description}
          </DialogDescription>
        </DialogHeader>

        {integrationKey === 'osticket' && (
          <OsTicketConfigFields config={config} updateField={updateField} />
        )}
        {integrationKey === '1c_erp' && (
          <OneCConfigFields config={config} updateField={updateField} />
        )}

        <Separator />

        <div className="flex items-center justify-between">
          <Label>{t('integrations.enabled', 'Enabled')}</Label>
          <Switch checked={enabled} onCheckedChange={setEnabled} />
        </div>

        <DialogFooter className="gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => testMutation.mutate(integrationKey!)}
            disabled={testMutation.isPending || !integrationKey}
          >
            <Play className="mr-1.5 h-3.5 w-3.5" />
            {t('integrations.test_connection', 'Test Connection')}
          </Button>
          <div className="flex-1" />
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel', 'Cancel')}
          </Button>
          <Button onClick={handleSave} disabled={saveMutation.isPending}>
            {saveMutation.isPending ? t('common.saving', 'Saving...') : t('common.save', 'Save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function OsTicketConfigFields({
  config,
  updateField,
}: {
  config: Record<string, unknown>
  updateField: (key: string, value: unknown) => void
}) {
  const { t } = useTranslation()
  return (
    <div className="space-y-4">
      <div className="space-y-2">
        <Label>{t('integrations.ost_base_url', 'osTicket URL')}</Label>
        <Input
          value={String(config.base_url || '')}
          onChange={(e) => updateField('base_url', e.target.value)}
          placeholder="https://support.example.com"
        />
      </div>
      <div className="space-y-2">
        <Label>{t('integrations.ost_api_key', 'API Key')}</Label>
        <Input
          type="password"
          value={String(config.api_key || '')}
          onChange={(e) => updateField('api_key', e.target.value)}
          placeholder="API-KEY-HERE"
        />
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label>{t('integrations.ost_department_id', 'Department ID')}</Label>
          <Input
            value={String(config.department_id || '')}
            onChange={(e) => updateField('department_id', e.target.value)}
            placeholder="1"
          />
        </div>
        <div className="space-y-2">
          <Label>{t('integrations.ost_topic_id', 'Topic ID')}</Label>
          <Input
            value={String(config.topic_id || '')}
            onChange={(e) => updateField('topic_id', e.target.value)}
            placeholder="1"
          />
        </div>
      </div>
      <div className="space-y-2">
        <Label>{t('integrations.ost_subject_template', 'Ticket Subject Template')}</Label>
        <Input
          value={String(config.ticket_subject_template || 'Build Order #{order_number}')}
          onChange={(e) => updateField('ticket_subject_template', e.target.value)}
        />
        <p className="text-xs text-muted-foreground">
          {t('integrations.ost_template_hint', 'Use {order_number} as placeholder')}
        </p>
      </div>
      <Separator />
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <Label className="text-sm">{t('integrations.ost_auto_create', 'Auto-create ticket on key assignment')}</Label>
          <Switch
            checked={!!config.auto_create_ticket}
            onCheckedChange={(v) => updateField('auto_create_ticket', v)}
          />
        </div>
        <div className="flex items-center justify-between">
          <Label className="text-sm">{t('integrations.ost_auto_reply', 'Auto-reply on activation complete')}</Label>
          <Switch
            checked={!!config.auto_reply_on_activation}
            onCheckedChange={(v) => updateField('auto_reply_on_activation', v)}
          />
        </div>
        <div className="flex items-center justify-between">
          <Label className="text-sm">{t('integrations.ost_include_hw', 'Include hardware details')}</Label>
          <Switch
            checked={!!config.include_hardware_details}
            onCheckedChange={(v) => updateField('include_hardware_details', v)}
          />
        </div>
      </div>
    </div>
  )
}

function OneCConfigFields({
  config,
  updateField,
}: {
  config: Record<string, unknown>
  updateField: (key: string, value: unknown) => void
}) {
  const { t } = useTranslation()
  return (
    <div className="space-y-4">
      <div className="space-y-2">
        <Label>{t('integrations.onec_base_url', '1C Server URL')}</Label>
        <Input
          value={String(config.base_url || '')}
          onChange={(e) => updateField('base_url', e.target.value)}
          placeholder="http://1c-server:8080/oem_integration"
        />
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label>{t('integrations.onec_username', 'Username')}</Label>
          <Input
            value={String(config.username || '')}
            onChange={(e) => updateField('username', e.target.value)}
          />
        </div>
        <div className="space-y-2">
          <Label>{t('integrations.onec_password', 'Password')}</Label>
          <Input
            type="password"
            value={String(config.password || '')}
            onChange={(e) => updateField('password', e.target.value)}
          />
        </div>
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label>{t('integrations.onec_endpoint_act', 'Activations Endpoint')}</Label>
          <Input
            value={String(config.endpoint_activations || '/api/hs/activations')}
            onChange={(e) => updateField('endpoint_activations', e.target.value)}
          />
        </div>
        <div className="space-y-2">
          <Label>{t('integrations.onec_endpoint_inv', 'Inventory Endpoint')}</Label>
          <Input
            value={String(config.endpoint_inventory || '/api/hs/inventory')}
            onChange={(e) => updateField('endpoint_inventory', e.target.value)}
          />
        </div>
      </div>
      <Separator />
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <Label className="text-sm">{t('integrations.onec_push_act', 'Push activations to 1C')}</Label>
          <Switch
            checked={!!config.push_activations}
            onCheckedChange={(v) => updateField('push_activations', v)}
          />
        </div>
        <div className="flex items-center justify-between">
          <Label className="text-sm">{t('integrations.onec_push_keys', 'Push key usage to 1C')}</Label>
          <Switch
            checked={!!config.push_key_usage}
            onCheckedChange={(v) => updateField('push_key_usage', v)}
          />
        </div>
        <div className="flex items-center justify-between">
          <Label className="text-sm">{t('integrations.onec_pull_inv', 'Pull inventory from 1C')}</Label>
          <Switch
            checked={!!config.pull_inventory}
            onCheckedChange={(v) => updateField('pull_inventory', v)}
          />
          <span className="text-xs text-muted-foreground ml-2">{t('common.coming_soon', 'Coming soon')}</span>
        </div>
      </div>
    </div>
  )
}
