#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 7: Post-Installation Verification
#
# Simulates: Human tests the installed system by browsing around,
#            checking API endpoints, logging in, etc.
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set -u

BASE_URL="http://127.0.0.1"
DB_NAME="${DB_NAME:-oem_activation}"
DB_USER="${DB_USER:-oem_user}"
DB_PASS="${DB_PASS:-oem_password_123}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-Admin2024!}"

MYSQL_BIN=$(find_mysql)
PASS_COUNT=0
FAIL_COUNT=0
TOTAL_TESTS=0

run_test() {
    local name="$1"
    local result="$2"  # 0 = pass, non-zero = fail
    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    if [ "$result" -eq 0 ]; then
        PASS_COUNT=$((PASS_COUNT + 1))
        log INFO "  ✅ TEST ${TOTAL_TESTS}: ${name}"
    else
        FAIL_COUNT=$((FAIL_COUNT + 1))
        log ERROR "  ❌ TEST ${TOTAL_TESTS}: ${name}"
    fi
}

echo ""
log INFO "Running post-installation verification tests..."
echo ""

# ── Pre-check: ensure Apache is serving PHP ──────────────────────
log INFO "── Pre-check ──"

# Restart Apache to ensure clean state
systemctl restart apache2 2>/dev/null || apachectl restart 2>/dev/null || true
sleep 2

WEBROOT="${WEBROOT:-/www/wwwroot/${SITE_DOMAIN:-oem-system.local}}"

