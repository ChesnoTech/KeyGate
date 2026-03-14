import { useState, useEffect, useCallback, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { useTranslation } from 'react-i18next'
import { AuthContext, type AuthContextValue } from '@/hooks/use-auth'
import { checkSession, login as apiLogin, logout as apiLogout } from '@/api/auth'
import { setCsrfToken, setSessionExpiredHandler } from '@/api/client'
import type { AdminUser, SessionInfo } from '@/types/api'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AdminUser | null>(null)
  const [permissions, setPermissions] = useState<Record<string, boolean>>({})
  const [isLoading, setIsLoading] = useState(true)
  const navigate = useNavigate()
  const { t } = useTranslation()

  // Register global 401 handler — clears state, redirects to login with toast
  useEffect(() => {
    setSessionExpiredHandler(() => {
      setUser(null)
      setPermissions({})
      navigate('/login', { replace: true })
      toast.error(t('auth.session_expired', 'Session expired. Please log in again.'))
    })
  }, [navigate, t])

  const applySession = useCallback((session: SessionInfo) => {
    if (session.authenticated && session.user) {
      setUser(session.user)
      setPermissions(session.permissions ?? {})
      if (session.csrf_token) {
        setCsrfToken(session.csrf_token)
      }
    }
  }, [])

  useEffect(() => {
    checkSession()
      .then(applySession)
      .catch(() => {})
      .finally(() => setIsLoading(false))
  }, [applySession])

  const login = useCallback(async (username: string, password: string) => {
    const result = await apiLogin(username, password)
    if (result.success) {
      // New JSON login returns user, permissions, and csrf_token directly
      // — no second check_session roundtrip needed
      if (result.user) {
        setUser(result.user)
      }
      if (result.permissions) {
        setPermissions(result.permissions)
      }
      if (result.csrf_token) {
        setCsrfToken(result.csrf_token)
      }
    }
    return result
  }, [])

  const logout = useCallback(async () => {
    await apiLogout()
    setUser(null)
    setPermissions({})
  }, [])

  const hasPermission = useCallback(
    (permission: string) => {
      if (!user) return false
      if (user.role === 'super_admin') return true
      return permissions[permission] === true
    },
    [user, permissions]
  )

  const value: AuthContextValue = {
    user,
    isLoading,
    isAuthenticated: !!user,
    login,
    logout,
    hasPermission,
    isSuperAdmin: user?.role === 'super_admin',
    isAdmin: user?.role === 'admin' || user?.role === 'super_admin',
  }

  return <AuthContext value={value}>{children}</AuthContext>
}
