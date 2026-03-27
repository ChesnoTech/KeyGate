#!/bin/bash
# ============================================================
# KeyGate Production Deployment Script
# For: Ubuntu 22.04 + aaPanel + Docker
# ============================================================
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/ChesnoTech/KeyGate/main/deploy/install.sh | bash
#   OR
#   wget -qO- https://raw.githubusercontent.com/ChesnoTech/KeyGate/main/deploy/install.sh | bash
# ============================================================

set -e

KEYGATE_DIR="/opt/keygate"
KEYGATE_REPO="https://github.com/ChesnoTech/KeyGate.git"
KEYGATE_BRANCH="main"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log()  { echo -e "${GREEN}[KeyGate]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# ── Pre-flight checks ─────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║     KeyGate Production Installer         ║${NC}"
echo -e "${CYAN}║     OEM Activation & QC Platform         ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════╝${NC}"
echo ""

# Must be root
if [ "$EUID" -ne 0 ]; then
    err "Please run as root: sudo bash install.sh"
fi

# Check OS
if ! grep -q "Ubuntu 22" /etc/os-release 2>/dev/null; then
    warn "This script is designed for Ubuntu 22.04. Your OS may work but is untested."
    read -p "Continue? [y/N] " -n 1 -r
    echo
    [[ $REPLY =~ ^[Yy]$ ]] || exit 1
fi

# ── Install Docker ─────────────────────────────────────────
if ! command -v docker &>/dev/null; then
    log "Installing Docker..."
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    systemctl enable docker
    systemctl start docker
    log "Docker installed successfully"
else
    log "Docker already installed: $(docker --version)"
fi

# ── Install Git ────────────────────────────────────────────
if ! command -v git &>/dev/null; then
    log "Installing Git..."
    apt-get install -y -qq git
fi

# ── Clone or update KeyGate ────────────────────────────────
if [ -d "$KEYGATE_DIR" ]; then
    log "Updating existing installation..."
    cd "$KEYGATE_DIR"
    git fetch origin
    git checkout "$KEYGATE_BRANCH"
    git pull origin "$KEYGATE_BRANCH"
else
    log "Cloning KeyGate..."
    git clone -b "$KEYGATE_BRANCH" "$KEYGATE_REPO" "$KEYGATE_DIR"
    cd "$KEYGATE_DIR"
fi

# ── Generate .env file ─────────────────────────────────────
if [ ! -f "$KEYGATE_DIR/.env" ]; then
    log "Generating .env file with secure passwords..."

    DB_ROOT_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
    DB_USER_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)
    REDIS_PASS=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 24)

    cat > "$KEYGATE_DIR/.env" << ENVEOF
# KeyGate Production Environment
# Generated: $(date -Iseconds)

# Database
DB_HOST=oem-activation-db
DB_PORT=3306
DB_NAME=oem_activation
DB_USER=oem_user
DB_PASS=${DB_USER_PASS}
MARIADB_ROOT_PASSWORD=${DB_ROOT_PASS}
MARIADB_DATABASE=oem_activation
MARIADB_USER=oem_user
MARIADB_PASSWORD=${DB_USER_PASS}

# Redis
REDIS_HOST=oem-activation-redis
REDIS_PORT=6379
REDIS_PASSWORD=${REDIS_PASS}

# Application
APP_TIMEZONE=UTC
APP_ENV=production

# phpMyAdmin (remove in production if not needed)
PMA_HOST=oem-activation-db
PMA_PORT=3306
ENVEOF

    chmod 600 "$KEYGATE_DIR/.env"
    log "Generated .env with secure random passwords"
    echo ""
    echo -e "${YELLOW}IMPORTANT: Save these credentials somewhere safe:${NC}"
    echo -e "  DB Root Password: ${RED}${DB_ROOT_PASS}${NC}"
    echo -e "  DB User Password: ${RED}${DB_USER_PASS}${NC}"
    echo -e "  Redis Password:   ${RED}${REDIS_PASS}${NC}"
    echo ""
