#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_val.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_val.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_val.sh <public dir> <services dir>}"
echo "== SHA256 of the 4 existing files the package changes (the --expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/app.js"
echo
echo "== the old report page (kept, never modified) =="
sha256sum "$P/assets/js/report-hpp.js" 2>&1 | sed 's/^/   /'
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== must be ABSENT before the package (the 2 NEW files) =="
ls -l "$SV/InventoryValuationService.php" "$P/assets/js/report-valuation.js" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-val-backup "$P"/assets/*/*.pre-val-backup "$SV"/*.pre-val-backup "$P"/*.val-patch.json "$P"/assets/*/*.val-patch.json "$SV"/*.val-patch.json 2>/dev/null || echo "no Laporan Nilai Stok & HPP leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/InventoryHppReportService.php';" "require_once InventoryHppReportService.php (insert anchor)   [1]"
c "$P/index.php" ' * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.' "docblock 'PHASE V2.6C interactive-period guard' (insert anchor)   [1]"
c "$P/index.php" "    'GET /reports/inventory-hpp/summary' => function () use (\$pdo, \$query) {" "GET /reports/inventory-hpp/summary route (insert anchor)   [1]"
c "$P/index.php" "function inv_hpp_resolve_warehouse_scope(" "inv_hpp_resolve_warehouse_scope (used by the new helper)   [1]"
c "$P/index.php" "services/ExcelWriterService.php" "ExcelWriterService required (used by the Excel export)   [1]"
c "$P/index.php" "InventoryValuationService" "already references InventoryValuationService   [0]"
c "$P/index.php" "inv_val_filters" "inv_val_filters already present   [0]"
c "$P/index.php" "'GET /reports/inventory-valuation'" "new overview route already present   [0]"
echo "-- app.js"
c "$P/assets/js/app.js" "ReportHpp.render(document.getElementById('tab-laporan-hpp'));" "ReportHpp route of tab-laporan-hpp (the ONE line the package re-points)   [1]"
c "$P/assets/js/app.js" "ReportValuation.render(" "already re-pointed   [0]"
echo "-- app.css"
c "$P/assets/css/app.css" ".val-" "'.val-' selectors already present   [0]"
echo "-- index.html (script / link tags)"
c "$P/index.html" 'assets/js/report-hpp.js?v=' "report-hpp.js script tag (insert point, kept untouched)   [1]"
c "$P/index.html" 'assets/js/report-valuation.js?v=' "report-valuation.js script tag   [0 = package inserts it]"
c "$P/index.html" 'assets/js/app.js?v=' "app.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'id="tab-laporan-hpp"' "container of the page the route re-points   [1]"
c "$P/index.html" 'data-tab="laporan-hpp"' "sidebar link of laporan-hpp   [>=1]"
c "$P/index.html" '20261016-val' "new token already used   [0]"
echo "-- the ledger / FIFO engine the report replays (must already exist, unchanged)"
c "$SV/FifoService.php" "public static function postOut(" "FifoService::postOut   [1]"
c "$SV/InventoryHppReportService.php" "SIGNED_VALUE_SQL" "InventoryHppReportService::SIGNED_VALUE_SQL (same signed-value rule)   [>=1]"
ls -l "$SV/ExcelWriterService.php" "$SV/Exceptions.php" 2>&1 | sed 's/^/   /'
echo
echo "== script tags now =="
grep -n '<script src="assets/js/\(api-client\|report-hpp\|app\)[a-z0-9.-]*js?v=\|app.css?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
echo "      then (READ ONLY): php scripts/valuation_reconcile_check.php --app-root=<app dir> --start=<YYYY-MM-DD> --end=<YYYY-MM-DD>   (after the service file is installed, step 3.3 of the README)"
