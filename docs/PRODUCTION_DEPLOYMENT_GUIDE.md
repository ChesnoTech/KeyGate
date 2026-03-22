# Production Deployment Guide

## Ubuntu Server 22.04 LTS + aaPanel 7.0.30

Step-by-step guide for deploying the KeyGate on a bare-metal (non-Docker) production server using aaPanel as the management panel.

> **Note**: This guide assumes you are running a Proxmox VM (or similar hypervisor). Docker is only used for local development — this guide covers manual production deployment.

---

## Table of Contents

1. [Prerequisites & VM Setup](#1-prerequisites--vm-setup)
2. [aaPanel Installation](#2-aapanel-installation)
3. [Install Required Software](#3-install-required-software-via-aapanel)
4. [PHP Configuration](#4-php-configuration)
5. [MariaDB Configuration](#5-mariadb-configuration)
6. [Redis Configuration](#6-redis-configuration)
7. [Clone & Deploy Application](#7-clone--deploy-application)
8. [Create Website in aaPanel](#8-create-website-in-aapanel)
9. [Initialize Database](#9-initialize-database)
10. [Run Setup Wizard](#10-run-setup-wizard)
11. [Cron Jobs](#11-cron-jobs-log-rotation--backups)
12. [Security Hardening](#12-security-hardening)
13. [Post-Install Verification](#13-post-install-verification-checklist)
14. [Updating the Application](#14-updating-the-application)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Prerequisites & VM Setup

### Proxmox VM Recommended Specs

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| vCPU | 2 cores | 4 cores |
| RAM | 4 GB | 8 GB |
| Disk | 20 GB (SSD) | 40 GB (SSD) |
| Network | 1 Gbps | 1 Gbps |
| OS | Ubuntu Server 22.04 LTS | Ubuntu Server 22.04 LTS |

### Initial Ubuntu Setup

```bash
# Update system
apt update && apt upgrade -y

# Set hostname
hostnamectl set-hostname oem-activation

# Set timezone (adjust to your timezone)
timediff set-timezone UTC

# Install essential tools
apt install -y curl wget git unzip software-properties-common
```

### Network Requirements

| Port | Protocol | Purpose | Access |
|------|----------|---------|--------|
| 80 | TCP | HTTP (redirects to HTTPS) | Public |
| 443 | TCP | HTTPS (main application) | Public |
| 3306 | TCP | MariaDB | Internal only (127.0.0.1) |
| 6379 | TCP | Redis | Internal only (127.0.0.1) |
| 8888* | TCP | aaPanel admin | Your IP only |

\* Default aaPanel port. Change this during setup.

---

## 2. aaPanel Installation

### Install aaPanel 7.0.30 (English)

```bash
wget -O install.sh https://www.aapanel.com/script/install_7.0_en.sh && bash install.sh aapanel
```

**Important**: Save the output! It contains:
- aaPanel URL (e.g., `http://YOUR_IP:8888/xxxxxxxx`)
- Default username
- Default password

### First Login

1. Open the aaPanel URL in your browser
2. **Change the default password immediately**
3. Go to **Settings** > **Security** > change the aaPanel port to something non-standard (e.g., `8891`)
4. Under **Settings** > **Firewall**, restrict aaPanel port access to your IP only

---

## 3. Install Required Software via aaPanel

### 3.1 Apache 2.4

1. aaPanel > **App Store** > **Web Server** > Install **Apache 2.4**
2. Wait for installation to complete

Enable required Apache modules:

```bash
a2enmod rewrite ssl headers deflate expires
systemctl restart apache2
```

### 3.2 PHP 8.3

1. aaPanel > **App Store** > **Runtime** > Install **PHP-8.3**
2. Wait for installation to complete

#### Install PHP Extensions

Go to aaPanel > **App Store** > **PHP-8.3** > **Settings** > **Install Extensions**:

Install these extensions (check the box and click Install for each):

| Extension | Purpose | Install Method |
|-----------|---------|----------------|
| `pdo_mysql` | Database connectivity | aaPanel built-in |
| `mysqli` | Alternative MySQL driver | aaPanel built-in |
| `mbstring` | UTF-8 string handling | aaPanel built-in |
| `xml` | XML processing | aaPanel built-in |
| `zip` | Archive handling | aaPanel built-in |
| `opcache` | Performance optimization | aaPanel built-in |
| `gmp` | Math library (VAPID keys) | aaPanel built-in |
| `curl` | HTTP client | aaPanel built-in |
| `openssl` | SSL/TLS | aaPanel built-in |
| `json` | JSON encoding | Built into PHP 8.3 |
| `gd` | Image processing | aaPanel built-in |
| `iconv` | Character encoding (QR codes) | aaPanel built-in |
| `redis` | Redis client | aaPanel built-in (PECL) |

#### Remove Disabled Functions

aaPanel disables several PHP functions by default that are required by Composer and the application.

Go to aaPanel > **App Store** > **PHP-8.3** > **Settings** > **Disabled Functions**

**Remove** these functions from the disabled list:
- `proc_open` (required by Composer)
- `proc_get_status` (required by Composer)
- `putenv` (required by Composer)
- `exec` (required by backup scripts)
- `shell_exec` (required by backup scripts)
- `passthru` (optional, used by some utilities)

### 3.3 MariaDB 10.11

1. aaPanel > **App Store** > **Database** > Install **MySQL** (select **MariaDB 10.11**)
2. Wait for installation to complete
3. Set the root password when prompted

### 3.4 Redis

1. aaPanel > **App Store** > **Database** > Install **Redis**
2. Wait for installation to complete

### 3.5 Composer (Manual Install)

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer --version
```

---

## 4. PHP Configuration

### Edit PHP Settings

Go to aaPanel > **App Store** > **PHP-8.3** > **Settings** > **Configuration File**

Or edit directly:

```bash
nano /www/server/php/83/etc/php.ini
```

Find and update these values:

```ini
; ── Memory & Execution ──────────────────────────────────
memory_limit = 256M
max_execution_time = 300
max_input_time = 60
max_input_vars = 1000

; ── File Uploads ────────────────────────────────────────
file_uploads = On
upload_max_filesize = 50M
post_max_size = 50M

; ── Timezone ────────────────────────────────────────────
date.timezone = UTC

; ── Error Handling (Production) ─────────────────────────
display_errors = Off
log_errors = On
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; ── Session Security ────────────────────────────────────
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
session.cookie_samesite = Strict
```

### OPcache Configuration

Find the OPcache section in `php.ini` and set:

```ini
; ── OPcache (Performance) ───────────────────────────────
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 4000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
```

### Restart PHP

```bash
systemctl restart php-fpm-83
# Or via aaPanel: App Store > PHP-8.3 > Service > Restart
```

### Verify PHP Configuration

```bash
php -m | grep -E "pdo_mysql|redis|gmp|mbstring|curl|zip|opcache|gd|iconv"
php -i | grep -E "memory_limit|upload_max_filesize|max_execution_time"
```

All extensions should be listed, and memory/upload values should match your settings.

---

## 5. MariaDB Configuration

### Create Database and User

Via aaPanel > **Database** > **Add Database**:
- **Database Name**: `oem_activation`
- **Username**: `oem_user`
- **Password**: (generate a strong password — save this!)
- **Character Set**: `utf8mb4`
- **Collation**: `utf8mb4_unicode_ci`
- **Access**: Localhost only

Or via command line:

```bash
mysql -u root -p
```

```sql
CREATE DATABASE oem_activation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'oem_user'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD';
GRANT ALL PRIVILEGES ON oem_activation.* TO 'oem_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Optimize MariaDB Configuration

Edit `/etc/mysql/mariadb.conf.d/50-server.cnf`:

```bash
nano /etc/mysql/mariadb.conf.d/50-server.cnf
```

Add/update under `[mysqld]`:

```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
max_connections = 200
innodb_buffer_pool_size = 256M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
bind-address = 127.0.0.1
```

Restart MariaDB:

```bash
systemctl restart mariadb
```

---

## 6. Redis Configuration

### Set Redis Password and Limits

Edit Redis config:

```bash
nano /etc/redis/redis.conf
```

Find and update:

```conf
# Bind to localhost only (security)
bind 127.0.0.1

# Set a strong password
requirepass YOUR_SECURE_REDIS_PASSWORD

# Memory limits
maxmemory 256mb
maxmemory-policy allkeys-lru
```

Restart Redis:

```bash
systemctl restart redis-server
```

### Verify Redis

```bash
redis-cli -a YOUR_SECURE_REDIS_PASSWORD ping
# Expected: PONG
```

---

## 7. Clone & Deploy Application

### Clone Repository

```bash
cd /www/wwwroot
git clone https://github.com/ChesnoTech/OEM_Activation_System.git
cd OEM_Activation_System
```

### Install Composer Dependencies

```bash
cd FINAL_PRODUCTION_SYSTEM
composer install --no-dev --optimize-autoloader --no-interaction
```

This installs: PHPMailer, TOTP/2FA, QR code generator, DomPDF, Web Push.

### Create Required Directories

```bash
mkdir -p logs backups uploads/client-resources tmp
```

### Set File Permissions

```bash
# Set ownership to web server user
chown -R www-data:www-data /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/logs
chown -R www-data:www-data /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/backups
chown -R www-data:www-data /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/uploads
chown -R www-data:www-data /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/tmp

# Set write permissions
chmod -R 775 /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/logs
chmod -R 775 /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/backups
chmod -R 775 /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/uploads
chmod -R 775 /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/tmp
```

> **Note**: aaPanel may use `www` user instead of `www-data`. Check with `ps aux | grep apache` to see what user Apache runs as. Adjust the `chown` commands accordingly.

### Configure Environment

```bash
cd /www/wwwroot/OEM_Activation_System
cp .env.example .env
nano .env
```

Update `.env` with your production values:

```env
# ── Database ────────────────────────────────────────────
DB_HOST=localhost
DB_NAME=oem_activation
DB_USER=oem_user
DB_PASS=YOUR_SECURE_DB_PASSWORD
MARIADB_ROOT_PASSWORD=YOUR_ROOT_PASSWORD

# ── Redis ───────────────────────────────────────────────
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=YOUR_SECURE_REDIS_PASSWORD

# ── Application ─────────────────────────────────────────
APP_TIMEZONE=UTC
BACKUP_RETENTION_DAYS=30

# ── CORS (leave empty for same-origin only) ─────────────
CORS_ORIGINS=

# ── PHP Settings ────────────────────────────────────────
PHP_MEMORY_LIMIT=256M
PHP_UPLOAD_MAX_FILESIZE=50M
PHP_POST_MAX_SIZE=50M
```

---

## 8. Create Website in aaPanel

### Add Site

1. Go to aaPanel > **Website** > **Add Site**
2. Fill in:
   - **Domain**: `your-domain.com` (or your server IP)
   - **Root Directory**: `/www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM`
   - **PHP Version**: `PHP-83`
   - **Database**: None (already created)

### Configure Apache VirtualHost

Go to aaPanel > **Website** > click your site > **Config** (Apache configuration)

Ensure the configuration includes:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM

    <Directory /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Redirect HTTP to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM

    SSLEngine on
    SSLCertificateFile /path/to/your/cert.pem
    SSLCertificateKeyFile /path/to/your/key.pem

    # Only allow TLS 1.2+
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1

    <Directory /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Critical**: `AllowOverride All` is required for the application's `.htaccess` file to work (URL rewriting, security headers, access control).

### SSL Certificate

**Option A: Let's Encrypt (Recommended for public domains)**

1. aaPanel > **Website** > your site > **SSL**
2. Select **Let's Encrypt**
3. Enter your domain, click **Apply**
4. Enable **Force HTTPS**

**Option B: Self-Signed (Internal/testing)**

```bash
mkdir -p /etc/apache2/ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/apache2/ssl/server.key \
  -out /etc/apache2/ssl/server.crt \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=your-domain.com"
```

Update the VirtualHost to point to these files.

### Verify Apache Modules

```bash
apache2ctl -M | grep -E "rewrite|ssl|headers|deflate|expires"
```

Expected output should include: `rewrite_module`, `ssl_module`, `headers_module`, `deflate_module`, `expires_module`.

---

## 9. Initialize Database

### Option A: Use the Convenience Script

```bash
chmod +x /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/scripts/init-database-manual.sh
/www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/scripts/init-database-manual.sh
```

The script will prompt for database credentials and run all 13 migrations in the correct order.

### Option B: Run Migrations Manually

```bash
DB_USER="oem_user"
DB_PASS="YOUR_PASSWORD"
DB_NAME="oem_activation"
SQL_DIR="/www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/database"

# Phase 1: Core schema
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/install.sql"

# Phase 2: Performance indexes
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/database_concurrency_indexes.sql"

# Phase 3: Security & access control
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/rbac_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/acl_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/2fa_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/rate_limiting_migration.sql"

# Phase 4: Feature migrations
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/backup_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/hardware_info_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/hardware_info_v2_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/push_notifications_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/client_resources_migration.sql"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/i18n_migration.sql"

# Phase 5: Data transformation
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL_DIR/temp_password_hash_migration.sql"
```

### Verify Database

```bash
mysql -u oem_user -p oem_activation -e "SHOW TABLES;" | wc -l
```

Expected: 25+ tables.

---

## 10. Run Setup Wizard

1. Open your browser and navigate to: `https://your-domain.com/setup/`
2. **Step 1**: System Requirements Check
   - All items should show green checkmarks
   - If any are red, go back and fix the corresponding configuration
3. **Step 2**: Database Connection
   - Host: `localhost`
   - Database: `oem_activation`
   - Username: `oem_user`
   - Password: your database password
   - Click **Test Connection** then **Next**
4. **Step 3**: Create Admin Account
   - Choose a strong username and password
   - This creates the first `super_admin` account
5. **Step 4**: SMTP Configuration (Optional)
   - Configure email for password reset notifications
   - Can be set up later via the admin panel Settings tab
6. **Complete**: The setup wizard auto-locks itself after completion

> **Important**: The setup wizard can only be run once. After completion, the `/setup/` URL returns a locked message. If you need to re-run it, you must clear the `system_config` table's `setup_completed` entry.

---

## 11. Cron Jobs (Log Rotation & Backups)

### Log Rotation

Create the logrotate config:

```bash
cat > /etc/logrotate.d/oem-activation << 'EOF'
/www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
    size 10M
}
EOF
```

Add cron job via aaPanel > **Cron** > **Add Cron Job**, or manually:

```bash
crontab -e
```

```cron
# Log rotation - daily at 2 AM
0 2 * * * /usr/sbin/logrotate /etc/logrotate.d/oem-activation > /dev/null 2>&1
```

### Database Backups

The application includes a backup script at `FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh`.

For bare-metal deployment, update the backup directory path:

```bash
# Edit the backup script to use the correct paths
nano /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh
```

Change line 14:
```bash
# FROM:
BACKUP_DIR="/var/www/html/activate/backups"
# TO:
BACKUP_DIR="/www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/backups"
```

Make executable:

```bash
chmod +x /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh
```

Add backup cron via aaPanel > **Cron** > **Add Cron Job**:

```cron
# Database backup - daily at 3 AM
0 3 * * * DB_HOST=localhost DB_NAME=oem_activation DB_USER=oem_user DB_PASS=YOUR_PASSWORD /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh >> /var/log/oem-backup.log 2>&1
```

### Verify Cron Jobs

```bash
crontab -l
```

Should show both the log rotation and backup entries.

---

## 12. Security Hardening

### UFW Firewall

```bash
# Enable UFW
ufw default deny incoming
ufw default allow outgoing

# Allow SSH (change port if needed)
ufw allow 22/tcp

# Allow HTTP and HTTPS
ufw allow 80/tcp
ufw allow 443/tcp

# Allow aaPanel (restrict to your IP)
ufw allow from YOUR_ADMIN_IP to any port 8891

# Enable firewall
ufw enable
ufw status
```

### Verify Service Binding

Ensure database and Redis only listen on localhost:

```bash
# MariaDB should show 127.0.0.1:3306
ss -tlnp | grep 3306

# Redis should show 127.0.0.1:6379
ss -tlnp | grep 6379
```

If either shows `0.0.0.0`, update their config to `bind 127.0.0.1`.

### File Access Protection

The application's `.htaccess` already blocks access to sensitive files (`.env`, `config.php`, `composer.json`, `.sql` files). Verify it's working:

```bash
# Should return 403 Forbidden
curl -I https://your-domain.com/config.php
curl -I https://your-domain.com/.env
curl -I https://your-domain.com/composer.json
```

### Optional: Fail2Ban

```bash
apt install -y fail2ban

cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[sshd]
enabled = true

[apache-auth]
enabled = true
EOF

systemctl enable fail2ban
systemctl start fail2ban
```

---

## 13. Post-Install Verification Checklist

Run through this checklist after deployment:

| # | Test | Expected Result |
|---|------|----------------|
| 1 | Open `https://your-domain.com/` | Admin login page loads |
| 2 | Login with admin credentials | Dashboard with statistics appears |
| 3 | Click through all 11 tabs | Dashboard, Keys, Technicians, USB Devices, History, Logs, Settings, 2FA, Trusted Networks, Backups, Roles all render |
| 4 | Toggle language EN to RU and back | All text switches correctly |
| 5 | API login test | `curl -X POST https://your-domain.com/api/login.php -d "technician_id=test&password=test"` returns JSON |
| 6 | Redis connectivity | `redis-cli -a PASSWORD ping` returns `PONG` |
| 7 | Database tables | `mysql -u oem_user -p oem_activation -e "SHOW TABLES;"` shows 25+ tables |
| 8 | SSL/HSTS | `curl -I https://your-domain.com` shows `Strict-Transport-Security` header |
| 9 | File protection | `curl -I https://your-domain.com/config.php` returns 403 |
| 10 | Manual backup | Run `scripts/backup-database.sh` — `.sql.gz` file created in `backups/` |
| 11 | Browser console | Open DevTools Console — zero JavaScript errors |
| 12 | Setup wizard locked | `https://your-domain.com/setup/` shows "already completed" message |

---

## 14. Updating the Application

### Pull Latest Changes

```bash
cd /www/wwwroot/OEM_Activation_System
git pull origin main
```

### Update Dependencies

```bash
cd FINAL_PRODUCTION_SYSTEM
composer install --no-dev --optimize-autoloader --no-interaction
```

### Run New Migrations (if any)

Check the release notes for any new SQL migrations to run.

### Restart Services

```bash
systemctl restart apache2
# Or via aaPanel: App Store > Apache > Restart
```

### Clear OPcache (if enabled)

Restart PHP-FPM to clear the OPcache:

```bash
systemctl restart php-fpm-83
```

---

## 15. Troubleshooting

### Common Issues

**Setup wizard shows red checkmarks**
- Check PHP version: `php -v` (must be 8.0+)
- Check extensions: `php -m` (look for missing ones)
- Check `memory_limit`: must be 256M+
- Check `max_execution_time`: must be 300+ or 0 (unlimited)
- Check directory permissions: `ls -la logs/ backups/ uploads/`

**500 Internal Server Error**
- Check Apache error log: `tail -50 /var/log/apache2/error.log`
- Check PHP error log: `tail -50 /www/wwwroot/OEM_Activation_System/FINAL_PRODUCTION_SYSTEM/logs/php_errors.log`
- Verify `.htaccess` is working: `AllowOverride All` must be set
- Check `mod_rewrite` is enabled: `apache2ctl -M | grep rewrite`

**Database connection failed**
- Verify credentials: `mysql -u oem_user -p oem_activation -e "SELECT 1;"`
- Check MariaDB is running: `systemctl status mariadb`
- Verify `.env` file has correct `DB_HOST=localhost` (not `db` which is the Docker hostname)

**Redis connection failed**
- Verify Redis is running: `systemctl status redis-server`
- Test with password: `redis-cli -a YOUR_PASSWORD ping`
- Check `.env` has `REDIS_HOST=127.0.0.1` (not `redis` which is the Docker hostname)
- Verify the PHP Redis extension is installed: `php -m | grep redis`

**Composer install fails**
- Check disabled functions: `proc_open`, `proc_get_status`, `putenv` must NOT be in the disabled list
- Check memory: `php -r "echo ini_get('memory_limit');"`
- Try with increased memory: `php -d memory_limit=512M /usr/local/bin/composer install`

**CSS/JS not loading**
- Check that `public/` directory exists inside `FINAL_PRODUCTION_SYSTEM/`
- Verify file permissions: `ls -la public/css/ public/js/`
- Check browser DevTools Network tab for 404 errors

**Cron jobs not running**
- Check crontab: `crontab -l`
- Check cron service: `systemctl status cron`
- Test backup script manually: `bash scripts/backup-database.sh`
- Check backup log: `tail -50 /var/log/oem-backup.log`

### Getting Help

- Check `logs/php_errors.log` for PHP errors
- Check `/var/log/apache2/error.log` for web server errors
- Check `/var/log/mysql/error.log` for database errors
- Check browser DevTools Console for JavaScript errors
