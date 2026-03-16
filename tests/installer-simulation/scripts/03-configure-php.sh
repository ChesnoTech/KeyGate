#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 3: Configure PHP Extensions & Settings
#
# Simulates: Human goes to aaPanel → PHP 8.3 → Settings tab
#            Enables extensions, adjusts php.ini values
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set -u  # Error on undefined vars, but don't exit on command failures

log INFO "Configuring PHP 8.3 environment..."

PHP_BIN=$(find_php)

# ── Find php.ini ────────────────────────────────────────────────
PHP_INI=$($PHP_BIN --ini 2>/dev/null | grep "Loaded Configuration" | awk -F: '{print $2}' | tr -d ' ')

if [ -z "$PHP_INI" ] || [ ! -f "$PHP_INI" ]; then
    # Try common locations
    for ini_path in \
        /www/server/php/83/etc/php.ini \
        /etc/php/8.3/fpm/php.ini \
        /etc/php/8.3/apache2/php.ini \
        /etc/php/8.3/cli/php.ini; do
        if [ -f "$ini_path" ]; then
            PHP_INI="$ini_path"
            break
        fi
    done
fi

if [ -z "$PHP_INI" ] || [ ! -f "$PHP_INI" ]; then
    # PHP is installed but no php.ini — create a minimal one
    log WARN "No php.ini found — creating minimal config"
    PHP_INI="/etc/php/8.3/cli/php.ini"
    mkdir -p "$(dirname "$PHP_INI")" 2>/dev/null || true
    cat > "$PHP_INI" <<'MININI'
[PHP]
engine = On
memory_limit = 128M
max_execution_time = 30
display_errors = Off
log_errors = On
file_uploads = On
upload_max_filesize = 2M
date.timezone = UTC
MININI
fi

log INFO "Found php.ini at: ${PHP_INI}"

# ── Verify required extensions ──────────────────────────────────
human_action "Checking required PHP extensions in aaPanel..."

REQUIRED_EXTS=(pdo pdo_mysql json mbstring openssl curl session)
OPTIONAL_EXTS=(redis gd zip intl bcmath)

MISSING_REQUIRED=()
for ext in "${REQUIRED_EXTS[@]}"; do
    if $PHP_BIN -m 2>/dev/null | grep -qi "^${ext}$"; then
        log INFO "  ✅ ${ext} — enabled"
    else
        log WARN "  ❌ ${ext} — MISSING, will try to enable"
        MISSING_REQUIRED+=("$ext")
    fi
done

for ext in "${OPTIONAL_EXTS[@]}"; do
    if $PHP_BIN -m 2>/dev/null | grep -qi "^${ext}$"; then
        log INFO "  ✅ ${ext} — enabled (optional)"
    else
        log INFO "  ⚠️  ${ext} — not installed (optional, will try)"
    fi
done

