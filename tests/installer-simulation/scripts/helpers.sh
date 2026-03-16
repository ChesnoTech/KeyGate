#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Shared helper functions for all simulation scripts
# ═══════════════════════════════════════════════════════════════════

LOG_FILE="${LOG_FILE:-/var/log/oem-simulation.log}"
export DEBIAN_FRONTEND=noninteractive
export TZ=UTC

# ── Colors ───────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log() {
    local level="$1"; shift
    local msg="$*"
    local ts=$(date '+%Y-%m-%d %H:%M:%S')
    local color="$NC"
    case "$level" in
        INFO)  color="$GREEN"  ;;
        WARN)  color="$YELLOW" ;;
        ERROR) color="$RED"    ;;
        STEP)  color="$CYAN"   ;;
        HUMAN) color="$BLUE"   ;;
    esac
    echo -e "${color}[${ts}] [${level}]${NC} ${msg}" | tee -a "$LOG_FILE"
}

human_action() {
    log HUMAN "👤 $*"
    sleep 2
}

check_result() {
    local desc="$1"
    local exit_code="$2"
    if [ "$exit_code" -eq 0 ]; then
        log INFO "✅ ${desc}"
    else
        log ERROR "❌ ${desc} (exit code: ${exit_code})"
        return "$exit_code"
    fi
}

wait_for_service() {
    local name="$1"
    local check_cmd="$2"
    local max_wait="${3:-120}"
    local waited=0
    log INFO "Waiting for ${name} (max ${max_wait}s)..."
    while [ $waited -lt $max_wait ]; do
        if eval "$check_cmd" > /dev/null 2>&1; then
            log INFO "${name} ready (${waited}s)"
            return 0
        fi
        sleep 5
        waited=$((waited + 5))
    done
    log ERROR "${name} not ready after ${max_wait}s"
    return 1
}

# ── Find PHP binary (aaPanel or system) ─────────────────────────
find_php() {
    if [ -f /www/server/php/83/bin/php ]; then
        echo "/www/server/php/83/bin/php"
    elif command -v php8.3 &> /dev/null; then
        echo "php8.3"
    elif command -v php &> /dev/null; then
        echo "php"
    else
        echo "php"
    fi
}

# ── Find MySQL/MariaDB client binary ───────────────────────────
find_mysql() {
    if [ -f /www/server/mariadb/bin/mariadb ]; then
        echo "/www/server/mariadb/bin/mariadb"
    elif command -v mariadb &> /dev/null; then
        echo "mariadb"
    elif [ -f /www/server/mariadb/bin/mysql ]; then
        echo "/www/server/mariadb/bin/mysql"
    elif command -v mysql &> /dev/null; then
        echo "mysql"
    else
        echo "mysql"
    fi
}

# ── Find PHP-FPM socket ────────────────────────────────────────
find_php_fpm_socket() {
    if [ -S /tmp/php-cgi-83.sock ]; then
        echo "/tmp/php-cgi-83.sock"
    elif [ -S /run/php/php8.3-fpm.sock ]; then
        echo "/run/php/php8.3-fpm.sock"
    elif [ -S /var/run/php/php8.3-fpm.sock ]; then
        echo "/var/run/php/php8.3-fpm.sock"
    else
        echo "/run/php/php8.3-fpm.sock"
    fi
}

# ── Manual LAMP setup (fallback if aaPanel unavailable) ─────────
setup_lamp_manually() {
    log WARN "Setting up LAMP stack manually (aaPanel not available)..."

    # Pre-set timezone to prevent interactive prompt
    echo "Etc/UTC" > /etc/timezone
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime
    export DEBIAN_FRONTEND=noninteractive
    export TZ=UTC

    # Check if packages are pre-installed (Docker build installs them)
    if dpkg -l apache2 2>/dev/null | grep -q '^ii' && \
       dpkg -l php8.3 2>/dev/null | grep -q '^ii' && \
       dpkg -l mariadb-server 2>/dev/null | grep -q '^ii'; then
        log INFO "  LAMP packages already pre-installed"
    else
        # Try to install (needs DNS/internet)
        apt-get update -y > /dev/null 2>&1 || true

        log INFO "  Installing Apache..."
        apt-get install -y -o Dpkg::Options::="--force-confold" \
            apache2 libapache2-mod-fcgid 2>&1 | tail -3 || true

        log INFO "  Adding PHP 8.3 PPA..."
        add-apt-repository -y ppa:ondrej/php 2>&1 | tail -3 || true
        apt-get update -y > /dev/null 2>&1 || true

        log INFO "  Installing PHP 8.3 + extensions..."
        apt-get install -y -o Dpkg::Options::="--force-confold" \
            php8.3 php8.3-fpm php8.3-cli \
            php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
            php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath \
            php8.3-opcache \
            2>&1 | tail -5 || true

        log INFO "  Installing MariaDB..."
        apt-get install -y -o Dpkg::Options::="--force-confold" \
            mariadb-server mariadb-client 2>&1 | tail -5 || true
    fi

    # Enable Apache modules
    a2enmod rewrite proxy_fcgi setenvif ssl headers 2>/dev/null || true

    # Enable mod_php for simplest Apache+PHP setup
    a2enmod php8.3 2>/dev/null || true
    a2dismod mpm_event 2>/dev/null || true
    a2enmod mpm_prefork 2>/dev/null || true

    # Start services
    log INFO "  Starting services..."
    systemctl enable apache2 2>/dev/null || true
    systemctl enable php8.3-fpm 2>/dev/null || true
    systemctl enable mariadb 2>/dev/null || true
    systemctl start apache2 2>/dev/null || true
    systemctl start php8.3-fpm 2>/dev/null || true
    systemctl start mariadb 2>/dev/null || true

    # Give services time to start
    sleep 3

    # Verify each service
    if systemctl is-active apache2 > /dev/null 2>&1; then
        log INFO "  ✅ Apache running"
    else
        log WARN "  ⚠️ Apache not running, trying manual start..."
        apache2ctl start 2>/dev/null || /usr/sbin/apache2 -k start 2>/dev/null || true
    fi

    if systemctl is-active php8.3-fpm > /dev/null 2>&1; then
        log INFO "  ✅ PHP-FPM running"
    else
        log WARN "  ⚠️ PHP-FPM not running, trying manual start..."
        /usr/sbin/php-fpm8.3 2>/dev/null || true
    fi

    if systemctl is-active mariadb > /dev/null 2>&1; then
        log INFO "  ✅ MariaDB running"
    else
        log WARN "  ⚠️ MariaDB not running, trying manual start..."
        mysqld_safe --user=mysql 2>/dev/null &
        sleep 3
    fi

    log INFO "Manual LAMP setup complete"
}
