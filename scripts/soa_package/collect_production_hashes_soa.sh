#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_soa.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_soa.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_soa.sh <public dir> <services dir>}"
echo "== SHA256 of the 5 existing files the package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$P/assets/js/stock-opname-report.js"
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== must be ABSENT before the package (the 1 NEW file) =="
ls -l "$SV/StockOpnameAuditReportService.php" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-soa-backup "$P"/assets/*/*.pre-soa-backup "$SV"/*.pre-soa-backup "$P"/*.soa-patch.json "$P"/assets/*/*.soa-patch.json "$SV"/*.soa-patch.json 2>/dev/null || echo "no Laporan Stock Opname leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/StockOpnameJejakService.php';" "require_once StockOpnameJejakService.php (insert anchor)   [1]"
c "$P/index.php" ' * PHASE V2.14.9.1 — same rule as inv_require_so_warehouse_scope(), but for' "docblock of inv_so_resolve_warehouse_scope (insert anchor)   [1]"
c "$P/index.php" "function inv_so_resolve_warehouse_scope(" "inv_so_resolve_warehouse_scope   [1]"
c "$P/index.php" "    'GET /reports/opname' => function () use (\$pdo, \$query) {" "GET /reports/opname route (insert anchor)   [1]"
c "$P/index.php" "'GET /reports/opname/{id}/jejak' =>" "GET /reports/opname/{id}/jejak (Jejak) kept   [1]"
c "$P/index.php" "StockOpnameAuditReportService" "already references StockOpnameAuditReportService   [0]"
c "$P/index.php" "inv_soa_filters" "inv_soa_filters already present   [0]"
c "$P/index.php" "'GET /reports/opname-audit/sessions'" "new sessions route already present   [0]"
c "$P/index.php" "'GET /reports/opname-audit/export'" "new export route already present   [0]"
echo "-- api-client.js"
c "$P/assets/js/api-client.js" '        stockOpnameReportPrintUrl: (id) => `/api/stock-opname-reports/${id}/print`,' "stockOpnameReportPrintUrl line (anchor)   [1]"
c "$P/assets/js/api-client.js" "opnameAuditSessions:" "opnameAuditSessions already present   [0]"
echo "-- app.css"
c "$P/assets/css/app.css" ".soa-" "'.soa-' selectors already present   [0]"
echo "-- stock-opname-report.js (REPLACED; hash gate) — if this file is ABSENT (count 0 / 'No such file') STOP and send me this output"
c "$P/assets/js/stock-opname-report.js" "const StockOpnameReport" "StockOpnameReport   [1]"
echo "-- index.html"
c "$P/index.html" "assets/js/stock-opname-report.js?v=" "stock-opname-report.js script tag   [1]"
c "$P/index.html" "assets/js/api-client.js?v=" "api-client.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'id="tab-opname-laporan"' "tab container for the Laporan Stock Opname page   [1]"
c "$P/index.html" '20261014-soa' "new token already used   [0]"
echo "-- the Jejak read model + book-stock engine the new service builds on (must already exist, unchanged)"
c "$SV/StockOpnameJejakService.php" "public static function detail(" "StockOpnameJejakService::detail   [1]"
c "$SV/StockOpnameBookStockService.php" "public static function reconciliation(" "StockOpnameBookStockService::reconciliation   [1]"
c "$SV/StockOpnamePhotoService.php" "public static function absolutePath(" "StockOpnamePhotoService::absolutePath   [1]"
ls -l "$SV/ExcelWriterService.php" "$SV/Exceptions.php" 2>&1 | sed 's/^/   /'
echo
echo "== cache-bust lines now =="
grep -n 'app.css?v=\|api-client.js?v=\|stock-opname-report[a-z.-]*js?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
echo "      then (READ ONLY): php scripts/opname_audit_reconcile_check.php --app-root=<app dir> --session=11,12   (after the service file is installed, step 3.3 of the README)"
