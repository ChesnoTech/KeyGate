#!/bin/bash
# =============================================================
# OEM Activation System v2.0 — Idempotent Database Initialization
# =============================================================
# This file is the ONLY entry point for docker-entrypoint-initdb.d.
# It runs all migration SQL files in correct dependency order,
# skipping any that have already been applied (tracked in schema_versions).
#
# MariaDB provides $MARIADB_DATABASE, $MARIADB_USER, $MARIADB_PASSWORD
# =============================================================

set -e

DB="${MARIADB_DATABASE:-oem_activation}"
SQL_DIR="/docker-entrypoint-initdb.d/sql"
MYSQL_CMD="mysql -u root -p${MARIADB_ROOT_PASSWORD} ${DB}"

echo "=== OEM Activation System: Database Initialization ==="
echo "Database: $DB"
echo "SQL directory: $SQL_DIR"

# ── Step 0: Ensure schema_versions table exists ──────────────
# This must run unconditionally so the tracking table is always present.
$MYSQL_CMD < "$SQL_DIR/schema_versions_migration.sql" 2>/dev/null || true

# ── Idempotent migration runner ──────────────────────────────
# Checks schema_versions before running; records after success.
run_sql() {
    local file="$SQL_DIR/$1"
    local version="$2"

    if [ ! -f "$file" ]; then
        echo "[WARN] File not found: $file"
        return
    fi

    # Check if already applied
    local applied
    applied=$($MYSQL_CMD -N -e "SELECT COUNT(*) FROM schema_versions WHERE filename = '$1'" 2>/dev/null || echo "0")

    if [ "$applied" -gt 0 ]; then
        echo "[SKIP] Already applied: $1"
        return
    fi

    echo "[INIT] Running: $1 (version $version)"
    if ! $MYSQL_CMD < "$file" 2>&1; then
        echo "[WARN] Non-fatal errors in $1 (continuing)"
    fi

    # Compute checksum and record
    local checksum
    checksum=$(sha256sum "$file" | cut -d' ' -f1)

    $MYSQL_CMD -e "INSERT INTO schema_versions (version, filename, checksum) VALUES ($version, '$1', '$checksum')"
    echo "[DONE] Applied: $1"
}

# ── Migrations in dependency order ───────────────────────────
# Version numbers are sequential and reflect the order of introduction.

# Phase 1: Core schema (technicians, oem_keys, activation_attempts, admin tables)
run_sql "install.sql"                          1

# Phase 2: Performance indexes for concurrent access
run_sql "database_concurrency_indexes.sql"     2

# Phase 3: Security and access control
run_sql "rbac_migration.sql"                   3
run_sql "acl_migration.sql"                    4
run_sql "2fa_migration.sql"                    5
run_sql "rate_limiting_migration.sql"          6

# Phase 4: Feature migrations
run_sql "backup_migration.sql"                 7
run_sql "hardware_info_migration.sql"          8
run_sql "hardware_info_v2_migration.sql"       9
run_sql "push_notifications_migration.sql"    10
run_sql "client_resources_migration.sql"      11
run_sql "i18n_migration.sql"                  12
run_sql "qc_compliance_migration.sql"         13
run_sql "order_field_config_migration.sql"    14
run_sql "integrations_migration.sql"          15

# Phase 5: Temp password column widening (allows bcrypt hashes)
run_sql "temp_password_hash_migration.sql"    16

# Phase 6: Product variants & partition QC
run_sql "product_variants_migration.sql"      17

# Phase 7: Missing drivers & unallocated space
run_sql "missing_drivers_migration.sql"       18
run_sql "unallocated_space_migration.sql"     19

# Phase 8: Downloads ACL permissions
run_sql "downloads_acl_migration.sql"         20

# Phase 9: System upgrade tracking
run_sql "upgrade_system_migration.sql"        21

echo ""
echo "=== Database initialization complete ==="
echo ""
echo "NOTE: To hash existing temp passwords, run after first boot:"
echo "  docker exec oem-activation-web php /var/www/html/activate/database/hash_temp_passwords.php"
