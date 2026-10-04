#!/usr/bin/env bash
# Builds the fail-closed Pergerakan Stok Harian redesign production package (NOT a deploy).
# Usage: [MVR_REV=<commit>] [MVR_BASE=<commit>] bash scripts/build_mvr_package.sh <output dir>   -> <out>/mvr_production_deploy_package.tar.gz
# MVR_BASE = the last commit BEFORE the feature (source of the reference hashes in the collect script); MVR_REV = the tested feature commit.
set -eu
cd "$(dirname "$0")/.."
MVR_REV="${MVR_REV:-9c278e1}"
MVR_BASE="${MVR_BASE:-a2dceb3}"
OUT="${1:?usage: build_mvr_package.sh <output dir>}"
N=mvr_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$MVR_REV:services/MovementDailyReportService.php" > "$R/payload/MovementDailyReportService.php"
git show "$MVR_REV:public/assets/js/report-movement.js" > "$R/payload/report-movement.js"
git show "$MVR_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$MVR_REV:public/index.php" > "$D/new_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
out = sys.argv[3]
def w(name, text):
    open(out + '/' + name, 'w', encoding='utf-8', newline='').write(text)
m = css.index('/* Pergerakan Stok Harian redesign (report-movement.js')
w('mvr_app_css_block.css', css[m:])
h0 = new.index('/**\n * Pergerakan Stok Harian (redesign): common query parsing.')
h1 = new.index('/**\n * Same "STOCK is always forced to their own warehouse, never a\n')
assert h0 < h1
w('mvr_index_php_helper.txt', new[h0:h1])
r0 = new.index('    // PERGERAKAN STOK HARIAN (redesign)')
r1 = new.index("    'GET /reports/reconciliation/movement' => function ($pdo, $query) {\n".replace('($pdo, $query)', '() use ($pdo, $query)'))
assert r0 < r1
w('mvr_index_php_routes.txt', new[r0:r1])
PY
for f in patch_mvr_app_css_production.php patch_mvr_index_html_production.php patch_mvr_index_php_production.php patch_mvr_api_client_production.php install_mvr_files_production.php rollback_mvr_production.php mvr_readonly_check.php movement_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/mvr_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/api-client.js public/assets/js/report-movement.js; do
  REFS="$REFS   $(git show "$MVR_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $MVR_BASE)\n"
done
python3 - "$R/collect_production_hashes_mvr.sh" "scripts/mvr_package/collect_production_hashes_mvr.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_SVC@@/$(hp MovementDailyReportService.php)/g; s/@@H_HELPER@@/$(hp mvr_index_php_helper.txt)/g; s/@@H_ROUTES@@/$(hp mvr_index_php_routes.txt)/g; s/@@H_JS@@/$(hp report-movement.js)/g; s/@@H_BLK@@/$(hp mvr_app_css_block.css)/g" scripts/mvr_package/README_DEPLOY_mvr.md.tpl > "$R/README_DEPLOY_mvr.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $MVR_REV, base $MVR_BASE)"; sha256sum "$OUT/$N.tar.gz"
