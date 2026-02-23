#!/bin/bash
# =============================================================
# OEM Activation System v2.0 — Complete Database Initialization
# =============================================================
# This file is the ONLY entry point for docker-entrypoint-initdb.d.
# It runs all migration SQL files in correct dependency order.
#
# MariaDB provides $MARIADB_DATABASE, $MARIADB_USER, $MARIADB_PASSWORD
# =============================================================

set -e

DB="${MARIADB_DATABASE:-oem_activation}"
SQL_DIR="/docker-entrypoint-initdb.d/sql"

echo "=== OEM Activation System: Database Initialization ==="
echo "Database: $DB"
echo "SQL directory: $SQL_DIR"

run_sql() {
    local file="$SQL_DIR/$1"
    if [ -f "$file" ]; then
        echo "[INIT] Running: $1"
        mysql -u root -p"${MARIADB_ROOT_PASSWORD}" "$DB" < "$file"
    else
        echo "[WARN] File not found: $file"
    fi
}

# Phase 1: Core schema (technicians, oem_keys, activation_attempts, admin tables)
run_sql "install.sql"

# Phase 2: Performance indexes for concurrent access
run_sql "database_concurrency_indexes.sql"

# Phase 3: Security and access control
run_sql "rbac_migration.sql"
run_sql "acl_migration.sql"
run_sql "2fa_migration.sql"
run_sql "rate_limiting_migration.sql"

# Phase 4: Feature migrations
run_sql "backup_migration.sql"
run_sql "hardware_info_migration.sql"
run_sql "hardware_info_v2_migration.sql"
run_sql "push_notifications_migration.sql"
run_sql "client_resources_migration.sql"
run_sql "i18n_migration.sql"

# Phase 5: Temp password column widening (allows bcrypt hashes)
run_sql "temp_password_hash_migration.sql"

echo "=== Database initialization complete ==="
echo ""
echo "NOTE: To hash existing temp passwords, run after first boot:"
echo "  docker exec oem-activation-web php /var/www/html/activate/database/hash_temp_passwords.php"
