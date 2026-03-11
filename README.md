# OEM Activation System v3.0

**Professional Windows OEM license management for computer assembly operations.**

Secure, database-driven system that automates Windows OEM key distribution, activation, and tracking across technician workstations. Replaces legacy SSH/CSV workflows with a modern REST API architecture deployed via Docker.

---

## Architecture

```
 Technician Workstation                     Production Server (Docker)
+-------------------------+          +------------------------------------------+
|                         |          |                                          |
|  OEM_Activator.cmd      |          |  +------------+    +----------------+   |
|    |                    |   HTTPS  |  |            |    |                |   |
|    +-> PowerShell v3    |--------->|  |  PHP 8.3   |--->|  MariaDB 10.11 |   |
|        (slmgr /ipk/ato) |<---------|  |  (Apache)  |    |  (oem_keys,    |   |
|                         |   JSON   |  |            |    |   technicians, |   |
+-------------------------+          |  +-----+------+    |   audit_log)   |   |
                                     |        |           +----------------+   |
 Admin Browser                       |        |                                |
+-------------------------+          |  +-----v------+                         |
|                         |   HTTPS  |  |            |                         |
|  Admin Dashboard (JS)   |--------->|  |  Redis 7.2 |  (rate limiting)       |
|  - Key management       |<---------|  |            |                         |
|  - Technician accounts  |          |  +------------+                         |
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
| Web App | PHP 8.3 + Apache | Admin panel, REST API |
| Database | MariaDB 10.11 | Keys, technicians, audit logs |
| Cache | Redis 7.2 | API rate limiting |
| Client | PowerShell 5.1/7 | Windows activation automation |
| Launcher | CMD batch | PS7 install, pre-activation tasks |
| Deployment | Docker Compose | Container orchestration |
| Hardware Bridge | C# .NET 8 + Chrome Extension | USB device detection |

---

## Quick Start

### Prerequisites
- Docker and Docker Compose
- SSL certificate (or use the included localhost cert for testing)

### Production Deployment

```bash
# 1. Clone the repo
git clone https://github.com/ChesnoTech/OEM_Activation_System.git
cd OEM_Activation_System

# 2. Configure environment
cp .env.example .env
# Edit .env with your database passwords and settings

# 3. Start the production stack
docker compose -f docker-compose.prod.yml up -d

# 4. Run the setup wizard
# Navigate to: https://your-server:8443/setup
```

### Development (with PHPMyAdmin + exposed ports)

```bash
docker compose up -d
# PHPMyAdmin available at http://localhost:8081
# DB accessible at localhost:3306
```

---

## Project Structure

```
OEM_Activation_System/
|
|-- FINAL_PRODUCTION_SYSTEM/     # Web application root
|   |-- admin_v2.php             #   Admin panel entry point
|   |-- secure-admin.php         #   Admin authentication
|   |-- config.php               #   Database config (reads .env)
|   |-- constants.php             #   Application constants
|   |-- security-headers.php     #   CSP, HSTS, X-Frame headers
|   |-- api/                     #   REST API endpoints
|   |   |-- login.php            #     Technician authentication
|   |   |-- get-key.php          #     Key distribution
|   |   |-- report-result.php    #     Activation result reporting
|   |   +-- middleware/           #     Rate limiting, validation
|   |-- activation/              #   PowerShell client scripts
|   |   |-- main_v3.PS1          #     Activation client (USB auth, hardware QC, adaptive timing)
|   |   +-- lang/                #     Localization (en, ru)
|   |-- controllers/admin/       #   MVC controllers (12 modules)
|   |-- views/                   #   Admin panel templates
|   |-- functions/               #   Shared PHP utilities
|   |-- lang/                    #   Admin panel i18n (en, ru)
|   |-- database/                #   SQL schema + migrations
|   |-- client/                  #   Technician distribution files
|   +-- setup/                   #   Installation wizard
|
|-- hardware-bridge/             # Chrome extension + C# native app
|-- database/                    # Additional SQL migrations
|-- ssl/                         # Apache SSL configuration
|-- docs/                        # Development documentation
|
|-- Dockerfile.php               # PHP 8.3 + Apache container
|-- docker-compose.yml           # Dev stack (PHPMyAdmin, exposed ports)
|-- docker-compose.prod.yml      # Production stack (hardened)
|-- .env.example                 # Environment variable template
+-- CLAUDE.md                    # AI assistant context
```

---

## Key Features

**Key Management**
- Bulk CSV import with validation and duplicate detection
- Atomic single-key distribution (prevents race conditions)
- Automatic key lifecycle: unused -> good/bad/retry
- Key recycling rules for failed activations

**Technician Management**
- Individual accounts with bcrypt password hashing
- Mandatory password rotation for temp passwords
- Account lockout after failed login attempts
- Complete per-technician activity history

**Security**
- HTTPS enforcement with HSTS
- Redis-backed API rate limiting (per-endpoint)
- RBAC with configurable roles and permissions
- CSRF protection, CSP headers, session fixation prevention
- IP whitelist support for admin panel
- Setup wizard auto-locks after installation

**Activation Client**
- PowerShell 7 auto-install (USB MSI or winget)
- Pre-activation tasks: WSUS cleanup, SMB hardening, BIOS drive format
- Progressive verification timing (legacy-proven, ~55s window)
- Key cleanup between attempts to prevent false negatives
- Adaptive network-based timing (measures Microsoft activation server latency)
- USB-based technician authentication
- Hardware QC data collection (HackBGRT detection, component inventory)

**Admin Dashboard**
- Real-time statistics and charts
- Full audit log with filtering
- SMTP email notifications
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
| **Production deployment (aaPanel)** | [`docs/PRODUCTION_DEPLOYMENT_GUIDE.md`](docs/PRODUCTION_DEPLOYMENT_GUIDE.md) |
| Web application details | [`FINAL_PRODUCTION_SYSTEM/README.md`](FINAL_PRODUCTION_SYSTEM/README.md) |
| Hardware bridge setup | [`hardware-bridge/README.md`](hardware-bridge/README.md) |
| Technician quick start | [`FINAL_PRODUCTION_SYSTEM/client/README_TECHNICIAN.md`](FINAL_PRODUCTION_SYSTEM/client/README_TECHNICIAN.md) |
| Environment config | [`.env.example`](.env.example) |
| Development history | [`docs/development/`](docs/development/) |

---

## License

Internal / Proprietary Use
