# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## System Overview

**KeyGate** — OEM license activation, quality control & workstation provisioning platform for PC builders. Automates legitimate Windows activation for authorized OEM operations with full audit trail, hardware fingerprinting, and quality compliance checks.

### Architecture at a Glance

```
TECHNICIAN WORKSTATION                         SERVER (Docker)
========================                       ============================

OEM_Activator.cmd                              Apache/PHP 8.3
  │ (downloads & launches)                       ├── api/            ← REST endpoints
  ▼                                              │   ├── login.php
main_v3.PS1                                      │   ├── get-key.php
  │                                              │   ├── report-result.php
  ├─ USB Auth OR Password ──POST──►              │   ├── collect-hardware-v2.php
  │                         ◄── session_token    │   └── ... (17 endpoints)
  │                                              │
  ├─ Collect Hardware ──────POST──►              ├── controllers/admin/  ← Admin API
  │   (MB, CPU, RAM, GPU,                       │   ├── KeysController.php
  │    disks, HackBGRT, etc)                     │   ├── TechniciansController.php
  │                                              │   └── ... (15 controllers)
  ├─ Get Key ───────────────POST──►              │
  │                         ◄── product_key      ├── admin_v2.php    ← Admin action router
  │                                              │
  ├─ slmgr /ipk (install)                       ├── functions/       ← Shared helpers
  ├─ slmgr /ato (activate)                      │   ├── qc-compliance.php
  │   (adaptive delays based                     │   └── integration-helpers.php
  │    on Microsoft server latency)              │
  ├─ Report Result ─────────POST──►              └── config.php       ← DB connection
  │
  └─ Done                                       MariaDB 10.11
                                                  └── oem_activation database
React Admin Panel (Vite)
  └── localhost:5173 ──proxy──► Docker:8080/activate/
```

### Data Flow Summary

1. **CMD launcher** → checks admin rights, cleans WSUS, downloads `main_v3.PS1` from server
2. **PS1 script** → authenticates (USB auto-detect or password), collects hardware, submits to API
3. **API** → validates session, runs QC compliance checks (Secure Boot, BIOS version, HackBGRT)
4. **PS1 script** → requests OEM key, installs via `slmgr /ipk`, activates via `slmgr /ato`
5. **Adaptive timing** → measures latency to Microsoft activation servers, scales all wait times
6. **PS1 script** → reports success/failure, loops to next key if failed (max 3 keys × 3 retries)
7. **Fallback** → if no OEM keys available, can auto-failover to alternative activation server

## Project Structure

### Client (Technician Workstation)
| File | Purpose |
|------|---------|
| `client/OEM_Activator.cmd` | Launcher: admin check, WSUS cleanup, PS7 install, downloads PS1 |
| `client/CONFIG.txt` | Server URL configuration |
| `activation/main_v3.PS1` | **Main activation script**: USB auth, hardware collection, activation, adaptive timing |

### API Endpoints (called by PS1 client)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `api/login.php` | POST | Password authentication |
| `api/authenticate-usb.php` | POST | USB device authentication |
| `api/check-usb-auth-enabled.php` | POST | Check if USB auth is enabled globally |
| `api/get-key.php` | POST | Get next available OEM key (with QC gate) |
| `api/report-result.php` | POST | Report activation success/failure |
| `api/collect-hardware-v2.php` | POST | Submit full hardware inventory + QC checks |
| `api/change-password.php` | POST | Technician password change |
| `api/get-alt-server-config.php` | POST | Get alternative server configuration |
| `api/health.php` | GET/HEAD | Server health check |
| `api/totp-*.php` | POST | 2FA setup/verify/disable |
| `api/download-resource.php` | GET | Download client resources |
| `api/import-csv.php` | POST | Legacy CSV data migration |

### Admin Backend (called by React frontend)
| File | Purpose |
|------|---------|
| `admin_v2.php` | **Action router** — dispatches `?action=xxx` to controllers |
| `secure-admin.php` | Admin authentication + HTTPS enforcement |
| `controllers/admin/*.php` | 15 controller classes, one per admin feature area |
| `functions/qc-compliance.php` | QC engine: Secure Boot, BIOS version, HackBGRT checks |
| `functions/integration-helpers.php` | Event dispatch for osTicket/1C ERP integrations |
| `config.php` | PDO connection + `jsonResponse()` helper |

