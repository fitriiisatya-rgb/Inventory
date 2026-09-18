#!/usr/bin/env bash
# FINAL FAST-TRACK GO_LIVE READINESS -- SCM + CIBADAK -- FINAL PRE-PRODUCTION
# DRY RUN orchestrator. Resets a dedicated STAGING database (never the
# inventory_test dev/test DB, never production), loads schema.sql, then runs
# the PHP staging import (scripts/staging_dry_run_scm_cibadak.php). Requires
# migration/workspace/staging_export/*.csv to already exist -- generate them
# first with: python3 migration/scripts/export_scm_cibadak_staging.py
#
# Usage: bash scripts/run_staging_dry_run.sh
set -euo pipefail
cd "$(dirname "$0")/.."

export DB_DATABASE="inventory_staging_scm_cibadak"
MYSQL_ARGS="${MYSQL_TEST_ARGS:--u root}"

if [ ! -f migration/workspace/staging_export/opening_16sep.csv ]; then
    echo "staging_export/*.csv not found -- run: python3 migration/scripts/export_scm_cibadak_staging.py" >&2
    exit 1
fi

echo "=============================================================="
echo "Resetting STAGING database: ${DB_DATABASE}"
echo "=============================================================="
mysql $MYSQL_ARGS -e "DROP DATABASE IF EXISTS \`${DB_DATABASE}\`; CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql $MYSQL_ARGS "${DB_DATABASE}" < database/schema.sql

echo "=============================================================="
echo "Running staging import (SCM + CIBADAK)"
echo "=============================================================="
php scripts/staging_dry_run_scm_cibadak.php

echo
echo "=============================================================="
echo "Running critical smoke test against the staging database"
echo "=============================================================="
php tests/staging_smoke_test.php
