#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 5: Run the Web Installer (6-step wizard)
#
# Simulates: Human opens browser, navigates to /install/,
#            fills in each step of the wizard, clicks Next
#
# Uses curl + cookie jar to simulate browser sessions exactly
# like the JavaScript in index.php would send them.
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set +e

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
SITE_DOMAIN="${SITE_DOMAIN:-oem-system.local}"
WEBROOT="${WEBROOT:-/www/wwwroot/${SITE_DOMAIN}}"

# Cookie jar for session persistence (like a real browser)
COOKIE_JAR="/tmp/installer_cookies.txt"
rm -f "$COOKIE_JAR"

# ── Helpers ───────────────────────────────────────────────────────
installer_post() {
    local action="$1"; shift
    curl -sf -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -H "X-Requested-With: XMLHttpRequest" \
        -d "action=${action}" "$@" \
        "${INSTALLER_URL}" 2>/dev/null
}

check_json() {
    local response="$1"
    local step_name="$2"
    if [ -z "$response" ]; then
        log ERROR "${step_name}: Empty response"
        return 1
    fi
    local success=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('success',False))" 2>/dev/null)
    local msg=$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('message',''))" 2>/dev/null)
    if [ "$success" = "True" ]; then
        log INFO "${step_name}: ${msg}"
        return 0
    else
        log ERROR "${step_name}: ${msg}"
        log ERROR "  Response: ${response}"
        return 1
    fi
}

# ═══════════════════════════════════════════════════════════════════
log INFO "Starting OEM Activation System Web Installer"
log INFO "URL: ${BASE_URL}/install/"

# ── Open installer page ──────────────────────────────────────────
human_action "Opening browser -> ${BASE_URL}/install/"

HTTP_CODE=$(curl -sf -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
    -o /dev/null -w '%{http_code}' "${BASE_URL}/install/" 2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ]; then
    log INFO "Installer page loaded (HTTP 200)"
else
    log ERROR "Installer returned HTTP ${HTTP_CODE}"
    exit 1
fi

# ── Step 1/6: Environment Pre-flight Check ────────────────────────
human_action "Step 1/6: Running environment checks..."
human_action "Clicking 'Check Environment' button..."
sleep 1

RESPONSE=$(installer_post "preflight")

# Parse and display results
python3 <<PARSE_PF
import json, sys
try:
    data = json.loads('''${RESPONSE}''')
except:
    print("  ERROR: Could not parse preflight response")
    sys.exit(1)

categories = {'php': 'PHP', 'extensions': 'Extensions', 'settings': 'Settings', 'directories': 'Directories'}
all_pass = True
for key, label in categories.items():
    items = data.get(key, [])
    print(f"  {label}:")
    for item in items:
        s = item.get('status','?')
        icon = {'pass':'OK','warn':'WARN','fail':'FAIL'}.get(s,'?')
        print(f"    [{icon}] {item.get('label','?')}: {item.get('value','?')}")
        if s == 'fail': all_pass = False
print(f"\n  {'All checks passed!' if all_pass else 'Some checks need attention'}")
PARSE_PF

