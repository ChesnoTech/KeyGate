#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# OEM Installer Simulation — Master Orchestrator
#
# Simulates a human admin deploying the OEM Activation System
# on a fresh Ubuntu 22.04 server using aaPanel.
#
# Flow:
#   1. Install aaPanel
#   2. Install LAMP stack (Apache + PHP 8.3 + MariaDB 10.11)
#   3. Configure PHP extensions & settings
#   4. Create website + database in aaPanel
#   5. Deploy application files
#   6. Run the web installer (all 6 steps)
#   7. Verify the installation
# ═══════════════════════════════════════════════════════════════════

set -u  # Error on undefined vars but don't exit on individual command failures

LOG_FILE="/var/log/oem-simulation.log"
RESULT_FILE="/opt/simulation/result.json"
INSTALL_MODE="${INSTALL_MODE:-fast}"

# ── Colors & formatting ─────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ── Logging ──────────────────────────────────────────────────────
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

step_banner() {
    local step_num="$1"; shift
    local title="$*"
    echo "" | tee -a "$LOG_FILE"
    echo -e "${BOLD}${CYAN}═══════════════════════════════════════════════════════════${NC}" | tee -a "$LOG_FILE"
    echo -e "${BOLD}${CYAN}  Step ${step_num}: ${title}${NC}" | tee -a "$LOG_FILE"
    echo -e "${BOLD}${CYAN}═══════════════════════════════════════════════════════════${NC}" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
}

human_action() {
    log HUMAN "👤 Human action: $*"
    # Small delay to simulate human think time
    sleep 2
}

check_result() {
    local desc="$1"
    local exit_code="$2"
    if [ "$exit_code" -eq 0 ]; then
        log INFO "✅ ${desc} — OK"
    else
        log ERROR "❌ ${desc} — FAILED (exit code: ${exit_code})"
        save_result "FAILED" "$desc"
        exit 1
    fi
}

save_result() {
    local status="$1"
    local message="${2:-}"
    local ts=$(date '+%Y-%m-%d %H:%M:%S')
    cat > "$RESULT_FILE" <<EOF
{
    "status": "${status}",
    "message": "${message}",
    "completed_at": "${ts}",
    "install_mode": "${INSTALL_MODE}",
    "log_file": "${LOG_FILE}"
}
EOF
}

wait_for_service() {
    local name="$1"
    local check_cmd="$2"
    local max_wait="${3:-120}"
    local waited=0

    log INFO "Waiting for ${name} to be ready (max ${max_wait}s)..."
    while [ $waited -lt $max_wait ]; do
        if eval "$check_cmd" > /dev/null 2>&1; then
            log INFO "${name} is ready (took ${waited}s)"
            return 0
        fi
        sleep 5
        waited=$((waited + 5))
    done
    log ERROR "${name} did not become ready within ${max_wait}s"
    return 1
}

# ═══════════════════════════════════════════════════════════════════
#  MAIN SIMULATION
# ═══════════════════════════════════════════════════════════════════

echo "" | tee -a "$LOG_FILE"
echo -e "${BOLD}${GREEN}" | tee -a "$LOG_FILE"
echo "  ╔═══════════════════════════════════════════════════════╗" | tee -a "$LOG_FILE"
echo "  ║     OEM Activation System — Installer Simulation     ║" | tee -a "$LOG_FILE"
echo "  ║                                                       ║" | tee -a "$LOG_FILE"
echo "  ║  Simulating production deployment on Ubuntu 22.04     ║" | tee -a "$LOG_FILE"
echo "  ║  Install mode: ${INSTALL_MODE}                                 ║" | tee -a "$LOG_FILE"
echo "  ╚═══════════════════════════════════════════════════════╝" | tee -a "$LOG_FILE"
echo -e "${NC}" | tee -a "$LOG_FILE"

SIMULATION_START=$(date +%s)

# ─────────────────────────────────────────────────────────────────
# Step 1: Install aaPanel
# ─────────────────────────────────────────────────────────────────
step_banner 1 "Install aaPanel on Ubuntu 22.04"

human_action "SSH into fresh Ubuntu 22.04 server"
human_action "Running aaPanel install script from aapanel.com..."

/opt/simulation/01-install-aapanel.sh
check_result "aaPanel installation" $?

