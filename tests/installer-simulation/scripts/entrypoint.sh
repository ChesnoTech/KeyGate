#!/bin/bash
# Write Docker environment variables to file for systemd service
# Uses plain KEY=VALUE format (no shell expansion in systemd EnvironmentFile)

cat > /opt/simulation/.env <<EOF
INSTALL_MODE=${INSTALL_MODE:-fast}
AAPANEL_PORT=${AAPANEL_PORT:-8888}
SITE_PORT=${SITE_PORT:-80}
DB_NAME=${DB_NAME:-oem_activation}
DB_USER=${DB_USER:-oem_user}
DB_PASS=${DB_PASS:-oem_password_123}
DB_ROOT_PASS=${DB_ROOT_PASS:-root_password_123}
DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-3306}
ADMIN_USER=${ADMIN_USER:-admin}
ADMIN_PASS=${ADMIN_PASS:-Admin2024!}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@oem-system.local}
SITE_DOMAIN=${SITE_DOMAIN:-oem-system.local}
DEBIAN_FRONTEND=noninteractive
TZ=UTC
EOF

exec /sbin/init
