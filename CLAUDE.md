# Development Guide

Internal development reference for the KeyGate codebase.

## System Overview

**KeyGate** — OEM license activation, quality control & workstation provisioning platform for PC builders. Automates legitimate Windows activation for authorized OEM operations with full audit trail, hardware fingerprinting, quality compliance checks, and enterprise production tracking.

### Architecture at a Glance

```
TECHNICIAN WORKSTATION                    KEYGATE SERVER (Docker)
========================                  ============================

OEM_Activator.cmd                         Apache/PHP 8.3
  │ (self-updating launcher)                ├── api/               ← 19 REST endpoints
  ▼                                         │   ├── login.php
main_v3.PS1                                 │   ├── get-key.php
  │                                         │   ├── collect-hardware-v2.php
  ├─ USB Auth OR Password ──POST──►         │   └── ... (19 total)
  │                         ◄── token       │
  ├─ Fetch Task Pipeline ──GET──►           ├── controllers/admin/  ← 21 controllers
  │  (dynamic per product line)             │   ├── KeysController.php
  │                                         │   ├── ProductionController.php
  ├─ Collect Hardware + Fingerprint         │   ├── TaskPipelineController.php
  │   (Get-CimInstance — Win11 25H2)        │   ├── UpgradeController.php
  │                                         │   ├── LicenseController.php
  ├─ Network Diagnostics (MAS-style)        │   └── ... (21 total)
  │   (4-host ping + COM fallback           │
  │    + MS licensing server test)           ├── admin_v2.php        ← 85 action router
  │                                         │
  ├─ QC Compliance ────────POST──►          ├── functions/           ← 23 helper modules
  │                                         │   ├── email-helpers.php
  ├─ Get Key ──────────────POST──►          │   ├── license-helpers.php
  │   (key pool alerts on low stock)        │   └── qc-compliance.php
  │                                         │
  ├─ slmgr /ipk + /ato                     └── config.php + VERSION.php
  │   (adaptive timing, retry logic)
  │                                         MariaDB 10.11 (26 migrations)
  ├─ Execute Custom Tasks ──────►           Redis 7.2 (rate limiting)
  │   (per-product-line pipeline)
  │                                         React Admin Panel (Vite + shadcn/ui)
  └─ Report Result ────────POST──►            └── 24 pages, 18 languages

CLOUDFLARE WORKER (License Server)
  ├── /webhook/github-sponsor    ← GitHub Sponsors payment
  ├── /webhook/lemonsqueezy      ← LemonSqueezy payment
  ├── /webhook/tbank             ← T-Bank Касса (Russia)
  ├── /api/register              ← Community license
  ├── /api/validate              ← License phone-home
  └── /api/retrieve              ← Manual license retrieval
```

### Data Flow Summary

1. **CMD launcher** → admin check, WSUS cleanup, security hardening, PS7 auto-install, self-update
2. **PS1 script** → authenticates (USB auto-detect or password), fetches dynamic task pipeline
3. **Task pipeline** → executes server-configured tasks per product line (built-in + custom PS1)
4. **Hardware collection** → Get-CimInstance (Win11 25H2), HWFingerprint (SHA256), v2 fields
5. **API** → validates session, runs QC compliance, creates CBR (Computer Build Report)
6. **PS1 script** → requests OEM key (pool alerts on low stock), installs + activates
7. **Adaptive timing** → MAS-style network diagnostics, Microsoft licensing server test
8. **PS1 script** → reports success/failure, loops to next key (configurable retries per key)
9. **Fallback** → auto-failover to alternative activation server

## Project Structure

### Scale Summary
| Metric | Count |
|--------|-------|
| Admin Controllers | 21 |
| API Endpoints | 19 |
| Admin Actions | 85 |
| Frontend Pages | 24 |
| Frontend Hooks | 22 |
| Frontend API Files | 21 |
| PHP Helper Modules | 23 |
| DB Migrations | 26 |
| Languages | 18 |
| Sidebar Nav Items | 30 |

### Client (Technician Workstation)
| File | Purpose |
|------|---------|
| `client/OEM_Activator.cmd` | Self-updating launcher: admin check, WSUS cleanup, PS7 install, task toggles |
| `client/CONFIG.txt` | Server URL configuration |
| `activation/main_v3.PS1` | Main activation script: dynamic pipeline, USB auth, CIM hardware, network diagnostics |

