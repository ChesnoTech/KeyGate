# OEM Activation System - Docker Demo Access Guide

**Docker Environment:** Running and Ready
**Date:** 2026-01-25

---

## Quick Access

### 🔐 Admin Panel (Main Interface)

**URL:** http://localhost:8080/activate/secure-admin.php

**Login Credentials:**
- **Username:** `admin`
- **Password:** `admin123`
- **Role:** Super Administrator

**Note:** Password has been verified and account lockout cleared. Login should work immediately.

**Alternative URL:** http://localhost:8080/activate/admin_v2.php

---

## All Available Interfaces

### 1. Admin Management Panel
**URL:** http://localhost:8080/activate/secure-admin.php

**Features:**
- ✅ Dashboard with system statistics
- ✅ Manage OEM keys (add, import CSV, view status)
- ✅ Manage technicians (create, edit, disable accounts)
- ✅ View activation history and attempts
- ✅ System configuration (SMTP, security settings)
- ✅ Activity logs and audit trail
- ✅ IP whitelist management

**Login:**
```
Username: admin
Password: admin123
```

---

### 2. PHPMyAdmin (Database Management)
**URL:** http://localhost:8081

**Login Credentials:**
- **Server:** db
- **Username:** `oem_user`
- **Password:** `oem_pass_456`

**Or use root access:**
- **Username:** `root`
- **Password:** `root_password_123`

**Features:**
- Direct database access
- SQL query interface
- Table browsing and editing
- Import/export data

---

### 3. API Endpoints (For Testing)

**Base URL:** http://localhost:8080/activate/api/

**Available Endpoints:**
- `POST /login.php` - Technician authentication
- `POST /get-key.php` - Allocate OEM key
- `POST /report-result.php` - Report activation result
- `POST /change-password.php` - Change technician password

**Test with curl:**
```bash
# Login
curl -X POST http://localhost:8080/activate/api/login.php \
  -H "Content-Type: application/json" \
  -H "User-Agent: PowerShell/7.0" \
  -d '{"technician_id":"TEST001","password":"test123"}'

# Get Key
curl -X POST http://localhost:8080/activate/api/get-key.php \
  -H "Content-Type: application/json" \
  -H "User-Agent: PowerShell/7.0" \
  -d '{"technician_id":"TEST001","order_number":"DEMO1"}'
```

---

## Demo Data Available

### Technician Accounts

| Technician ID | Password | Full Name |
|---------------|----------|-----------|
| TEST001 | test123 | Test Technician |
| TECH002 | test123 | Technician Two |
| TECH003 | test123 | Tech TECH003 |
| TECH004 | test123 | Tech TECH004 |
| TECH005 | test123 | Tech TECH005 |

### OEM Keys

Several test keys are available in the database:
- XXXXX-XXXXX-XXXXX-XXXXX-XXXXX (status: good - already used)
- YYYYY-YYYYY-YYYYY-YYYYY-YYYYY (status: bad - failed)
- ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ (status: allocated)
- BBBBB-BBBBB-BBBBB-BBBBB-BBBBB (status: allocated)
- KEY06-XXXXX-XXXXX-XXXXX-XXXXX (status: unused)
- KEY07-XXXXX-XXXXX-XXXXX-XXXXX (status: unused)
- KEY08-XXXXX-XXXXX-XXXXX-XXXXX (status: unused)
- KEY09-XXXXX-XXXXX-XXXXX-XXXXX (status: unused)

---

## Docker Container Details

### Running Containers

```bash
# Check container status
docker ps --filter "name=oem-activation"

# Expected output:
# oem-activation-web       - Port 8080 (HTTP), 8443 (HTTPS)
# oem-activation-db        - Port 3306 (MySQL)
# oem-activation-phpmyadmin - Port 8081 (HTTP)
```

### Container Management

```bash
# Stop all containers
docker-compose down

# Start containers
docker-compose up -d

# View logs
docker logs oem-activation-web
docker logs oem-activation-db

# Access container shell
docker exec -it oem-activation-web bash
docker exec -it oem-activation-db bash

# Access MySQL directly
docker exec -it oem-activation-db mariadb -uroot -proot_password_123 oem_activation
```

