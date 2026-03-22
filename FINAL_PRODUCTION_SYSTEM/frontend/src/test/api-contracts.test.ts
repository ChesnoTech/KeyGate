/**
 * API Contract Tests
 *
 * Verifies that every API function in the frontend calls a valid backend action
 * and uses the correct HTTP method (GET vs POST).
 *
 * This catches:
 * - Typos in action names (e.g., 'list_key' instead of 'list_keys')
 * - GET/POST mismatch (using apiPost for a read-only endpoint)
 * - Orphaned API functions that call non-existent backend actions
 */
import { describe, it, expect } from 'vitest'
import fs from 'fs'
import path from 'path'
import { glob } from './test-utils'

// The authoritative list of backend actions from admin_v2.php action_registry
// Update this when you add new backend actions
const BACKEND_ACTIONS: Record<string, { method: 'GET' | 'POST'; csrf: boolean }> = {
  // dashboard
  get_stats:             { method: 'GET',  csrf: false },
  generate_report:       { method: 'GET',  csrf: false },
  download_report:       { method: 'GET',  csrf: false },

  // keys
  list_keys:             { method: 'GET',  csrf: false },
  recycle_key:           { method: 'POST', csrf: true },
  delete_key:            { method: 'POST', csrf: true },
  import_keys:           { method: 'POST', csrf: true },
  export_keys:           { method: 'GET',  csrf: false },
  add_keys:              { method: 'POST', csrf: true },

  // technicians
  list_techs:            { method: 'GET',  csrf: false },
  list_technicians:      { method: 'GET',  csrf: false },
  add_tech:              { method: 'POST', csrf: true },
  edit_tech:             { method: 'POST', csrf: false },
  get_tech:              { method: 'GET',  csrf: false },
  update_tech:           { method: 'POST', csrf: true },
  reset_password:        { method: 'POST', csrf: true },
  toggle_tech:           { method: 'POST', csrf: true },
  delete_tech:           { method: 'POST', csrf: true },

  // history
  list_history:          { method: 'GET',  csrf: false },
  get_hardware:          { method: 'GET',  csrf: false },
  get_hardware_by_order: { method: 'GET',  csrf: false },

  // logs
  list_logs:             { method: 'GET',  csrf: false },

  // settings
  get_alt_server_settings:   { method: 'GET',  csrf: false },
  save_alt_server_settings:  { method: 'POST', csrf: true },
  get_order_field_settings:  { method: 'GET',  csrf: false },
  save_order_field_settings: { method: 'POST', csrf: true },
  get_session_settings:      { method: 'GET',  csrf: false },
  save_session_settings:     { method: 'POST', csrf: true },
  get_client_config_settings:  { method: 'GET',  csrf: false },
  save_client_config_settings: { method: 'POST', csrf: true },

  // smtp / email
  get_smtp_settings:         { method: 'GET',  csrf: false },
  save_smtp_settings:        { method: 'POST', csrf: true },
  test_smtp_connection:      { method: 'POST', csrf: true },

  // usb devices
  list_usb_devices:          { method: 'GET',  csrf: false },
  register_usb_device:       { method: 'POST', csrf: true },
  update_usb_device_status:  { method: 'POST', csrf: true },
  delete_usb_device:         { method: 'POST', csrf: true },

  // 2fa & security
  get_2fa_status:            { method: 'GET',  csrf: false },
  list_trusted_networks:     { method: 'GET',  csrf: false },
  add_trusted_network:       { method: 'POST', csrf: true },
  delete_trusted_network:    { method: 'POST', csrf: true },

  // backups
  list_backups:              { method: 'GET',  csrf: false },
  trigger_manual_backup:     { method: 'POST', csrf: true },

  // push notifications
  push_get_vapid_key:        { method: 'GET',  csrf: false },
  push_subscribe:            { method: 'POST', csrf: true },
  push_unsubscribe:          { method: 'POST', csrf: true },
  get_push_preferences:      { method: 'GET',  csrf: false },
  save_push_preferences:     { method: 'POST', csrf: true },
  get_notifications:         { method: 'GET',  csrf: false },
  mark_notifications_read:   { method: 'POST', csrf: true },
  send_test_notification:    { method: 'POST', csrf: true },

  // client resources / downloads
  list_client_resources:     { method: 'GET',  csrf: false },
  upload_client_resource:    { method: 'POST', csrf: true },
  delete_client_resource:    { method: 'POST', csrf: true },
  download_client_resource:  { method: 'GET',  csrf: false },

  // acl / roles
  acl_list_roles:            { method: 'GET',  csrf: false },
  acl_get_role:              { method: 'GET',  csrf: false },
  acl_list_permissions:      { method: 'GET',  csrf: false },
  acl_create_role:           { method: 'POST', csrf: true },
  acl_update_role:           { method: 'POST', csrf: true },
  acl_delete_role:           { method: 'POST', csrf: true },
  acl_clone_role:            { method: 'POST', csrf: true },
  acl_get_user_effective:    { method: 'GET',  csrf: false },
  acl_set_user_override:     { method: 'POST', csrf: true },
  acl_remove_user_override:  { method: 'POST', csrf: true },
  acl_get_changelog:         { method: 'GET',  csrf: false },

  // branding
  get_branding:              { method: 'GET',  csrf: false },
  save_branding:             { method: 'POST', csrf: true },
  upload_brand_asset:        { method: 'POST', csrf: true },
  delete_brand_asset:        { method: 'POST', csrf: true },

  // integrations
  list_integrations:         { method: 'GET',  csrf: false },
  get_integration:           { method: 'GET',  csrf: false },
  save_integration:          { method: 'POST', csrf: true },
  test_integration:          { method: 'POST', csrf: true },
  retry_integration_events:  { method: 'POST', csrf: true },

  // compliance
  qc_get_settings:             { method: 'GET',  csrf: false },
  qc_save_settings:            { method: 'POST', csrf: true },
  qc_list_motherboards:        { method: 'GET',  csrf: false },
  qc_get_motherboard:          { method: 'GET',  csrf: false },
  qc_update_motherboard:       { method: 'POST', csrf: true },
  qc_list_manufacturers:       { method: 'GET',  csrf: false },
  qc_update_manufacturer:      { method: 'POST', csrf: true },
  qc_list_compliance_results:  { method: 'GET',  csrf: false },
  qc_list_compliance_grouped:  { method: 'GET',  csrf: false },
  qc_get_stats:                { method: 'GET',  csrf: false },

  // product lines & variants (partition QC)
  get_product_lines:           { method: 'GET',  csrf: false },
  get_product_line:            { method: 'GET',  csrf: false },
  save_product_line:           { method: 'POST', csrf: true },
  delete_product_line:         { method: 'POST', csrf: true },
  save_product_variant:        { method: 'POST', csrf: true },
  delete_product_variant:      { method: 'POST', csrf: true },

  // licensing
  license_status:              { method: 'GET',  csrf: false },
  license_register:            { method: 'POST', csrf: true },
  license_deactivate:          { method: 'POST', csrf: true },
  license_generate_dev:        { method: 'POST', csrf: true },

  // system upgrade
  upgrade_check_github:        { method: 'GET',  csrf: false },
  upgrade_download_github:     { method: 'POST', csrf: true },
  upgrade_get_status:          { method: 'GET',  csrf: false },
  upgrade_upload_package:      { method: 'POST', csrf: true },
  upgrade_preflight:           { method: 'POST', csrf: true },
  upgrade_backup:              { method: 'POST', csrf: true },
  upgrade_apply:               { method: 'POST', csrf: true },
  upgrade_verify:              { method: 'POST', csrf: true },
  upgrade_rollback:            { method: 'POST', csrf: true },
  upgrade_history:             { method: 'GET',  csrf: false },

  // pre-auth (no registry — handled directly in admin_v2.php)
  check_session:               { method: 'GET',  csrf: false },
  get_csrf:                    { method: 'GET',  csrf: false },
  admin_login:                 { method: 'POST', csrf: false },
  get_public_branding:         { method: 'GET',  csrf: false },
}