else
    log ".env already exists, keeping existing config"
fi

# ── Generate SSL certificate ───────────────────────────────
SSL_DIR="$KEYGATE_DIR/ssl"
if [ ! -f "$SSL_DIR/server.crt" ]; then
    log "Generating self-signed SSL certificate..."
    mkdir -p "$SSL_DIR"
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$SSL_DIR/server.key" \
        -out "$SSL_DIR/server.crt" \
        -subj "/C=US/ST=State/L=City/O=KeyGate/CN=localhost" 2>/dev/null
    log "SSL certificate generated (self-signed, valid 365 days)"
    warn "For production, replace ssl/server.crt and ssl/server.key with real certificates"
else
    log "SSL certificate already exists"
fi

# ── Build and start ────────────────────────────────────────
log "Building Docker images..."
cd "$KEYGATE_DIR"
docker compose build --quiet

log "Starting KeyGate..."
docker compose up -d

# ── Wait for health ────────────────────────────────────────
log "Waiting for services to start..."
RETRIES=30
until curl -sf http://localhost:8080/api/health.php > /dev/null 2>&1 || [ $RETRIES -eq 0 ]; do
    RETRIES=$((RETRIES - 1))
    sleep 2
done

if [ $RETRIES -eq 0 ]; then
    warn "Health check didn't pass in 60 seconds. Check: docker compose logs"
else
    HEALTH=$(curl -s http://localhost:8080/api/health.php)
    VERSION=$(echo "$HEALTH" | grep -o '"version":"[^"]*"' | cut -d'"' -f4)
    log "Health check passed! Version: ${VERSION:-unknown}"
fi

# ── Create admin user ──────────────────────────────────────
log "Setting up admin account..."
docker compose exec -T web php -r "
require '/var/www/html/activate/config.php';

// Check if admin exists
\$stmt = \$pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
\$stmt->execute(['admin']);
if (\$stmt->fetch()) {
    echo \"Admin user already exists\n\";
    exit(0);
}

// Create admin
\$hash = password_hash('Admin2024!', PASSWORD_BCRYPT, ['cost' => 10]);

// Get or create super_admin role
\$pdo->exec(\"INSERT INTO acl_roles (role_name, display_name, description, role_type, is_system_role) VALUES ('super_admin', 'Super Admin', 'Full system access', 'admin', 1) ON DUPLICATE KEY UPDATE id=id\");
\$roleId = \$pdo->query(\"SELECT id FROM acl_roles WHERE role_name = 'super_admin'\")->fetchColumn();

\$stmt = \$pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name, email, role, custom_role_id, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 0)');
\$stmt->execute(['admin', \$hash, 'System Administrator', 'admin@keygate.local', 'super_admin', \$roleId]);
echo \"Admin user created: admin / Admin2024!\n\";
echo \"CHANGE THIS PASSWORD IMMEDIATELY!\n\";
" 2>/dev/null

# ── Summary ────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     KeyGate Installation Complete!       ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Admin Panel:  ${CYAN}http://$(hostname -I | awk '{print $1}'):8080${NC}"
echo -e "  HTTPS:        ${CYAN}https://$(hostname -I | awk '{print $1}'):8443${NC}"
echo -e "  phpMyAdmin:   ${CYAN}http://$(hostname -I | awk '{print $1}'):8081${NC}"
echo -e "  Health:       ${CYAN}http://$(hostname -I | awk '{print $1}'):8080/api/health.php${NC}"
echo ""
echo -e "  Login:        ${YELLOW}admin / Admin2024!${NC}"
echo -e "  ${RED}Change the admin password immediately!${NC}"
echo ""
echo -e "  Logs:         docker compose -C $KEYGATE_DIR logs -f"
echo -e "  Stop:         docker compose -C $KEYGATE_DIR down"
echo -e "  Update:       cd $KEYGATE_DIR && git pull && docker compose up -d --build"
echo ""
