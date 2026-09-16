#!/usr/bin/env bash
# PHASE G17 — Restore a backup produced by backup_db.sh. Requires the
# TARGET database to already exist (this script never runs CREATE
# DATABASE / DROP DATABASE — that decision belongs to the operator, made
# explicitly, not to a restore script).
#
# Usage:
#   migration/scripts/restore_db.sh <backup_file.sql.gz> <target_db_name> [mysql_args...]
set -euo pipefail

BACKUP_FILE="${1:-}"
DB_NAME="${2:-}"
shift 2 || true
MYSQL_ARGS=("$@")

if [[ -z "$BACKUP_FILE" || -z "$DB_NAME" ]]; then
    echo "Usage: $0 <backup_file.sql.gz> <target_db_name> [mysql args...]" >&2
    exit 1
fi
if [[ ! -f "$BACKUP_FILE" ]]; then
    echo "Backup file not found: $BACKUP_FILE" >&2
    exit 1
fi

echo "About to restore '$BACKUP_FILE' into database '$DB_NAME'."
echo "This does NOT drop or create the database first — it must already exist and be empty/expected to receive this data."
read -r -p "Type the database name again to confirm: " CONFIRM
if [[ "$CONFIRM" != "$DB_NAME" ]]; then
    echo "Confirmation did not match — aborting." >&2
    exit 1
fi

gunzip -c "$BACKUP_FILE" | mysql "${MYSQL_ARGS[@]}" "$DB_NAME"
echo "Restore complete into '$DB_NAME'."
