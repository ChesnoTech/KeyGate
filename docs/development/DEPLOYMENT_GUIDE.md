# OEM Activation System - Deployment Guide
## Security Hardening Features - Production Deployment

**Version**: 2.0.0
**Date**: February 1, 2026
**Status**: Production Ready ✅

---

## 📋 PRE-DEPLOYMENT CHECKLIST

Before deploying, ensure you have:

- [ ] Docker and Docker Compose installed
- [ ] Composer installed (for PHP dependencies)
- [ ] Database backup of existing system
- [ ] Admin credentials ready
- [ ] Office network IP range identified (for trusted networks)
- [ ] Redis password configured (default: redis_password_123)
- [ ] Database root password configured (default: root_password_123)

---

## 🚀 STEP-BY-STEP DEPLOYMENT

### Step 1: Backup Current System

**CRITICAL: Always backup before making changes!**

```bash
# Navigate to project directory
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System

# Create backup directory
mkdir -p backups

# Backup current database
docker exec oem-activation-db mysqldump \
    -uroot -proot_password_123 \
    --single-transaction \
    --routines --triggers --events \
    oem_activation | gzip > backups/pre-security-upgrade_$(date +%Y%m%d_%H%M%S).sql.gz

# Verify backup
ls -lh backups/
```

---

### Step 2: Apply Database Migrations

Apply all 4 migration files in order:

```bash
# Navigate to database directory
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System\database

# Apply 2FA migration
cat 2fa_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Apply rate limiting migration
cat rate_limiting_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Apply RBAC migration
cat rbac_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Apply backup migration
cat backup_migration.sql | docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation
```

**Verify migrations succeeded**:

```bash
# Check for new tables
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SHOW TABLES LIKE '%totp%'; SHOW TABLES LIKE '%trusted%'; SHOW TABLES LIKE '%rate%'; SHOW TABLES LIKE '%rbac%'; SHOW TABLES LIKE '%backup%';"

# Expected output: 6 new tables
# - admin_totp_secrets
# - trusted_networks
# - rate_limit_violations
# - rbac_permission_denials
# - backup_history
# - backup_restore_log
```

---

### Step 3: Install PHP Dependencies

Install required Composer packages for 2FA:

```bash
# Navigate to project root
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System\FINAL_PRODUCTION_SYSTEM

# Install TOTP and QR code libraries
docker exec oem-activation-web composer require spomky-labs/otphp bacon/bacon-qr-code

# Verify installation
docker exec oem-activation-web composer show | grep -E "(otphp|bacon)"

# Expected output:
# bacon/bacon-qr-code  2.0.8
# spomky-labs/otphp    11.4.2
```

---

### Step 4: Update Docker Compose Configuration

The `docker-compose.yml` should include Redis and cron containers. Verify or add:

```yaml
services:
  # ... existing services ...

  oem-activation-redis:
    image: redis:7.2-alpine
    container_name: oem-activation-redis
    restart: unless-stopped
    command: redis-server --requirepass redis_password_123 --maxmemory 256mb --maxmemory-policy allkeys-lru
    volumes:
      - ./redis-data:/data
    networks:
      - oem-network
    ports:
      - "6379:6379"

  oem-activation-cron:
    image: alpine:3.19
    container_name: oem-activation-cron
    restart: unless-stopped
    command: crond -f -l 2
    volumes:
      - ./FINAL_PRODUCTION_SYSTEM:/var/www/html/activate
      - ./backups:/var/www/html/activate/backups
      - ./cron/backup-cron:/etc/cron.d/backup-cron:ro
    networks:
      - oem-network
    depends_on:
      - oem-activation-db
    environment:
      - DB_HOST=oem-activation-db
      - DB_NAME=oem_activation
      - DB_USER=root
      - DB_PASSWORD=root_password_123
```

**Create cron schedule file**:

```bash
mkdir -p cron

cat > cron/backup-cron << 'EOF'
# Run database backup daily at 2:00 AM
0 2 * * * root /bin/sh /var/www/html/activate/scripts/backup-database.sh >> /var/www/html/activate/backups/backup.log 2>&1
EOF
```

---

### Step 5: Update PHP Dockerfile (Add Redis Extension)

Ensure `Dockerfile.php` includes Redis extension:

```dockerfile
FROM php:8.3-apache

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# ... rest of Dockerfile ...
```

---

### Step 6: Rebuild and Restart Docker Containers

```bash
# Navigate to project root
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System

# Stop containers
docker-compose down

# Rebuild with new configuration
docker-compose build

# Start all containers
docker-compose up -d

# Verify all containers are running
docker ps

# Expected: 4 containers running
# - oem-activation-web
# - oem-activation-db
# - oem-activation-redis
# - oem-activation-cron
```