### Admin Controllers (21 files in `controllers/admin/`)
| Controller | Actions | Purpose |
|-----------|---------|---------|
| DashboardController | 3 | Statistics, report generation |
| KeysController | 6 | OEM key CRUD, import/export |
| TechniciansController | 9 | Technician account management |
| HistoryController | 3 | Activation history + hardware |
| LogsController | 1 | Audit trail |
| SettingsController | 10 | Alt server, order fields, session, client config, language |
| SmtpController | 3 | SMTP configuration + test |
| UsbDevicesController | 4 | USB device authentication |
| SecurityController | 4 | 2FA, trusted networks |
| BackupsController | 2 | Database backup management |
| NotificationsController | 8 | Push notifications (VAPID) |
| ClientResourcesController | 4 | Client file downloads (MSI, PS1) |
| AclController | 11 | RBAC roles, permissions, overrides |
| BrandingController | 4 | White-label customization |
| IntegrationController | 5 | osTicket, 1C ERP |
| ComplianceController | 10 | QC settings, motherboard registry, results |
| ProductVariantsController | 6 | Product lines + variant QC |
| ProductionController | 14 | CBR reports, key pools, work orders, hardware bindings, DPK import |
| TaskPipelineController | 8 | Task templates, per-product pipelines, execution logs |
| LicenseController | 4 | JWT licensing, registration, tier management |
| UpgradeController | 10 | System upgrade wizard, GitHub updates, rollback |

### API Endpoints (19 files in `api/`)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `login.php` | POST | Password authentication |
| `authenticate-usb.php` | POST | USB device authentication |
| `check-usb-auth-enabled.php` | POST | USB auth global toggle |
| `get-key.php` | POST | Get next OEM key (+ key pool alerts) |
| `report-result.php` | POST | Report activation result |
| `collect-hardware-v2.php` | POST | Hardware inventory + HWFingerprint |
| `get-client-config.php` | GET | Client launcher configuration |
| `get-launcher-config.php` | GET | CMD task toggles |
| `get-alt-server-config.php` | POST | Alternative server config |
| `change-password.php` | POST | Technician password change |
| `health.php` | GET/HEAD | Health check (DB, Redis, disk, version, branding) |
| `detect-usb-devices.php` | POST | USB device detection |
| `download-resource.php` | GET | Stream client resource files |
| `totp-setup.php` | POST | 2FA TOTP setup |
| `totp-verify.php` | POST | 2FA code verification |
| `totp-disable.php` | POST | Disable 2FA |
| `totp-regenerate-backup-codes.php` | POST | Regenerate 2FA backup codes |
| `import-csv.php` | POST | Legacy CSV migration |
| `submit-hardware.php` | POST | Hardware submission alias |

### React Frontend (24 pages)
| Page | Route | Permission | Purpose |
|------|-------|-----------|---------|
| Dashboard | `/` | — | Statistics overview |
| Keys | `/keys` | view_keys | OEM key management |
| Technicians | `/technicians` | view_technicians | Technician accounts |
| History | `/history` | view_activations | Activation history |
| Devices | `/devices` | view_usb_devices | USB device auth |
| Work Orders | `/work-orders` | view_activations | Production work orders |
| CBR Reports | `/cbr-reports` | view_activations | Computer Build Reports |
| Key Pool | `/key-pool` | view_keys | Key inventory + alerts |
| Hardware Bindings | `/hardware-bindings` | view_keys | Key-to-hardware tracking |
| DPK Import | `/dpk-import` | view_keys | Batch key import |
| Compliance | `/compliance` | view_compliance | QC settings + motherboard registry |
| Compliance Results | `/compliance/results` | view_compliance | QC results detail |
| Product Lines | `/product-lines` | view_compliance | Product variant QC |
| Task Pipeline | `/task-pipeline` | system_settings | Dynamic task templates |
| 2FA | `/2fa` | — | TOTP setup |
| Networks | `/networks` | manage_trusted_nets | IP whitelist |
| Roles | `/roles` | manage_roles | RBAC management |
| Settings | `/settings` | system_settings | System configuration (6 cards) |
| Integrations | `/integrations` | system_settings | osTicket / 1C ERP |
| Downloads | `/downloads` | view_downloads | Client resource management |
| Subscription | `/subscription` | system_settings | License + payment management |
| System Upgrade | `/system-upgrade` | system_settings | Upgrade wizard + GitHub updates |
| Backups | `/backups` | view_backups | Database backups |
| Logs | `/logs` | view_logs | Audit trail |

