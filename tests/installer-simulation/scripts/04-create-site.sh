#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 4: Create Website & Database via aaPanel
#
# Simulates: Human creates a site in aaPanel → Websites → Add Site
#            Then creates a database in Databases → Add Database
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set -u

SITE_DOMAIN="${SITE_DOMAIN:-oem-system.local}"
DB_NAME="${DB_NAME:-oem_activation}"
DB_USER="${DB_USER:-oem_user}"
DB_PASS="${DB_PASS:-oem_password_123}"
DB_ROOT_PASS="${DB_ROOT_PASS:-root_password_123}"

MYSQL_BIN=$(find_mysql)

# ═══════════════════════════════════════════════════════════════
# Create Database
# ═══════════════════════════════════════════════════════════════
human_action "Opening aaPanel → Databases → Add Database"
human_action "Filling in: Name=${DB_NAME}, Username=${DB_USER}, Password=***"

log INFO "Creating database '${DB_NAME}' and user '${DB_USER}'..."

# Build mysql auth args (root may have no password in some cases)
MYSQL_AUTH="-u root"
if $MYSQL_BIN -u root -p"${DB_ROOT_PASS}" -e "SELECT 1" > /dev/null 2>&1; then
    MYSQL_AUTH="-u root -p${DB_ROOT_PASS}"
elif $MYSQL_BIN -u root -e "SELECT 1" > /dev/null 2>&1; then
    MYSQL_AUTH="-u root"
else
    log ERROR "Cannot connect to MariaDB as root"
fi

$MYSQL_BIN $MYSQL_AUTH -e "
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
" 2>/dev/null
check_result "Database '${DB_NAME}' created" $?

# Create user with proper permissions
$MYSQL_BIN $MYSQL_AUTH -e "
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    FLUSH PRIVILEGES;
" 2>/dev/null
check_result "Database user '${DB_USER}' created" $?

# Verify connection with app user
$MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" -e "USE ${DB_NAME}; SELECT 1;" > /dev/null 2>&1
check_result "Database connection verified (as ${DB_USER})" $?

# ═══════════════════════════════════════════════════════════════
# Create Website / Virtual Host
# ═══════════════════════════════════════════════════════════════
human_action "Opening aaPanel → Websites → Add Site"
human_action "Domain: ${SITE_DOMAIN}, PHP Version: 8.3, Web Server: Apache"

log INFO "Creating Apache virtual host for ${SITE_DOMAIN}..."

# Web root directory
WEBROOT="/www/wwwroot/${SITE_DOMAIN}"
if [ ! -d "$WEBROOT" ]; then
    mkdir -p "$WEBROOT"
    log INFO "Created webroot: ${WEBROOT}"
fi

# Determine Apache config directory
if [ -d /www/server/apache/conf/vhost ]; then
    VHOST_DIR="/www/server/apache/conf/vhost"
    APACHE_TYPE="aapanel"
elif [ -d /etc/apache2/sites-available ]; then
    VHOST_DIR="/etc/apache2/sites-available"
    APACHE_TYPE="system"
else
    mkdir -p /etc/apache2/sites-available
    VHOST_DIR="/etc/apache2/sites-available"
    APACHE_TYPE="system"
fi

# Ensure PHP-FPM is running before configuring vhost
log INFO "Starting PHP-FPM..."
systemctl restart php8.3-fpm 2>/dev/null || \
    /usr/sbin/php-fpm8.3 --daemonize 2>/dev/null || true
sleep 2

PHP_FPM_SOCKET=$(find_php_fpm_socket)
log INFO "PHP-FPM socket: ${PHP_FPM_SOCKET}"

# If socket doesn't exist, try TCP fallback
if [ ! -S "$PHP_FPM_SOCKET" ]; then
    log WARN "PHP-FPM socket not found, using TCP fallback (127.0.0.1:9000)"
    PHP_FPM_HANDLER='SetHandler "proxy:fcgi://127.0.0.1:9000"'
else
    PHP_FPM_HANDLER="SetHandler \"proxy:unix:${PHP_FPM_SOCKET}|fcgi://localhost\""
fi

# Create virtual host configuration
VHOST_FILE="${VHOST_DIR}/${SITE_DOMAIN}.conf"
cat > "$VHOST_FILE" <<VHOST
<VirtualHost *:80>
    ServerName ${SITE_DOMAIN}
    ServerAlias www.${SITE_DOMAIN} localhost
    DocumentRoot ${WEBROOT}

    <Directory ${WEBROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # PHP-FPM via proxy
    <FilesMatch \.php$>
        ${PHP_FPM_HANDLER}
    </FilesMatch>

    # Security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"

    # Logging
    ErrorLog \${APACHE_LOG_DIR}/${SITE_DOMAIN}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_DOMAIN}-access.log combined
</VirtualHost>
VHOST

log INFO "Virtual host config written: ${VHOST_FILE}"