---

## Admin Panel Features Tour

### 1. Dashboard (Home)
- System overview statistics
- Recent activations
- Active sessions count
- Key pool status

### 2. Key Management
**Navigate to:** Keys → Manage Keys

**Actions:**
- **Add Single Key:** Manually add one OEM key
- **Import CSV:** Bulk import keys from CSV file
- **View Keys:** Browse all keys with filters
- **Key Status:** See which keys are unused/allocated/good/bad
- **Search:** Find specific keys by product key or OEM ID

**CSV Import Format:**
```csv
productkey,oemidentifier,rollserial,barcode,status
XXXXX-XXXXX-XXXXX-XXXXX-XXXXX,OEM-001,ROLL001,BAR001,unused
```

### 3. Technician Management
**Navigate to:** Technicians → Manage Technicians

**Actions:**
- **Create Account:** Add new technician
- **Edit Details:** Modify technician info
- **Reset Password:** Generate temporary password
- **Disable/Enable:** Deactivate/reactivate accounts
- **View Activity:** See activation history per technician

### 4. Activation History
**Navigate to:** Reports → Activation History

**View:**
- All activation attempts
- Success/failure statistics
- Filter by date, technician, status
- Export reports

### 5. System Configuration
**Navigate to:** Settings → System Config

**Configure:**
- SMTP settings (email notifications)
- Session timeout duration
- IP whitelist for admin access
- Security settings
- Audit log retention

### 6. Activity Logs
**Navigate to:** Reports → Activity Logs

**Monitor:**
- Admin actions
- Login attempts
- Configuration changes
- Security events

---

## Testing Scenarios

### Scenario 1: View System Dashboard
1. Go to http://localhost:8080/activate/secure-admin.php
2. Login with `admin` / `admin123`
3. You'll see the dashboard with system statistics
4. Check active sessions, key counts, recent activations

### Scenario 2: Add a New OEM Key
1. Login to admin panel
2. Navigate to **Keys** → **Add Key**
3. Fill in:
   - Product Key: `NEWKY-XXXXX-XXXXX-XXXXX-XXXXX` (29 chars)
   - OEM Identifier: `OEM-NEW-001`
   - Roll Serial: `ROLL-NEW-001`
   - Barcode: (optional)
   - Status: `unused`
4. Click **Add Key**
5. Verify it appears in the key list

### Scenario 3: Create a New Technician
1. Login to admin panel
2. Navigate to **Technicians** → **Add Technician**
3. Fill in:
   - Technician ID: `DEMO001` (alphanumeric, max 20 chars)
   - Full Name: `Demo User`
   - Email: `demo@test.com`
   - Temporary Password: (auto-generated)
4. Click **Create**
5. Test login with API using the credentials

### Scenario 4: View Activation History
1. Login to admin panel
2. Navigate to **Reports** → **Activation Attempts**
3. You'll see all previous test activations
4. Filter by:
   - Technician ID
   - Date range
   - Result (success/failed)
   - Order number

### Scenario 5: Test API with Postman/curl
See "API Endpoints" section above for curl examples

### Scenario 6: Import Keys from CSV
1. Create CSV file (example above)
2. Login to admin panel
3. Navigate to **Keys** → **Import CSV**
4. Upload file
5. Review import summary
6. Verify keys added to database

---

## Database Direct Access

### Using PHPMyAdmin
1. Go to http://localhost:8081
2. Login with `oem_user` / `oem_pass_456`
3. Select `oem_activation` database
4. Browse tables:
   - `admin_users` - Admin accounts
   - `technicians` - Technician accounts
   - `oem_keys` - Product keys
   - `active_sessions` - Current sessions
   - `activation_attempts` - Activation history
   - `system_config` - Configuration settings

