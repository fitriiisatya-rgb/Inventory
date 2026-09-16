#!/usr/bin/env bash
# PHASE G13 — Creates/resets `inventory_staging`, loaded with a completely
# fresh, empty schema (never copied from any existing database, dummy or
# otherwise). This is where approved production files are imported and
# smoke-tested BEFORE anything touches a real production database.
#
# Usage: migration/scripts/setup_staging_db.sh <db_name> [mysql_args...]
#   (db_name defaults to inventory_staging)
set -euo pipefail
cd "$(dirname "$0")/../.."

DB_NAME="${1:-inventory_staging}"
shift || true
MYSQL_ARGS=("$@")

echo "This will DROP and recreate database '${DB_NAME}' with a fresh empty schema."
read -r -p "Type the database name again to confirm: " CONFIRM
if [[ "$CONFIRM" != "$DB_NAME" ]]; then
    echo "Confirmation did not match — aborting." >&2
    exit 1
fi

mysql "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql "${MYSQL_ARGS[@]}" "${DB_NAME}" < database/schema.sql
echo "Staging database '${DB_NAME}' ready with a fresh schema (0 rows in every business table)."
echo "Next: provision a SUPERADMIN (migration/install.php) and stage/commit approved files from migration/workspace/approved/ via the normal import API."
