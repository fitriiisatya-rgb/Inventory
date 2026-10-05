#!/usr/bin/env bash
# Builds the fail-closed Laporan Nilai Stok & HPP (dual valuation FIFO + Average) production package (NOT a deploy).
# Usage: [VAL_REV=<commit>] [VAL_BASE=<commit>] bash scripts/build_val_package.sh <output dir>   -> <out>/val_production_deploy_package.tar.gz
# VAL_REV = the tested commit of the feature (service, page, css block, index.php helper + routes); VAL_BASE = last commit BEFORE the feature (reference hashes in the collect script).
set -eu
cd "$(dirname "$0")/.."
VAL_REV="${VAL_REV:-a6a2515}"
VAL_BASE="${VAL_BASE:-55fc7f8}"
OUT="${1:?usage: build_val_package.sh <output dir>}"
N=val_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$VAL_REV:services/InventoryValuationService.php" > "$R/payload/InventoryValuationService.php"
git show "$VAL_REV:public/assets/js/report-valuation.js" > "$R/payload/report-valuation.js"
git show "$VAL_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$VAL_REV:public/index.php" > "$D/new_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
m = css.index('/* Nilai Stok & HPP dual valuation (report-valuation.js')
w('val_app_css_block.css', css[m:])
h0 = new.index('/**\n * Laporan Nilai Stok & HPP (dual valuation FIFO + Average): request parsing')
h1 = new.index('/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.')
assert h0 < h1
w('val_index_php_helper.txt', new[h0:h1])
r0 = new.index('    // LAPORAN NILAI STOK & HPP (dual valuation FIFO + Average)')
r1 = new.index("    'GET /reports/inventory-hpp/summary' => function () use ($pdo, $query) {\n")
assert r0 < r1
w('val_index_php_routes.txt', new[r0:r1])
PY
for f in patch_val_app_css_production.php patch_val_index_html_production.php patch_val_index_php_production.php patch_val_app_js_production.php install_val_files_production.php rollback_val_production.php val_readonly_check.php valuation_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/val_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/app.js public/assets/js/report-hpp.js; do
  REFS="$REFS   $(git show "$VAL_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $VAL_BASE)\n"
done
python3 - "$R/collect_production_hashes_val.sh" "scripts/val_package/collect_production_hashes_val.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_SVC@@/$(hp InventoryValuationService.php)/g; s/@@H_HELPER@@/$(hp val_index_php_helper.txt)/g; s/@@H_ROUTES@@/$(hp val_index_php_routes.txt)/g; s/@@H_JS@@/$(hp report-valuation.js)/g; s/@@H_BLK@@/$(hp val_app_css_block.css)/g" scripts/val_package/README_DEPLOY_val.md.tpl > "$R/README_DEPLOY_val.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $VAL_REV, base $VAL_BASE)"; sha256sum "$OUT/$N.tar.gz"
