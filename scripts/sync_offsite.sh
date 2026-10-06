#!/bin/bash
# =============================================================================
# sync_offsite.sh — Provider-Neutral Offsite Disaster Recovery Replication
# Usage: sudo bash sync_offsite.sh <archive_file_path>
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

ARCHIVE="${1:-}"

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    echo "[ERROR] Backup archive file does not exist: $ARCHIVE" >&2
    echo "Usage: sudo bash sync_offsite.sh <archive_file_path>" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../laravel-panel/.env"

ENABLED="false"
TRANSPORT="rsync"
HOST=""
PORT="22"
USER="backup-operator"
REMOTE_PATH="/var/backups/remote-mailsaas"
SSH_KEY=""

if [[ -f "$ENV_FILE" ]]; then
    ENABLED="$(grep -E '^BACKUP_OFFSITE_ENABLED=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'false')"
    TRANSPORT="$(grep -E '^BACKUP_OFFSITE_TRANSPORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'rsync')"
    HOST="$(grep -E '^BACKUP_OFFSITE_HOST=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    PORT="$(grep -E '^BACKUP_OFFSITE_PORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '22')"
    USER="$(grep -E '^BACKUP_OFFSITE_USER=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'backup-operator')"
    REMOTE_PATH="$(grep -E '^BACKUP_OFFSITE_PATH=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '/var/backups/remote-mailsaas')"
    SSH_KEY="$(grep -E '^BACKUP_OFFSITE_SSH_KEY=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
fi

if [[ "$ENABLED" != "true" ]]; then
    echo "[NOTICE] Offsite backup replication is disabled (BACKUP_OFFSITE_ENABLED != true)."
    exit 0
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting offsite synchronization for: $(basename "$ARCHIVE")"

# 1. Verify local archive integrity prior to replication
echo "  [VERIFY] Validating local archive integrity prior to offsite transfer..."
if [[ -f "${SCRIPT_DIR}/verify_backup.sh" ]]; then
    if ! bash "${SCRIPT_DIR}/verify_backup.sh" "$ARCHIVE"; then
        echo "[ERROR] Local archive failed integrity verification. Aborting offsite replication." >&2
        exit 1
    fi
fi

MANIFEST="${ARCHIVE}.manifest.json"

# 2. Execute transport with fail-safe semantics (remote errors never affect local archive)
SSH_KEY_ARG=""
if [[ -n "$SSH_KEY" && -f "$SSH_KEY" ]]; then
    SSH_KEY_ARG="-i $SSH_KEY"
fi

case "$TRANSPORT" in
    rsync)
        if [[ -z "$HOST" ]]; then
            echo "[ERROR] Offsite host (BACKUP_OFFSITE_HOST) is not configured for rsync transport." >&2
            exit 1
        fi
        SSH_CMD="ssh -p $PORT $SSH_KEY_ARG -o StrictHostKeyChecking=accept-new"
        echo "  [TRANSPORT] Replicating via rsync to $USER@$HOST:$REMOTE_PATH/..."
        rsync -avz -e "$SSH_CMD" "$ARCHIVE" "$USER@$HOST:$REMOTE_PATH/"
        if [[ -f "$MANIFEST" ]]; then
            rsync -avz -e "$SSH_CMD" "$MANIFEST" "$USER@$HOST:$REMOTE_PATH/"
        fi
        ;;

    scp)
        if [[ -z "$HOST" ]]; then
            echo "[ERROR] Offsite host (BACKUP_OFFSITE_HOST) is not configured for scp transport." >&2
            exit 1
        fi
        echo "  [TRANSPORT] Replicating via scp to $USER@$HOST:$REMOTE_PATH/..."
        scp -P "$PORT" $SSH_KEY_ARG "$ARCHIVE" "$USER@$HOST:$REMOTE_PATH/$(basename "$ARCHIVE")"
        if [[ -f "$MANIFEST" ]]; then
            scp -P "$PORT" $SSH_KEY_ARG "$MANIFEST" "$USER@$HOST:$REMOTE_PATH/$(basename "$MANIFEST")"
        fi
        ;;

    local)
        echo "  [TRANSPORT] Replicating to local mount destination: $REMOTE_PATH/..."
        mkdir -p "$REMOTE_PATH"
        cp "$ARCHIVE" "$REMOTE_PATH/$(basename "$ARCHIVE")"
        if [[ -f "$MANIFEST" ]]; then
            cp "$MANIFEST" "$REMOTE_PATH/$(basename "$MANIFEST")"
        fi
        ;;

    *)
        echo "[ERROR] Unsupported offsite transport: $TRANSPORT" >&2
        exit 1
        ;;
esac

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Offsite replication completed successfully: $(basename "$ARCHIVE")"
exit 0