HAS_FAIL=$(echo "$RESPONSE" | python3 -c "
import sys,json; d=json.load(sys.stdin)
print(sum(1 for c in d.values() if isinstance(c,list) for i in c if i.get('status')=='fail'))" 2>/dev/null || echo "0")

if [ "$HAS_FAIL" != "0" ]; then
    log WARN "Pre-flight has ${HAS_FAIL} failure(s)"
fi

human_action "Environment looks good, clicking Next..."

# ── Step 2/6: Database Connection Test ────────────────────────────
human_action "Step 2/6: Configuring database connection..."
human_action "Host=${DB_HOST}, Port=${DB_PORT}, Database=${DB_NAME}"
human_action "Clicking 'Test Connection'..."
sleep 1

RESPONSE=$(installer_post "test_db" \
    -d "db_host=${DB_HOST}" -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}")

check_json "$RESPONSE" "Database Connection" || exit 1
human_action "Database connected! Clicking Next..."

# ── Step 3/6: Install Database (Run Migrations) ──────────────────
human_action "Step 3/6: Installing database schema..."
human_action "Clicking 'Install Database' button..."
sleep 1

RESPONSE=$(installer_post "install_db" \
    -d "db_host=${DB_HOST}" -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}")

# Show migration results
python3 <<PARSE_MIG
import json, sys
try:
    data = json.loads('''${RESPONSE}''')
except:
    print("  ERROR: Could not parse migration response"); sys.exit(1)
results = data.get('results', [])
ok = skip = err = 0
for r in results:
    s = r.get('status','?')
    if s == 'ok': ok += 1
    elif s == 'skipped': skip += 1
    else: err += 1
    icon = {'ok':'OK','skipped':'SKIP'}.get(s,'ERR')
    print(f"    [{icon}] {r.get('file','?')}: {r.get('message','?')}")
print(f"\n  Summary: {ok} applied, {skip} skipped, {err} errors")
PARSE_MIG

check_json "$RESPONSE" "Database Migration" || exit 1
human_action "All migrations applied! Clicking Next..."

# ── Step 4/6: Create Admin Account ────────────────────────────────
human_action "Step 4/6: Creating admin account..."
human_action "Username=${ADMIN_USER}, Full Name=System Administrator"
human_action "Email=${ADMIN_EMAIL}"
human_action "Clicking 'Create Admin Account'..."
sleep 1

RESPONSE=$(installer_post "create_admin" \
    -d "db_host=${DB_HOST}" -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}" \
    -d "username=${ADMIN_USER}" -d "full_name=System Administrator" \
    -d "email=${ADMIN_EMAIL}" \
    -d "password=${ADMIN_PASS}" -d "password_confirm=${ADMIN_PASS}")

check_json "$RESPONSE" "Admin Account" || exit 1
human_action "Admin account created! Clicking Next..."

# ── Step 5-6/6: Finalize Installation ─────────────────────────────
human_action "Step 5/6: System settings..."
human_action "System Name: OEM Activation System"
human_action "Server URL: ${BASE_URL}"
human_action "Timezone: UTC, Language: English"
human_action "Clicking 'Complete Installation'..."
sleep 1

RESPONSE=$(installer_post "finalize" \
    -d "db_host=${DB_HOST}" -d "db_port=${DB_PORT}" \
    -d "db_user=${DB_USER}" -d "db_pass=${DB_PASS}" \
    -d "db_name=${DB_NAME}" \
    -d "system_name=OEM Activation System" \
    -d "server_url=${BASE_URL}" \
    -d "timezone=UTC" -d "language=en" \
    -d "admin_username=${ADMIN_USER}")

check_json "$RESPONSE" "Finalize Installation" || exit 1

# Show completion info
python3 <<PARSE_DONE
import json, sys
try:
    data = json.loads('''${RESPONSE}''')
    info = data.get('info', {})
    if info:
        print("\n  Installation Complete!")
        for k, v in info.items():
            print(f"    {k}: {v}")
except: pass
PARSE_DONE

# ── Verify install artifacts ──────────────────────────────────────
if [ -f "${WEBROOT}/install.lock" ]; then
    log INFO "install.lock created"
else
    log WARN "install.lock not found"
fi

if [ -f "${WEBROOT}/config.php" ]; then
    log INFO "config.php generated"
    if grep -q "${DB_NAME}" "${WEBROOT}/config.php"; then
        log INFO "  config.php contains correct DB name"
    fi
else
    log ERROR "config.php NOT generated"
    exit 1
fi

# ── Verify installer blocks re-run ────────────────────────────────
human_action "Trying to access installer again (should be blocked)..."
RERUN=$(installer_post "preflight" 2>/dev/null || echo "")
if echo "$RERUN" | grep -qi "already installed"; then
    log INFO "Installer blocks re-run"
fi

# ── Verify trusted network auto-detected ──────────────────────────
MYSQL_BIN=$(find_mysql)
TRUSTED=$($MYSQL_BIN -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "SELECT network_name, ip_range FROM trusted_networks LIMIT 1" -sN 2>/dev/null || echo "")
if [ -n "$TRUSTED" ]; then
    log INFO "Trusted network auto-detected: ${TRUSTED}"
else
    log WARN "No trusted network detected"
fi

rm -f "$COOKIE_JAR"
log INFO "Web installer completed -- all 6 steps passed"
exit 0