### Database Migrations (26 versioned, in `database/docker-init/00-init.sh`)
| Version | File | Purpose |
|---------|------|---------|
| 1 | install.sql | Core schema (technicians, keys, attempts, admins) |
| 2 | database_concurrency_indexes.sql | Performance indexes |
| 3 | rbac_migration.sql | Role-based access control |
| 4 | acl_migration.sql | Granular permissions + overrides |
| 5 | 2fa_migration.sql | TOTP two-factor auth |
| 6 | rate_limiting_migration.sql | Rate limiting per IP |
| 7 | backup_migration.sql | Backup tracking + config |
| 8 | hardware_info_migration.sql | Hardware collection v1 |
| 9 | hardware_info_v2_migration.sql | Hardware v2 (chassis, TPM, network, fingerprint) |
| 10 | push_notifications_migration.sql | Web push subscriptions |
| 11 | client_resources_migration.sql | Client file downloads |
| 12 | i18n_migration.sql | Language configuration |
| 13 | qc_compliance_migration.sql | QC engine tables |
| 14 | order_field_config_migration.sql | Custom order field templates |
| 15 | integrations_migration.sql | osTicket / 1C ERP events |
| 16 | temp_password_hash_migration.sql | Wider bcrypt column |
| 17 | product_variants_migration.sql | Product lines + variants |
| 18 | missing_drivers_migration.sql | Missing driver tracking |
| 19 | unallocated_space_migration.sql | Disk space tracking |
| 20 | downloads_acl_migration.sql | Download permissions |
| 21 | upgrade_system_migration.sql | Upgrade history tracking |
| 22 | client_config_migration.sql | Client configuration defaults |
| 23 | license_migration.sql | JWT licensing system |
| 24 | usb_devices_migration.sql | USB device registry |
| 25 | task_pipeline_migration.sql | Task templates + execution logs |
| 26 | production_tracking_migration.sql | CBR reports, key pools, work orders, DPK batches |

### Helper Modules (23 files in `functions/`)
| File | Purpose |
|------|---------|
| acl.php | Permission checking, role management |
| admin-helpers.php | Session validation, password hashing, admin utilities |
| bios-helpers.php | BIOS version compatibility checks |
| branding-integrity.php | Branding hash verification |
| csv-import.php | CSV parsing |
| email-helpers.php | SMTP email via PHPMailer (key pool alerts, notifications) |
| http-helpers.php | jsonResponse(), CORS headers |
| i18n.php | Translation loading |
| integration-helpers.php | Event dispatch to osTicket / 1C |
| key-helpers.php | Key status, recycling logic |
| license-helpers.php | JWT license validation, tier enforcement |
| logger.php | Structured logging |
| network-utils.php | IP whitelisting, trusted networks |
| push-helpers.php | VAPID push notifications |
| qc-compliance.php | QC engine: Secure Boot, BIOS, HackBGRT |
| rbac.php | Role & permission helpers |
| session-helpers.php | Token generation, session management |
| totp-helpers.php | TOTP 2FA generation & validation |
| integrations/osticket-client.php | osTicket API client |
| integrations/osticket-handler.php | osTicket event handler |
| integrations/1c_erp-client.php | 1C ERP API client |
| integrations/1c_erp-handler.php | 1C ERP event handler |

### Internationalization (18 languages in `frontend/src/i18n/`)
| Code | Language | Status |
|------|----------|--------|
| en | English | Full (primary) |
| ru | Russian | Full (secondary) |
| ar | Arabic | Translated + RTL support |
| tr | Turkish | Translated |
| zh | Chinese (Simplified) | Translated |
| es | Spanish | Translated |
| pt | Portuguese (Brazilian) | Translated |
| de | German | Translated |
| fr | French | Translated |
| ja | Japanese | Translated |
| ko | Korean | Translated |
| it | Italian | Translated |
| pl | Polish | Translated |
| nl | Dutch | Stub (EN fallback) |
| uk | Ukrainian | Stub (EN fallback) |
| hi | Hindi | Stub (EN fallback) |
| id | Indonesian | Stub (EN fallback) |
| vi | Vietnamese | Stub (EN fallback) |

### License Server (Cloudflare Worker in `license-server/`)
| File | Purpose |
|------|---------|
| worker.js | JWT license generation, webhook handlers (GitHub/LemonSqueezy/T-Bank) |
| wrangler.toml | Cloudflare Worker configuration |
| README.md | Deployment instructions |

## Development Commands

