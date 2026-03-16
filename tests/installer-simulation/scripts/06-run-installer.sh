#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 6: Run the Web Installer (Joomla-style, 6 steps)
#
# Simulates: Human opens browser, navigates to /install/,
#            fills in each step of the wizard, clicks Next
#
# Uses curl to simulate browser form submissions exactly like
# the JavaScript in index.php would send them.
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set -u

BASE_URL="http://127.0.0.1"
INSTALLER_URL="${BASE_URL}/install/ajax.php"

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-oem_activation}"
DB_USER="${DB_USER:-oem_user}"
DB_PASS="${DB_PASS:-oem_password_123}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-Admin2024!}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@oem-system.local}"
SERVER_URL="${BASE_URL}"
SITE_DOMAIN="${SITE_DOMAIN:-oem-system.local}"

# Cookie jar for session persistence (like a real browser)
COOKIE_JAR="/tmp/installer_cookies.txt"
rm -f "$COOKIE_JAR"

# ── Helper: POST to installer AJAX endpoint ────────────────────
installer_post() {
    local action="$1"
    shift

    curl -sf \
        -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -H "X-Requested-With: XMLHttpRequest" \
        -d "action=${action}" \
        "$@" \
        "${INSTALLER_URL}" 2>/dev/null
}

# ── Helper: Check JSON response ────────────────────────────────
check_json_success() {
    local response="$1"
    local step_name="$2"

    if [ -z "$response" ]; then
        log ERROR "${step_name}: Empty response"
        return 1
    fi

    local success=$(echo "$response" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    print(d.get('success', False))
except:
    print('False')
" 2>/dev/null)

    if [ "$success" = "True" ]; then
        local msg=$(echo "$response" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    print(d.get('message', 'OK'))
except:
    print('OK')
" 2>/dev/null)
        log INFO "${step_name}: ✅ ${msg}"
        return 0
    else
        local msg=$(echo "$response" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    print(d.get('message', d.get('error', 'Unknown error')))
except:
    print('Parse error')
" 2>/dev/null)
        log ERROR "${step_name}: ❌ ${msg}"
        echo "  Full response: ${response}" | tee -a "$LOG_FILE"
        return 1
    fi
}

# ═══════════════════════════════════════════════════════════════
log INFO "Starting OEM Activation System Web Installer"
log INFO "URL: ${BASE_URL}/install/"
echo ""

# ─────────────────────────────────────────────────────────────
# Step 1: Open installer page (verify it's accessible)
# ─────────────────────────────────────────────────────────────
human_action "Opening browser → ${BASE_URL}/install/"

HTTP_CODE=$(curl -sf -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -o /dev/null -w '%{http_code}' \
    "${BASE_URL}/install/" 2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ]; then
    log INFO "Installer page loaded (HTTP 200)"
else
    log ERROR "Installer page returned HTTP ${HTTP_CODE}"
    exit 1
fi

# ─────────────────────────────────────────────────────────────
# Step 2: Pre-flight Environment Check
# ─────────────────────────────────────────────────────────────
human_action "Step 1/6: Running environment pre-flight checks..."
human_action "Clicking 'Check Environment' button..."

sleep 1  # Human reads the page first

RESPONSE=$(installer_post "preflight")

if [ -z "$RESPONSE" ]; then
    log ERROR "Preflight check returned empty response"
    exit 1
fi

# Parse preflight results
python3 <<PARSE_PREFLIGHT
import json, sys

try:
    data = json.loads('''${RESPONSE}''')
except:
    print("ERROR: Could not parse preflight response")
    sys.exit(1)

print("\n  📋 Pre-flight Check Results:")
print("  " + "─" * 50)

categories = {
    'php': '🐘 PHP',
    'extensions': '🧩 Extensions',
    'settings': '⚙️  Settings',
    'directories': '📁 Directories'
}

all_pass = True
for cat_key, cat_label in categories.items():
    items = data.get(cat_key, [])
    print(f"\n  {cat_label}:")
    for item in items:
        status = item.get('status', 'unknown')
        icon = '✅' if status == 'pass' else ('⚠️' if status == 'warn' else '❌')
        label = item.get('label', '?')
        value = item.get('value', '?')
        print(f"    {icon} {label}: {value}")
        if status == 'fail':
            all_pass = False

print(f"\n  {'✅ All checks passed!' if all_pass else '⚠️  Some checks need attention'}")
PARSE_PREFLIGHT

# Check for any failures
HAS_FAIL=$(echo "$RESPONSE" | python3 -c "
import sys, json
data = json.load(sys.stdin)
fails = sum(1 for cat in data.values() if isinstance(cat, list) for item in cat if item.get('status') == 'fail')
print(fails)
" 2>/dev/null || echo "0")

if [ "$HAS_FAIL" != "0" ]; then
    log WARN "Pre-flight has ${HAS_FAIL} failure(s) — installer may still work"
fi

human_action "Environment looks good, clicking 'Next' →"

# ─────────────────────────────────────────────────────────────
# Step 3: Database Connection Test
# ─────────────────────────────────────────────────────────────
human_action "Step 2/6: Configuring database connection..."
human_action "Filling in: Host=${DB_HOST}, Port=${DB_PORT}, Database=${DB_NAME}"
human_action "Username=${DB_USER}, Password=****"
human_action "Clicking 'Test Connection'..."

sleep 1

RESPONSE=$(installer_post "test_db" \
    -d "db_host=${DB_HOST}" \
    -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" \
    -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}")

check_json_success "$RESPONSE" "Database Connection"
DB_RESULT=$?

if [ $DB_RESULT -ne 0 ]; then
    log ERROR "Database connection failed — cannot proceed"
    exit 1
fi

human_action "Database connected! Clicking 'Next' →"

# ─────────────────────────────────────────────────────────────
# Step 4: Install Database (Run Migrations)
# ─────────────────────────────────────────────────────────────
human_action "Step 3/6: Installing database schema..."
human_action "Clicking 'Install Database' button..."

sleep 1

RESPONSE=$(installer_post "install_db" \
    -d "db_host=${DB_HOST}" \
    -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" \
    -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}")

# Parse migration results
python3 <<PARSE_MIGRATIONS
import json, sys

try:
    data = json.loads('''${RESPONSE}''')
except:
    print("ERROR: Could not parse migration response")
    sys.exit(1)

results = data.get('results', [])
print(f"\n  📦 Database Migrations ({len(results)} total):")
print("  " + "─" * 50)

ok_count = skip_count = err_count = 0
for r in results:
    status = r.get('status', 'unknown')
    icon = '✅' if status == 'ok' else ('⏭️' if status == 'skipped' else '❌')
    if status == 'ok': ok_count += 1
    elif status == 'skipped': skip_count += 1
    else: err_count += 1
    print(f"    {icon} {r.get('file', '?')}: {r.get('message', '?')}")

print(f"\n  Summary: {ok_count} applied, {skip_count} skipped, {err_count} errors")
print(f"  {'✅ ' + data.get('message', 'Complete') if data.get('success') else '❌ ' + data.get('message', 'Failed')}")
PARSE_MIGRATIONS

check_json_success "$RESPONSE" "Database Migration"
if [ $? -ne 0 ]; then
    log ERROR "Database migration failed"
    exit 1
fi

human_action "All migrations applied! Clicking 'Next' →"

# ─────────────────────────────────────────────────────────────
# Step 5: Create Admin Account
# ─────────────────────────────────────────────────────────────
human_action "Step 4/6: Creating admin account..."
human_action "Filling in: Username=${ADMIN_USER}, Full Name=System Administrator"
human_action "Email=${ADMIN_EMAIL}, Password=****"
human_action "Clicking 'Create Admin Account'..."

sleep 1

RESPONSE=$(installer_post "create_admin" \
    -d "db_host=${DB_HOST}" \
    -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" \
    -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}" \
    -d "username=${ADMIN_USER}" \
    -d "full_name=System Administrator" \
    -d "email=${ADMIN_EMAIL}" \
    -d "password=${ADMIN_PASS}" \
    -d "password_confirm=${ADMIN_PASS}")

check_json_success "$RESPONSE" "Admin Account"
if [ $? -ne 0 ]; then
    log ERROR "Admin account creation failed"
    exit 1
fi

human_action "Admin account created! Clicking 'Next' →"

# ─────────────────────────────────────────────────────────────
# Step 6: Finalize Installation
# ─────────────────────────────────────────────────────────────
human_action "Step 5/6: Configuring system settings..."
human_action "System Name: OEM Activation System"
human_action "Server URL: ${SERVER_URL}"
human_action "Timezone: UTC"
human_action "Language: English"
human_action "Clicking 'Complete Installation' →"

sleep 1

RESPONSE=$(installer_post "finalize" \
    -d "db_host=${DB_HOST}" \
    -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" \
    -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}" \
    -d "system_name=OEM Activation System" \
    -d "server_url=${SERVER_URL}" \
    -d "timezone=UTC" \
    -d "language=en" \
    -d "admin_username=${ADMIN_USER}")

check_json_success "$RESPONSE" "Finalize Installation"
FINAL_RESULT=$?

if [ $FINAL_RESULT -ne 0 ]; then
    log ERROR "Installation finalization failed"
    exit 1
fi

# Parse and display completion info
python3 <<PARSE_COMPLETE
import json, sys

try:
    data = json.loads('''${RESPONSE}''')
except:
    print("Could not parse completion info")
    sys.exit(0)

info = data.get('info', {})
if info:
    print("\n  🎉 Installation Complete!")
    print("  " + "─" * 50)
    for key, val in info.items():
        print(f"    📌 {key}: {val}")
PARSE_COMPLETE

echo ""

# ─────────────────────────────────────────────────────────────
# Verify: install.lock was created
# ─────────────────────────────────────────────────────────────
WEBROOT="${WEBROOT:-/www/wwwroot/${SITE_DOMAIN:-oem-system.local}}"

if [ -f "${WEBROOT}/install.lock" ]; then
    log INFO "install.lock created ✅"
    log INFO "  Contents: $(cat ${WEBROOT}/install.lock)"
else
    log WARN "install.lock not found — may need to check config"
fi

# Verify config.php was generated
if [ -f "${WEBROOT}/config.php" ]; then
    log INFO "config.php generated ✅"
    # Verify it's not the default/template
    if grep -q "${DB_NAME}" "${WEBROOT}/config.php"; then
        log INFO "  config.php contains correct database name"
    fi
else
    log ERROR "config.php was NOT generated"
    exit 1
fi

# ─────────────────────────────────────────────────────────────
# Verify: Installer blocks re-run
# ─────────────────────────────────────────────────────────────
human_action "Trying to access installer again (should be blocked)..."

RERUN_RESPONSE=$(installer_post "preflight" 2>/dev/null || echo "")
if echo "$RERUN_RESPONSE" | grep -qi "already installed"; then
    log INFO "Installer correctly blocks re-run ✅"
else
    log WARN "Installer may not be blocking re-runs properly"
fi

# ─────────────────────────────────────────────────────────────
# Verify: Trusted network was auto-detected
# ─────────────────────────────────────────────────────────────
MYSQL_BIN=$(find_mysql)
TRUSTED_NET=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT network_name, ip_range, description FROM trusted_networks LIMIT 1" -sN 2>/dev/null || echo "")

if [ -n "$TRUSTED_NET" ]; then
    log INFO "Trusted network auto-detected ✅"
    log INFO "  ${TRUSTED_NET}"
else
    log WARN "No trusted network was auto-detected"
fi

# Clean up
rm -f "$COOKIE_JAR"

log INFO "Web installer completed successfully — all 6 steps passed"
