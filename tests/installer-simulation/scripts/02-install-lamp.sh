#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 2: Install LAMP Stack via aaPanel API (or manual fallback)
#
# Simulates: Human opens aaPanel web UI -> App Store -> selects
#            Apache, PHP 8.3, MariaDB 10.11 -> clicks Install
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set +e

# If manual mode (no aaPanel), LAMP was already installed in step 1
if [ -f /opt/simulation/.manual_mode ]; then
    log INFO "Manual mode -- LAMP already installed in step 1"

    # Still need to verify and set root password
    MYSQL_BIN=$(find_mysql)
    DB_ROOT_PASS="${DB_ROOT_PASS:-root_password_123}"

    # Ensure services are running
    systemctl start apache2 php8.3-fpm mariadb 2>/dev/null || true
    sleep 2

    # Verify Apache + PHP
    echo '<?php echo "PHP_OK";' > /var/www/html/phptest.php
    TEST=$(curl -sf http://127.0.0.1/phptest.php 2>/dev/null || echo "FAIL")
    rm -f /var/www/html/phptest.php
    if [ "$TEST" = "PHP_OK" ]; then
        log INFO "Apache + PHP working"
    else
        log WARN "Apache + PHP test failed -- will fix in vhost config"
    fi

    exit 0
fi

# ═══════════════════════════════════════════════════════════════════
# aaPanel mode: Install via aaPanel API / bt CLI
# ═══════════════════════════════════════════════════════════════════

human_action "Opening aaPanel web UI at http://server:${AAPANEL_PORT:-8888}"
human_action "Navigating to App Store..."
human_action "Selecting LAMP stack: Apache 2.4, PHP 8.3, MariaDB 10.11"

# ── Install Apache via aaPanel ────────────────────────────────────
log INFO "Installing Apache via aaPanel..."
human_action "Clicking Install on Apache 2.4..."

# bt CLI is the easiest way to install software in aaPanel
if command -v bt &>/dev/null; then
    # Install Apache
    bt install apache 2>&1 | tee -a "$LOG_FILE" | tail -5 || true
    sleep 5

    # Install PHP 8.3
    human_action "Clicking Install on PHP 8.3..."
    bt install php 8.3 2>&1 | tee -a "$LOG_FILE" | tail -5 || true
    sleep 5

    # Install MariaDB
    human_action "Clicking Install on MariaDB 10.11..."
    bt install mysql mariadb_10.11 2>&1 | tee -a "$LOG_FILE" | tail -5 || true
    sleep 5
fi

# ── Verify or fallback to apt ─────────────────────────────────────
# Check if aaPanel installed things (they go to /www/server/)
APACHE_OK=false
PHP_OK=false
MARIA_OK=false

if [ -f /www/server/apache/bin/httpd ] || systemctl is-active apache2 &>/dev/null; then
    APACHE_OK=true
    log INFO "Apache: installed"
fi

PHP_BIN=$(find_php)
if $PHP_BIN -v 2>/dev/null | grep -q "8.3"; then
    PHP_OK=true
    log INFO "PHP 8.3: installed ($($PHP_BIN -v 2>/dev/null | head -1))"
fi

MYSQL_BIN=$(find_mysql)
if $MYSQL_BIN --version 2>/dev/null | grep -qi "maria"; then
    MARIA_OK=true
    log INFO "MariaDB: installed ($($MYSQL_BIN --version 2>/dev/null | head -1))"
fi

# Fallback: install via apt what's missing
if [ "$APACHE_OK" = false ]; then
    log WARN "Apache not found via aaPanel, installing via apt..."
    apt-get update -y > /dev/null 2>&1 || true
    apt-get install -y apache2 libapache2-mod-fcgid 2>&1 | tail -3 || true
    a2enmod rewrite proxy_fcgi setenvif headers ssl 2>/dev/null || true
    systemctl enable apache2 && systemctl start apache2 2>/dev/null || true
fi

if [ "$PHP_OK" = false ]; then
    log WARN "PHP 8.3 not found via aaPanel, installing via apt..."
    add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3 || true
    apt-get update -y > /dev/null 2>&1 || true
    apt-get install -y \
        php8.3 php8.3-fpm php8.3-cli \
        php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
        php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath \
        php8.3-redis php8.3-opcache php8.3-readline \
        libapache2-mod-php8.3 \
        2>&1 | tail -5 || true
    systemctl enable php8.3-fpm && systemctl start php8.3-fpm 2>/dev/null || true
fi

if [ "$MARIA_OK" = false ]; then
    log WARN "MariaDB not found via aaPanel, installing via apt..."
    apt-get install -y mariadb-server mariadb-client 2>&1 | tail -5 || true
    systemctl enable mariadb && systemctl start mariadb 2>/dev/null || true
    sleep 3
fi

# ── Set MariaDB root password ─────────────────────────────────────
DB_ROOT_PASS="${DB_ROOT_PASS:-root_password_123}"
MYSQL_BIN=$(find_mysql)

# Try to set password (may already have one from aaPanel)
$MYSQL_BIN -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS}';" 2>/dev/null || \
$MYSQL_BIN -u root -p"${DB_ROOT_PASS}" -e "SELECT 1" 2>/dev/null || \
$MYSQL_BIN -u root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_ROOT_PASS}');" 2>/dev/null || true