---

### Step 7: Verify Redis Connectivity

```bash
# Test Redis connection
docker exec oem-activation-redis redis-cli -a redis_password_123 ping

# Expected output: PONG

# Test from PHP
docker exec oem-activation-web php -r "
\$redis = new Redis();
\$redis->connect('oem-activation-redis', 6379);
\$redis->auth('redis_password_123');
\$redis->set('test', 'hello');
echo \$redis->get('test');
"

# Expected output: hello
```

---

### Step 8: Configure Trusted Networks

**IMPORTANT: Do this BEFORE testing USB authentication!**

1. Open browser and navigate to: `http://localhost:8080/activate/admin_v2.php`
2. Login as **super_admin**
3. Click on **"🌐 Trusted Networks"** tab
4. Click **"➕ Add Trusted Network"**
5. Fill in the form:
   - **Network Name**: Office LAN
   - **IP Range (CIDR)**: `192.168.1.0/24` (adjust to your network)
   - ☑ **Bypass 2FA**: Checked
   - ☑ **Allow USB Authentication**: Checked
   - **Description**: Main office network
6. Click **"Add Network"**

**Verify in database**:

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT * FROM trusted_networks WHERE is_active = 1;"
```

---

### Step 9: Make Backup Script Executable

```bash
chmod +x FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh
```

---

## 🧪 TESTING & VALIDATION

### Test 1: Database Schema Verification

```bash
# Run verification query
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation << 'EOF'
SELECT 'Checking tables...' AS status;

SELECT
    CASE
        WHEN COUNT(*) = 6 THEN 'PASS: All 6 tables exist'
        ELSE CONCAT('FAIL: Only ', COUNT(*), ' tables found')
    END AS result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'oem_activation'
AND TABLE_NAME IN (
    'admin_totp_secrets',
    'trusted_networks',
    'rate_limit_violations',
    'rbac_permission_denials',
    'backup_history',
    'backup_restore_log'
);
EOF
```

---

### Test 2: USB Network Restriction

**From Untrusted IP** (should be BLOCKED):

```powershell
$headers = @{
    "Content-Type" = "application/json"
    "X-Forwarded-For" = "8.8.8.8"  # Simulated external IP
    "User-Agent" = "PowerShell"
}