# Also set as default site (so localhost:80 works)
if [ "$APACHE_TYPE" = "system" ]; then
    # Disable default site, enable our site
    a2dissite 000-default 2>/dev/null || true
    ln -sf "$VHOST_FILE" /etc/apache2/sites-enabled/${SITE_DOMAIN}.conf 2>/dev/null || true
    a2ensite ${SITE_DOMAIN} 2>/dev/null || true

    # Also make sure localhost works
    cat > /etc/apache2/sites-available/000-default.conf <<DEFAULT_VHOST
<VirtualHost *:80>
    DocumentRoot ${WEBROOT}
    <Directory ${WEBROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \.php$>
        ${PHP_FPM_HANDLER}
    </FilesMatch>
</VirtualHost>
DEFAULT_VHOST
    a2ensite 000-default 2>/dev/null || true
fi

# ── Enable required Apache modules ─────────────────────────────
human_action "Enabling Apache modules: rewrite, proxy_fcgi, headers..."

if [ "$APACHE_TYPE" = "system" ]; then
    a2enmod rewrite proxy_fcgi setenvif headers ssl 2>/dev/null || true
fi

# ── Create .htaccess for the app ────────────────────────────────
cat > "${WEBROOT}/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Deny access to sensitive files
    RewriteRule ^config\.php$ - [F,L]
    RewriteRule ^constants\.php$ - [F,L]
    RewriteRule ^install\.lock$ - [F,L]
    RewriteRule ^\.env$ - [F,L]

    # Deny access to directories
    RewriteRule ^database/ - [F,L]
    RewriteRule ^controllers/ - [F,L]
    RewriteRule ^functions/ - [F,L]
</IfModule>

# Deny access to SQL files
<FilesMatch "\.(sql|sh|log)$">
    Require all denied
</FilesMatch>
HTACCESS

log INFO ".htaccess security rules created"

# ── Test Apache config and restart ─────────────────────────────
human_action "Testing Apache configuration and restarting..."

if command -v apachectl &> /dev/null; then
    apachectl configtest 2>&1 || true
    apachectl restart 2>/dev/null || systemctl restart apache2 2>/dev/null || true
elif command -v apache2 &> /dev/null; then
    apache2ctl configtest 2>&1 || true
    systemctl restart apache2 2>/dev/null || true
fi

sleep 2

# ── Verify web server is responding ────────────────────────────
wait_for_service "Apache vhost" "curl -sf http://127.0.0.1/ -o /dev/null" 30

# ── Create a test PHP file to verify PHP works ─────────────────
cat > "${WEBROOT}/test.php" <<'PHP'
<?php
echo json_encode([
    'status' => 'ok',
    'php_version' => PHP_VERSION,
    'extensions' => get_loaded_extensions(),
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
]);
PHP

TEST_RESULT=$(curl -sf http://127.0.0.1/test.php 2>/dev/null || echo '{"status":"fail"}')
if echo "$TEST_RESULT" | grep -q '"ok"'; then
    log INFO "PHP is working through Apache!"
    PHP_VER=$(echo "$TEST_RESULT" | python3 -c "import sys,json; print(json.load(sys.stdin)['php_version'])" 2>/dev/null || echo "8.x")
    log INFO "  PHP version via web: ${PHP_VER}"
else
    log WARN "PHP via proxy_fcgi failed. Trying libapache2-mod-php fallback..."

    # Install mod_php as fallback (simpler than debugging FPM socket)
    apt-get install -y libapache2-mod-php8.3 > /dev/null 2>&1 || true
    a2enmod php8.3 2>/dev/null || true
    a2dismod mpm_event 2>/dev/null || true
    a2enmod mpm_prefork 2>/dev/null || true

    # Remove proxy handler from vhost (use mod_php instead)
    sed -i '/<FilesMatch.*php/,/<\/FilesMatch>/d' "${VHOST_FILE}" 2>/dev/null || true
    sed -i '/<FilesMatch.*php/,/<\/FilesMatch>/d' /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

    systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
    sleep 3

    TEST_RESULT=$(curl -sf http://127.0.0.1/test.php 2>/dev/null || echo '{"status":"fail"}')
    if echo "$TEST_RESULT" | grep -q '"ok"'; then
        log INFO "PHP is working through Apache (mod_php fallback)!"
    else
        log ERROR "PHP still not working. Apache error log:"
        tail -10 /var/log/apache2/*error*.log 2>/dev/null || true
        log WARN "Continuing anyway — installer test may fail later"
    fi
fi

# Clean up test file
rm -f "${WEBROOT}/test.php"

# Save webroot path for later scripts
echo "WEBROOT=${WEBROOT}" >> /opt/simulation/.panel_creds

log INFO "Website and database creation complete"
log INFO "  Domain:   ${SITE_DOMAIN}"
log INFO "  Webroot:  ${WEBROOT}"
log INFO "  Database: ${DB_NAME} (user: ${DB_USER})"
