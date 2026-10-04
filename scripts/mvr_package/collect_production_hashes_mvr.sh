#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_mvr.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_mvr.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_mvr.sh <public dir> <services dir>}"
echo "== SHA256 of the 5 existing files the package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$P/assets/js/report-movement.js"
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== must be ABSENT before the package (the 1 NEW file) =="
ls -l "$SV/MovementDailyReportService.php" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-mvr-backup "$P"/assets/*/*.pre-mvr-backup "$SV"/*.pre-mvr-backup "$P"/*.mvr-patch.json "$P"/assets/*/*.mvr-patch.json "$SV"/*.mvr-patch.json 2>/dev/null || echo "no Pergerakan Stok leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/InventoryMovementReportService.php';" "require_once InventoryMovementReportService.php (insert anchor)   [1]"
c "$P/index.php" ' * Same "STOCK is always forced to their own warehouse, never a' "docblock of inv_hpp_resolve_warehouse_scope (insert anchor)   [1]"
c "$P/index.php" "function inv_hpp_resolve_warehouse_scope(" "inv_hpp_resolve_warehouse_scope   [1]"
c "$P/index.php" "'GET /reports/reconciliation/movement' => function (" "reconciliation/movement route (insert anchor)   [1]"
c "$P/index.php" "MovementDailyReportService" "already references MovementDailyReportService   [0]"
c "$P/index.php" "inv_movement_params" "inv_movement_params already present   [0]"
c "$P/index.php" "'GET /reports/movement/overview'" "new overview route already present   [0]"
c "$P/index.php" "'GET /reports/movement/export'" "new export route already present   [0]"
for r in daily day-breakdown day-transactions historical-transactions; do c "$P/index.php" "'GET /reports/movement/$r' =>" "legacy route /reports/movement/$r kept   [1]"; done
echo "-- api-client.js"
c "$P/assets/js/api-client.js" "        movementHistoricalTransactions: " "movementHistoricalTransactions line (anchor)   [1]"
c "$P/assets/js/api-client.js" "        movementDailyExportUrl: " "movementDailyExportUrl line (anchor)   [1]"
c "$P/assets/js/api-client.js" "movementOverview:" "movementOverview already present   [0]"
c "$P/assets/js/api-client.js" "movementExportUrl:" "movementExportUrl already present   [0]"
echo "-- app.css"
c "$P/assets/css/app.css" ".mvr-" "'.mvr-' selectors already present   [0]"
echo "-- report-movement.js (replaced; hash gate)"
c "$P/assets/js/report-movement.js" "const ReportMovement" "ReportMovement   [1]"
echo "-- index.html"
for f in api-client report-movement; do c "$P/index.html" "assets/js/$f.js?v=" "$f.js script tag   [1]"; done
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" '20261013-mvr' "new token already used   [0]"
echo "-- the report engine the new service reuses (must already exist, public static, unchanged)"
c "$SV/InventoryHppReportService.php" "public static function cutoverContext(" "InventoryHppReportService::cutoverContext   [1]"
c "$SV/InventoryHppReportService.php" "public static function signedValueBefore(" "InventoryHppReportService::signedValueBefore   [1]"
c "$SV/InventoryHppReportService.php" "public static function itemFilterClauses(" "InventoryHppReportService::itemFilterClauses   [1]"
c "$SV/InventoryHppReportService.php" "public const SIGNED_VALUE_SQL" "InventoryHppReportService::SIGNED_VALUE_SQL   [1]"
c "$SV/InventoryMovementReportService.php" "public static function dailyMovement(" "InventoryMovementReportService::dailyMovement   [1]"
ls -l "$SV/Database.php" 2>&1 | sed 's/^/   /'
echo
echo "== cache-bust lines now =="
grep -n 'app.css?v=\|api-client.js?v=\|report-movement.js?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