$body = @{
    usb_serial_number = "TEST123456789"
    computer_name = "TEST-PC"
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8080/activate/api/authenticate-usb.php" `
                   -Method Post `
                   -Headers $headers `
                   -Body $body

# Expected response:
# {
#   "authenticated": false,
#   "reason": "USB authentication only allowed from trusted networks"
# }
```

**From Trusted IP** (should be ALLOWED - if USB device is registered):

```powershell
$headers = @{
    "Content-Type" = "application/json"
    "User-Agent" = "PowerShell"
}

$body = @{
    usb_serial_number = "REGISTERED_USB_SERIAL"
    computer_name = "TEST-PC"
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8080/activate/api/authenticate-usb.php" `
                   -Method Post `
                   -Headers $headers `
                   -Body $body

# Expected: Authentication proceeds (requires registered USB device)
```

---

### Test 3: Rate Limiting

**Test Login Rate Limit** (20 attempts per hour):

```powershell
# Run automated test
.\test-security-features.ps1

# OR manual test:
for ($i = 1; $i -le 25; $i++) {
    $body = @{
        technician_id = "test"
        password = "wrongpassword"
    } | ConvertTo-Json

    try {
        Invoke-RestMethod -Uri "http://localhost:8080/activate/api/login.php" `
                          -Method Post `
                          -Body $body `
                          -ContentType "application/json"
        Write-Host "Request $i: Success"
    } catch {
        if ($_.Exception.Response.StatusCode -eq 429) {
            Write-Host "Request $i: RATE LIMITED (429)" -ForegroundColor Red
        } else {
            Write-Host "Request $i: Error"
        }
    }

    Start-Sleep -Milliseconds 100
}

# Expected: First ~20 succeed, remaining return 429 Too Many Requests
```

**Check rate limit violations**:

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT * FROM rate_limit_violations ORDER BY violated_at DESC LIMIT 10;"
```

---

### Test 4: RBAC Permission Enforcement

**Test as Viewer** (should have READ-ONLY access):

1. Login to admin panel as **viewer** role
2. Verify:
   - ✓ Dashboard, Keys, Technicians tabs visible
   - ✗ NO "Add" buttons visible
   - ✗ NO "Edit" buttons visible
   - ✗ NO "Delete" buttons visible
   - ✗ 2FA Settings tab NOT visible
   - ✗ Trusted Networks tab NOT visible
   - ✗ Backups tab NOT visible

**Test as Admin** (should have MODIFY but not DELETE):

1. Login as **admin** role
2. Verify:
   - ✓ "Add" and "Edit" buttons visible
   - ✗ "Delete" buttons NOT visible
   - ✓ 2FA Settings tab visible
   - ✗ Trusted Networks tab NOT visible
   - ✗ Backups tab NOT visible

**Test as Super Admin** (should have FULL access):

1. Login as **super_admin** role
2. Verify:
   - ✓ All buttons visible (Add, Edit, Delete)
   - ✓ All tabs visible (including 2FA, Trusted Networks, Backups)

**Check permission denials**:

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT * FROM rbac_permission_denials ORDER BY denied_at DESC LIMIT 10;"
```

---

### Test 5: Automated Backups

**Trigger Manual Backup**:

1. Login as **super_admin**
2. Click **"💾 Backups"** tab
3. Click **"▶️ Run Backup Now"**
4. Wait for success message
5. Verify backup file created:

```bash
ls -lh backups/

# Expected: New file like oem_activation_20260201_143052.sql.gz
```

**Verify backup integrity**:

```bash
# Test gzip integrity
gzip -t backups/oem_activation_*.sql.gz

# Extract and check first 20 lines
zcat backups/oem_activation_*.sql.gz | head -20

# Check backup history in database
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT * FROM backup_history ORDER BY created_at DESC LIMIT 5;"
```

**Test Automated Backup**:

```bash
# Manually trigger backup script
docker exec oem-activation-cron sh /var/www/html/activate/scripts/backup-database.sh

# Check cron logs
docker logs oem-activation-cron --tail 50
```

---

### Test 6: Admin Panel UI

**2FA Settings Tab**:

1. Login as **admin** or **super_admin**
2. Click **"🔐 2FA Settings"** tab
3. Verify tab loads and displays 2FA status

**Trusted Networks Tab**:

1. Login as **super_admin**
2. Click **"🌐 Trusted Networks"** tab
3. Verify networks table loads
4. Test "Add Network" button
5. Test "Delete Network" button

**Backups Tab**:

1. Login as **super_admin**
2. Click **"💾 Backups"** tab
3. Verify backup history table loads
4. Test "Run Backup Now" button

---

## 🔒 SECURITY VERIFICATION CHECKLIST

After deployment, verify all security features:

- [ ] **Redis**: Container running and responding to PING
- [ ] **Database**: All 6 new tables created
- [ ] **Composer**: TOTP libraries installed
- [ ] **USB Network Restriction**: Blocks untrusted IPs
- [ ] **Rate Limiting**: Returns 429 after limit exceeded
- [ ] **RBAC**: Viewer role cannot modify data
- [ ] **RBAC**: Admin role cannot delete records
- [ ] **RBAC**: Super admin has full access
- [ ] **Trusted Networks**: Can add/delete networks
- [ ] **Backups**: Manual backup creates file
- [ ] **Backups**: Automated backup runs on schedule
- [ ] **Admin Panel**: All tabs load correctly
- [ ] **Admin Panel**: Tab visibility matches role

---

## 📊 MONITORING & MAINTENANCE

### Daily Checks

```bash
# Check Redis status
docker exec oem-activation-redis redis-cli -a redis_password_123 INFO memory

# Check recent rate limit violations
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT COUNT(*) as violations_today FROM rate_limit_violations WHERE DATE(violated_at) = CURDATE();"

# Check recent backup status
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT backup_filename, backup_status, backup_size_mb, created_at FROM backup_history ORDER BY created_at DESC LIMIT 1;"
```

### Weekly Checks

```bash
# Check permission denials by role
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT admin_role, requested_action, COUNT(*) as denial_count FROM rbac_permission_denials WHERE denied_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY admin_role, requested_action;"

# Verify backup retention (should have ~7 backups for weekly)
ls -lt backups/ | head -10
```

### Monthly Maintenance

```bash
# Archive old rate limit violations (keep 90 days)
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "DELETE FROM rate_limit_violations WHERE violated_at < DATE_SUB(NOW(), INTERVAL 90 DAY);"

# Archive old permission denials (keep 90 days)
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "DELETE FROM rbac_permission_denials WHERE denied_at < DATE_SUB(NOW(), INTERVAL 90 DAY);"

# Verify backup cleanup (30-day retention)
find backups/ -name "oem_activation_*.sql.gz" -mtime +30
```

---

## 🆘 TROUBLESHOOTING

### Redis Connection Issues

**Problem**: Rate limiting not working

```bash
# Check Redis container
docker ps | grep redis

# If not running, start it
docker-compose up -d oem-activation-redis

# Test connection
docker exec oem-activation-redis redis-cli -a redis_password_123 ping

# Check PHP extension
docker exec oem-activation-web php -m | grep redis
```

### USB Authentication Always Blocked

**Problem**: USB auth blocked even from office network

```bash
# Check trusted networks
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation \
    -e "SELECT * FROM trusted_networks WHERE is_active = 1 AND allow_usb_auth = 1;"

# If empty, add your network via admin panel or SQL:
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation << 'EOF'
INSERT INTO trusted_networks (network_name, ip_range, bypass_2fa, allow_usb_auth, is_active)
VALUES ('Office LAN', '192.168.1.0/24', 1, 1, 1);
EOF
```

### Rate Limiting Not Enforcing

**Problem**: All requests succeed, no 429 responses

```bash
# Check Redis keys
docker exec oem-activation-redis redis-cli -a redis_password_123 KEYS "ratelimit:*"

# If empty, check:
# 1. Redis extension loaded
docker exec oem-activation-web php -m | grep redis

# 2. Rate limit check included in API files
grep "rate-limit-check" FINAL_PRODUCTION_SYSTEM/api/*.php
```

### Backup Script Fails

**Problem**: Manual backup returns error

```bash
# Check script permissions
ls -l FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh

# Make executable
chmod +x FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh

# Check backups directory
ls -ld backups/
chmod 777 backups/

# Run manually to see errors
docker exec oem-activation-cron sh /var/www/html/activate/scripts/backup-database.sh
```

---

## 🔄 ROLLBACK PROCEDURE

If issues occur after deployment:

### Step 1: Restore Database Backup

```bash
# Stop web container
docker-compose stop oem-activation-web

# Restore from backup
zcat backups/pre-security-upgrade_*.sql.gz | \
    docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation

# Restart web container
docker-compose start oem-activation-web
```

### Step 2: Remove New Tables (if needed)

```bash
docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation << 'EOF'
DROP TABLE IF EXISTS rbac_permission_denials;
DROP TABLE IF EXISTS rate_limit_violations;
DROP TABLE IF EXISTS backup_restore_log;
DROP TABLE IF EXISTS backup_history;
DROP TABLE IF EXISTS trusted_networks;
DROP TABLE IF EXISTS admin_totp_secrets;
EOF
```

### Step 3: Revert Docker Configuration

```bash
# Remove Redis and cron containers
docker-compose down oem-activation-redis oem-activation-cron

# Restart original containers
docker-compose up -d
```

---

## 📞 SUPPORT & DOCUMENTATION

### Useful Commands

```bash
# View Redis memory usage
docker exec oem-activation-redis redis-cli -a redis_password_123 INFO memory

# View rate limit keys
docker exec oem-activation-redis redis-cli -a redis_password_123 KEYS "ratelimit:*"

# View recent logs
docker logs oem-activation-web --tail 100
docker logs oem-activation-redis --tail 100
docker logs oem-activation-cron --tail 100

# Database queries
docker exec -it oem-activation-db mariadb -uroot -proot_password_123 oem_activation
```

### Documentation Files

- `SECURITY_HARDENING_COMPLETE.md` - Implementation summary
- `PROJECT_COMPLETION_SUMMARY.md` - Detailed feature list
- `FINAL_IMPLEMENTATION_COMPLETE.md` - Quick reference
- `test-security-features.ps1` - Automated testing script

---

## ✅ POST-DEPLOYMENT CHECKLIST

After successful deployment:

- [ ] All containers running (web, db, redis, cron)
- [ ] All 6 database tables created
- [ ] Redis responding to PING
- [ ] PHP Redis extension loaded
- [ ] Composer packages installed
- [ ] Trusted network configured
- [ ] USB network restriction tested
- [ ] Rate limiting tested
- [ ] RBAC tested with all 3 roles
- [ ] Manual backup tested
- [ ] Admin panel UI tested
- [ ] Backup retention verified
- [ ] Monitoring queries saved
- [ ] Team trained on new features

---

**Deployment Complete!** 🎉

Your OEM Activation System now has enterprise-grade security features:
- Redis-based rate limiting
- USB network restriction
- Optional 2FA with Google Authenticator
- Role-based access control
- Automated database backups
- Network-aware security

**System Status**: Production Ready ✅
