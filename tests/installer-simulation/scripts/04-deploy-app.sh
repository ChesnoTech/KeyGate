#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 4: Deploy Application Files to Web Root
#
# Simulates: Human uploads OEM Activation System files via aaPanel
#            File Manager, sets permissions, prepares for installer
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set +e

SITE_DOMAIN="${SITE_DOMAIN:-oem-system.local}"
WEBROOT="${WEBROOT:-/www/wwwroot/${SITE_DOMAIN}}"
APP_SOURCE="/opt/oem-app"

# ═══════════════════════════════════════════════════════════════════
# Upload files (simulates aaPanel File Manager upload)
# ═══════════════════════════════════════════════════════════════════
human_action "Opening aaPanel -> File Manager -> navigating to ${WEBROOT}"
human_action "Clicking Upload -> selecting OEM Activation System package..."

if [ ! -d "$APP_SOURCE" ]; then
    log ERROR "Application source not found at ${APP_SOURCE}"
    exit 1
fi

FILE_COUNT=$(find "$APP_SOURCE" -type f | wc -l)
log INFO "Deploying ${FILE_COUNT} files to ${WEBROOT}..."

human_action "Extracting files to web root..."
cp -a "${APP_SOURCE}/." "${WEBROOT}/"
check_result "Files copied to webroot" $?

# ═══════════════════════════════════════════════════════════════════
# Set permissions (simulates right-click -> Permissions in aaPanel)
# ═══════════════════════════════════════════════════════════════════
human_action "Right-click -> Permissions -> Setting ownership to www-data..."

APACHE_USER="www-data"
if id -u www &> /dev/null; then
    APACHE_USER="www"  # aaPanel uses 'www' user
fi

chown -R ${APACHE_USER}:${APACHE_USER} "${WEBROOT}/"
find "${WEBROOT}" -type d -exec chmod 755 {} \;
find "${WEBROOT}" -type f -exec chmod 644 {} \;
log INFO "Ownership: ${APACHE_USER}, dirs: 755, files: 644"

# Writable directories
for dir in uploads uploads/branding database logs backups; do
    full_path="${WEBROOT}/${dir}"
    mkdir -p "$full_path"
    chmod 775 "$full_path"
    chown ${APACHE_USER}:${APACHE_USER} "$full_path"
done
log INFO "Writable directories created"

# ═══════════════════════════════════════════════════════════════════
# Prepare for fresh install
# ═══════════════════════════════════════════════════════════════════
human_action "Removing any existing config.php (installer will generate fresh one)..."

if [ -f "${WEBROOT}/config.php" ]; then
    mv "${WEBROOT}/config.php" "${WEBROOT}/config.php.default"
    log INFO "Renamed config.php -> config.php.default"
fi
rm -f "${WEBROOT}/install.lock"

# ═══════════════════════════════════════════════════════════════════
# Verify deployment
# ═══════════════════════════════════════════════════════════════════
human_action "Verifying deployed files..."

CRITICAL_FILES=(
    "install/index.php" "install/ajax.php" "constants.php"
    "database/install.sql" "api/health.php"
    "secure-admin.php" "admin_v2.php" "index.php"
)

ALL_FOUND=true
for f in "${CRITICAL_FILES[@]}"; do
    if [ -f "${WEBROOT}/${f}" ]; then
        log INFO "  ${f}"
    else
        log ERROR "  MISSING: ${f}"
        ALL_FOUND=false
    fi
done

if [ "$ALL_FOUND" = false ]; then
    log ERROR "Critical files missing!"
    exit 1
fi

MIGRATION_COUNT=$(find "${WEBROOT}/database" -name "*.sql" -type f | wc -l)
log INFO "Found ${MIGRATION_COUNT} SQL migration files"

# Test installer page is accessible
INSTALL_HTTP=$(curl -sf http://127.0.0.1/install/ -o /dev/null -w '%{http_code}' 2>/dev/null || echo "000")
if [ "$INSTALL_HTTP" = "200" ]; then
    log INFO "Installer page: HTTP 200 (accessible)"
else
    log WARN "Installer returned HTTP ${INSTALL_HTTP}, restarting Apache..."
    systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
    sleep 2
    INSTALL_HTTP=$(curl -sf http://127.0.0.1/install/ -o /dev/null -w '%{http_code}' 2>/dev/null || echo "000")
    log INFO "After restart: HTTP ${INSTALL_HTTP}"
fi

DEPLOYED_COUNT=$(find "${WEBROOT}" -type f | wc -l)
log INFO "Deployment complete: ${DEPLOYED_COUNT} files in ${WEBROOT}"

exit 0
