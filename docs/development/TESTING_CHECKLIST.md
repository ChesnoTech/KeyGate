# KeyGate - Testing Checklist

**Date**: February 1, 2026
**Purpose**: Comprehensive testing of security hardening features

---

## ✅ COMPLETED FIXES

### Bug #1: HTTP 500 Error
- **Issue**: Function redefinition (`getClientIP()`)
- **Fix**: Removed duplicate from `network-utils.php`
- **Status**: ✅ FIXED

### Bug #2: Variable Typo
- **Issue**: `HTTP_FORWARDED_FOR` instead of `HTTP_X_FORWARDED_FOR`
- **Fix**: Corrected variable name
- **Status**: ✅ FIXED

### Bug #3: Missing Action Handler
- **Issue**: `list_technicians` action not defined
- **Fix**: Added new action handler in admin_v2.php
- **Status**: ✅ FIXED

### Bug #4: Docker Containers Stopped
- **Issue**: Web and DB containers exited
- **Fix**: Restarted containers
- **Status**: ✅ FIXED

---

## 🧪 MANUAL TESTING CHECKLIST

### 1. Admin Panel Access
- [ ] Navigate to `http://localhost:8080/admin_v2.php`
- [ ] Login redirects to secure-admin
- [ ] After login, admin panel loads
- [ ] Dashboard tab shows statistics

### 2. Existing Tabs (Before Security Features)
- [ ] **Dashboard**: Loads stats (keys, technicians, activations)
- [ ] **Keys**: Lists OEM keys
- [ ] **Technicians**: Lists technicians
- [ ] **USB Devices**: Shows USB device management
- [ ] **Activation History**: Shows activation attempts
- [ ] **Activity Logs**: Shows admin activity
- [ ] **Settings**: Shows system settings

### 3. New Security Tabs (After Implementation)
- [ ] **🔐 2FA Settings**: Tab visible for admin/super_admin
- [ ] **🌐 Trusted Networks**: Tab visible for super_admin only
- [ ] **💾 Backups**: Tab visible for super_admin only

### 4. RBAC Permission Testing

#### Test as Super Admin
- [ ] Login as super_admin
- [ ] All tabs visible (10 tabs total)
- [ ] All buttons visible (Add, Edit, Delete)
- [ ] Can add technicians
- [ ] Can edit technicians
- [ ] Can delete technicians
- [ ] Can register USB devices
- [ ] Can delete USB devices
- [ ] Can add trusted networks
- [ ] Can trigger manual backup

#### Test as Admin
- [ ] Login as admin role
- [ ] Can see: Dashboard, Keys, Technicians, USB Devices, History, Logs, Settings, 2FA Settings
- [ ] **Cannot see**: Trusted Networks, Backups
- [ ] Can Add and Edit but **cannot Delete**
- [ ] Can add technicians
- [ ] Can edit technicians
- [ ] **Cannot delete** technicians
- [ ] Can register USB devices
- [ ] **Cannot delete** USB devices

#### Test as Viewer
- [ ] Login as viewer role
- [ ] Can see: Dashboard, Keys, Technicians, USB Devices, History, Logs, Settings
- [ ] **Cannot see**: 2FA Settings, Trusted Networks, Backups
- [ ] **No Add/Edit/Delete buttons** visible
- [ ] Cannot modify any data
- [ ] Can only view information

### 5. USB Device Registration (Critical Test)
- [ ] Click "USB Devices" tab
- [ ] Click "Register New USB Device"
- [ ] **VERIFY**: Technician dropdown is populated
- [ ] **VERIFY**: Shows actual technician names (not empty)
- [ ] Fill in test data:
  - Technician: Select one
  - Device Name: Test USB
  - Serial Number: TEST123456789
  - Manufacturer: SanDisk
  - Model: Ultra USB 3.0
  - Capacity: 64
- [ ] Click Register
- [ ] **VERIFY**: Success message appears
- [ ] **VERIFY**: Device appears in list

### 6. Database Migrations (If Not Applied)

If new security tables don't exist yet:

