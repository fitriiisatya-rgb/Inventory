#!/usr/bin/env bash
# Builds the fail-closed Laporan Stock Opname V3 production package (NOT a deploy).
# Usage: [SOA3_REV=<commit>] [SOA3_BASE=<commit>] bash scripts/build_soa3_package.sh <output dir>   -> <out>/soa3_production_deploy_package.tar.gz
# SOA3_REV  = the tested commit of the report (service, page, css block, index.php helper + routes); SOA3_BASE = last commit BEFORE the feature (reference hashes in the collect script).
# The css block is cut at the NEXT redesign block (later features append their own blocks after it).
set -eu
cd "$(dirname "$0")/.."
SOA3_REV="${SOA3_REV:-643a0dd}"
SOA3_BASE="${SOA3_BASE:-55fc7f8}"
OUT="${1:?usage: build_soa3_package.sh <output dir>}"
N=soa3_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$SOA3_REV:services/StockOpnameAuditReportService.php" > "$R/payload/StockOpnameAuditReportService.php"
git show "$SOA3_REV:public/assets/js/stock-opname-report.js" > "$R/payload/stock-opname-report.js"
git show "$SOA3_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$SOA3_REV:public/index.php" > "$D/new_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
m = css.index('/* Laporan Stock Opname audit redesign (stock-opname-report.js')
end = len(css)
for nxt in ('\n\n/* Laporan Pembelian redesign', '\n\n/* Nilai Stok & HPP dual valuation'):
    k = css.find(nxt, m)
    if k != -1:
        end = min(end, k)
block = css[m:end]
if not block.endswith('\n'):
    block += '\n'
w('soa_app_css_block.css', block)
h0 = new.index('/**\n * Laporan Stock Opname (audit redesign): request parsing')
h1 = new.index('/**\n * PHASE V2.14.9.1 — same rule as inv_require_so_warehouse_scope(), but for\n')
assert h0 < h1
w('soa_index_php_helper.txt', new[h0:h1])
r0 = new.index('    // LAPORAN STOCK OPNAME (audit redesign)')
r1 = new.index("    'GET /reports/opname' => function () use ($pdo, $query) {\n")
assert r0 < r1
w('soa_index_php_routes.txt', new[r0:r1])
PY
for f in patch_soa3_app_css_production.php patch_soa3_index_html_production.php patch_soa3_index_php_production.php patch_soa3_app_js_production.php install_soa3_files_production.php rollback_soa3_production.php soa3_readonly_check.php soa3_state_check.php opname_audit_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/soa3_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/app.js; do
  REFS="$REFS   $(git show "$SOA3_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $SOA3_BASE)\n"
done
python3 - "$R/collect_production_hashes_soa3.sh" "scripts/soa3_package/collect_production_hashes_soa3.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_SVC@@/$(hp StockOpnameAuditReportService.php)/g; s/@@H_HELPER@@/$(hp soa_index_php_helper.txt)/g; s/@@H_ROUTES@@/$(hp soa_index_php_routes.txt)/g; s/@@H_JS@@/$(hp stock-opname-report.js)/g; s/@@H_BLK@@/$(hp soa_app_css_block.css)/g" scripts/soa3_package/README_DEPLOY_soa3.md.tpl > "$R/README_DEPLOY_soa3.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $SOA3_REV, base $SOA3_BASE)"; sha256sum "$OUT/$N.tar.gz"
