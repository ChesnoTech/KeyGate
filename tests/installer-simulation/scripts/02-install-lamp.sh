#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 2: Install LAMP Stack
#
# Tries aaPanel API first, falls back to direct apt install.
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh

export DEBIAN_FRONTEND=noninteractive
export TZ=UTC

INSTALL_MODE="${INSTALL_MODE:-fast}"

log INFO "Installing LAMP stack (mode: ${INSTALL_MODE})..."

# If manual mode was set in step 1, everything is already installed
if [ -f /opt/simulation/.manual_mode ]; then
    log INFO "Manual mode — LAMP already installed in step 1"
    exit 0
fi

# ── Ensure PPA is available ─────────────────────────────────────
ensure_php_ppa() {
    if ! apt-cache show php8.3 > /dev/null 2>&1; then
        log INFO "Adding PHP 8.3 PPA..."
        add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3 || true
        apt-get update -y > /dev/null 2>&1 || true
    fi
}

# ═══════════════════════════════════════════════════════════════
# Install Apache
# ═══════════════════════════════════════════════════════════════
human_action "Installing Apache web server..."

if ! dpkg -l apache2 2>/dev/null | grep -q '^ii'; then
    log INFO "Installing Apache via apt..."
    apt-get install -y apache2 libapache2-mod-fcgid 2>&1 | tail -3 || true
fi

# Enable modules
a2enmod rewrite proxy_fcgi setenvif headers ssl 2>/dev/null || true

# Start Apache
systemctl enable apache2 2>/dev/null || true
systemctl start apache2 2>/dev/null || true

if systemctl is-active apache2 > /dev/null 2>&1; then
    APACHE_VER=$(apache2 -v 2>/dev/null | head -1 || echo "installed")
    log INFO "✅ Apache running: ${APACHE_VER}"
else
    log WARN "Apache not running via systemd, trying direct start..."
    apachectl start 2>/dev/null || true
    sleep 2
fi

# ═══════════════════════════════════════════════════════════════
# Install PHP 8.3
# ═══════════════════════════════════════════════════════════════
human_action "Installing PHP 8.3 + extensions..."

ensure_php_ppa

if ! dpkg -l php8.3 2>/dev/null | grep -q '^ii'; then
    log INFO "Installing PHP 8.3 via apt..."
    apt-get install -y \
        php8.3 php8.3-fpm php8.3-cli \
        php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
        php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath \
        php8.3-redis php8.3-opcache php8.3-readline \
        libapache2-mod-php8.3 \
        2>&1 | tail -5 || true
fi

# Start PHP-FPM
systemctl enable php8.3-fpm 2>/dev/null || true
systemctl start php8.3-fpm 2>/dev/null || true

PHP_BIN=$(find_php)
PHP_VER=$($PHP_BIN -v 2>/dev/null | head -1 || echo "unknown")

if echo "$PHP_VER" | grep -q "8.3"; then
    log INFO "✅ PHP installed: ${PHP_VER}"
else
    log ERROR "PHP 8.3 not properly installed: ${PHP_VER}"
fi

# ═══════════════════════════════════════════════════════════════
# Install MariaDB
# ═══════════════════════════════════════════════════════════════
human_action "Installing MariaDB server..."

if ! dpkg -l mariadb-server 2>/dev/null | grep -q '^ii'; then
    log INFO "Installing MariaDB via apt..."
    apt-get install -y mariadb-server mariadb-client 2>&1 | tail -5 || true
fi

# Start MariaDB
systemctl enable mariadb 2>/dev/null || true
systemctl start mariadb 2>/dev/null || true

sleep 2

# Check if MariaDB is running
MYSQL_BIN=$(find_mysql)
if ! $MYSQL_BIN -u root -e "SELECT 1" > /dev/null 2>&1; then
    log WARN "MariaDB not responding, trying manual start..."
    mysqld_safe --user=mysql &
    sleep 5
fi

# Set root password
DB_ROOT_PASS="${DB_ROOT_PASS:-root_password_123}"
$MYSQL_BIN -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';" 2>/dev/null || \
$MYSQL_BIN -u root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_ROOT_PASS}');" 2>/dev/null || \
mysqladmin -u root password "${DB_ROOT_PASS}" 2>/dev/null || true

# Verify
if $MYSQL_BIN -u root -p"${DB_ROOT_PASS}" -e "SELECT 1" > /dev/null 2>&1; then
    DB_VER=$($MYSQL_BIN -u root -p"${DB_ROOT_PASS}" -e "SELECT VERSION()" -sN 2>/dev/null || echo "unknown")
    log INFO "✅ MariaDB running: ${DB_VER}"
elif $MYSQL_BIN -u root -e "SELECT 1" > /dev/null 2>&1; then
    # Root has no password (OK for simulation)
    DB_VER=$($MYSQL_BIN -u root -e "SELECT VERSION()" -sN 2>/dev/null || echo "unknown")
    log INFO "✅ MariaDB running (no root password): ${DB_VER}"
    # Store that root has no password
    echo "DB_ROOT_NO_PASS=1" >> /opt/simulation/.env
else
    log ERROR "MariaDB is not responding"
fi

# ═══════════════════════════════════════════════════════════════
# Verify Apache can serve PHP
# ═══════════════════════════════════════════════════════════════
human_action "Verifying Apache + PHP integration..."

# Enable mod_php as the simplest approach
a2enmod php8.3 2>/dev/null || true
# May need to switch to prefork for mod_php
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
sleep 2

# Quick PHP test
echo '<?php echo "PHP_OK";' > /var/www/html/phptest.php
TEST=$(curl -sf http://127.0.0.1/phptest.php 2>/dev/null || echo "FAIL")
rm -f /var/www/html/phptest.php

if [ "$TEST" = "PHP_OK" ]; then
    log INFO "✅ Apache + PHP integration working"
else
    log WARN "Apache + PHP test failed (will fix in step 4)"
fi

log INFO "LAMP stack installation complete"
