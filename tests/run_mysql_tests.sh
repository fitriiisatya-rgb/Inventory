#!/usr/bin/env bash
# Runs every real-MySQL test file against a FRESH copy of database/schema.sql,
# resetting between files — several of these tests create book_closings
# (LOCKED) rows that would otherwise leak into a later file's fixture dates.
# Requires a configured .env (DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD)
# pointing at a throwaway database — never point this at production.
set -euo pipefail
cd "$(dirname "$0")/.."

DB_NAME="${DB_DATABASE:-$(grep '^DB_DATABASE=' .env | cut -d= -f2)}"
MYSQL_ARGS="${MYSQL_TEST_ARGS:--u root}"  # override via env if a socket/host/port is needed

reset_db() {
    mysql $MYSQL_ARGS -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql $MYSQL_ARGS "${DB_NAME}" < database/schema.sql
}

FAILED=0
for test in tests/mysql_smoke_test.php tests/mysql_integration_test.php tests/mysql_importer_test.php tests/mysql_void_test.php tests/mysql_security_test.php tests/opening_g_data_2_test.php tests/migration_negative_stock_test.php tests/warehouse_isolation_regression_test.php tests/stock_policy_test.php tests/master_data_v2_test.php tests/stock_report_test.php tests/transaction_history_test.php tests/master_data_v2_1_test.php tests/trace_test.php tests/inventory_hpp_report_test.php tests/inventory_hpp_costing_audit_test.php tests/inventory_hpp_v23c_production_hotfix_test.php tests/inventory_hpp_v23d_cutover_test.php tests/inventory_v25_transaction_correction_test.php tests/mysql_import_xlsx_v26a_test.php tests/inventory_movement_report_v26b_test.php tests/inventory_reports_v26b_extended_test.php tests/inventory_reports_v26c_test.php tests/inventory_v26_final_gate_test.php tests/inventory_v26d_stock_card_test.php; do
    echo "=============================================================="
    echo "Resetting database and running ${test}"
    echo "=============================================================="
    reset_db
    php "$test" || FAILED=1
    echo
done

echo "=============================================================="
echo "Resetting database and running tests/concurrency_test.sh"
echo "=============================================================="
reset_db
bash tests/concurrency_test.sh 5 || FAILED=1

exit $FAILED
