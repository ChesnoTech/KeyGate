# OEM Activation System v3.0

[![CI](https://github.com/ChesnoTech/OEM_Activation_System/actions/workflows/ci.yml/badge.svg)](https://github.com/ChesnoTech/OEM_Activation_System/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/ChesnoTech/OEM_Activation_System?label=release)](https://github.com/ChesnoTech/OEM_Activation_System/releases/latest)
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php&logoColor=white)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)
![MariaDB](https://img.shields.io/badge/MariaDB-10.5+-003545?logo=mariadb&logoColor=white)
![License](https://img.shields.io/badge/license-proprietary-red)

**Professional Windows OEM license management for computer assembly operations.**

Secure, database-driven system that automates Windows OEM key distribution, activation, and tracking across technician workstations. Replaces legacy SSH/CSV workflows with a modern REST API architecture. Deploy on any LAMP server — no Docker required.

---

## Architecture

```
 Technician Workstation                     Production Server
+-------------------------+          +------------------------------------------+
|                         |          |                                          |
|  OEM_Activator.cmd      |          |  +------------+    +----------------+   |
|    |                    |   HTTPS  |  |            |    |                |   |
|    +-> PowerShell v3    |--------->|  |  PHP 8.0+  |--->|  MariaDB/MySQL |   |
|        (slmgr /ipk/ato) |<---------|  |  (Apache)  |    |  (oem_keys,    |   |
|                         |   JSON   |  |            |    |   technicians, |   |
+-------------------------+          |  +-----+------+    |   audit_log)   |   |
                                     |        |           +----------------+   |
 Admin Browser                       |        |                                |
+-------------------------+          |  +-----v------+                         |
|                         |   HTTPS  |  |            |                         |
|  React Admin Panel      |--------->|  |  Redis     |  (rate limiting,       |
|  - Key management       |<---------|  |  (optional)|   graceful degradation)|
|  - QC compliance        |          |  +------------+                         |
|  - Audit logs           |          |                                          |
+-------------------------+          +------------------------------------------+
```

### How It Works

1. Technician runs `OEM_Activator.cmd` on a fresh Windows PC
2. CMD launcher installs PowerShell 7 (if needed), runs pre-activation tasks (WSUS cleanup, security hardening)
3. PowerShell script authenticates via REST API, requests an OEM key
4. Key is installed and activated using `slmgr.vbs` with progressive verification (6 checks over ~55 seconds)
5. Result is reported back to the API; key is marked as `good` or recycled for retry
6. Full audit trail logged in the database

---

## Tech Stack

| Component | Technology | Purpose |
|-----------|-----------|---------|
| Web App | PHP 8.0+ / Apache | Admin panel, REST API |
| Frontend | React 19 / Vite / shadcn/ui | Admin dashboard (i18n: EN + RU) |
| Database | MariaDB 10.5+ / MySQL 5.7+ | Keys, technicians, audit logs |
| Cache | Redis (optional) | API rate limiting (graceful degradation without it) |
| Client | PowerShell 5.1/7 | Windows activation automation |
| Launcher | CMD batch | PS7 install, pre-activation tasks |
| Hardware Bridge | C# .NET 8 + Chrome Extension | USB device detection |

---

## Quick Start

### Requirements
- Apache or Nginx web server
- PHP 8.0+ with extensions: PDO, pdo_mysql, json, mbstring, openssl, curl
- MariaDB 10.5+ or MySQL 5.7+
- SSL certificate (recommended for production)

### Production Installation

```
1. Download and upload FINAL_PRODUCTION_SYSTEM/ to your web server document root

2. Navigate to http://your-server/install/ in your browser

3. Follow the 6-step setup wizard:
   Step 1 — Environment check (PHP, extensions, permissions)
   Step 2 — Database connection (host, port, credentials)
   Step 3 — Install tables (runs 19 migrations automatically)
   Step 4 — Create admin account
   Step 5 — System settings (name, URL, timezone, language)
   Step 6 — Done! Delete /install/ directory for security

4. Open admin panel at http://your-server/secure-admin.php
```

No Docker, no Composer, no npm — just upload and run the installer.

### Development (Docker, optional)

```bash
docker compose up -d
# Web app:     http://localhost:8080
# PHPMyAdmin:  http://localhost:8081
# DB:          localhost:3306

# React frontend dev server:
cd FINAL_PRODUCTION_SYSTEM/frontend && npm run dev
# Admin panel: http://localhost:5173
```

---

## Project Structure

```
OEM_Activation_System/
|
|-- FINAL_PRODUCTION_SYSTEM/     # Web application root (upload this to your server)
|   |-- install/                 #   Web installer wizard (delete after setup!)
|   |   |-- index.php            #     6-step setup UI
|   |   +-- ajax.php             #     Installer backend (env check, migrations, config)
|   |-- admin_v2.php             #   Admin API action router
|   |-- secure-admin.php         #   Admin authentication
|   |-- config.php               #   Database config (generated by installer)
|   |-- constants.php            #   Application constants
|   |-- api/                     #   REST API endpoints (17 endpoints)
|   |   |-- login.php            #     Technician authentication
|   |   |-- get-key.php          #     Key distribution (with QC gate)
|   |   |-- report-result.php    #     Activation result reporting
|   |   |-- collect-hardware-v2.php #  Hardware inventory submission
|   |   +-- health.php           #     Server health check
|   |-- activation/              #   PowerShell client scripts
|   |   +-- main_v3.PS1          #     Activation client (USB auth, hardware QC, adaptive timing)
|   |-- controllers/admin/       #   Admin controllers (15 modules)
|   |-- functions/               #   Shared PHP utilities
|   |   |-- qc-compliance.php    #     QC engine (Secure Boot, BIOS, HackBGRT, partitions, drivers)
|   |   +-- integration-helpers.php #  Event dispatch (osTicket, 1C ERP)
|   |-- frontend/                #   React admin panel (Vite + shadcn/ui)
|   |-- database/                #   SQL schema + 19 migrations
|   +-- client/                  #   Technician distribution files
|
|-- hardware-bridge/             # Chrome extension + C# native app
|-- docs/                        # Development documentation
|
|-- Dockerfile.php               # PHP 8.3 + Apache (dev only)
|-- docker-compose.yml           # Dev stack (optional)
+-- CLAUDE.md                    # AI assistant context
```

---

## Key Features

**Key Management**
- Bulk CSV import with validation and duplicate detection
- Atomic single-key distribution (prevents race conditions)
- Automatic key lifecycle: unused → good/bad/retry
- Key recycling rules for failed activations

**QC Compliance**
- Hardware quality checks: Secure Boot, BIOS version, boot logo (HackBGRT), partitions, drivers
- Cascade enforcement hierarchy: Global → Product Line → Manufacturer → Model
- Motherboard registry with approved BIOS versions
- Enforcement levels: Disabled / Info / Warning / Blocking

**Technician Management**
- Individual accounts with bcrypt password hashing
- USB hardware-bound authentication (optional passwordless)
- Account lockout after failed login attempts
- TOTP two-factor authentication (2FA)

**Security**
- HTTPS enforcement with HSTS
- Redis-backed API rate limiting (per-endpoint, graceful degradation without Redis)
- RBAC with configurable roles and ACL permissions
- CSRF protection, CSP headers, session fixation prevention
- IP whitelist support for admin panel
- Installer auto-locks after setup (install.lock)

**Activation Client**
- PowerShell 7 auto-install (USB MSI or winget)
- Pre-activation tasks: WSUS cleanup, SMB hardening
- Adaptive timing based on Microsoft activation server latency
- Key cleanup between attempts to prevent false negatives
- USB-based technician authentication
- Full hardware inventory collection (MB, CPU, RAM, GPU, disks, drivers)

**Admin Dashboard (React)**
- Real-time statistics and charts
- Full audit log with filtering
- QC compliance management with product lines
- Integration framework (osTicket, 1C ERP)
- White-label branding (logo, colors, company name)
- Bilingual interface (English / Russian)
- Database backup management
- Responsive design (mobile/tablet/desktop)

---

## Branch Strategy

| Branch | Purpose |
|--------|---------|
| `main` | Production-ready, matches deployed system |
| `develop` | Active development, merged to `main` when stable |

---

## Documentation

| Document | Location |
|----------|----------|
| **Web installer** | Upload `FINAL_PRODUCTION_SYSTEM/` → navigate to `/install/` |
| **Production deployment (aaPanel)** | [`docs/PRODUCTION_DEPLOYMENT_GUIDE.md`](docs/PRODUCTION_DEPLOYMENT_GUIDE.md) |
| Web application details | [`FINAL_PRODUCTION_SYSTEM/README.md`](FINAL_PRODUCTION_SYSTEM/README.md) |
| Hardware bridge setup | [`hardware-bridge/README.md`](hardware-bridge/README.md) |
| Technician quick start | [`FINAL_PRODUCTION_SYSTEM/client/README_TECHNICIAN.md`](FINAL_PRODUCTION_SYSTEM/client/README_TECHNICIAN.md) |
| Development guide | [`CLAUDE.md`](CLAUDE.md) |

---

## License

Internal / Proprietary Use
