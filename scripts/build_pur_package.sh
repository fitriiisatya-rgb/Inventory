#!/usr/bin/env bash
# Builds the fail-closed Laporan Pembelian redesign production package (NOT a deploy).
# Usage: [PUR_REV=<commit>] [PUR_BASE=<commit>] bash scripts/build_pur_package.sh <output dir>   -> <out>/pur_production_deploy_package.tar.gz
# PUR_BASE = the last commit BEFORE the feature (source of the reference hashes in the collect script); PUR_REV = the tested feature commit.
set -eu
cd "$(dirname "$0")/.."
PUR_REV="${PUR_REV:-af5ead4}"
PUR_BASE="${PUR_BASE:-ffdfcaa}"
OUT="${1:?usage: build_pur_package.sh <output dir>}"
N=pur_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$PUR_REV:services/PurchaseReportService.php" > "$R/payload/PurchaseReportService.php"
git show "$PUR_REV:public/assets/js/report-purchase.js" > "$R/payload/report-purchase.js"
git show "$PUR_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$PUR_REV:public/index.php" > "$D/new_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
m = css.index('/* Laporan Pembelian redesign (report-purchase.js')
w('pur_app_css_block.css', css[m:])
h0 = new.index('/**\n * Laporan Pembelian (redesign): request parsing')
h1 = new.index('/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.')
assert h0 < h1
w('pur_index_php_helper.txt', new[h0:h1])
r0 = new.index('    // LAPORAN PEMBELIAN (redesign)')
r1 = new.index("    'GET /reports/purchase' => function () use ($pdo, $query) {\n")
assert r0 < r1
w('pur_index_php_routes.txt', new[r0:r1])
PY
for f in patch_pur_app_css_production.php patch_pur_index_html_production.php patch_pur_index_php_production.php patch_pur_api_client_production.php install_pur_files_production.php rollback_pur_production.php pur_readonly_check.php purchase_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/pur_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/api-client.js public/assets/js/report-purchase.js; do
  REFS="$REFS   $(git show "$PUR_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $PUR_BASE)\n"
done
python3 - "$R/collect_production_hashes_pur.sh" "scripts/pur_package/collect_production_hashes_pur.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_SVC@@/$(hp PurchaseReportService.php)/g; s/@@H_HELPER@@/$(hp pur_index_php_helper.txt)/g; s/@@H_ROUTES@@/$(hp pur_index_php_routes.txt)/g; s/@@H_JS@@/$(hp report-purchase.js)/g; s/@@H_BLK@@/$(hp pur_app_css_block.css)/g" scripts/pur_package/README_DEPLOY_pur.md.tpl > "$R/README_DEPLOY_pur.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $PUR_REV, base $PUR_BASE)"; sha256sum "$OUT/$N.tar.gz"
