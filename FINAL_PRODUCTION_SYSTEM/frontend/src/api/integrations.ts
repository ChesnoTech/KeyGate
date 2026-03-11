import { apiGet, apiPostJson } from './client'

export interface IntegrationEventCounts {
  total: number
  failed: number
  pending: number
}

export interface IntegrationEvent {
  id: number
  event_type: string
  status: 'pending' | 'sent' | 'failed' | 'skipped'
  response_code: number | null
  error_message: string | null
  created_at: string
  processed_at: string | null
}

export interface IntegrationRow {
  id: number
  integration_key: string
  display_name: string
  description: string
  integration_type: 'webhook' | 'api_sync' | 'plugin'
  enabled: boolean
  config: Record<string, unknown>
  status: 'disconnected' | 'connected' | 'error'
  last_sync_at: string | null
  last_error: string | null
  created_at: string
  updated_at: string
  event_counts?: IntegrationEventCounts
  recent_events?: IntegrationEvent[]
}

export interface ListIntegrationsResponse {
  success: boolean
  integrations: IntegrationRow[]
}

export interface GetIntegrationResponse {
  success: boolean
  integration: IntegrationRow
}

export function listIntegrations() {
  return apiGet<ListIntegrationsResponse>('list_integrations')
}

export function getIntegration(key: string) {
  return apiGet<GetIntegrationResponse>('get_integration', { integration_key: key })
}

export function saveIntegration(data: {
  integration_key: string
  enabled?: boolean
  config?: Record<string, unknown>
}) {
  return apiPostJson<{ success: boolean; error?: string }>('save_integration', data)
}

export function testIntegration(key: string) {
  return apiPostJson<{ success: boolean; message?: string; error?: string }>(
    'test_integration',
    { integration_key: key }
  )
}

export function retryIntegrationEvents(key: string) {
  return apiPostJson<{ success: boolean; retried?: number; succeeded?: number; error?: string }>(
    'retry_integration_events',
    { integration_key: key }
  )
}
