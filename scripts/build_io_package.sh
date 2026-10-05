#!/usr/bin/env bash
# Builds the fail-closed Laporan IN / OUT / Transfer production package (NOT a deploy).
# Usage: [IO_REV=<commit>] [IO_BASE=<commit>] bash scripts/build_io_package.sh <output dir>   -> <out>/io_production_deploy_package.tar.gz
# IO_REV = the tested commit of the feature (service, page, css block, index.php helper + routes); IO_BASE = last commit BEFORE the feature (reference hashes in the collect script).
set -eu
cd "$(dirname "$0")/.."
IO_REV="${IO_REV:-d9f84fb}"
IO_BASE="${IO_BASE:-f59e816}"
OUT="${1:?usage: build_io_package.sh <output dir>}"
N=io_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$IO_REV:services/InOutReportService.php" > "$R/payload/InOutReportService.php"
git show "$IO_REV:public/assets/js/report-io.js" > "$R/payload/report-io.js"
git show "$IO_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$IO_REV:public/index.php" > "$D/new_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
m = css.index('/* Laporan IN / OUT / Transfer (report-io.js)')
w('io_app_css_block.css', css[m:])
h0 = new.index('/**\n * Laporan IN / OUT / Transfer: request parsing')
h1 = new.index('/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.')
assert h0 < h1
w('io_index_php_helper.txt', new[h0:h1])
r0 = new.index('    // LAPORAN IN / OUT / TRANSFER — READ-ONLY (GET)')
r1 = new.index("    // Report 12 — Distribusi per Bakery: qualifying OUT rows with a real\n")
assert r0 < r1
w('io_index_php_routes.txt', new[r0:r1])
PY
for f in patch_io_app_css_production.php patch_io_index_html_production.php patch_io_index_php_production.php patch_io_app_js_production.php install_io_files_production.php rollback_io_production.php io_readonly_check.php inout_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/io_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/app.js public/assets/js/report-inout.js public/assets/js/report-transfer.js; do
  REFS="$REFS   $(git show "$IO_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $IO_BASE)\n"
done
python3 - "$R/collect_production_hashes_io.sh" "scripts/io_package/collect_production_hashes_io.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_SVC@@/$(hp InOutReportService.php)/g; s/@@H_HELPER@@/$(hp io_index_php_helper.txt)/g; s/@@H_ROUTES@@/$(hp io_index_php_routes.txt)/g; s/@@H_JS@@/$(hp report-io.js)/g; s/@@H_BLK@@/$(hp io_app_css_block.css)/g" scripts/io_package/README_DEPLOY_io.md.tpl > "$R/README_DEPLOY_io.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $IO_REV, base $IO_BASE)"; sha256sum "$OUT/$N.tar.gz"
