#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 1: Install aaPanel on Ubuntu 22.04
#
# Simulates: Human SSHs into server, runs the aaPanel install script
# Falls back to manual LAMP if aaPanel CDN is unavailable.
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh

# Disable strict mode for this script — aaPanel install is flaky
set +e

log INFO "Installing aaPanel on Ubuntu 22.04..."

# Pre-set timezone to avoid interactive prompts
echo "Etc/UTC" > /etc/timezone
ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime
export DEBIAN_FRONTEND=noninteractive
export TZ=UTC

# ── Pre-requisites ──────────────────────────────────────────────
human_action "Updating system packages first..."
apt-get update -y > /dev/null 2>&1
apt-get upgrade -y -o Dpkg::Options::="--force-confold" > /dev/null 2>&1
log INFO "✅ System update"

human_action "Installing prerequisites..."
apt-get install -y \
    wget curl software-properties-common \
    libcurl4-openssl-dev libssl-dev \
    build-essential \
    > /dev/null 2>&1
log INFO "✅ Prerequisites installed"

# ── Try to download and install aaPanel ─────────────────────────
human_action "Downloading aaPanel install script..."

AAPANEL_URL="https://www.aapanel.com/script/install_7.0_en.sh"
AAPANEL_OK=false

cd /tmp
if wget --no-check-certificate -q --timeout=30 -O install_panel.sh "$AAPANEL_URL" 2>/dev/null; then
    log INFO "Downloaded aaPanel install script"

    human_action "Running aaPanel installer (non-interactive)..."
    echo "y" | timeout 300 bash install_panel.sh aapanel 2>&1 | tee -a /var/log/aapanel-install.log

    # Check if bt command is available (= aaPanel installed successfully)
    if command -v bt &> /dev/null; then
        AAPANEL_OK=true
        log INFO "✅ aaPanel installed successfully"

        BT_INFO=$(bt default 2>/dev/null || echo "")
        if [ -n "$BT_INFO" ]; then
            log INFO "aaPanel panel info:"
            echo "$BT_INFO" | head -10 | tee -a /var/log/oem-simulation.log
        fi

        # Save panel creds
        PANEL_URL=$(echo "$BT_INFO" | grep -oP 'http://\S+' | head -1 || echo "")
        PANEL_USER=$(echo "$BT_INFO" | grep -i 'username' | awk '{print $NF}' || echo "")
        PANEL_PASS=$(echo "$BT_INFO" | grep -i 'password' | awk '{print $NF}' || echo "")
        cat > /opt/simulation/.panel_creds <<EOF
PANEL_URL=${PANEL_URL}
PANEL_USER=${PANEL_USER}
PANEL_PASS=${PANEL_PASS}
PANEL_PORT=${AAPANEL_PORT:-8888}
EOF
        # Enable API
        API_TOKEN=$(openssl rand -hex 16)
        for apipath in /www/server/panel/config/api.json /www/server/panel/data/api.json; do
            if [ -d "$(dirname $apipath)" ]; then
                echo "{\"open\":true,\"token\":\"${API_TOKEN}\",\"limit_addr\":[\"127.0.0.1\"]}" > "$apipath"
                echo "API_TOKEN=${API_TOKEN}" >> /opt/simulation/.panel_creds
                break
            fi
        done
        bt restart 2>/dev/null || /etc/init.d/bt restart 2>/dev/null || true
    else
        log WARN "aaPanel install script ran but 'bt' command not found"
    fi
else
    log WARN "aaPanel download failed (CDN may be blocking Docker)"
fi

# ── Fallback to manual LAMP if aaPanel didn't install ───────────
if [ "$AAPANEL_OK" = false ]; then
    log WARN "aaPanel not available — falling back to manual LAMP setup"
    touch /opt/simulation/.manual_mode
    setup_lamp_manually
fi

log INFO "Step 1 complete (aaPanel: ${AAPANEL_OK})"
exit 0
