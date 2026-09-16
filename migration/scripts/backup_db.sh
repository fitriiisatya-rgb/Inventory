#!/usr/bin/env bash
# PHASE G17 — Backup helper. Wraps mysqldump for the three backup points the
# cutover checklist requires: schema-only, pre-import, and post-import.
# Never point this at a database you don't intend to actually back up —
# it does not ask for confirmation.
#
# Usage:
#   migration/scripts/backup_db.sh schema      <db_name> [mysql_args...]
#   migration/scripts/backup_db.sh pre-import  <db_name> [mysql_args...]
#   migration/scripts/backup_db.sh post-import <db_name> [mysql_args...]
#
# mysql_args are passed straight to mysqldump (e.g. -h127.0.0.1 -P13306 -uroot -pSECRET).
# Output goes to storage/backups/<db_name>_<label>_<timestamp>.sql.gz
set -euo pipefail
cd "$(dirname "$0")/../.."

LABEL="${1:-}"
DB_NAME="${2:-}"
shift 2 || true
MYSQL_ARGS=("$@")

if [[ -z "$LABEL" || -z "$DB_NAME" ]]; then
    echo "Usage: $0 {schema|pre-import|post-import} <db_name> [mysqldump args...]" >&2
    exit 1
fi

case "$LABEL" in
    schema) DUMP_FLAGS=(--no-data --routines --triggers) ;;
    pre-import|post-import) DUMP_FLAGS=(--single-transaction --routines --triggers) ;;
    *) echo "Unknown label '$LABEL' — expected schema|pre-import|post-import" >&2; exit 1 ;;
esac

mkdir -p storage/backups
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="storage/backups/${DB_NAME}_${LABEL}_${TIMESTAMP}.sql.gz"

mysqldump "${MYSQL_ARGS[@]}" "${DUMP_FLAGS[@]}" "$DB_NAME" | gzip > "$OUT"
echo "Backup written: $OUT ($(du -h "$OUT" | cut -f1))"
echo "Restore with: migration/scripts/restore_db.sh $OUT <target_db_name> [mysql args...]"