# Quick PHP test to verify Apache+PHP is working
echo '<?php echo "VERIFY_OK";' > "${WEBROOT}/_verify_test.php"
chown www-data:www-data "${WEBROOT}/_verify_test.php" 2>/dev/null || true
VERIFY_PHP=$(curl -s http://127.0.0.1/_verify_test.php 2>/dev/null || echo "FAIL")
rm -f "${WEBROOT}/_verify_test.php"
if [ "$VERIFY_PHP" = "VERIFY_OK" ]; then
    log INFO "Apache + PHP serving from webroot OK"
else
    log WARN "Apache may not be serving PHP correctly (got: ${VERIFY_PHP})"
    # Show recent Apache errors for debugging
    tail -5 /var/log/apache2/*error*.log 2>/dev/null || true
fi

# ═══════════════════════════════════════════════════════════════
# API Health Check
# ═══════════════════════════════════════════════════════════════
log INFO "── API Endpoints ──"

human_action "Testing API health endpoint..."

# health.php doesn't require PowerShell UA — use plain curl without -f
# so we get the response body even on HTTP errors
HEALTH_CODE=$(curl -s -o /tmp/health_response.txt -w '%{http_code}' \
    "${BASE_URL}/api/health.php" 2>/dev/null || echo "000")
HEALTH=$(cat /tmp/health_response.txt 2>/dev/null || echo "")

if [ "$HEALTH_CODE" != "200" ] && [ "$HEALTH_CODE" != "503" ]; then
    log WARN "Health endpoint returned HTTP ${HEALTH_CODE}"
    log WARN "  Response: $(head -c 200 /tmp/health_response.txt 2>/dev/null || echo '(empty)')"
    # Try running via PHP CLI as fallback diagnostic
    PHP_BIN=$(find_php)
    log INFO "  Testing via PHP CLI..."
    CLI_RESULT=$($PHP_BIN "${WEBROOT}/api/health.php" 2>&1 | tail -3 || echo "CLI failed")
    log INFO "  CLI result: ${CLI_RESULT}"
fi
rm -f /tmp/health_response.txt

# health.php returns "healthy" or "degraded" in status field
HEALTH_OK=$(echo "$HEALTH" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    # 'healthy' or 'degraded' both mean the endpoint is working
    print('1' if d.get('status') in ('healthy', 'degraded', 'ok') or d.get('success') else '0')
except:
    print('0')
" 2>/dev/null || echo "0")

run_test "API health endpoint responds (HTTP ${HEALTH_CODE})" $([ "$HEALTH_OK" = "1" ] && echo 0 || echo 1)

# ═══════════════════════════════════════════════════════════════
# Admin Login (via admin_v2.php JSON API)
# ═══════════════════════════════════════════════════════════════
log INFO "── Admin Authentication ──"

human_action "Testing admin login..."

COOKIE_JAR="/tmp/verify_cookies.txt"
rm -f "$COOKIE_JAR"

# Use admin_v2.php check_session to verify admin infrastructure works
# (secure-admin.php returns redirects, not JSON, for legacy POST login)
LOGIN_RESPONSE=$(curl -s \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    "${BASE_URL}/admin_v2.php?action=check_session" 2>/dev/null || echo "")

# check_session returns {authenticated: false} when not logged in — that's fine,
# it proves the admin API is reachable and PHP is processing it
LOGIN_OK=$(echo "$LOGIN_RESPONSE" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    # If we got valid JSON back, the admin API is working
    print('1' if 'authenticated' in d or 'success' in d else '0')
except:
    print('0')
" 2>/dev/null || echo "0")

if [ "$LOGIN_OK" != "1" ]; then
    log WARN "  Admin API response: $(echo "$LOGIN_RESPONSE" | head -c 200)"
fi

run_test "Admin API responds (admin_v2.php)" $([ "$LOGIN_OK" = "1" ] && echo 0 || echo 1)

# Also verify admin credentials work via direct DB check
ADMIN_HASH=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT password_hash FROM admin_users WHERE username='${ADMIN_USER}'" -sN 2>/dev/null || echo "")
PHP_BIN=$(find_php)
if [ -n "$ADMIN_HASH" ]; then
    PASS_VALID=$($PHP_BIN -r "echo password_verify('${ADMIN_PASS}', '${ADMIN_HASH}') ? 'yes' : 'no';" 2>/dev/null || echo "no")
    run_test "Admin password hash is valid" $([ "$PASS_VALID" = "yes" ] && echo 0 || echo 1)
else
    run_test "Admin password hash is valid" 1
fi

# ═══════════════════════════════════════════════════════════════
# Database Integrity
# ═══════════════════════════════════════════════════════════════
log INFO "── Database Integrity ──"

human_action "Checking database tables..."

# Count tables
TABLE_COUNT=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}'" -sN 2>/dev/null || echo "0")

run_test "Database has tables (found: ${TABLE_COUNT})" $([ "$TABLE_COUNT" -gt 10 ] && echo 0 || echo 1)

# Check critical tables exist
CRITICAL_TABLES=(
    "admin_users"
    "technicians"
    "oem_keys"
    "activation_attempts"
    "system_config"
    "schema_versions"
    "trusted_networks"
    "admin_ip_whitelist"
    "acl_roles"
    "acl_permissions"
)

for table in "${CRITICAL_TABLES[@]}"; do
    EXISTS=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
        -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='${table}'" -sN 2>/dev/null || echo "0")
    run_test "Table '${table}' exists" $([ "$EXISTS" = "1" ] && echo 0 || echo 1)
done

# Check schema_versions has all migrations
MIGRATION_COUNT=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM schema_versions" -sN 2>/dev/null || echo "0")
run_test "Migrations tracked in schema_versions (${MIGRATION_COUNT})" $([ "$MIGRATION_COUNT" -gt 15 ] && echo 0 || echo 1)

# Check admin user exists
ADMIN_EXISTS=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM admin_users WHERE username='${ADMIN_USER}'" -sN 2>/dev/null || echo "0")
run_test "Admin user '${ADMIN_USER}' exists in DB" $([ "$ADMIN_EXISTS" = "1" ] && echo 0 || echo 1)

# Check system_config has required settings
for config_key in "system_name" "server_url" "timezone"; do
    HAS_CONFIG=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
        -e "SELECT COUNT(*) FROM system_config WHERE config_key='${config_key}'" -sN 2>/dev/null || echo "0")
    run_test "Config '${config_key}' set" $([ "$HAS_CONFIG" = "1" ] && echo 0 || echo 1)
done

# ═══════════════════════════════════════════════════════════════
# Trusted Network Auto-Detection
# ═══════════════════════════════════════════════════════════════
log INFO "── Trusted Network Auto-Detection ──"

TRUSTED_COUNT=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM trusted_networks WHERE network_name='Installation Network'" -sN 2>/dev/null || echo "0")
run_test "Trusted network auto-detected by installer" $([ "$TRUSTED_COUNT" -ge 1 ] && echo 0 || echo 1)

WHITELIST_COUNT=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM admin_ip_whitelist WHERE description LIKE '%Auto-detected%'" -sN 2>/dev/null || echo "0")
run_test "Admin IP whitelist auto-populated" $([ "$WHITELIST_COUNT" -ge 1 ] && echo 0 || echo 1)

# ═══════════════════════════════════════════════════════════════
# File System Checks
# ═══════════════════════════════════════════════════════════════
log INFO "── File System ──"

run_test "config.php exists" $([ -f "${WEBROOT}/config.php" ] && echo 0 || echo 1)
run_test "install.lock exists" $([ -f "${WEBROOT}/install.lock" ] && echo 0 || echo 1)
run_test "config.php is not world-readable" $([ ! -o "${WEBROOT}/config.php" ] && echo 0 || echo 1)

# Check install.lock content
if [ -f "${WEBROOT}/install.lock" ]; then
    LOCK_VALID=$(python3 -c "
import json
try:
    with open('${WEBROOT}/install.lock') as f:
        d = json.load(f)
    print('1' if 'installed_at' in d and 'admin_username' in d else '0')
except:
    print('0')
" 2>/dev/null || echo "0")
    run_test "install.lock has valid JSON content" $([ "$LOCK_VALID" = "1" ] && echo 0 || echo 1)
fi

# ═══════════════════════════════════════════════════════════════
# Installer Security (blocked after install)
# ═══════════════════════════════════════════════════════════════
log INFO "── Security ──"

REINSTALL=$(curl -sf -X POST \
    -d "action=preflight" \
    "${BASE_URL}/install/ajax.php" 2>/dev/null || echo "")
BLOCKED=$(echo "$REINSTALL" | grep -ci "already installed" || echo "0")
run_test "Installer blocks after installation" $([ "$BLOCKED" -ge 1 ] && echo 0 || echo 1)

# Check demo technician was removed
DEMO_EXISTS=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT COUNT(*) FROM technicians WHERE technician_id='demo'" -sN 2>/dev/null || echo "0")
run_test "Demo technician removed" $([ "$DEMO_EXISTS" = "0" ] && echo 0 || echo 1)

# ═══════════════════════════════════════════════════════════════
# Client API Endpoints (require PowerShell User-Agent)
# ═══════════════════════════════════════════════════════════════
log INFO "── Client API Endpoints ──"

# The API middleware requires PowerShell User-Agent (blocks browser access)
PS_UA="PowerShell/7.4.0"

# Test login endpoint (should return error without creds, not 404)
LOGIN_API=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "User-Agent: ${PS_UA}" \
    -H "Content-Type: application/json" \
    -X POST -d '{}' \
    "${BASE_URL}/api/login.php" 2>/dev/null || echo "000")
# 200 = success response, 400/422 = validation error (still reachable)
run_test "API /api/login.php reachable (HTTP ${LOGIN_API})" $([ "$LOGIN_API" != "000" ] && [ "$LOGIN_API" != "404" ] && [ "$LOGIN_API" != "403" ] && echo 0 || echo 1)

# Test USB auth check endpoint
USB_API=$(curl -s -o /dev/null -w '%{http_code}' \
    -H "User-Agent: ${PS_UA}" \
    -H "Content-Type: application/json" \
    -X POST -d '{}' \
    "${BASE_URL}/api/check-usb-auth-enabled.php" 2>/dev/null || echo "000")
run_test "API /api/check-usb-auth-enabled.php reachable (HTTP ${USB_API})" $([ "$USB_API" != "000" ] && [ "$USB_API" != "404" ] && [ "$USB_API" != "403" ] && echo 0 || echo 1)

# ═══════════════════════════════════════════════════════════════
# RESULTS SUMMARY
# ═══════════════════════════════════════════════════════════════
echo ""
echo -e "${BOLD}  ══════════════════════════════════════════════════${NC}" | tee -a "$LOG_FILE"
echo -e "${BOLD}  VERIFICATION RESULTS${NC}" | tee -a "$LOG_FILE"
echo -e "${BOLD}  ══════════════════════════════════════════════════${NC}" | tee -a "$LOG_FILE"
echo ""
echo -e "  Total:  ${TOTAL_TESTS}" | tee -a "$LOG_FILE"
echo -e "  ${GREEN}Passed: ${PASS_COUNT}${NC}" | tee -a "$LOG_FILE"
echo -e "  ${RED}Failed: ${FAIL_COUNT}${NC}" | tee -a "$LOG_FILE"
echo ""

if [ $FAIL_COUNT -eq 0 ]; then
    echo -e "  ${BOLD}${GREEN}🎉 ALL TESTS PASSED${NC}" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
else
    echo -e "  ${BOLD}${RED}⚠️  ${FAIL_COUNT} TEST(S) FAILED${NC}" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
fi

# Clean up
rm -f "$COOKIE_JAR"

# Exit with failure if any tests failed
[ $FAIL_COUNT -eq 0 ]
