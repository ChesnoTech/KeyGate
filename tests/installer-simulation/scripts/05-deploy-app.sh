#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 5: Deploy Application Files to Web Root
#
# Simulates: Human uses aaPanel File Manager to upload the
#            OEM Activation System package and extract it
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set -u

# Load saved config
if [ -f /opt/simulation/.panel_creds ]; then
    source /opt/simulation/.panel_creds
fi

WEBROOT="${WEBROOT:-/www/wwwroot/${SITE_DOMAIN:-oem-system.local}}"
APP_SOURCE="/opt/oem-app"

log INFO "Deploying OEM Activation System to ${WEBROOT}..."

# ═══════════════════════════════════════════════════════════════
# Upload & Extract (simulates aaPanel File Manager)
# ═══════════════════════════════════════════════════════════════
human_action "Opening aaPanel → File Manager → navigating to ${WEBROOT}"
human_action "Clicking 'Upload' → selecting OEM Activation System package..."
human_action "Extracting files to web root..."

# Check source exists
if [ ! -d "$APP_SOURCE" ]; then
    log ERROR "Application source not found at ${APP_SOURCE}"
    exit 1
fi

# Count files to be deployed
FILE_COUNT=$(find "$APP_SOURCE" -type f | wc -l)
log INFO "Deploying ${FILE_COUNT} files..."

# Copy all application files to webroot
# Use rsync-like behavior: copy contents, not the directory itself
cp -a "${APP_SOURCE}/." "${WEBROOT}/"
check_result "Files copied to webroot" $?

# ═══════════════════════════════════════════════════════════════
# Set Permissions (like clicking 'Permissions' in aaPanel)
# ═══════════════════════════════════════════════════════════════
human_action "Right-clicking → Permissions → Setting proper ownership..."

# Set ownership (www-data for Apache)
APACHE_USER="www-data"
if id -u www &> /dev/null; then
    APACHE_USER="www"  # aaPanel uses 'www' user
fi

chown -R ${APACHE_USER}:${APACHE_USER} "${WEBROOT}/"
log INFO "Ownership set to ${APACHE_USER}"

# Set directory permissions
find "${WEBROOT}" -type d -exec chmod 755 {} \;
log INFO "Directory permissions set to 755"

# Set file permissions
find "${WEBROOT}" -type f -exec chmod 644 {} \;
log INFO "File permissions set to 644"

# Make specific directories writable (for uploads, logs, etc.)
human_action "Setting writable permissions on upload/log directories..."

WRITABLE_DIRS=(
    "uploads"
    "uploads/branding"
    "database"
)

for dir in "${WRITABLE_DIRS[@]}"; do
    full_path="${WEBROOT}/${dir}"
    if [ ! -d "$full_path" ]; then
        mkdir -p "$full_path"
        log INFO "  Created directory: ${dir}/"
    fi
    chmod 775 "$full_path"
    chown ${APACHE_USER}:${APACHE_USER} "$full_path"
    log INFO "  Set writable: ${dir}/"
done

# ═══════════════════════════════════════════════════════════════
# Remove config.php if exists (installer will generate it)
# ═══════════════════════════════════════════════════════════════
human_action "Checking for existing config.php..."

if [ -f "${WEBROOT}/config.php" ]; then
    # Rename it — don't delete (like a cautious human)
    mv "${WEBROOT}/config.php" "${WEBROOT}/config.php.default"
    log INFO "Renamed existing config.php → config.php.default"
    log INFO "  (The installer will generate a fresh config.php)"
fi

# Also remove install.lock if it exists
if [ -f "${WEBROOT}/install.lock" ]; then
    rm -f "${WEBROOT}/install.lock"
    log INFO "Removed existing install.lock"
fi

# ═══════════════════════════════════════════════════════════════
# Verify deployment
# ═══════════════════════════════════════════════════════════════
human_action "Verifying deployed files..."

# Check critical files exist
CRITICAL_FILES=(
    "install/index.php"
    "install/ajax.php"
    "constants.php"
    "database/install.sql"
    "api/health.php"
    "secure-admin.php"
    "admin_v2.php"
)

ALL_FOUND=true
for f in "${CRITICAL_FILES[@]}"; do
    if [ -f "${WEBROOT}/${f}" ]; then
        log INFO "  ✅ ${f}"
    else
        log ERROR "  ❌ ${f} — MISSING"
        ALL_FOUND=false
    fi
done

if [ "$ALL_FOUND" = false ]; then
    log ERROR "Some critical files are missing!"
    exit 1
fi

# Count SQL migration files
MIGRATION_COUNT=$(find "${WEBROOT}/database" -name "*.sql" -type f | wc -l)
log INFO "  Found ${MIGRATION_COUNT} SQL migration files"

# Verify install/index.php is accessible
INSTALL_CHECK=$(curl -sf http://127.0.0.1/install/ -o /dev/null -w '%{http_code}' 2>/dev/null || echo "000")
if [ "$INSTALL_CHECK" = "200" ]; then
    log INFO "Installer page accessible (HTTP 200)"
else
    log WARN "Installer returned HTTP ${INSTALL_CHECK} — may need Apache restart"
    # Restart Apache
    systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
    sleep 2
    INSTALL_CHECK=$(curl -sf http://127.0.0.1/install/ -o /dev/null -w '%{http_code}' 2>/dev/null || echo "000")
    log INFO "After restart: HTTP ${INSTALL_CHECK}"
fi

DEPLOYED_COUNT=$(find "${WEBROOT}" -type f | wc -l)
log INFO "Deployment complete: ${DEPLOYED_COUNT} files in ${WEBROOT}"