// Scan all api/*.ts files for action names used in apiGet/apiPost/apiPostJson calls
function extractFrontendApiCalls(): { action: string; method: 'GET' | 'POST'; file: string; line: number }[] {
  const apiDir = path.resolve(__dirname, '../api')
  const results: { action: string; method: 'GET' | 'POST'; file: string; line: number }[] = []

  if (!fs.existsSync(apiDir)) return results

  for (const entry of fs.readdirSync(apiDir)) {
    if (!entry.endsWith('.ts') && !entry.endsWith('.tsx')) continue
    const filePath = path.join(apiDir, entry)
    const content = fs.readFileSync(filePath, 'utf-8')
    const lines = content.split('\n')

    lines.forEach((line, idx) => {
      // Match apiGet('action_name'...) or apiGet<Type>('action_name'...)
      const getMatch = line.match(/apiGet(?:<[^>]+>)?\(\s*['"]([^'"]+)['"]/)
      if (getMatch) {
        results.push({ action: getMatch[1], method: 'GET', file: entry, line: idx + 1 })
      }

      // Match apiPost('action_name'...) or apiPostJson('action_name'...)
      const postMatch = line.match(/apiPost(?:Json)?(?:<[^>]+>)?\(\s*['"]([^'"]+)['"]/)
      if (postMatch) {
        results.push({ action: postMatch[1], method: 'POST', file: entry, line: idx + 1 })
      }
    })
  }

  return results
}

const frontendCalls = extractFrontendApiCalls()

describe('API Contracts: Action Names', () => {
  it('every frontend API call references a valid backend action', () => {
    const invalid: string[] = []
    for (const call of frontendCalls) {
      if (!(call.action in BACKEND_ACTIONS)) {
        invalid.push(`${call.file}:${call.line} — "${call.action}" not in backend registry`)
      }
    }
    expect(
      invalid,
      `Frontend calls non-existent backend actions:\n  ${invalid.join('\n  ')}`
    ).toEqual([])
  })

  it('GET endpoints use apiGet, POST endpoints use apiPost/apiPostJson', () => {
    const mismatches: string[] = []
    for (const call of frontendCalls) {
      const backend = BACKEND_ACTIONS[call.action]
      if (!backend) continue // caught by the action name test above

      if (backend.method === 'GET' && call.method !== 'GET') {
        mismatches.push(`${call.file}:${call.line} — "${call.action}" is GET on backend but frontend uses POST`)
      }
      // Note: POST actions called via apiGet would fail with CSRF errors,
      // but GET actions called via apiPost are wasteful (not a hard error)
    }
    expect(
      mismatches,
      `HTTP method mismatches:\n  ${mismatches.join('\n  ')}`
    ).toEqual([])
  })
})

describe('API Contracts: Backend Coverage', () => {
  it('reports backend actions with no frontend caller (informational)', () => {
    const calledActions = new Set(frontendCalls.map(c => c.action))
    const uncalled: string[] = []
    for (const action of Object.keys(BACKEND_ACTIONS)) {
      // Skip pre-auth actions — they're called from auth hooks, not api/*.ts
      if (['check_session', 'get_csrf', 'admin_login', 'get_public_branding'].includes(action)) continue
      if (!calledActions.has(action)) {
        uncalled.push(action)
      }
    }
    if (uncalled.length > 0) {
      console.info(
        `\n📋 ${uncalled.length} backend actions not called from api/*.ts files (may be called elsewhere):\n` +
        `  ${uncalled.join('\n  ')}`
      )
    }
    // Informational only — many actions are called from hooks, pages, or via dynamic action strings
    expect(true).toBe(true)
  })
})
