import type { SessionInfo } from '@/types/api'
import type { BrandingConfig } from './branding'

const API_BASE = '/activate/admin_v2.php'

export async function checkSession(): Promise<SessionInfo> {
  const res = await fetch(`${API_BASE}?action=check_session`, {
    credentials: 'include',
  })
  if (!res.ok) {
    return { authenticated: false }
  }
  return res.json()
}

export interface LoginResponse {
  success: boolean
  error?: string
  error_code?: string
  user?: SessionInfo['user']
  permissions?: Record<string, boolean>
  csrf_token?: string
}

export async function login(username: string, password: string): Promise<LoginResponse> {
  const res = await fetch(`${API_BASE}?action=admin_login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
    credentials: 'include',
  })

  // Always returns JSON — no more HTML regex parsing
  const data: LoginResponse = await res.json()
  return data
}

export async function logout(): Promise<void> {
  await fetch('/activate/secure-admin.php?logout=1', {
    credentials: 'include',
  })
}

/** Fetch branding without auth — for login page display */
export async function getPublicBranding(): Promise<{ success: boolean; config: BrandingConfig } | null> {
  try {
    const res = await fetch(`${API_BASE}?action=get_public_branding`, {
      credentials: 'include',
    })
    if (!res.ok) return null
    return res.json()
  } catch {
    return null
  }
}
