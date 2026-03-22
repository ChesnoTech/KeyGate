#!/bin/bash
set -e

# =====================================================
# KeyGate - Database Backup Script
# =====================================================
# This script performs automated database backups with:
# - Gzip compression
# - Integrity verification
# - Automatic cleanup of old backups
# - Database logging of backup history

# Configuration
BACKUP_DIR="/var/www/html/activate/backups"
DB_HOST="${DB_HOST:-db}"
DB_NAME="${DB_NAME:-oem_activation}"
DB_USER="${DB_USER:-oem_user}"
DB_PASSWORD="${DB_PASSWORD:-${DB_PASS}}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
BACKUP_TYPE="${BACKUP_TYPE:-scheduled}"
# MariaDB SSL workaround - skip SSL for internal Docker connections
MYSQL_OPTS="--skip-ssl"

# Create backup directory if not exists
mkdir -p "$BACKUP_DIR"

# Generate backup filename with timestamp
TIMESTAMP=$(date +"%Y-%m-%d_%H%M%S")
BACKUP_FILE="oem_activation_${TIMESTAMP}.sql.gz"
BACKUP_PATH="${BACKUP_DIR}/${BACKUP_FILE}"

# Start time
START_TIME=$(date +%s)

echo "==================================="
echo "KeyGate - Database Backup"
echo "==================================="
echo "Date: $(date)"
echo "Database: ${DB_NAME}"
echo "Backup file: ${BACKUP_FILE}"
echo "==================================="

# Execute mysqldump with compression
echo "Starting backup..."
mysqldump \
    ${MYSQL_OPTS} \
    --host="${DB_HOST}" \
    --user="${DB_USER}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --quick \
    --lock-tables=false \
    --routines \
    --triggers \
    --events \
    --add-drop-database \
    --databases "${DB_NAME}" | gzip > "${BACKUP_PATH}"

# Verify backup integrity
echo "Verifying backup integrity..."
if gzip -t "${BACKUP_PATH}" 2>/dev/null; then
    echo "✅ Backup created successfully: ${BACKUP_FILE}"

    # Calculate backup size
    BACKUP_SIZE=$(du -m "${BACKUP_PATH}" | cut -f1)
    echo "📦 Backup size: ${BACKUP_SIZE} MB"

    # End time
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    echo "⏱️  Duration: ${DURATION} seconds"

    # Count tables
    TABLES_COUNT=$(zcat "${BACKUP_PATH}" | grep -c "CREATE TABLE" || echo "0")
    echo "📊 Tables backed up: ${TABLES_COUNT}"

    # Log to database
    echo "Logging backup to database..."
    mysql ${MYSQL_OPTS} --host="${DB_HOST}" --user="${DB_USER}" --password="${DB_PASSWORD}" "${DB_NAME}" <<EOF
INSERT INTO backup_history (
    backup_filename, backup_size_mb, backup_type, backup_status,
    backup_duration_seconds, tables_count, created_at
) VALUES (
    '${BACKUP_FILE}', ${BACKUP_SIZE}, '${BACKUP_TYPE}', 'success',
    ${DURATION}, ${TABLES_COUNT}, NOW()
);
EOF

    echo "✅ Backup logged to database"

else
    echo "❌ Backup verification failed!"

    # Log failure to database
    mysql ${MYSQL_OPTS} --host="${DB_HOST}" --user="${DB_USER}" --password="${DB_PASSWORD}" "${DB_NAME}" <<EOF
INSERT INTO backup_history (
    backup_filename, backup_size_mb, backup_type, backup_status,
    error_message, created_at
) VALUES (
    '${BACKUP_FILE}', 0, '${BACKUP_TYPE}', 'failed',
    'Backup file verification failed (corrupted gzip)', NOW()
);
EOF

    # Remove corrupted backup
    rm -f "${BACKUP_PATH}"

    exit 1
fi

# Cleanup old backups
echo ""
echo "🧹 Cleaning up backups older than ${RETENTION_DAYS} days..."
DELETED_COUNT=$(find "${BACKUP_DIR}" -name "oem_activation_*.sql.gz" -type f -mtime +${RETENTION_DAYS} -print | wc -l)

if [ "$DELETED_COUNT" -gt 0 ]; then
    find "${BACKUP_DIR}" -name "oem_activation_*.sql.gz" -type f -mtime +${RETENTION_DAYS} -print -delete
    echo "🗑️  Deleted ${DELETED_COUNT} old backup(s)"

    # Mark deleted backups in database
    mysql ${MYSQL_OPTS} --host="${DB_HOST}" --user="${DB_USER}" --password="${DB_PASSWORD}" "${DB_NAME}" <<EOF
UPDATE backup_history
SET deleted_at = NOW()
WHERE backup_filename NOT IN (
    SELECT CONCAT('oem_activation_', DATE_FORMAT(created_at, '%Y-%m-%d_%H%i%s'), '.sql.gz')
    FROM (SELECT * FROM backup_history) AS bh
)
AND deleted_at IS NULL
AND backup_type = 'scheduled';
EOF
else
    echo "✓ No old backups to delete"
fi

# Show current backup count
BACKUP_COUNT=$(find "${BACKUP_DIR}" -name "oem_activation_*.sql.gz" -type f | wc -l)
echo "📁 Total backups: ${BACKUP_COUNT}"

# Calculate total backup size
TOTAL_SIZE=$(du -shm "${BACKUP_DIR}" | cut -f1)
echo "💾 Total backup size: ${TOTAL_SIZE} MB"

echo ""
echo "==================================="
echo "✅ Backup completed successfully!"
echo "==================================="

exit 0
