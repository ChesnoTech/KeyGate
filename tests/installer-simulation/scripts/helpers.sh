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
    log HUMAN "  $*"
    sleep 2
}

check_result() {
    local desc="$1"
    local exit_code="$2"
    if [ "$exit_code" -eq 0 ]; then
        log INFO "  ${desc}"
    else
        log ERROR "  ${desc} (exit code: ${exit_code})"
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

# ── aaPanel API helper ───────────────────────────────────────────
# Uses the aaPanel panel API (bt CLI provides the key)
bt_api() {
    local action="$1"
    shift
    local bt_key=""
    local bt_panel=""

    # Get panel URL and key
    if [ -f /www/server/panel/data/default.pl ]; then
        bt_panel="http://127.0.0.1:$(cat /www/server/panel/data/port.pl 2>/dev/null || echo 8888)"
        bt_key=$(cat /www/server/panel/data/token.pl 2>/dev/null || echo "")
    fi

    if [ -z "$bt_key" ]; then
        log WARN "aaPanel API key not found"
        return 1
    fi

    local ts=$(date +%s)
    local token=$(echo -n "${bt_key}${ts}" | md5sum | awk '{print $1}')

    curl -sf -X POST "${bt_panel}/${action}" \
        -d "request_time=${ts}&request_token=${token}" \
        "$@" 2>/dev/null
}

# ── Find PHP binary ──────────────────────────────────────────────
find_php() {
    for p in /www/server/php/83/bin/php /www/server/php/80/bin/php php8.3 php; do
        if command -v "$p" &>/dev/null || [ -x "$p" ]; then
            echo "$p"
            return
        fi
    done
    echo "php"
}

# ── Find MySQL binary ────────────────────────────────────────────
find_mysql() {
    for p in /www/server/mariadb/bin/mariadb /www/server/mariadb/bin/mysql mariadb mysql; do
        if command -v "$p" &>/dev/null || [ -x "$p" ]; then
            echo "$p"
            return
        fi
    done
    echo "mysql"
}

# ── Find PHP-FPM socket ─────────────────────────────────────────
find_php_fpm_socket() {
    for s in /tmp/php-cgi-83.sock /run/php/php8.3-fpm.sock /var/run/php/php8.3-fpm.sock; do
        if [ -S "$s" ]; then
            echo "$s"
            return
        fi
    done
    echo "/run/php/php8.3-fpm.sock"
}

# ── Manual LAMP setup (fallback if aaPanel unavailable) ──────────
setup_lamp_manually() {
    log WARN "Setting up LAMP stack manually (aaPanel not available)..."

    echo "Etc/UTC" > /etc/timezone
    ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime

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
        php8.3-opcache php8.3-readline \
        libapache2-mod-php8.3 \
        2>&1 | tail -5 || true

    log INFO "  Installing MariaDB..."
    apt-get install -y -o Dpkg::Options::="--force-confold" \
        mariadb-server mariadb-client 2>&1 | tail -5 || true

    # Enable Apache modules + mod_php
    a2enmod rewrite proxy_fcgi setenvif ssl headers 2>/dev/null || true
    a2enmod php8.3 2>/dev/null || true
    a2dismod mpm_event 2>/dev/null || true
    a2enmod mpm_prefork 2>/dev/null || true

    # Start services
    systemctl enable apache2 php8.3-fpm mariadb 2>/dev/null || true
    systemctl start apache2 php8.3-fpm mariadb 2>/dev/null || true
    sleep 3

    for svc in apache2 mariadb; do
        if systemctl is-active "$svc" > /dev/null 2>&1; then
            log INFO "  $svc running"
        else
            log WARN "  $svc not running"
        fi
    done

    log INFO "Manual LAMP setup complete"
}
