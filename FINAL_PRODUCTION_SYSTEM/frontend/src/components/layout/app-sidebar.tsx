import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  LayoutDashboard,
  Key,
  Users,
  History,
  Usb,
  Shield,
  Network,
  UserCog,
  ClipboardCheck,
  ShieldCheck,
  Boxes,
  Settings,
  Database,
  ScrollText,
  Bell,
  Sun,
  Moon,
  Monitor,
  LogOut,
  Languages,
  Plug2,
  Download,
  ArrowUpCircle,
  KeyRound,
} from 'lucide-react'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from '@/components/ui/sidebar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { useAuth } from '@/hooks/use-auth'
import { useTheme } from '@/components/theme-provider'
import { useBrandingContext } from '@/components/branding-provider'
import { switchLanguage, LANGUAGE_META } from '@/i18n/config'
import { useLanguageSettings } from '@/hooks/use-settings'

const navGroups = [
  {
    labelKey: 'sidebar.overview',
    items: [
      { path: '/', labelKey: 'nav.dashboard', icon: LayoutDashboard },
    ],
  },
  {
    labelKey: 'sidebar.operations',
    items: [
      { path: '/keys', labelKey: 'nav.keys', icon: Key },
      { path: '/technicians', labelKey: 'nav.technicians', icon: Users },
      { path: '/history', labelKey: 'nav.history', icon: History },
      { path: '/devices', labelKey: 'nav.usb_devices', icon: Usb },
    ],
  },
  {
    labelKey: 'sidebar.quality_control',
    items: [
      { path: '/compliance', labelKey: 'nav.compliance', icon: ClipboardCheck, minRole: 'admin' as const },
      { path: '/compliance/results', labelKey: 'nav.compliance_results', icon: ShieldCheck },
      { path: '/product-lines', labelKey: 'nav.product_lines', icon: Boxes, minRole: 'admin' as const },
      { path: '/task-pipeline', labelKey: 'nav.task_pipeline', icon: Play, minRole: 'admin' as const },
    ],
  },
  {
    labelKey: 'sidebar.security',
    items: [
      { path: '/2fa', labelKey: 'nav.2fa_settings', icon: Shield, minRole: 'admin' as const },
      { path: '/networks', labelKey: 'nav.trusted_networks', icon: Network, minRole: 'super_admin' as const },
      { path: '/roles', labelKey: 'nav.roles', icon: UserCog, minRole: 'super_admin' as const },
    ],
  },
  {
    labelKey: 'sidebar.system',
    items: [
      { path: '/settings', labelKey: 'nav.settings', icon: Settings, minRole: 'admin' as const },
      { path: '/integrations', labelKey: 'nav.integrations', icon: Plug2, minRole: 'admin' as const },
      { path: '/downloads', labelKey: 'nav.downloads', icon: Download, minRole: 'admin' as const },
      { path: '/subscription', labelKey: 'nav.subscription', icon: KeyRound, minRole: 'super_admin' as const },
      { path: '/system-upgrade', labelKey: 'nav.system_upgrade', icon: ArrowUpCircle, minRole: 'super_admin' as const },
      { path: '/backups', labelKey: 'nav.backups', icon: Database, minRole: 'super_admin' as const },
      { path: '/logs', labelKey: 'nav.logs', icon: ScrollText },
      { path: '/notifications', labelKey: 'nav.notifications', icon: Bell },
    ],
  },
]

export function AppSidebar() {
  const { t, i18n } = useTranslation()
  const { pathname } = useLocation()
  const navigate = useNavigate()
  const { user, logout, isSuperAdmin, isAdmin } = useAuth()
  const { theme, setTheme } = useTheme()
  const branding = useBrandingContext()

  function canSeeItem(item: { minRole?: 'admin' | 'super_admin' }): boolean {
    if (!item.minRole) return true
    if (item.minRole === 'super_admin') return isSuperAdmin
    if (item.minRole === 'admin') return isAdmin
    return false
  }

  const langSettings = useLanguageSettings()
  const enabledLangs = langSettings.data?.config?.enabled_languages ?? ['en', 'ru']

  const ThemeIcon = theme === 'dark' ? Moon : theme === 'light' ? Sun : Monitor

  return (
    <Sidebar>
      <SidebarHeader className="p-4">
        <div className="flex items-center gap-2">
          {branding.logoUrl ? (
            <img src={branding.logoUrl} alt="" className="h-6 w-6 object-contain" />
          ) : (
            <Key className="h-6 w-6 text-primary" />
          )}
          <div className="flex flex-col">
            <span className="text-sm font-semibold">
              {branding.companyName || t('common.app_name', 'KeyGate')}
            </span>
            <span className="text-xs text-muted-foreground">
              {branding.appVersion || t('common.app_version', 'v2.1.0')}
            </span>
          </div>
        </div>
      </SidebarHeader>

      <SidebarContent>
        {navGroups.map((group) => {
          const visibleItems = group.items.filter(canSeeItem)
          if (visibleItems.length === 0) return null

          return (
            <SidebarGroup key={group.labelKey}>
              <SidebarGroupLabel>{t(group.labelKey, group.labelKey.split('.')[1])}</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {visibleItems.map((item) => (
                    <SidebarMenuItem key={item.path}>
                      <SidebarMenuButton
                        isActive={pathname === item.path}
                        onClick={() => navigate(item.path)}
                      >
                        <item.icon className="h-4 w-4" />
                        <span>{t(item.labelKey)}</span>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          )
        })}
      </SidebarContent>

      <SidebarFooter className="p-2">
        <div className="text-center py-1">
          <span className="text-[9px] text-muted-foreground/60 tracking-wide">Powered by KeyGate</span>
        </div>
        <Separator className="mb-2" />
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-1">
            <DropdownMenu>
              <DropdownMenuTrigger className="inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground h-7 w-7">
                <ThemeIcon className="h-3.5 w-3.5" />
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start">
                <DropdownMenuItem onClick={() => setTheme('light')}>
                  <Sun className="mr-2 h-4 w-4" /> {t('theme.light', 'Light')}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => setTheme('dark')}>
                  <Moon className="mr-2 h-4 w-4" /> {t('theme.dark', 'Dark')}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => setTheme('system')}>
                  <Monitor className="mr-2 h-4 w-4" /> {t('theme.system', 'System')}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
              <DropdownMenuTrigger className="inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground h-7 w-7">
                <Languages className="h-3.5 w-3.5" />
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start">
                {enabledLangs.map(code => {
                  const meta = LANGUAGE_META[code]
                  if (!meta) return null
                  return (
                    <DropdownMenuItem
                      key={code}
                      onClick={() => switchLanguage(code)}
                      className={i18n.language === code ? 'bg-accent' : ''}
                    >
                      <span className="mr-2 text-xs font-mono w-5">{code.toUpperCase()}</span>
                      {meta.nativeName}
                    </DropdownMenuItem>
                  )
                })}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>

          <div className="flex items-center gap-2">
            <div className="flex flex-col items-end">
              <span className="text-xs font-medium leading-tight">{user?.full_name}</span>
              <span className="text-[10px] text-muted-foreground leading-tight">{user?.role}</span>
            </div>
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              title={t('nav.logout', 'Logout')}
              onClick={() => logout().then(() => navigate('/login'))}
            >
              <LogOut className="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>
      </SidebarFooter>
    </Sidebar>
  )
}