```bash
cd C:/Users/ChesnoTechAdmin/OEM_Activation_System/database

# Apply migrations
cat 2fa_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation
cat rate_limiting_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation
cat rbac_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation
cat backup_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation
```

### 7. Trusted Networks Management (Super Admin Only)

- [ ] Login as super_admin
- [ ] Click "🌐 Trusted Networks" tab
- [ ] **VERIFY**: Tab loads and shows empty table or existing networks
- [ ] Click "➕ Add Trusted Network"
- [ ] Fill in test data:
  - Network Name: Office LAN
  - IP Range: 192.168.1.0/24
  - Bypass 2FA: Checked
  - Allow USB Auth: Checked
  - Description: Main office network
- [ ] Click "Add Network"
- [ ] **VERIFY**: Success message
- [ ] **VERIFY**: Network appears in table
- [ ] **VERIFY**: Shows correct CIDR, flags, and status

### 8. Backup Management (Super Admin Only)

- [ ] Login as super_admin
- [ ] Click "💾 Backups" tab
- [ ] **VERIFY**: Tab loads and shows backup history
- [ ] Click "▶️ Run Backup Now"
- [ ] **VERIFY**: Button changes to "⏳ Running backup..."
- [ ] **VERIFY**: Success message after completion
- [ ] **VERIFY**: New backup appears in history table
- [ ] **VERIFY**: Backup file created in backups/ directory

### 9. 2FA Settings (Admin/Super Admin)

- [ ] Login as admin or super_admin
- [ ] Click "🔐 2FA Settings" tab
- [ ] **VERIFY**: Tab loads and shows current 2FA status
- [ ] **VERIFY**: Shows "2FA Not Enabled" if not set up
- [ ] Click "🔒 Enable 2FA Now" (if available)
- [ ] **NOTE**: Full 2FA setup requires API integration
- [ ] **VERIFY**: No JavaScript errors in console

### 10. JavaScript Console Errors

- [ ] Open browser Developer Tools (F12)
- [ ] Navigate through all tabs
- [ ] **VERIFY**: No JavaScript errors in console
- [ ] **VERIFY**: All AJAX requests return 200 (not 500/404)
- [ ] **VERIFY**: All tabs load their data

---

## 🔒 SECURITY FEATURE TESTS

### Test 1: Rate Limiting (Requires PowerShell)

```powershell
# Test login rate limiting (20 per hour)
for ($i = 1; $i -le 25; $i++) {
    $body = @{
        technician_id = "test"
        password = "wrongpassword"
    } | ConvertTo-Json

    try {
        Invoke-RestMethod -Uri "http://localhost:8080/api/login.php" `
                          -Method Post `
                          -Body $body `
                          -ContentType "application/json"
        Write-Host "Request $i: Success" -ForegroundColor Green
    } catch {
        if ($_.Exception.Response.StatusCode -eq 429) {
            Write-Host "Request $i: RATE LIMITED (429)" -ForegroundColor Red
        } else {
            Write-Host "Request $i: Error" -ForegroundColor Yellow
        }
    }

    Start-Sleep -Milliseconds 100
}

# Expected: First ~20 succeed, rest return 429
```

### Test 2: USB Network Restriction

**Test from Untrusted IP** (should be BLOCKED):

```powershell
$headers = @{
    "Content-Type" = "application/json"
    "X-Forwarded-For" = "8.8.8.8"  # Simulated external IP
}

