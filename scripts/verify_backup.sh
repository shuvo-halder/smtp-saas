#!/bin/bash
# =============================================================================
# verify_backup.sh — Archive Integrity and Checksum Verification Script
# Usage: sudo bash verify_backup.sh <archive_file_path>
# Supports encrypted archives (.sql.gz.enc, .tar.gz.enc)
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

ARCHIVE="${1:-}"

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    echo "[ERROR] Archive file does not exist: $ARCHIVE" >&2
    exit 1
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Verifying backup archive: $ARCHIVE"

# 1. Physical file readability & non-empty check
FILE_SIZE="$(stat -c %s "$ARCHIVE" 2>/dev/null || stat -f %z "$ARCHIVE" 2>/dev/null || wc -c < "$ARCHIVE")"
if [[ "$FILE_SIZE" -le 0 ]]; then
    echo "[FAILED] Backup file is empty (0 bytes)." >&2
    exit 1
fi
echo "  [PASS] File size: $FILE_SIZE bytes"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../laravel-panel/.env"
ENC_KEY=""
ENC_KEY_PATH=""

if [[ -f "$ENV_FILE" ]]; then
    ENC_KEY="$(grep -E '^BACKUP_ENCRYPTION_KEY=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    ENC_KEY_PATH="$(grep -E '^BACKUP_ENCRYPTION_KEY_PATH=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
fi

if [[ -z "$ENC_KEY" && -n "$ENC_KEY_PATH" && -f "$ENC_KEY_PATH" ]]; then
    ENC_KEY="$(head -n 1 "$ENC_KEY_PATH" | tr -d '\r\n')"
fi

# 2. Decompression / Decryption stream test
if [[ "$ARCHIVE" == *.enc ]]; then
    MAGIC="$(head -c 16 "$ARCHIVE" 2>/dev/null || echo '')"
    if [[ "$MAGIC" == "EMAILSAAS_GCM_V1" ]]; then
        echo "  [PASS] Authenticated AEAD encrypted envelope detected (EMAILSAAS_GCM_V1)."
        if [[ -n "$ENC_KEY" ]]; then
            if ! php "${SCRIPT_DIR}/../laravel-panel/artisan" backup:decrypt "$ARCHIVE" --stdout --key="$ENC_KEY" 2>/dev/null | gzip -t 2>/dev/null; then
                echo "[FAILED] AEAD decryption and gzip verification test failed. Archive corrupted, tampered, or key incorrect." >&2
                exit 1
            fi
            echo "  [PASS] Authenticated decryption and inner gzip stream integrity verified."
        else
            echo "  [WARN] No decryption key configured. Ciphertext envelope verified; payload decompression skipped."
        fi
    elif [[ "$MAGIC" == Salted__* ]]; then
        echo "  [WARN] Legacy OpenSSL AES-256-CBC envelope detected (Salted__). Authenticity NOT guaranteed."
        if [[ -n "$ENC_KEY" ]]; then
            if ! openssl enc -d -aes-256-cbc -pbkdf2 -pass pass:"$ENC_KEY" -in "$ARCHIVE" 2>/dev/null | gzip -t 2>/dev/null; then
                echo "[FAILED] Decryption and gzip verification test failed. Archive corrupted or key incorrect." >&2
                exit 1
            fi
            echo "  [PASS] Legacy decryption and inner gzip stream integrity verified (openssl enc -d | gzip -t)."
        else
            echo "  [WARN] No decryption key configured. Ciphertext envelope verified; payload decompression skipped."
        fi
    else
        echo "[FAILED] Encrypted archive does not contain valid magic header (expected EMAILSAAS_GCM_V1 or Salted__)." >&2
        exit 1
    fi
else
    if ! gzip -t "$ARCHIVE" 2>/dev/null; then
        echo "[FAILED] Gzip decompression test failed. Archive is corrupted." >&2
        exit 1
    fi
    echo "  [PASS] Gzip integrity test passed (gzip -t)."
fi

# 3. Manifest and SHA-256 verification
MANIFEST="${ARCHIVE}.manifest.json"
ACTUAL_HASH="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
echo "  [INFO] Computed SHA-256: $ACTUAL_HASH"

if [[ -f "$MANIFEST" ]]; then
    EXPECTED_HASH="$(grep -oP '"sha256":\s*"\K[a-f0-9]{64}' "$MANIFEST" || echo '')"
    if [[ -z "$EXPECTED_HASH" ]]; then
        echo "[WARN] Manifest file found but no valid sha256 property discovered."
    elif [[ "$ACTUAL_HASH" != "$EXPECTED_HASH" ]]; then
        echo "[FAILED] SHA-256 checksum mismatch!" >&2
        echo "         Expected: $EXPECTED_HASH" >&2
        echo "         Actual:   $ACTUAL_HASH" >&2
        exit 1
    else
        echo "  [PASS] SHA-256 checksum matches manifest exactly ($EXPECTED_HASH)."
    fi
else
    echo "  [WARN] Manifest file not found ($MANIFEST). SHA-256 computed but not cross-referenced."
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Archive verification SUCCESSFUL: $ARCHIVE"
exit 0
