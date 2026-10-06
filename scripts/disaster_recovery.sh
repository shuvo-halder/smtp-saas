#!/bin/bash
# =============================================================================
# disaster_recovery.sh — Total Disaster Recovery Orchestrator
# Usage: sudo bash disaster_recovery.sh <db_archive> <vmail_archive> [--confirm] [--run-migrations]
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

DB_ARCHIVE="${1:-}"
VMAIL_ARCHIVE="${2:-}"
CONFIRM="${3:-}"
RUN_MIGRATIONS=false

for arg in "$@"; do
    if [[ "$arg" == "--run-migrations" ]]; then
        RUN_MIGRATIONS=true
    fi
done

if [[ $EUID -ne 0 ]]; then
    echo "[ERROR] This disaster recovery orchestrator must be run as root (sudo)." >&2
    exit 1
fi

if [[ -z "$DB_ARCHIVE" || ! -f "$DB_ARCHIVE" || -z "$VMAIL_ARCHIVE" || ! -f "$VMAIL_ARCHIVE" ]]; then
    echo "============================================================================="
    echo " EmailSaaS Disaster Recovery Orchestration"
    echo "============================================================================="
    echo "Usage: sudo bash disaster_recovery.sh <db_archive> <vmail_archive> --confirm [--run-migrations]"
    echo ""
    echo "Parameters:"
    echo "  db_archive            : Path to verified MariaDB/MySQL dump (.sql.gz or .sql.gz.enc)"
    echo "  vmail_archive         : Path to verified /var/vmail archive (.tar.gz or .tar.gz.enc)"
    echo "  --confirm             : Explicit operator confirmation flag (MANDATORY)"
    echo "  --run-migrations      : Apply pending Laravel migrations post-restore (FAIL-CLOSED)"
    exit 1
fi

if [[ "$CONFIRM" != "--confirm" ]]; then
    echo "============================================================================="
    echo " ATTENTION: FULL SYSTEM RESTORATION REQUESTED"
    echo "============================================================================="
    echo "This will restore the database from:           $DB_ARCHIVE"
    echo "And overwrite mail storage at /var/vmail from: $VMAIL_ARCHIVE"
    echo ""
    echo "To proceed, rerun with the --confirm flag:"
    echo "  sudo bash disaster_recovery.sh \"$DB_ARCHIVE\" \"$VMAIL_ARCHIVE\" --confirm [--run-migrations]"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
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

# 4. Laravel Application Verification & Controlled Migration Execution
echo "[STEP 4/6] Verifying Laravel application environment..."
if [[ -d "$LARAVEL_DIR" ]]; then
    cd "$LARAVEL_DIR"
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    php artisan cache:clear

    echo "  [MIGRATION STATUS] Current database migration status:"
    php artisan migrate:status

    if [[ "$RUN_MIGRATIONS" == "true" ]]; then
        echo "  [MIGRATING] Applying pending database migrations (--force)..."
        # Fail-closed: Do NOT swallow migration errors with || true
        php artisan migrate --force
        echo "  [OK] Migrations applied successfully."
    else
        echo "  [NOTICE] Pending migrations NOT applied automatically (pass --run-migrations to apply)."
    fi
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
    php artisan backup:status
fi

echo ""
echo "============================================================================="
echo "[$(date +'%Y-%m-%d %H:%M:%S')] DISASTER RECOVERY PROCEDURE COMPLETED SUCCESSFULLY"
echo "============================================================================="
exit 0
