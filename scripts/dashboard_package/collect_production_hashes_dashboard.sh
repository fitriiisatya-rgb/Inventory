#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_dashboard.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_dashboard.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_dashboard.sh <public dir> <services dir>}"
echo "== SHA256 of the 4 files the dashboard package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/assets/js/dashboard.js" "$P/assets/css/app.css" "$P/index.html" "$P/index.php"
echo
echo "== must be ABSENT before the dashboard package =="
ls -l "$SV/DashboardInventoryService.php" 2>&1 | head -1
ls "$P"/assets/js/*.pre-dash-backup "$P"/assets/css/*.pre-dash-backup "$P"/*.pre-dash-backup "$P"/*.dashboard-patch.json "$P"/assets/*/*.dashboard-patch.json 2>/dev/null || echo "no dashboard leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/InventoryHppReportService.php';" "require_once InventoryHppReportService.php   [1]"
c "$P/index.php" "'GET /inventory/value' => function () use (\$pdo) {" "route 'GET /inventory/value'   [1]"
c "$P/index.php" "function inv_hpp_resolve_warehouse_scope" "inv_hpp_resolve_warehouse_scope() defined   [1]"
c "$P/index.php" "DashboardInventoryService" "DashboardInventoryService already referenced   [0]"
c "$P/index.php" "/dashboard/inventory" "dashboard routes already present   [0]"
for s in InventoryService InventorySummaryReportService StockReportService SlowMovementReportService ExpiryReportService InventoryHppReportService; do
  c "$P/index.php" "services/$s.php" "index.php requires $s.php   [1]"
done
echo "-- app.css"
c "$P/assets/css/app.css" ".dash-" "'.dash-' selectors already present   [0]"
c "$P/assets/css/app.css" "Dashboard redesign (dashboard.js)" "dashboard CSS marker already present   [0]"
echo "-- index.html"
c "$P/index.html" 'assets/js/dashboard.js?v=' "dashboard.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" '20261009-dash1' "new dashboard token already used   [0]"
echo
echo "== backend dependencies must exist =="
ls -l "$SV/Database.php" "$SV/Exceptions.php" "$SV/InventoryService.php" "$SV/InventorySummaryReportService.php" "$SV/StockReportService.php" "$SV/SlowMovementReportService.php" "$SV/ExpiryReportService.php" "$SV/InventoryHppReportService.php" 2>&1
echo
echo "== cache-bust lines now (Jejak state is irrelevant to this package) =="
grep -n 'dashboard.js?v=\|app.css?v=\|stock-opname-report-jejak.js?v=\|report-opname.js?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
