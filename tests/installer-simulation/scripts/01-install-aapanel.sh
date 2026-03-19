#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# Step 1: Install aaPanel on fresh Ubuntu 22.04
#
# Simulates: Human SSHs into VPS, runs the aaPanel install script
# Falls back to manual LAMP if aaPanel CDN is unreachable.
# ═══════════════════════════════════════════════════════════════════

source /opt/simulation/helpers.sh
set +e  # aaPanel install is flaky, don't exit on errors

# Pre-set timezone
echo "Etc/UTC" > /etc/timezone
ln -sf /usr/share/zoneinfo/Etc/UTC /etc/localtime

# ── System update (like a human would do first) ──────────────────
human_action "SSH into fresh Ubuntu 22.04 VPS..."
human_action "apt update && apt upgrade -y"

apt-get update -y > /dev/null 2>&1
apt-get upgrade -y -o Dpkg::Options::="--force-confold" > /dev/null 2>&1
log INFO "System packages updated"

# ── Try aaPanel install ──────────────────────────────────────────
human_action "Downloading aaPanel install script from aapanel.com..."

AAPANEL_OK=false

# Try pre-downloaded first, then fresh download
if [ -f /opt/aapanel/install.sh ] && [ -s /opt/aapanel/install.sh ]; then
    cp /opt/aapanel/install.sh /tmp/install_panel.sh
    log INFO "Using pre-downloaded aaPanel installer"
elif wget --no-check-certificate -q --timeout=30 -O /tmp/install_panel.sh \
    "https://www.aapanel.com/script/install_7.0_en.sh" 2>/dev/null; then
    log INFO "Downloaded aaPanel installer"
else
    log WARN "aaPanel download failed (CDN may be blocking Docker)"
fi

if [ -f /tmp/install_panel.sh ] && [ -s /tmp/install_panel.sh ]; then
    human_action "Running aaPanel installer (answering 'y' to all prompts)..."

    echo y | timeout 300 bash /tmp/install_panel.sh aapanel 2>&1 | tee -a /var/log/aapanel-install.log | tail -20

    if command -v bt &> /dev/null; then
        AAPANEL_OK=true
        log INFO "aaPanel installed successfully"

        # Show panel info
        bt default 2>/dev/null | head -10 | tee -a "$LOG_FILE" || true

        # Save panel credentials for later scripts
        BT_INFO=$(bt default 2>/dev/null || echo "")
        PANEL_USER=$(echo "$BT_INFO" | grep -i 'username' | awk '{print $NF}' || echo "")
        PANEL_PASS=$(echo "$BT_INFO" | grep -i 'password' | awk '{print $NF}' || echo "")
        PANEL_PORT=$(cat /www/server/panel/data/port.pl 2>/dev/null || echo "${AAPANEL_PORT:-8888}")

        cat > /opt/simulation/.panel_creds <<EOF
PANEL_USER=${PANEL_USER}
PANEL_PASS=${PANEL_PASS}
PANEL_PORT=${PANEL_PORT}
EOF

        # Enable aaPanel API for programmatic access
        API_TOKEN=$(openssl rand -hex 16 2>/dev/null || echo "simulation_test_token_$(date +%s)")
        for apipath in /www/server/panel/config/api.json /www/server/panel/data/api.json; do
            if [ -d "$(dirname $apipath)" ]; then
                echo "{\"open\":true,\"token\":\"${API_TOKEN}\",\"limit_addr\":[\"127.0.0.1\"]}" > "$apipath"
                echo "API_TOKEN=${API_TOKEN}" >> /opt/simulation/.panel_creds
                log INFO "aaPanel API enabled with token"
                break
            fi
        done

        # Restart panel to pick up API config
        bt restart 2>/dev/null || /etc/init.d/bt restart 2>/dev/null || true
        sleep 3
    else
        log WARN "aaPanel installer ran but 'bt' command not found"
    fi
fi

# ── Fallback: manual LAMP ─────────────────────────────────────────
if [ "$AAPANEL_OK" = false ]; then
    log WARN "aaPanel not available -- falling back to manual LAMP setup"
    touch /opt/simulation/.manual_mode
    setup_lamp_manually

    # Set MariaDB root password
    MYSQL_BIN=$(find_mysql)
    log INFO "Setting MariaDB root password..."
    $MYSQL_BIN -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_ROOT_PASS:-root_password_123}';" 2>/dev/null || \
    $MYSQL_BIN -u root -e "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_ROOT_PASS:-root_password_123}');" 2>/dev/null || \
    log WARN "Could not set root password"
fi

# ── Summary ───────────────────────────────────────────────────────
log INFO "Step 1 complete:"
log INFO "  aaPanel: $(if [ "$AAPANEL_OK" = true ]; then echo 'Installed'; else echo 'Manual mode'; fi)"
log INFO "  Ubuntu:  $(lsb_release -ds 2>/dev/null || cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2)"

exit 0
