import { apiGet, apiPostJson } from './client'

export interface NotificationRow {
  id: number
  category: string
  title_key: string
  body: string
  action_url: string | null
  is_read: boolean
  created_at: string
}

export interface GetNotificationsResponse {
  success: boolean
  notifications: NotificationRow[]
  unread_count: number
}

export interface PushPreferences {
  security: boolean
  keys: boolean
  technicians: boolean
  system: boolean
  devices: boolean
  activation: boolean
}

export interface GetPushPreferencesResponse {
  success: boolean
  preferences: PushPreferences
}

export function getNotifications() {
  return apiGet<GetNotificationsResponse>('get_notifications')
}

export function markNotificationsRead(ids?: number[]) {
  return apiPostJson<{ success: boolean; error?: string }>('mark_notifications_read', ids ? { ids } : undefined)
}

export function getPushPreferences() {
  return apiGet<GetPushPreferencesResponse>('get_push_preferences')
}

export function savePushPreferences(prefs: PushPreferences) {
  return apiPostJson<{ success: boolean; error?: string }>('save_push_preferences', {
    preferences: prefs,
  })
}
