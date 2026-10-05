#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_pur.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_pur.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_pur.sh <public dir> <services dir>}"
echo "== SHA256 of the 5 existing files the package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$P/assets/js/report-purchase.js"
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== must be ABSENT before the package (the 1 NEW file) =="
ls -l "$SV/PurchaseReportService.php" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-pur-backup "$P"/assets/*/*.pre-pur-backup "$SV"/*.pre-pur-backup "$P"/*.pur-patch.json "$P"/assets/*/*.pur-patch.json "$SV"/*.pur-patch.json 2>/dev/null || echo "no Laporan Pembelian leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/PurchaseCostingGateway.php';" "require_once PurchaseCostingGateway.php (insert anchor)   [1]"
c "$P/index.php" ' * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.' "docblock 'PHASE V2.6C interactive-period guard' (insert anchor)   [1]"
c "$P/index.php" "    'GET /reports/purchase' => function () use (\$pdo, \$query) {" "GET /reports/purchase route (insert anchor)   [1]"
c "$P/index.php" "function inv_hpp_resolve_warehouse_scope(" "inv_hpp_resolve_warehouse_scope (used by the new helper)   [1]"
c "$P/index.php" "function inv_require_warehouse_scope(" "inv_require_warehouse_scope (used by invoice-detail)   [1]"
c "$P/index.php" "function inv_export_csv(" "inv_export_csv (used by the CSV export)   [1]"
c "$P/index.php" "services/ExcelWriterService.php" "ExcelWriterService required (used by the Excel export)   [1]"
c "$P/index.php" "PurchaseReportService" "already references PurchaseReportService   [0]"
c "$P/index.php" "inv_pur_filters" "inv_pur_filters already present   [0]"
c "$P/index.php" "'GET /reports/purchase-v2/overview'" "new overview route already present   [0]"
c "$P/index.php" "'GET /reports/purchase-v2/export'" "new export route already present   [0]"
echo "-- api-client.js"
c "$P/assets/js/api-client.js" '        purchaseBySupplierExportUrl: (params) => `/api/reports/purchase/by-supplier${qs(Object.assign({}, params, { format: '"'"'csv'"'"' }))}`,' "purchaseBySupplierExportUrl line (anchor)   [1]"
c "$P/assets/js/api-client.js" "purchaseV2Overview:" "purchaseV2Overview already present   [0]"
echo "-- app.css"
c "$P/assets/css/app.css" ".pur-" "'.pur-' selectors already present   [0]"
echo "-- report-purchase.js (REPLACED; hash gate) — if this file is ABSENT (count 0 / 'No such file') STOP and send me this output"
ls -l "$P/assets/js/report-purchase.js" 2>&1 | sed 's/^/   /'
c "$P/assets/js/report-purchase.js" "ReportPurchase" "ReportPurchase   [>=1]"
echo "-- index.html"
c "$P/index.html" "assets/js/report-purchase.js?v=" "report-purchase.js script tag   [1]"
c "$P/index.html" "assets/js/api-client.js?v=" "api-client.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'id="tab-laporan-pembelian"' "tab container for the Laporan Pembelian page   [1]"
c "$P/index.html" '20261015-pur' "new token already used   [0]"
echo "-- services the new service builds on (must already exist)"
c "$SV/PurchaseCostingService.php" "allocateProportionally" "PurchaseCostingService::allocateProportionally   [>=1]"
ls -l "$SV/ExcelWriterService.php" "$SV/Exceptions.php" "$SV/PurchaseInvoiceService.php" 2>&1 | sed 's/^/   /'
echo
echo "== cache-bust lines now =="
grep -n 'app.css?v=\|api-client.js?v=\|report-purchase[a-z.-]*js?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
echo "      then (READ ONLY): php scripts/purchase_reconcile_check.php --app-root=<app dir> --start=<YYYY-MM-DD> --end=<YYYY-MM-DD>   (after the service file is installed, step 3.3 of the README)"
