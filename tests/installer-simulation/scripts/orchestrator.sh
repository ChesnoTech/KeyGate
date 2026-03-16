#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# OEM Activation System — Full Deployment Simulation Orchestrator
#
# Simulates a human admin deploying on a fresh Ubuntu 22.04 server:
#   1. Install aaPanel (or fallback to manual LAMP)
#   2. Install LAMP via aaPanel API (Apache, PHP 8.3, MariaDB)
#   3. Create website + database via aaPanel API
#   4. Deploy application files
#   5. Run the 6-step web installer (simulating browser)
#   6. Post-installation verification (30+ tests)
# ═══════════════════════════════════════════════════════════════════

set -u

LOG_FILE="/var/log/oem-simulation.log"
RESULT_FILE="/opt/simulation/result.json"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

log() {
    local level="$1"; shift
    local ts=$(date '+%Y-%m-%d %H:%M:%S')
    local color="$NC"
    case "$level" in
        INFO)  color="$GREEN"  ;;
        WARN)  color="$YELLOW" ;;
        ERROR) color="$RED"    ;;
    esac
    echo -e "${color}[${ts}] [${level}]${NC} $*" | tee -a "$LOG_FILE"
}

step_banner() {
    local num="$1"; shift
    echo "" | tee -a "$LOG_FILE"
    echo -e "${BOLD}${CYAN}=======================================================${NC}" | tee -a "$LOG_FILE"
    echo -e "${BOLD}${CYAN}  Step ${num}: $*${NC}" | tee -a "$LOG_FILE"
    echo -e "${BOLD}${CYAN}=======================================================${NC}" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
}

save_result() {
    local status="$1"
    local message="${2:-}"
    cat > "$RESULT_FILE" <<EOF
{
    "status": "${status}",
    "message": "${message}",
    "completed_at": "$(date '+%Y-%m-%d %H:%M:%S')",
    "log_file": "${LOG_FILE}"
}
EOF
}

run_step() {
    local num="$1"
    local title="$2"
    local script="$3"

    step_banner "$num" "$title"
    /opt/simulation/${script}
    local rc=$?
    if [ $rc -ne 0 ]; then
        log ERROR "Step ${num} FAILED: ${title}"
        save_result "FAILED" "Step ${num}: ${title}"
        exit 1
    fi
    log INFO "Step ${num} complete: ${title}"
    return 0
}

# ═══════════════════════════════════════════════════════════════════
echo "" | tee -a "$LOG_FILE"
echo -e "${BOLD}${GREEN}" | tee -a "$LOG_FILE"
echo "  +-------------------------------------------------------+" | tee -a "$LOG_FILE"
echo "  |     OEM Activation System — Deployment Simulation      |" | tee -a "$LOG_FILE"
echo "  |                                                         |" | tee -a "$LOG_FILE"
echo "  |  Fresh Ubuntu 22.04 -> aaPanel -> LAMP -> Web Install  |" | tee -a "$LOG_FILE"
echo "  +-------------------------------------------------------+" | tee -a "$LOG_FILE"
echo -e "${NC}" | tee -a "$LOG_FILE"

SIMULATION_START=$(date +%s)

# ── Run all steps sequentially ────────────────────────────────────
run_step 1 "Install aaPanel on Ubuntu 22.04"              "01-install-aapanel.sh"
run_step 2 "Install LAMP Stack via aaPanel"                "02-install-lamp.sh"
run_step 3 "Create Website + Database via aaPanel"         "03-create-site.sh"
run_step 4 "Deploy Application Files"                      "04-deploy-app.sh"
run_step 5 "Run Web Installer (6-step wizard)"             "05-run-installer.sh"
run_step 6 "Post-Installation Verification"                "06-verify.sh"

# ── Complete ──────────────────────────────────────────────────────
DURATION=$(( $(date +%s) - SIMULATION_START ))
MINS=$((DURATION / 60))
SECS=$((DURATION % 60))

echo "" | tee -a "$LOG_FILE"
echo -e "${BOLD}${GREEN}" | tee -a "$LOG_FILE"
echo "  +-------------------------------------------------------+" | tee -a "$LOG_FILE"
echo "  |        SIMULATION COMPLETED SUCCESSFULLY               |" | tee -a "$LOG_FILE"
echo "  |                                                         |" | tee -a "$LOG_FILE"
echo "  |  Duration: ${MINS}m ${SECS}s                                     |" | tee -a "$LOG_FILE"
echo "  |  OEM App:  http://localhost:9080                       |" | tee -a "$LOG_FILE"
echo "  |  aaPanel:  http://localhost:9888                       |" | tee -a "$LOG_FILE"
echo "  +-------------------------------------------------------+" | tee -a "$LOG_FILE"
echo -e "${NC}" | tee -a "$LOG_FILE"

save_result "SUCCESS" "Full deployment completed in ${MINS}m ${SECS}s"
