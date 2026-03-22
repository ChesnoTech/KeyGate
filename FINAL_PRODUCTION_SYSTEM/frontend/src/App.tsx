import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider } from '@/components/auth-provider'
import { ThemeProvider } from '@/components/theme-provider'
import { BrandingProvider } from '@/components/branding-provider'
import { ProtectedRoute } from '@/components/layout/protected-route'
import { RequirePermission } from '@/components/layout/require-permission'
import { Skeleton } from '@/components/ui/skeleton'
import { Toaster } from 'sonner'
import { LoginPage } from '@/pages/login'

const DashboardPage = lazy(() => import('@/pages/dashboard').then(m => ({ default: m.DashboardPage })))
const KeysPage = lazy(() => import('@/pages/keys').then(m => ({ default: m.KeysPage })))
const TechniciansPage = lazy(() => import('@/pages/technicians').then(m => ({ default: m.TechniciansPage })))
const HistoryPage = lazy(() => import('@/pages/history').then(m => ({ default: m.HistoryPage })))
const DevicesPage = lazy(() => import('@/pages/devices').then(m => ({ default: m.DevicesPage })))
const LogsPage = lazy(() => import('@/pages/logs').then(m => ({ default: m.LogsPage })))
const SettingsPage = lazy(() => import('@/pages/settings').then(m => ({ default: m.SettingsPage })))
const NotificationsPage = lazy(() => import('@/pages/notifications').then(m => ({ default: m.NotificationsPage })))
const TwoFaPage = lazy(() => import('@/pages/two-fa').then(m => ({ default: m.TwoFaPage })))
const NetworksPage = lazy(() => import('@/pages/networks').then(m => ({ default: m.NetworksPage })))
const BackupsPage = lazy(() => import('@/pages/backups').then(m => ({ default: m.BackupsPage })))
const RolesPage = lazy(() => import('@/pages/roles').then(m => ({ default: m.RolesPage })))
const CompliancePage = lazy(() => import('@/pages/compliance').then(m => ({ default: m.CompliancePage })))
const ComplianceResultsPage = lazy(() => import('@/pages/compliance/results').then(m => ({ default: m.ComplianceResultsPage })))
const IntegrationsPage = lazy(() => import('@/pages/integrations').then(m => ({ default: m.IntegrationsPage })))
const ProductLinesPage = lazy(() => import('@/pages/product-lines').then(m => ({ default: m.ProductLinesPage })))
const DownloadsPage = lazy(() => import('@/pages/downloads').then(m => ({ default: m.DownloadsPage })))
const SystemUpgradePage = lazy(() => import('@/pages/system-upgrade').then(m => ({ default: m.SystemUpgradePage })))
const LicensePage = lazy(() => import('@/pages/license').then(m => ({ default: m.LicensePage })))

function PageLoader() {
  return (
    <div className="flex-1 p-6 space-y-4">
      <Skeleton className="h-8 w-48" />
      <Skeleton className="h-64 w-full" />
    </div>
  )
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: 1,
    },
  },
})

export default function App() {
  return (
    <ThemeProvider defaultTheme="system">
      <QueryClientProvider client={queryClient}>
        <TooltipProvider>
          <BrowserRouter>
            <AuthProvider>
            <BrandingProvider>
              <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route element={<ProtectedRoute />}>
                  <Route index element={<Suspense fallback={<PageLoader />}><DashboardPage /></Suspense>} />
                  <Route path="keys" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_keys"><KeysPage /></RequirePermission></Suspense>} />
                  <Route path="technicians" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_technicians"><TechniciansPage /></RequirePermission></Suspense>} />
                  <Route path="history" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_activations"><HistoryPage /></RequirePermission></Suspense>} />
                  <Route path="devices" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_usb_devices"><DevicesPage /></RequirePermission></Suspense>} />
                  <Route path="logs" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_logs"><LogsPage /></RequirePermission></Suspense>} />
                  <Route path="settings" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="system_settings"><SettingsPage /></RequirePermission></Suspense>} />
                  <Route path="notifications" element={<Suspense fallback={<PageLoader />}><NotificationsPage /></Suspense>} />
                  <Route path="2fa" element={<Suspense fallback={<PageLoader />}><TwoFaPage /></Suspense>} />
                  <Route path="networks" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="manage_trusted_nets"><NetworksPage /></RequirePermission></Suspense>} />
                  <Route path="backups" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_backups"><BackupsPage /></RequirePermission></Suspense>} />
                  <Route path="roles" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="manage_roles"><RolesPage /></RequirePermission></Suspense>} />
                  <Route path="compliance" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_compliance"><CompliancePage /></RequirePermission></Suspense>} />
                  <Route path="compliance/results" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_compliance"><ComplianceResultsPage /></RequirePermission></Suspense>} />
                  <Route path="product-lines" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_compliance"><ProductLinesPage /></RequirePermission></Suspense>} />
                  <Route path="integrations" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="system_settings"><IntegrationsPage /></RequirePermission></Suspense>} />
                  <Route path="downloads" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="view_downloads"><DownloadsPage /></RequirePermission></Suspense>} />
                  <Route path="system-upgrade" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="system_settings"><SystemUpgradePage /></RequirePermission></Suspense>} />
                  <Route path="subscription" element={<Suspense fallback={<PageLoader />}><RequirePermission permission="system_settings"><LicensePage /></RequirePermission></Suspense>} />
                </Route>
                <Route path="*" element={<Navigate to="/" replace />} />
              </Routes>
            </BrandingProvider>
            </AuthProvider>
          </BrowserRouter>
        </TooltipProvider>
        <Toaster position="top-right" richColors closeButton duration={4000} />
      </QueryClientProvider>
    </ThemeProvider>
  )
}