# Verify MariaDB connectivity
if $MYSQL_BIN -u root -p"${DB_ROOT_PASS}" -e "SELECT VERSION()" -sN 2>/dev/null; then
    log INFO "MariaDB root access: OK"
elif $MYSQL_BIN -u root -e "SELECT VERSION()" -sN 2>/dev/null; then
    log INFO "MariaDB root access: OK (no password)"
    echo "DB_ROOT_NO_PASS=1" >> /opt/simulation/.env
else
    log ERROR "Cannot connect to MariaDB"
fi

# ── Configure PHP settings ────────────────────────────────────────
human_action "Going to aaPanel -> PHP 8.3 -> Settings..."
human_action "Adjusting php.ini: memory_limit, upload_max, max_execution_time..."

PHP_BIN=$(find_php)
PHP_INI=$($PHP_BIN -r "echo php_ini_loaded_file();" 2>/dev/null || echo "")

if [ -n "$PHP_INI" ] && [ -f "$PHP_INI" ]; then
    log INFO "Configuring php.ini: ${PHP_INI}"

    # Apply settings like a human would in aaPanel UI
    declare -A PHP_SETTINGS=(
        ["memory_limit"]="256M"
        ["max_execution_time"]="120"
        ["max_input_time"]="120"
        ["upload_max_filesize"]="64M"
        ["post_max_size"]="64M"
        ["max_input_vars"]="3000"
        ["date.timezone"]="UTC"
        ["display_errors"]="Off"
        ["log_errors"]="On"
        ["session.cookie_httponly"]="1"
        ["session.use_strict_mode"]="1"
    )

    for key in "${!PHP_SETTINGS[@]}"; do
        val="${PHP_SETTINGS[$key]}"
        # Uncomment and set the value
        sed -i "s|^;*\s*${key}\s*=.*|${key} = ${val}|" "$PHP_INI" 2>/dev/null || true
    done

    log INFO "PHP settings applied"
else
    log WARN "Could not locate php.ini"
fi

# ── Enable mod_php for simplest Apache+PHP ────────────────────────
human_action "Configuring Apache to use PHP..."
a2enmod php8.3 2>/dev/null || true
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true
systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
sleep 2

# Quick test
echo '<?php echo "PHP_OK";' > /var/www/html/phptest.php
TEST=$(curl -sf http://127.0.0.1/phptest.php 2>/dev/null || echo "FAIL")
rm -f /var/www/html/phptest.php

if [ "$TEST" = "PHP_OK" ]; then
    log INFO "Apache + PHP integration: working"
else
    log WARN "Apache + PHP test failed (will retry after vhost config)"
fi

# ── Summary ───────────────────────────────────────────────────────
log INFO "LAMP stack installation complete:"
log INFO "  Apache:  $(apache2 -v 2>/dev/null | head -1 || /www/server/apache/bin/httpd -v 2>/dev/null | head -1 || echo 'installed')"
log INFO "  PHP:     $($(find_php) -v 2>/dev/null | head -1 || echo 'installed')"
log INFO "  MariaDB: $($(find_mysql) --version 2>/dev/null | head -1 || echo 'installed')"

exit 0
