#!/usr/bin/env bash
# Builds the fail-closed Laporan Stock Opname audit-redesign production package (NOT a deploy).
# Usage: [SOA_REV=<commit>] [SOA_BASE=<commit>] bash scripts/build_soa_package.sh <output dir>   -> <out>/soa_production_deploy_package.tar.gz
# SOA_BASE = the last commit BEFORE the feature (source of the reference hashes in the collect script); SOA_REV = the tested feature commit.
set -eu
cd "$(dirname "$0")/.."
SOA_REV="${SOA_REV:-ad6e7ac}"
SOA_BASE="${SOA_BASE:-55fc7f8}"
OUT="${1:?usage: build_soa_package.sh <output dir>}"
N=soa_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$SOA_REV:services/StockOpnameAuditReportService.php" > "$R/payload/StockOpnameAuditReportService.php"
git show "$SOA_REV:public/assets/js/stock-opname-report.js" > "$R/payload/stock-opname-report.js"
git show "$SOA_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$SOA_REV:public/index.php" > "$D/new_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
m = css.index('/* Laporan Stock Opname audit redesign (stock-opname-report.js')
w('soa_app_css_block.css', css[m:])
h0 = new.index('/**\n * Laporan Stock Opname (audit redesign): request parsing')
h1 = new.index('/**\n * PHASE V2.14.9.1 — same rule as inv_require_so_warehouse_scope(), but for\n')
assert h0 < h1
w('soa_index_php_helper.txt', new[h0:h1])
r0 = new.index('    // LAPORAN STOCK OPNAME (audit redesign)')
r1 = new.index("    'GET /reports/opname' => function () use ($pdo, $query) {\n")
assert r0 < r1
w('soa_index_php_routes.txt', new[r0:r1])
PY
for f in patch_soa_app_css_production.php patch_soa_index_html_production.php patch_soa_index_php_production.php patch_soa_app_js_production.php install_soa_files_production.php rollback_soa_production.php soa_readonly_check.php opname_audit_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/soa_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/app.js public/assets/js/stock-opname-report.js; do
  REFS="$REFS   $(git show "$SOA_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $SOA_BASE)\n"
done
python3 - "$R/collect_production_hashes_soa.sh" "scripts/soa_package/collect_production_hashes_soa.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_SVC@@/$(hp StockOpnameAuditReportService.php)/g; s/@@H_HELPER@@/$(hp soa_index_php_helper.txt)/g; s/@@H_ROUTES@@/$(hp soa_index_php_routes.txt)/g; s/@@H_JS@@/$(hp stock-opname-report.js)/g; s/@@H_BLK@@/$(hp soa_app_css_block.css)/g" scripts/soa_package/README_DEPLOY_soa.md.tpl > "$R/README_DEPLOY_soa.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $SOA_REV, base $SOA_BASE)"; sha256sum "$OUT/$N.tar.gz"