```bash
# Frontend dev server (React + Vite)
cd FINAL_PRODUCTION_SYSTEM/frontend && npm run dev

# Run frontend tests (i18n completeness, API contracts, route guards)
cd FINAL_PRODUCTION_SYSTEM/frontend && npm test

# TypeScript check
cd FINAL_PRODUCTION_SYSTEM/frontend && npx tsc --noEmit

# Docker stack (full environment)
docker compose up -d

# Docker fresh start (wipes DB)
docker compose down -v && docker compose up -d

# Test API health
curl http://localhost:8080/api/health.php

# PHP lint (via Docker)
docker compose exec web php -l /var/www/html/activate/your-file.php

# Create admin user (fresh DB)
# Use phpMyAdmin at localhost:8081 or INSERT via mariadb CLI
# Password: Admin2024! (NEVER CHANGE THIS IN DEV)

# Test PS1 on Windows
powershell -ExecutionPolicy Bypass -File "FINAL_PRODUCTION_SYSTEM/activation/main_v3.PS1"

# Deploy license server
cd license-server && npx wrangler login && npx wrangler deploy
```

## Contributing Guide

### "I need to add a new admin feature"

1. **Create controller**: `controllers/admin/MyFeatureController.php`
   - Functions like `handle_my_action($pdo, $admin_session, $json_input)`
   - Use prepared statements for ALL queries
   - Use `jsonResponse()` — never raw `echo json_encode()`
   - Log errors with `error_log()`, return generic messages to client
2. **Register actions** in `admin_v2.php` `$action_registry` array
   - Format: `'action_name' => ['ControllerFile.php', 'handler_fn', requires_csrf, accepts_json]`
3. **Create API layer**: `frontend/src/api/my-feature.ts`
4. **Create hooks**: `frontend/src/hooks/use-my-feature.ts`
5. **Create page**: `frontend/src/pages/my-feature/index.tsx`
6. **Add route** in `frontend/src/App.tsx` with `<RequirePermission>`
7. **Add sidebar entry** in `frontend/src/components/layout/app-sidebar.tsx`
8. **Add translations** to `i18n/en.json` and `i18n/ru.json` (minimum)
9. **Add test contracts** in `frontend/src/test/api-contracts.test.ts`
10. **Run tests**: `cd frontend && npm test`

### "I need to add a new API endpoint" (called by PS1 client)

1. Create `api/my-endpoint.php`
2. Start with: `require_once __DIR__ . '/../config.php';`
3. Use `jsonResponse()` for all responses
4. Validate session token if authenticated
5. Update `BACKEND_ACTIONS` in `frontend/src/test/api-contracts.test.ts`

### "I need to add a new database table"

1. Create `database/my_feature_migration.sql`
2. Register in `database/docker-init/00-init.sh` with next version number
3. Use `CREATE TABLE IF NOT EXISTS` for idempotency
4. Add to `schema_versions` tracking

### Code Patterns to Follow

| Pattern | Do This | Not This |
|---------|---------|----------|
| SQL params | `WHERE id = ?` with `execute([$id])` | `WHERE id = $id` |
| JSON response | `jsonResponse(['success' => true])` | `echo json_encode(...)` |
| File includes | `require_once __DIR__ . '/../config.php'` | `require_once '../config.php'` |
| Error messages | `'An error occurred'` + `error_log($e)` | `$e->getMessage()` to client |
| LIMIT/OFFSET | `LIMIT ? OFFSET ?` with `(int)` cast | `LIMIT $limit OFFSET $offset` |
| Race conditions | `$pdo->beginTransaction()` | Check-then-insert without TX |
| WMI (PS1) | `Get-CimInstance Win32_BaseBoard` | `Get-WmiObject Win32_BaseBoard` |
| Admin password | **NEVER CHANGE** `Admin2024!` in dev | Changing default credentials |

### Testing Checklist

- [ ] `cd frontend && npm test` passes (i18n, API contracts, route guards)
- [ ] `docker compose exec web php -l your-file.php` — no syntax errors
- [ ] New translations added to both `en.json` and `ru.json`
- [ ] Every non-public route has `<RequirePermission>` wrapper
- [ ] New actions registered in `$action_registry` AND `api-contracts.test.ts`
- [ ] No `echo json_encode()` — use `jsonResponse()`
- [ ] No `Get-WmiObject` — use `Get-CimInstance` (Win11 25H2)

## Feature Groups

### Core Activation
- OEM key management, distribution, recycling
- Technician authentication (password + USB)
- Adaptive timing based on network latency
- Alternative server failover
- Configurable retry logic (per-key retries, max keys, error classification)