### Using Command Line
```bash
# Access MySQL shell
docker exec -it oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Useful queries:
# View all keys
SELECT id, product_key, oem_identifier, key_status FROM oem_keys;

# View active sessions
SELECT technician_id, order_number, expires_at FROM active_sessions WHERE is_active=1;

# View recent activations
SELECT * FROM activation_attempts ORDER BY attempted_at DESC LIMIT 10;

# Count keys by status
SELECT key_status, COUNT(*) FROM oem_keys GROUP BY key_status;
```

---

## Troubleshooting

### Cannot Access http://localhost:8080

**Check containers are running:**
```bash
docker ps --filter "name=oem-activation"
```

**Restart containers:**
```bash
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System
docker-compose down
docker-compose up -d
```

**Check logs:**
```bash
docker logs oem-activation-web
```

### Login Not Working

**Verify admin account exists:**
```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT username, is_active FROM admin_users WHERE username='admin';"
```

**Reset password:**
```bash
# Generate new hash for 'admin123'
docker exec oem-activation-web sh -c "php -r \"echo password_hash('admin123', PASSWORD_BCRYPT);\""

# Update database (use output from above)
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "UPDATE admin_users SET password_hash='[HASH_HERE]' WHERE username='admin';"
```

### Database Connection Errors

**Check database is healthy:**
```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 -e "SELECT 1;"
```

**Restart database:**
```bash
docker restart oem-activation-db
```

### PHPMyAdmin Shows Error

**Restart PHPMyAdmin:**
```bash
docker restart oem-activation-phpmyadmin
```

---

## Security Notes

### Demo Environment

⚠️ **This is a demo/development environment:**
- Default passwords are weak (for demo purposes)
- No HTTPS enforcement
- Debug mode may be enabled
- Not suitable for production use

### Before Production

Change these before deploying to production:
1. ✅ Change all default passwords
2. ✅ Enable HTTPS with valid certificates
3. ✅ Disable debug mode
4. ✅ Configure IP whitelist
5. ✅ Set up proper SMTP server
6. ✅ Enable fail2ban or similar protection
7. ✅ Configure firewall rules
8. ✅ Regular database backups

---

## Features to Explore

### ✅ Implemented and Working
- [x] Admin authentication and session management
- [x] Technician account management
- [x] OEM key management (CRUD operations)
- [x] CSV import for bulk key addition
- [x] Key allocation with concurrency protection (FIXED!)
- [x] Activation tracking and history
- [x] Audit logging
- [x] SMTP email notifications (configurable)
- [x] API endpoints for technician clients
- [x] Security features (password hashing, session timeout)
- [x] Multi-user support with roles
- [x] IP whitelist for admin access

### 🔍 Key Improvements Made
- [x] Fixed race condition in concurrent key allocation
- [x] Added 'allocated' status to prevent duplicates
- [x] Tested with 20+ concurrent requests (0 duplicates)
- [x] Added Windows time sync before activation
- [x] Comprehensive error handling
- [x] Transaction safety and rollback
- [x] Lock timeout handling (10 seconds)

---

## Next Steps

After exploring the demo:

1. **Test the Admin Panel**
   - Add keys, create technicians
   - View activation history
   - Configure SMTP settings

2. **Test the API**
   - Use curl or Postman
   - Test login → get-key → report-result flow
   - Try concurrent requests

3. **Verify Concurrency Fix**
   - Run multiple simultaneous API requests
   - Check for duplicate key allocations (should be zero)

4. **Review Database**
   - Use PHPMyAdmin to browse tables
   - Check data integrity
   - Verify foreign key relationships

5. **Check Documentation**
   - Review test reports in project directory
   - Read implementation notes
   - Understand the architecture

---

## Support Files

All documentation is in the project root:
- `COMPLETE_CONCURRENCY_TEST_REPORT.md` - Full test results
- `RACE_CONDITION_FIX_COMPLETE.md` - Bug fix details
- `TIME_SYNC_UPDATE.md` - Time sync function
- `DOCKER_QUICK_START.md` - Docker setup guide
- `FINAL_TESTING_COMPLETE.md` - Initial testing report

---

**Demo Environment Ready!** 🎉

Access the admin panel now at: **http://localhost:8080/activate/secure-admin.php**

Login with: `admin` / `admin123`