# ── Enable missing extensions ───────────────────────────────────
if [ ${#MISSING_REQUIRED[@]} -gt 0 ]; then
    human_action "Enabling missing extensions in aaPanel PHP settings..."

    for ext in "${MISSING_REQUIRED[@]}"; do
        # aaPanel stores extension configs in /www/server/php/83/etc/php.d/
        EXT_INI_DIR="/www/server/php/83/etc/php.d"
        if [ -d "$EXT_INI_DIR" ]; then
            echo "extension=${ext}.so" > "${EXT_INI_DIR}/${ext}.ini"
            log INFO "  Enabled ${ext} via php.d/"
        fi

        # Also try phpenmod for system PHP
        if command -v phpenmod &> /dev/null; then
            phpenmod "$ext" 2>/dev/null || true
        fi
    done
fi

# ── Configure PHP settings (like adjusting sliders in aaPanel) ──
human_action "Adjusting PHP settings in aaPanel..."

# Backup original
cp "$PHP_INI" "${PHP_INI}.bak"

# Apply settings
declare -A PHP_SETTINGS=(
    [memory_limit]="256M"
    [max_execution_time]="300"
    [max_input_time]="300"
    [post_max_size]="64M"
    [upload_max_filesize]="64M"
    [max_file_uploads]="20"
    [file_uploads]="On"
    [allow_url_fopen]="On"
    [date.timezone]="UTC"
    [session.gc_maxlifetime]="7200"
    [opcache.enable]="1"
    [opcache.memory_consumption]="128"
    [opcache.interned_strings_buffer]="16"
    [opcache.max_accelerated_files]="10000"
    [opcache.validate_timestamps]="1"
    [opcache.revalidate_freq]="2"
    [display_errors]="Off"
    [log_errors]="On"
    [error_reporting]="E_ALL & ~E_DEPRECATED & ~E_STRICT"
)

for key in "${!PHP_SETTINGS[@]}"; do
    val="${PHP_SETTINGS[$key]}"

    # Check if setting exists (commented or uncommented)
    if grep -qP "^;?\s*${key}\s*=" "$PHP_INI"; then
        # Replace existing (including commented-out)
        sed -i "s|^;*\s*${key}\s*=.*|${key} = ${val}|" "$PHP_INI"
    else
        # Append to end
        echo "${key} = ${val}" >> "$PHP_INI"
    fi

    log INFO "  Set ${key} = ${val}"
done

# ── Also update FPM-specific php.ini if separate ────────────────
FPM_INI="/etc/php/8.3/fpm/php.ini"
if [ -f "$FPM_INI" ] && [ "$FPM_INI" != "$PHP_INI" ]; then
    human_action "Also updating PHP-FPM php.ini..."
    cp "$FPM_INI" "${FPM_INI}.bak"
    for key in "${!PHP_SETTINGS[@]}"; do
        val="${PHP_SETTINGS[$key]}"
        if grep -qP "^;?\s*${key}\s*=" "$FPM_INI"; then
            sed -i "s|^;*\s*${key}\s*=.*|${key} = ${val}|" "$FPM_INI"
        else
            echo "${key} = ${val}" >> "$FPM_INI"
        fi
    done
fi

# ── Configure PHP-FPM pool settings ────────────────────────────
human_action "Adjusting PHP-FPM pool settings for performance..."

FPM_POOL="/www/server/php/83/etc/php-fpm.d/www.conf"
if [ ! -f "$FPM_POOL" ]; then
    FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"
fi

if [ -f "$FPM_POOL" ]; then
    sed -i 's|^pm\s*=.*|pm = dynamic|' "$FPM_POOL"
    sed -i 's|^pm.max_children\s*=.*|pm.max_children = 20|' "$FPM_POOL"
    sed -i 's|^pm.start_servers\s*=.*|pm.start_servers = 5|' "$FPM_POOL"
    sed -i 's|^pm.min_spare_servers\s*=.*|pm.min_spare_servers = 3|' "$FPM_POOL"
    sed -i 's|^pm.max_spare_servers\s*=.*|pm.max_spare_servers = 10|' "$FPM_POOL"
    log INFO "PHP-FPM pool settings updated"
fi

# ── Restart PHP-FPM ────────────────────────────────────────────
human_action "Restarting PHP-FPM to apply changes..."

/etc/init.d/php-fpm-83 restart 2>/dev/null || \
    systemctl restart php8.3-fpm 2>/dev/null || \
    /www/server/php/83/sbin/php-fpm --fpm-config /www/server/php/83/etc/php-fpm.conf 2>/dev/null || \
    true

sleep 2

# ── Verify configuration ───────────────────────────────────────
log INFO "Verifying PHP configuration..."

MEM_LIMIT=$($PHP_BIN -r "echo ini_get('memory_limit');" 2>/dev/null || echo "unknown")
MAX_EXEC=$($PHP_BIN -r "echo ini_get('max_execution_time');" 2>/dev/null || echo "unknown")
UPLOAD=$($PHP_BIN -r "echo ini_get('upload_max_filesize');" 2>/dev/null || echo "unknown")
log INFO "  memory_limit: ${MEM_LIMIT}"
log INFO "  max_execution_time: ${MAX_EXEC}"
log INFO "  upload_max_filesize: ${UPLOAD}"

# Verify extensions
EXT_COUNT=$($PHP_BIN -m 2>/dev/null | wc -l)
log INFO "  Loaded extensions: ${EXT_COUNT}"

log INFO "PHP configuration complete"