### React Frontend
| Directory | Purpose |
|-----------|---------|
| `frontend/src/pages/` | 14 page modules (dashboard, keys, technicians, compliance, etc.) |
| `frontend/src/api/` | API client with `apiGet`/`apiPost`/`apiPostJson` helpers |
| `frontend/src/components/` | Shared UI (shadcn/ui based) |
| `frontend/src/i18n/` | EN + RU translation files |
| `frontend/src/test/` | Vitest: i18n completeness, API contracts, route permissions |

### Database
| File | Purpose |
|------|---------|
| `database/install.sql` | Core schema (technicians, oem_keys, activation_attempts, etc.) |
| `database/qc_compliance_migration.sql` | QC tables (motherboard registry, compliance results) |
| `database/integrations_migration.sql` | Integration framework tables |
| `database/docker-init/00-init.sh` | Ordered migration runner for Docker startup |

## Development Commands

```bash
# Frontend dev server (React + Vite)
cd FINAL_PRODUCTION_SYSTEM/frontend && npm run dev

# Run frontend tests
cd FINAL_PRODUCTION_SYSTEM/frontend && npm test

# Docker stack
docker compose up -d

# Test API health
curl http://localhost:8080/api/health.php

# Test PS1 activation (on Windows client)
powershell -ExecutionPolicy Bypass -File "FINAL_PRODUCTION_SYSTEM/activation/main_v3.PS1"
```

## Contributing Guide

### "I need to add a new admin feature"

1. **Create a controller**: `controllers/admin/MyFeatureController.php`
   - Class with static methods like `handle_my_action($pdo)`
   - Use prepared statements for ALL queries
   - Use `jsonResponse()` (not raw `echo json_encode()`) for security headers
   - Log errors with `error_log()`, return generic messages to client
2. **Register actions** in `admin_v2.php` action dispatcher (the big `switch` block)
3. **Create frontend page**: `frontend/src/pages/my-feature/index.tsx`
4. **Add route** in `frontend/src/App.tsx` wrapped in `<RequirePermission>`
5. **Add translations** to both `i18n/en.json` and `i18n/ru.json`
6. **Run tests**: `cd frontend && npm test` to verify i18n completeness and route guards

### "I need to add a new API endpoint" (called by PS1 client)

1. Create `api/my-endpoint.php`
2. Start with: `require_once __DIR__ . '/../config.php';` (always use `__DIR__`)
3. Use `jsonResponse()` for all responses
4. Validate session token if the endpoint requires authentication
5. Update the `BACKEND_ACTIONS` registry in `frontend/src/test/api-contracts.test.ts`

### Code Patterns to Follow

| Pattern | Do This | Not This |
|---------|---------|----------|
| SQL params | `WHERE id = ?` with `execute([$id])` | `WHERE id = $id` |
| JSON response | `jsonResponse(['success' => true])` | `echo json_encode(...)` |
| File includes | `require_once __DIR__ . '/../config.php'` | `require_once '../config.php'` |
| Error messages | `'An error occurred'` + `error_log($e)` | `$e->getMessage()` to client |
| LIMIT/OFFSET | `LIMIT ? OFFSET ?` with `(int)` cast params | `LIMIT $limit OFFSET $offset` |
| Race conditions | Wrap check+insert in `$pdo->beginTransaction()` | Check-then-insert without TX |

### Testing Checklist

- [ ] `cd frontend && npm test` passes (i18n, API contracts, route guards)
- [ ] PHP syntax check: `php -l your-file.php`
- [ ] All new translations added to both `en.json` and `ru.json`
- [ ] Every non-public route has `<RequirePermission>` wrapper
- [ ] No `echo json_encode()` — use `jsonResponse()` instead

## Security Considerations

This system handles **Windows OEM license activation** — legitimate defensive security work:
- Automates legal license management for authorized OEM assembly operations
- bcrypt hashing, account lockout, session tokens, IP whitelists
- Complete audit trail of all activation attempts
- Single key distribution (prevents bulk key extraction)
- QC compliance gates (block activation if hardware checks fail)
- USB hardware-bound authentication (optional passwordless auth)

## Docker Dev Environment

```
oem-activation-web    — Apache/PHP 8.3, ports 8080 (HTTP), 8443 (HTTPS)
oem-activation-db     — MariaDB 10.11, port 3306
oem-activation-redis  — Redis, port 6379
oem-activation-phpmyadmin — phpMyAdmin, port 8081
```

DocumentRoot is `/var/www/html/activate` — API URLs are `/api/...` not `/activate/api/...` from inside the container.