$body = @{
    usb_serial_number = "TEST123456789"
    computer_name = "TEST-PC"
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8080/api/authenticate-usb.php" `
                   -Method Post `
                   -Headers $headers `
                   -Body $body

# Expected: {"authenticated":false,"reason":"USB authentication only allowed from trusted networks"}
```

### Test 3: RBAC Permission Denials

Check database for denied actions:

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
  -e "SELECT * FROM rbac_permission_denials ORDER BY denied_at DESC LIMIT 10;"
```

---

## 📊 DATABASE VERIFICATION

### Check New Tables Exist

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
  -e "SHOW TABLES LIKE '%totp%'; SHOW TABLES LIKE '%trusted%'; SHOW TABLES LIKE '%rate%'; SHOW TABLES LIKE '%rbac%'; SHOW TABLES LIKE '%backup%';"

# Expected output: 6 tables
# - admin_totp_secrets
# - trusted_networks
# - rate_limit_violations
# - rbac_permission_denials
# - backup_history
# - backup_restore_log
```

### Check Technicians Table

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
  -e "SELECT technician_id, full_name, is_active FROM technicians LIMIT 5;"

# Expected: Should show technicians
```

### Check Admin Users and Roles

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
  -e "SELECT id, username, role FROM admin_users;"

# Expected: Shows demo (super_admin) and other admins
```

---

## 🐛 KNOWN ISSUES FIXED

1. ✅ **HTTP 500 Error**: Function redefinition - FIXED
2. ✅ **Technician Dropdown Empty**: Missing action handler - FIXED
3. ✅ **Docker Containers Stopped**: Containers restarted - FIXED
4. ✅ **Variable Typo**: HTTP header variable corrected - FIXED

---

## 🎯 CRITICAL TESTS TO PERFORM

### Priority 1: Basic Functionality
1. ✅ Admin panel loads without errors
2. ✅ Login works
3. ✅ Dashboard shows statistics
4. ⚠️ **Technician dropdown populates** (JUST FIXED - NEEDS TESTING)
5. ⚠️ USB device registration works
6. ⚠️ All tabs load correctly

### Priority 2: New Security Tabs
7. ⚠️ 2FA Settings tab visible (admin/super_admin)
8. ⚠️ Trusted Networks tab visible (super_admin only)
9. ⚠️ Backups tab visible (super_admin only)
10. ⚠️ Tabs load without JavaScript errors

### Priority 3: RBAC Enforcement
11. ⚠️ Viewer cannot modify data
12. ⚠️ Admin cannot delete records
13. ⚠️ Super admin has full access
14. ⚠️ Permission denials logged to database

### Priority 4: Security Features (After Migrations)
15. ⏳ Rate limiting works (requires testing)
16. ⏳ USB network restriction works (requires trusted network setup)
17. ⏳ Backup creation works
18. ⏳ Trusted network CRUD works

---

## 📝 TEST RESULTS LOG

Use this section to log test results:

### Test Date: _____________
### Tester: _____________

| Test ID | Test Name | Status | Notes |
|---------|-----------|--------|-------|
| 1.1 | Admin panel loads | ⬜ | |
| 1.2 | Login works | ⬜ | |
| 1.3 | Dashboard statistics | ⬜ | |
| 2.1 | Technician dropdown | ⬜ | JUST FIXED |
| 2.2 | USB registration | ⬜ | |
| 3.1 | 2FA Settings tab | ⬜ | |
| 3.2 | Trusted Networks tab | ⬜ | |
| 3.3 | Backups tab | ⬜ | |
| 4.1 | Viewer RBAC | ⬜ | |
| 4.2 | Admin RBAC | ⬜ | |
| 4.3 | Super admin RBAC | ⬜ | |

---

## 🚨 IMMEDIATE ACTION ITEMS

1. **Test technician dropdown** in USB device registration modal
2. **Refresh browser** to load updated JavaScript
3. **Click through all tabs** to verify they load
4. **Test RBAC** with different roles
5. **Apply database migrations** if security features needed
6. **Run automated tests** using test-security-features.ps1

---

## 📞 TROUBLESHOOTING

### Issue: Technician Dropdown Still Empty
**Solution**: Hard refresh browser (Ctrl+F5) to clear cached JavaScript

### Issue: 500 Errors in Console
**Solution**: Check browser console for specific error, check admin_v2.php syntax

### Issue: Containers Not Running
**Solution**: `docker start oem-activation-db oem-activation-web`

### Issue: Database Connection Failed
**Solution**: Verify database container is running and healthy

---

**Status**: System ready for testing
**Next Step**: Please test the technician dropdown in USB device registration
