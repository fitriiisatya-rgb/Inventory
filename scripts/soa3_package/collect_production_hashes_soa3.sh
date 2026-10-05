#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_soa3.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_soa3.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_soa3.sh <public dir> <services dir>}"
echo "== SHA256 of the 4 existing files the package changes (these are the --expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/app.js"
echo
echo "== stock-opname-report.js: expected ABSENT in production (the package then CREATES it). If it exists, its hash is the --replace-expect-sha256 value =="
ls -l "$P/assets/js/stock-opname-report.js" 2>&1 | sed 's/^/   /'
[ -f "$P/assets/js/stock-opname-report.js" ] && sha256sum "$P/assets/js/stock-opname-report.js"
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== service file: ABSENT (clean) or the exact SOA V2 file (partial V2 backend) — the state check below decides =="
ls -l "$SV/StockOpnameAuditReportService.php" 2>&1 | sed 's/^/   /'
[ -f "$SV/StockOpnameAuditReportService.php" ] && sha256sum "$SV/StockOpnameAuditReportService.php"
echo "   SOA V2 state files (informational, never touched by V3):"
ls "$SV"/*.soa-patch.json "$P"/index.php.soa-patch.json 2>/dev/null | sed 's/^/   /' || true
ls "$P"/*.pre-soa3-backup "$P"/assets/*/*.pre-soa3-backup "$SV"/*.pre-soa3-backup "$P"/*.soa3-patch.json "$P"/assets/*/*.soa3-patch.json "$SV"/*.soa3-patch.json 2>/dev/null || echo "no Laporan Stock Opname leftovers (good)"
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
echo "-- app.js"
c "$P/assets/js/app.js" "ReportOpname.render(document.getElementById('tab-laporan-opname'));" "ReportOpname route of tab-laporan-opname (the ONE line the package re-points)   [1]"
c "$P/assets/js/app.js" "StockOpnameReport.render(document.getElementById('tab-laporan-opname'))" "already re-pointed   [0]"
c "$P/assets/js/app.js" "name === 'opname-laporan'" "route of the 'opname-laporan' tab (needed only by the new sidebar link of the sidebar-cleanup package)   [1 or 0 — report it]"
echo "-- app.css"
c "$P/assets/css/app.css" ".soa-" "'.soa-' selectors already present   [0]"
echo "-- index.html (script / link tags)"
c "$P/index.html" 'assets/js/stock-opname-report-jejak.js?v=' "Jejak drawer script tag (insert point, kept untouched)   [1]"
c "$P/index.html" 'assets/js/stock-opname-report.js?v=' "stock-opname-report.js script tag   [0 = package inserts it, 1 = only its token moves]"
c "$P/index.html" 'assets/js/app.js?v=' "app.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'id="tab-laporan-opname"' "container of the page the route re-points   [1]"
c "$P/index.html" 'id="tab-opname-laporan"' "container of the 'opname-laporan' tab   [1 or 0 — report it]"
c "$P/index.html" 'data-tab="laporan-opname"' "sidebar link of laporan-opname   [>=1]"
c "$P/index.html" '20261017-soa3' "V3 token already used   [0]"
c "$P/index.html" '20261014-soa' "V2 frontend token present (V2 frontend must NOT be applied)   [0]"
echo "-- the Jejak read model + book-stock engine the new service builds on (must already exist, unchanged)"
c "$SV/StockOpnameJejakService.php" "public static function detail(" "StockOpnameJejakService::detail   [1]"
c "$SV/StockOpnameBookStockService.php" "public static function reconciliation(" "StockOpnameBookStockService::reconciliation   [1]"
c "$SV/StockOpnamePhotoService.php" "public static function absolutePath(" "StockOpnamePhotoService::absolutePath   [1]"
ls -l "$SV/ExcelWriterService.php" "$SV/Exceptions.php" 2>&1 | sed 's/^/   /'
echo
echo "== script tags now (order matters for the audit below) =="
grep -n '<script src="assets/js/\(api-client\|stock-opname\|report-opname\|app\)[a-z0-9.-]*js?v=\|app.css?v=' "$P/index.html"
echo
echo "== AUDIT: which api-client file defines InvApi? (the new page does NOT use InvApi — this is informational for the other reports) =="
for f in "$P"/assets/js/api-client*.js; do
  [ -f "$f" ] || continue
  printf '   %s  sha256=%s\n' "$(basename "$f")" "$(sha256sum "$f" | cut -d' ' -f1)"
  printf '      InvApi declarations: '; grep -cE '^(const|var|let) InvApi|window\.InvApi *=' "$f"
  printf '      markers: listUnits=%s dashboardOverview=%s movementOverview=%s purchaseV2Overview=%s stockOpnameReportPrintUrl=%s opnameExportFinalUrl=%s\n' \
    "$(grep -c 'listUnits' "$f")" "$(grep -c 'dashboardOverview\|inventoryDashboard' "$f")" "$(grep -c 'movementOverview\|movementDaily' "$f")" "$(grep -c 'purchaseV2Overview' "$f")" "$(grep -c 'stockOpnameReportPrintUrl' "$f")" "$(grep -c 'opnameExportFinalUrl' "$f")"
done
echo "   In the browser console on any report page run:  typeof InvApi.movementOverview   and   typeof InvApi.listUnits   — send both answers."
echo
echo "== STATE CHECK (read-only) — which path applies =="
HERE="$(cd "$(dirname "$0")" && pwd)"
php "$HERE/scripts/soa3_state_check.php" --public-dir="$P" --services-dir="$SV" --payload-dir="$HERE/payload"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
echo "      then (READ ONLY): php scripts/opname_audit_reconcile_check.php --app-root=<app dir> --session=11,12   (after the service file is installed, step 3.3 of the README)"