### Quality Control
- QC compliance engine (Secure Boot, BIOS version, HackBGRT detection)
- Motherboard registry with manufacturer whitelisting
- Product lines with variant-specific QC rules
- Compliance result tracking and reporting

### Production Tracking (Enterprise)
- Computer Build Reports (CBR) — auto-generated per activation
- Work orders with customer tracking and shipping status
- Hardware binding verification (detect key reuse on different hardware)
- DPK batch import (CSV/TXT/XML from Microsoft deliveries)
- Key pool monitoring with email alerts (low/critical thresholds)

### Dynamic Task Pipeline
- Server-configurable task templates (built-in + custom PowerShell)
- Per-product-line task ordering
- Timeout, failure handling (stop/skip/warn) per task
- Execution logging and reporting

### System Upgrade (Joomla-style)
- Upload ZIP upgrade package with manifest.json
- Pre-flight compatibility checks (PHP, MariaDB, disk, extensions)
- Full backup before upgrade (DB + files)
- Atomic migration + file update with staging
- Post-upgrade verification + rollback
- GitHub release auto-detection

### Licensing & Payments
- JWT-based license keys (Community/Pro/Enterprise tiers)
- Feature gating by tier (technician limits, key limits)
- GitHub Sponsors integration (0% fee)
- LemonSqueezy integration (international)
- T-Bank Касса integration (Russia/CIS)
- Manual invoice flow for Russian companies
- Cloudflare Worker license server

### Security
- bcrypt password hashing with account lockout
- TOTP 2FA (Google Authenticator / Authy)
- Trusted IP networks
- RBAC with granular permissions + per-user overrides
- CSRF token validation
- Rate limiting (Redis)
- Deep branding with integrity checks

### Internationalization
- 18 languages (EN + RU primary, 16 additional translations)
- RTL support for Arabic (automatic via `dir="rtl"`)
- Admin-configurable language toggle (enable/disable per language)
- Dynamic lazy-loading of language bundles

## Docker Environment

```
oem-activation-web        — Apache/PHP 8.3,  ports 8080 (HTTP), 8443 (HTTPS)
oem-activation-db         — MariaDB 10.11,   port 3306
oem-activation-redis      — Redis 7.2,       port 6379
oem-activation-phpmyadmin — phpMyAdmin,       port 8081
```

DocumentRoot is `/var/www/html/activate` — API URLs are `/api/...` from inside the container.

## CI/CD Workflows

| Workflow | Trigger | Jobs |
|----------|---------|------|
| `ci.yml` | Push to main/develop | PHP Lint, Frontend Build & Test, Docker Stack Health |
| `integration-test.yml` | Push to main/develop | Full API integration tests via Docker |
| `release.yml` | Tag `v*` push | Build upgrade ZIP, create GitHub Release |

## Key Files Quick Reference

| File | What It Does |
|------|-------------|
| `admin_v2.php` | Action router — ALL 85 admin API calls go through here |
| `config.php` | Database connection, config cache, helper loading |
| `VERSION.php` | Application version (updated by upgrade system) |
| `constants.php` | Immutable defaults (bcrypt cost, timeouts, pagination) |
| `00-init.sh` | Database migration runner (26 phases) |
| `main_v3.PS1` | Main PowerShell activation script (2400+ lines) |
| `OEM_Activator.cmd` | Self-updating CMD launcher |
| `worker.js` | Cloudflare Worker license server |
| `App.tsx` | React router with 24 routes |
| `app-sidebar.tsx` | Navigation with 5 groups, 30 items |
| `api-contracts.test.ts` | Backend action registry validation |

## graphify

KeyGate has a graphify knowledge graph at `FINAL_PRODUCTION_SYSTEM/graphify-out/` (13,138 nodes, 19,511 edges, 1,260 communities — AST-only).

Rules:
- Before answering architecture or codebase questions, read `FINAL_PRODUCTION_SYSTEM/graphify-out/GRAPH_REPORT.md` for god nodes and community structure
- If `FINAL_PRODUCTION_SYSTEM/graphify-out/wiki/index.md` exists, navigate it instead of reading raw files
- For cross-module "how does X relate to Y" questions, prefer `graphify query "<question>"`, `graphify path "<A>" "<B>"`, or `graphify explain "<concept>"` over grep — these traverse the graph's EXTRACTED + INFERRED edges instead of scanning files
- After modifying code files in this session, run `graphify update FINAL_PRODUCTION_SYSTEM` to keep the graph current (AST-only, no API cost)
- Git post-commit + post-checkout hooks auto-rebuild graph on commits / branch switches
