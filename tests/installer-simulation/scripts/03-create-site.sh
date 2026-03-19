#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 3: Create Website + Database via aaPanel
#
# Simulates: Human opens aaPanel -> Websites -> Add Site
#            Then aaPanel -> Databases -> Add Database
#            Configures Apache vhost, creates MariaDB DB + user
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set +e

SITE_DOMAIN="${SITE_DOMAIN:-oem-system.local}"
DB_NAME="${DB_NAME:-oem_activation}"
DB_USER="${DB_USER:-oem_user}"
DB_PASS="${DB_PASS:-oem_password_123}"
DB_ROOT_PASS="${DB_ROOT_PASS:-root_password_123}"

MYSQL_BIN=$(find_mysql)

# ═══════════════════════════════════════════════════════════════════
# Create Database (aaPanel -> Databases -> Add Database)
# ═══════════════════════════════════════════════════════════════════
human_action "Opening aaPanel -> Databases -> Add Database"
human_action "Database name: ${DB_NAME}, Username: ${DB_USER}"

# Determine mysql auth
MYSQL_AUTH="-u root"
if $MYSQL_BIN -u root -p"${DB_ROOT_PASS}" -e "SELECT 1" > /dev/null 2>&1; then
    MYSQL_AUTH="-u root -p${DB_ROOT_PASS}"
elif $MYSQL_BIN -u root -e "SELECT 1" > /dev/null 2>&1; then
    MYSQL_AUTH="-u root"
else
    log ERROR "Cannot connect to MariaDB as root"
    exit 1
fi

$MYSQL_BIN $MYSQL_AUTH -e "
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
" 2>/dev/null
check_result "Database '${DB_NAME}' created" $?

$MYSQL_BIN $MYSQL_AUTH -e "
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    FLUSH PRIVILEGES;
" 2>/dev/null
check_result "User '${DB_USER}' created with grants" $?

# Verify app user can connect
$MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" -e "USE ${DB_NAME}; SELECT 1;" > /dev/null 2>&1
check_result "DB connection verified (as ${DB_USER})" $?

# ═══════════════════════════════════════════════════════════════════
# Create Website / Apache Virtual Host
# ═══════════════════════════════════════════════════════════════════
human_action "Opening aaPanel -> Websites -> Add Site"
human_action "Domain: ${SITE_DOMAIN}, PHP: 8.3, Server: Apache"

WEBROOT="/www/wwwroot/${SITE_DOMAIN}"
mkdir -p "$WEBROOT"

# Determine Apache vhost directory
if [ -d /www/server/apache/conf/vhost ]; then
    VHOST_DIR="/www/server/apache/conf/vhost"
elif [ -d /etc/apache2/sites-available ]; then
    VHOST_DIR="/etc/apache2/sites-available"
else
    mkdir -p /etc/apache2/sites-available
    VHOST_DIR="/etc/apache2/sites-available"
fi

# Find PHP-FPM socket
PHP_FPM_SOCKET=$(find_php_fpm_socket)
if [ -S "$PHP_FPM_SOCKET" ]; then
    PHP_FPM_HANDLER="SetHandler \"proxy:unix:${PHP_FPM_SOCKET}|fcgi://localhost\""
else
    PHP_FPM_HANDLER='SetHandler "proxy:fcgi://127.0.0.1:9000"'
fi

# Write vhost config
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

    <FilesMatch \.php$>
        ${PHP_FPM_HANDLER}
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"

    ErrorLog \${APACHE_LOG_DIR}/${SITE_DOMAIN}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_DOMAIN}-access.log combined
</VirtualHost>
VHOST
log INFO "Vhost config: ${VHOST_FILE}"

# Also set as default site (localhost:80)
if [ -d /etc/apache2/sites-available ]; then
    a2dissite 000-default 2>/dev/null || true
    ln -sf "$VHOST_FILE" /etc/apache2/sites-enabled/${SITE_DOMAIN}.conf 2>/dev/null || true

    # Also override default to point to our webroot
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

# Enable modules
a2enmod rewrite proxy_fcgi setenvif headers ssl 2>/dev/null || true

# Restart Apache
apachectl configtest 2>&1 || apache2ctl configtest 2>&1 || true
systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
sleep 2

# ── Test PHP via web ──────────────────────────────────────────────
cat > "${WEBROOT}/test.php" <<'PHP'
<?php echo json_encode(['status'=>'ok','php'=>PHP_VERSION]);
PHP

TEST=$(curl -sf http://127.0.0.1/test.php 2>/dev/null || echo "")
if echo "$TEST" | grep -q '"ok"'; then
    log INFO "PHP working via Apache (proxy_fcgi)"
else
    log WARN "proxy_fcgi failed, switching to mod_php..."
    apt-get install -y libapache2-mod-php8.3 > /dev/null 2>&1 || true
    a2enmod php8.3 2>/dev/null || true
    a2dismod mpm_event 2>/dev/null || true
    a2enmod mpm_prefork 2>/dev/null || true

    # Remove FPM handler from vhosts
    sed -i '/<FilesMatch.*php/,/<\/FilesMatch>/d' "${VHOST_FILE}" 2>/dev/null || true
    sed -i '/<FilesMatch.*php/,/<\/FilesMatch>/d' /etc/apache2/sites-available/000-default.conf 2>/dev/null || true

    systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
    sleep 3

    TEST=$(curl -sf http://127.0.0.1/test.php 2>/dev/null || echo "")
    if echo "$TEST" | grep -q '"ok"'; then
        log INFO "PHP working via mod_php (fallback)"
    else
        log ERROR "PHP still not working via Apache"
        tail -5 /var/log/apache2/*error*.log 2>/dev/null || true
    fi
fi
rm -f "${WEBROOT}/test.php"

# Save webroot for later scripts
echo "WEBROOT=${WEBROOT}" >> /opt/simulation/.env

# ── .htaccess security rules ─────────────────────────────────────
cat > "${WEBROOT}/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^config\.php$ - [F,L]
    RewriteRule ^constants\.php$ - [F,L]
    RewriteRule ^install\.lock$ - [F,L]
    RewriteRule ^database/ - [F,L]
    RewriteRule ^controllers/ - [F,L]
    RewriteRule ^functions/ - [F,L]
</IfModule>
<FilesMatch "\.(sql|sh|log)$">
    Require all denied
</FilesMatch>
HTACCESS

log INFO "Site creation complete:"
log INFO "  Domain:   ${SITE_DOMAIN}"
log INFO "  Webroot:  ${WEBROOT}"
log INFO "  Database: ${DB_NAME} (user: ${DB_USER})"

exit 0
