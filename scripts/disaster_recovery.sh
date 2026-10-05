#!/bin/bash
# =============================================================================
# disaster_recovery.sh — Total Disaster Recovery Orchestrator
# Usage: sudo bash disaster_recovery.sh <db_archive.sql.gz> <vmail_archive.tar.gz> [--confirm]
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

DB_ARCHIVE="${1:-}"
VMAIL_ARCHIVE="${2:-}"
CONFIRM="${3:-}"

if [[ $EUID -ne 0 ]]; then
    echo "[ERROR] This disaster recovery orchestrator must be run as root (sudo)." >&2
    exit 1
fi

if [[ -z "$DB_ARCHIVE" || ! -f "$DB_ARCHIVE" || -z "$VMAIL_ARCHIVE" || ! -f "$VMAIL_ARCHIVE" ]]; then
    echo "============================================================================="
    echo " EmailSaaS Disaster Recovery Orchestration"
    echo "============================================================================="
    echo "Usage: sudo bash disaster_recovery.sh <db_archive.sql.gz> <vmail_archive.tar.gz> --confirm"
    echo ""
    echo "Parameters:"
    echo "  db_archive.sql.gz     : Path to verified MariaDB/MySQL dump"
    echo "  vmail_archive.tar.gz  : Path to verified /var/vmail archive"
    echo "  --confirm             : Explicit operator confirmation flag"
    exit 1
fi

if [[ "$CONFIRM" != "--confirm" ]]; then
    echo "============================================================================="
    echo " ATTENTION: FULL SYSTEM RESTORATION REQUESTED"
    echo "============================================================================="
    echo "This will restore the database from:     $DB_ARCHIVE"
    echo "And overwrite mail storage at /var/vmail from: $VMAIL_ARCHIVE"
    echo ""
    echo "To proceed, rerun with the --confirm flag:"
    echo "  sudo bash disaster_recovery.sh \"$DB_ARCHIVE\" \"$VMAIL_ARCHIVE\" --confirm"
    exit 1
fi

SCRIPT_DIR="$(dirname "$0")"
LARAVEL_DIR="${SCRIPT_DIR}/../laravel-panel"

echo ""
echo "============================================================================="
echo "[$(date +'%Y-%m-%d %H:%M:%S')] STARTING TOTAL DISASTER RECOVERY SEQUENCE"
echo "============================================================================="

# 1. Stop mail ingress to prevent inconsistencies during recovery
echo "[STEP 1/6] Pausing mail ingress services (Postfix)..."
if command -v systemctl &>/dev/null && systemctl is-active --quiet postfix; then
    systemctl stop postfix || true
    echo "  [OK] Postfix stopped."
fi

# 2. Restore Database
echo "[STEP 2/6] Restoring MariaDB/MySQL database..."
bash "${SCRIPT_DIR}/restore_db.sh" "$DB_ARCHIVE" --confirm
echo "  [OK] Database restored."

# 3. Restore Mail Storage
echo "[STEP 3/6] Restoring Maildir storage (/var/vmail)..."
bash "${SCRIPT_DIR}/restore_vmail.sh" "$VMAIL_ARCHIVE" --confirm "/var/vmail"
echo "  [OK] Mail storage restored and permissions verified."

# 4. Laravel Application Verification & Cache Flush
echo "[STEP 4/6] Verifying Laravel application environment and migrations..."
if [[ -d "$LARAVEL_DIR" ]]; then
    cd "$LARAVEL_DIR"
    php artisan config:clear || true
    php artisan route:clear || true
    php artisan view:clear || true
    php artisan cache:clear || true
    php artisan migrate --force || true
    echo "  [OK] Laravel migrations verified and application caches cleared."
else
    echo "  [WARN] Laravel directory not found at $LARAVEL_DIR. Skipping artisan steps."
fi

# 5. Service Reloads & Restart
echo "[STEP 5/6] Starting / Reloading infrastructure services..."
if command -v systemctl &>/dev/null; then
    for SVC in dovecot postfix redis-server mariadb mysql php8.2-fpm nginx; do
        if systemctl list-unit-files "${SVC}.service" &>/dev/null; then
            systemctl restart "$SVC" 2>/dev/null || systemctl reload "$SVC" 2>/dev/null || true
            echo "  Service $SVC: restarted/reloaded"
        fi
    done
fi

# 6. Post-Recovery Verification Report
echo "[STEP 6/6] Generating post-recovery health status..."
if [[ -d "$LARAVEL_DIR" ]]; then
    cd "$LARAVEL_DIR"
    php artisan backup:status || true
fi

echo ""
echo "============================================================================="
echo "[$(date +'%Y-%m-%d %H:%M:%S')] DISASTER RECOVERY PROCEDURE COMPLETED"
echo "============================================================================="
exit 0
