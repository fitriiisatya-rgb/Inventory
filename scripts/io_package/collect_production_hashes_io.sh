#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_io.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_io.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_io.sh <public dir> <services dir>}"
echo "== SHA256 of the 4 existing files the package changes (the --expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/app.js"
echo
echo "== the old report pages (kept, never modified) =="
sha256sum "$P/assets/js/report-inout.js" "$P/assets/js/report-transfer.js" 2>&1 | sed 's/^/   /'
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== must be ABSENT before the package (the 2 NEW files) =="
ls -l "$SV/InOutReportService.php" "$P/assets/js/report-io.js" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-io-backup "$P"/assets/*/*.pre-io-backup "$SV"/*.pre-io-backup "$P"/*.io-patch.json "$P"/assets/*/*.io-patch.json "$SV"/*.io-patch.json 2>/dev/null || echo "no Laporan IN / OUT / Transfer leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/TransferReportService.php';" "require_once TransferReportService.php (insert anchor)   [1]"
c "$P/index.php" ' * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.' "docblock 'PHASE V2.6C interactive-period guard' (insert anchor)   [1]"
c "$P/index.php" '    // Report 12 — Distribusi per Bakery: qualifying OUT rows with a real' "comment 'Report 12 - Distribusi per Bakery' (route insert anchor)   [1]"
c "$P/index.php" "function inv_hpp_resolve_warehouse_scope(" "inv_hpp_resolve_warehouse_scope (used by the new helper)   [1]"
c "$P/index.php" "services/ExcelWriterService.php" "ExcelWriterService required (used by the Excel export)   [1]"
c "$P/index.php" "InOutReportService" "already references InOutReportService   [0]"
c "$P/index.php" "inv_io_filters" "inv_io_filters already present   [0]"
c "$P/index.php" "'GET /reports/io/" "new /reports/io routes already present   [0]"
echo "-- app.js"
c "$P/assets/js/app.js" "ReportInOut.render(document.getElementById('tab-laporan-inout'));" "ReportInOut route of tab-laporan-inout (re-pointed by the package)   [1]"
c "$P/assets/js/app.js" "ReportTransferList.render(document.getElementById('tab-laporan-transfer'));" "ReportTransferList route of tab-laporan-transfer (re-pointed by the package)   [1]"
c "$P/assets/js/app.js" "ReportIO.render(" "already re-pointed   [0]"
echo "-- app.css"
c "$P/assets/css/app.css" ".io-" "'.io-' selectors already present   [0]"
echo "-- index.html (script / link tags)"
c "$P/index.html" 'assets/js/report-inout.js?v=' "report-inout.js script tag (insert point, kept untouched)   [1]"
c "$P/index.html" 'assets/js/report-io.js?v=' "report-io.js script tag   [0 = package inserts it]"
c "$P/index.html" 'assets/js/app.js?v=' "app.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'id="tab-laporan-inout"' "container of the IN / OUT page   [1]"
c "$P/index.html" 'id="tab-laporan-transfer"' "container of the Transfer page   [1]"
c "$P/index.html" '20261017-io' "new token already used   [0]"
echo "-- the ledger / FIFO engine the report replays (must already exist, unchanged)"
c "$SV/FifoService.php" "public static function postOut(" "FifoService::postOut   [1]"
c "$SV/TransferReportService.php" "final class TransferReportService" "TransferReportService (old transfer report, kept)   [1]"
ls -l "$SV/ExcelWriterService.php" "$SV/Exceptions.php" "$SV/StockOutService.php" "$SV/PurchaseInvoiceService.php" "$SV/DistributionOrderService.php" "$SV/TransferService.php" 2>&1 | sed 's/^/   /'
echo
echo "== script tags now =="
grep -n '<script src="assets/js/\(api-client\|report-inout\|report-transfer\|app\)[a-z0-9.-]*js?v=\|app.css?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
echo "      then (READ ONLY): php scripts/inout_reconcile_check.php --app-root=<app dir> --start=<YYYY-MM-DD> --end=<YYYY-MM-DD>   (after the service file is installed, step 3.3 of the README)"
