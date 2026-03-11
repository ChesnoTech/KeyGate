/**
 * Route Permission Guard Tests
 *
 * Verifies that every route in App.tsx that should have a permission guard
 * actually has one. Catches cases where someone adds a new page but forgets
 * to wrap it in <RequirePermission>.
 *
 * Also verifies that the permission strings used in routes match actual
 * backend permission names.
 */
import { describe, it, expect } from 'vitest'
import fs from 'fs'
import path from 'path'

const appTsxPath = path.resolve(__dirname, '../App.tsx')
const appContent = fs.readFileSync(appTsxPath, 'utf-8')

// Routes that intentionally have NO permission guard (available to all authenticated users)
const PUBLIC_ROUTES = new Set([
  '/',           // dashboard — everyone sees it
  '/login',      // login page
  'login',       // login page (alternate parse)
  'notifications',
  '2fa',
  '*',           // catch-all redirect
])

// All known backend permissions (from ACL system)
const VALID_PERMISSIONS = new Set([
  'view_dashboard', 'view_reports', 'export_data',
  'view_keys', 'view_key_full', 'add_key', 'import_keys', 'edit_key', 'recycle_key', 'delete_key',
  'view_technicians', 'add_technician', 'edit_technician', 'delete_technician',
  'reset_tech_password', 'assign_tech_role',
  'view_activations', 'add_activation_note', 'delete_activation',
  'view_hardware', 'export_hardware',
  'view_usb_devices', 'register_usb_device', 'disable_usb_device', 'enable_usb_device', 'delete_usb_device',
  'view_admins', 'manage_admins', 'assign_admin_role',
  'view_system_info', 'system_settings', 'manual_backup',
  'manage_trusted_nets', 'manage_smtp',
  'view_backups', 'view_logs', 'view_audit_trail', 'delete_logs',
  'manage_roles', 'view_acl_changelog',
  'view_compliance', 'manage_compliance_rules', 'manage_compliance',
])

// Parse routes from App.tsx
interface ParsedRoute {
  path: string
  permission: string | null
  line: number
}

function parseRoutes(): ParsedRoute[] {
  const routes: ParsedRoute[] = []
  const lines = appContent.split('\n')

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    // Match: <Route path="keys" ... or <Route index ...
    const routeMatch = line.match(/<Route\s+(?:index|path="([^"]*)")/)
    if (!routeMatch) continue

    const routePath = routeMatch[1] ?? '/'

    // Check if RequirePermission is on this line or the surrounding context
    const permMatch = line.match(/RequirePermission\s+permission="([^"]+)"/)
    routes.push({
      path: routePath,
      permission: permMatch ? permMatch[1] : null,
      line: i + 1,
    })
  }

  return routes
}

const routes = parseRoutes()

describe('Route Permission Guards', () => {
  it('found routes in App.tsx', () => {
    expect(routes.length).toBeGreaterThan(5)
  })

  it('every non-public route has a RequirePermission guard', () => {
    const unguarded: string[] = []
    for (const route of routes) {
      if (PUBLIC_ROUTES.has(route.path)) continue
      if (route.permission === null) {
        unguarded.push(`Route "${route.path}" (line ${route.line}) has no permission guard`)
      }
    }
    expect(
      unguarded,
      `Routes missing <RequirePermission>:\n  ${unguarded.join('\n  ')}`
    ).toEqual([])
  })

  it('every permission string in routes is a valid backend permission', () => {
    const invalid: string[] = []
    for (const route of routes) {
      if (route.permission && !VALID_PERMISSIONS.has(route.permission)) {
        invalid.push(`Route "${route.path}" uses unknown permission "${route.permission}" (line ${route.line})`)
      }
    }
    expect(
      invalid,
      `Invalid permission names in routes:\n  ${invalid.join('\n  ')}`
    ).toEqual([])
  })
})

describe('Route Permission Coverage', () => {
  it('reports which permissions are used in route guards', () => {
    const usedPerms = new Set(routes.filter(r => r.permission).map(r => r.permission!))
    console.info(`\n📋 ${usedPerms.size} permissions used in route guards: ${[...usedPerms].join(', ')}`)
    expect(usedPerms.size).toBeGreaterThan(5)
  })
})
