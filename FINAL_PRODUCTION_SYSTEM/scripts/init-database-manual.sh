#!/bin/bash
# =============================================================
# KeyGate v2.0 — Manual Database Initialization
# =============================================================
# Use this script for bare-metal (non-Docker) deployments.
# Runs all SQL migrations in correct dependency order.
#
# Usage:
#   ./init-database-manual.sh
#   DB_USER=oem_user DB_PASS=secret DB_NAME=oem_activation ./init-database-manual.sh
# =============================================================

set -e

# Resolve script directory (migrations are in ../database/)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SQL_DIR="${SCRIPT_DIR}/../database"

# Database credentials (from env or prompt)
DB_HOST="${DB_HOST:-localhost}"
DB_NAME="${DB_NAME:-oem_activation}"

if [ -z "$DB_USER" ]; then
    read -p "Database username [oem_user]: " DB_USER
    DB_USER="${DB_USER:-oem_user}"
fi

if [ -z "$DB_PASS" ]; then
    read -sp "Database password: " DB_PASS
    echo
fi

if [ -z "$DB_PASS" ]; then
    echo "ERROR: Database password is required."
    exit 1
fi

echo "=== KeyGate: Database Initialization ==="
echo "Host:     $DB_HOST"
echo "Database: $DB_NAME"
echo "User:     $DB_USER"
echo "SQL dir:  $SQL_DIR"
echo "======================================================="
echo ""

# Helper function
run_sql() {
    local file="$SQL_DIR/$1"
    if [ -f "$file" ]; then
        echo "[INIT] Running: $1"
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$file"
        if [ $? -eq 0 ]; then
            echo "       OK"
        else
            echo "       FAILED!"
            exit 1
        fi
    else
        echo "[WARN] File not found: $file (skipping)"
    fi
}

# Phase 1: Core schema (technicians, oem_keys, activation_attempts, admin tables)
echo ""
echo "--- Phase 1: Core Schema ---"
run_sql "install.sql"

# Phase 2: Performance indexes for concurrent access
echo ""
echo "--- Phase 2: Performance Indexes ---"
run_sql "database_concurrency_indexes.sql"

# Phase 3: Security and access control
echo ""
echo "--- Phase 3: Security & Access Control ---"
run_sql "rbac_migration.sql"
run_sql "acl_migration.sql"
run_sql "2fa_migration.sql"
run_sql "rate_limiting_migration.sql"

# Phase 4: Feature migrations
echo ""
echo "--- Phase 4: Feature Migrations ---"
run_sql "backup_migration.sql"
run_sql "hardware_info_migration.sql"
run_sql "hardware_info_v2_migration.sql"
run_sql "push_notifications_migration.sql"
run_sql "client_resources_migration.sql"
run_sql "i18n_migration.sql"

# Phase 5: Data transformation (temp password column widening)
echo ""
echo "--- Phase 5: Data Transformation ---"
run_sql "temp_password_hash_migration.sql"

# Verify
echo ""
echo "=== Verifying installation ==="
TABLE_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME';")
echo "Tables created: $TABLE_COUNT"

echo ""
echo "=== Database initialization complete ==="
echo ""
echo "Next steps:"
echo "  1. Open https://your-domain.com/setup/ to run the Setup Wizard"
echo "  2. Create your admin account in Step 3 of the wizard"
echo ""
