import { createContext, useContext, useEffect, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getBranding, type BrandingConfig } from '@/api/branding'
import { getPublicBranding } from '@/api/auth'
import { useAuth } from '@/hooks/use-auth'

interface BrandingContextValue {
  companyName: string
  appVersion: string
  logoUrl: string
  faviconUrl: string
  loginTitle: string
  loginSubtitle: string
  primaryColor: string
  sidebarColor: string
  accentColor: string
  isLoaded: boolean
}

const defaultBranding: BrandingContextValue = {
  companyName: '',
  appVersion: '',
  logoUrl: '',
  faviconUrl: '',
  loginTitle: '',
  loginSubtitle: '',
  primaryColor: '',
  sidebarColor: '',
  accentColor: '',
  isLoaded: false,
}

const BrandingContext = createContext<BrandingContextValue>(defaultBranding)

function resolveAssetUrl(path: string): string {
  if (!path) return ''
  if (path.startsWith('http')) return path
  // Resolve relative to the backend base
  return `/activate/${path}`
}

export function BrandingProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth()
  const isAuthenticated = !!user

  // When authenticated, use the full branding endpoint (requires session)
  const { data: authData } = useQuery({
    queryKey: ['branding'],
    queryFn: () => getBranding(),
    staleTime: 10 * 60 * 1000, // 10 min cache
    enabled: isAuthenticated,
    retry: false,
  })

  // When NOT authenticated, use the public branding endpoint (no auth required)
  // This allows the login page to display custom branding
  const { data: publicData } = useQuery({
    queryKey: ['branding', 'public'],
    queryFn: () => getPublicBranding(),
    staleTime: 10 * 60 * 1000,
    enabled: !isAuthenticated,
    retry: false,
  })

  const config: BrandingConfig | undefined = authData?.config ?? publicData?.config

  // Apply custom CSS variables
  useEffect(() => {
    const root = document.documentElement
    if (config?.brand_primary_color) {
      root.style.setProperty('--brand-primary', config.brand_primary_color)
    } else {
      root.style.removeProperty('--brand-primary')
    }
    if (config?.brand_sidebar_color) {
      root.style.setProperty('--brand-sidebar', config.brand_sidebar_color)
    } else {
      root.style.removeProperty('--brand-sidebar')
    }
    if (config?.brand_accent_color) {
      root.style.setProperty('--brand-accent', config.brand_accent_color)
    } else {
      root.style.removeProperty('--brand-accent')
    }
  }, [config?.brand_primary_color, config?.brand_sidebar_color, config?.brand_accent_color])

  // Update favicon
  useEffect(() => {
    if (!config?.brand_favicon_path) return
    const url = resolveAssetUrl(config.brand_favicon_path)
    const link = document.querySelector("link[rel~='icon']") as HTMLLinkElement | null
    if (link) {
      link.href = url
    } else {
      const newLink = document.createElement('link')
      newLink.rel = 'icon'
      newLink.href = url
      document.head.appendChild(newLink)
    }
  }, [config?.brand_favicon_path])

  // Update document title
  useEffect(() => {
    if (config?.brand_company_name) {
      document.title = config.brand_company_name
    }
  }, [config?.brand_company_name])

  const value = useMemo<BrandingContextValue>(() => ({
    companyName: config?.brand_company_name || '',
    appVersion: config?.brand_app_version || '',
    logoUrl: resolveAssetUrl(config?.brand_logo_path || ''),
    faviconUrl: resolveAssetUrl(config?.brand_favicon_path || ''),
    loginTitle: config?.brand_login_title || '',
    loginSubtitle: config?.brand_login_subtitle || '',
    primaryColor: config?.brand_primary_color || '',
    sidebarColor: config?.brand_sidebar_color || '',
    accentColor: config?.brand_accent_color || '',
    isLoaded: !!config,
  }), [config])

  return (
    <BrandingContext.Provider value={value}>
      {children}
    </BrandingContext.Provider>
  )
}

export function useBrandingContext() {
  return useContext(BrandingContext)
}
