export interface AdminUser {
  id: number
  username: string
  full_name: string
  role: 'super_admin' | 'admin' | 'viewer'
  is_active?: boolean
  preferred_language?: string
  permissions?: string[]
}

export interface SessionInfo {
  authenticated: boolean
  user?: AdminUser
  permissions?: Record<string, boolean>
  csrf_token?: string
}

export interface DashboardStats {
  keys: {
    unused: number
    allocated: number
    good: number
    bad: number
    retry: number
    total: number
  }
  technicians: {
    active: number
    inactive: number
    total: number
  }
  activations: {
    today: number
    week: number
    month: number
  }
  daily_trend: DailyTrend[]
  recent_activity: RecentActivity[]
}

export interface DailyTrend {
  date: string
  total: number
  successes: number
  failures: number
}

export interface RecentActivity {
  created_at: string
  username: string
  action: string
  description: string
}

// Domain-specific row types (OemKeyRow, TechnicianRow, HistoryRow, UsbDeviceRow,
// RoleRow, TrustedNetworkRow, BackupRow) live in their respective api/*.ts files
// where they match the actual API response shapes.