# ─────────────────────────────────────────────────────────────────
# Step 2: Install LAMP Stack via aaPanel
# ─────────────────────────────────────────────────────────────────
step_banner 2 "Install LAMP Stack (Apache + PHP 8.3 + MariaDB 10.11)"

human_action "Opening aaPanel web UI at http://server:8888"
human_action "Navigating to App Store..."
human_action "Selecting LAMP environment with ${INSTALL_MODE} install..."

/opt/simulation/02-install-lamp.sh
check_result "LAMP stack installation" $?

# ─────────────────────────────────────────────────────────────────
# Step 3: Configure PHP Environment
# ─────────────────────────────────────────────────────────────────
step_banner 3 "Configure PHP Extensions & Settings"

human_action "Going to aaPanel → App Store → PHP 8.3 → Settings"
human_action "Enabling required extensions: pdo_mysql, mbstring, openssl, curl..."
human_action "Adjusting PHP settings: memory_limit, upload_max_filesize..."

/opt/simulation/03-configure-php.sh
check_result "PHP configuration" $?

# ─────────────────────────────────────────────────────────────────
# Step 4: Create Website + Database in aaPanel
# ─────────────────────────────────────────────────────────────────
step_banner 4 "Create Website & Database via aaPanel"

human_action "Going to aaPanel → Websites → Add Site"
human_action "Domain: ${SITE_DOMAIN:-oem-system.local}, PHP 8.3, Apache"
human_action "Going to aaPanel → Databases → Add Database"
human_action "Database: ${DB_NAME}, User: ${DB_USER}"

/opt/simulation/04-create-site.sh
check_result "Website and database creation" $?

# ─────────────────────────────────────────────────────────────────
# Step 5: Deploy Application Files
# ─────────────────────────────────────────────────────────────────
step_banner 5 "Deploy Application to Web Root"

human_action "Uploading OEM Activation System files to web root via aaPanel File Manager..."
human_action "Setting file permissions..."

/opt/simulation/05-deploy-app.sh
check_result "Application deployment" $?

# ─────────────────────────────────────────────────────────────────
# Step 6: Run Web Installer (Joomla-style)
# ─────────────────────────────────────────────────────────────────
step_banner 6 "Run Web Installer (6-step wizard)"

human_action "Opening browser → http://${SITE_DOMAIN:-oem-system.local}/install/"
human_action "Starting the installation wizard..."

/opt/simulation/06-run-installer.sh
check_result "Web installer" $?

# ─────────────────────────────────────────────────────────────────
# Step 7: Post-Installation Verification
# ─────────────────────────────────────────────────────────────────
step_banner 7 "Post-Installation Verification"

human_action "Testing the installed system..."

/opt/simulation/07-verify.sh
check_result "Post-installation verification" $?

# ─────────────────────────────────────────────────────────────────
# SIMULATION COMPLETE
# ─────────────────────────────────────────────────────────────────
SIMULATION_END=$(date +%s)
DURATION=$((SIMULATION_END - SIMULATION_START))
MINUTES=$((DURATION / 60))
SECONDS_REM=$((DURATION % 60))

echo "" | tee -a "$LOG_FILE"
echo -e "${BOLD}${GREEN}" | tee -a "$LOG_FILE"
echo "  ╔═══════════════════════════════════════════════════════╗" | tee -a "$LOG_FILE"
echo "  ║          ✅ SIMULATION COMPLETED SUCCESSFULLY         ║" | tee -a "$LOG_FILE"
echo "  ║                                                       ║" | tee -a "$LOG_FILE"
echo "  ║  Duration: ${MINUTES}m ${SECONDS_REM}s                               ║" | tee -a "$LOG_FILE"
echo "  ║  Mode: ${INSTALL_MODE}                                         ║" | tee -a "$LOG_FILE"
echo "  ║                                                       ║" | tee -a "$LOG_FILE"
echo "  ║  aaPanel:     http://localhost:8888                   ║" | tee -a "$LOG_FILE"
echo "  ║  OEM App:     http://localhost:8080                   ║" | tee -a "$LOG_FILE"
echo "  ║  Admin Panel: http://localhost:8080/secure-admin.php  ║" | tee -a "$LOG_FILE"
echo "  ╚═══════════════════════════════════════════════════════╝" | tee -a "$LOG_FILE"
echo -e "${NC}" | tee -a "$LOG_FILE"

save_result "SUCCESS" "Full simulation completed in ${MINUTES}m ${SECONDS_REM}s"
