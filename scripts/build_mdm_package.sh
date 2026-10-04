#!/usr/bin/env bash
# Builds the fail-closed Master Data "Tambah ..." production package (NOT a deploy).
# Usage: [MDM_REV=<commit>] [MDM_BASE=<commit>] bash scripts/build_mdm_package.sh <output dir>   -> <out>/mdm_production_deploy_package.tar.gz
# MDM_BASE = the last commit BEFORE the feature (source of the "old" category block and of the reference hashes in the collect script).
set -eu
cd "$(dirname "$0")/.."
MDM_REV="${MDM_REV:-HEAD}"
MDM_BASE="${MDM_BASE:-6775b7f}"
OUT="${1:?usage: build_mdm_package.sh <output dir>}"
N=mdm_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
JS="master-common master-categories master-vendors master-bakery-destinations master-items master-warehouses master-divisions"
git show "$MDM_REV:services/MasterRecordService.php" > "$R/payload/MasterRecordService.php"
git show "$MDM_REV:services/SupplierService.php" > "$R/payload/SupplierService.php"
git show "$MDM_REV:services/BakeryDestinationService.php" > "$R/payload/BakeryDestinationService.php"
for f in $JS; do git show "$MDM_REV:public/assets/js/$f.js" > "$R/payload/$f.js"; done
git show "$MDM_REV:public/assets/css/app.css" > "$D/ui_app.css"
git show "$MDM_REV:public/index.php" > "$D/new_index.php"
git show "$MDM_BASE:public/index.php" > "$D/old_index.php"
python3 - "$D/ui_app.css" "$D/new_index.php" "$D/old_index.php" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
new = open(sys.argv[2], encoding='utf-8').read()
old = open(sys.argv[3], encoding='utf-8').read()
out = sys.argv[4]
m = css.index('/* Master Data compact create modal (master-common.js')
open(out + '/mdm_app_css_block.css', 'w', encoding='utf-8', newline='').write(css[m:])
# routes: from the comment that introduces them up to (not including) the PUT /warehouses/{id} route
start = new.index('    // Master Data "Tambah ..." — the three creates')
end = new.index("    'PUT /warehouses/{id}' => function (array $params) use ($pdo, $input) {\n")
open(out + '/mdm_index_php_routes.txt', 'w', encoding='utf-8', newline='').write(new[start:end])
# category block: old (base) vs new (feature)
a0 = "        $existing = $pdo->prepare('SELECT id FROM categories WHERE code = :c');\n"
o0, o1 = old.index(a0), old.index("        $stmt->execute(['c' => $code, 'n' => $name]);\n")
n0, n1 = new.index(a0), new.index("        $stmt->execute(['c' => $code, 'n' => $name, 'a' => $isActive]);\n")
open(out + '/mdm_category_old.txt', 'w', encoding='utf-8', newline='').write(old[o0:o1 + len("        $stmt->execute(['c' => $code, 'n' => $name]);\n")])
open(out + '/mdm_category_new.txt', 'w', encoding='utf-8', newline='').write(new[n0:n1 + len("        $stmt->execute(['c' => $code, 'n' => $name, 'a' => $isActive]);\n")])
PY
for f in patch_mdm_app_css_production.php patch_mdm_index_html_production.php patch_mdm_index_php_production.php patch_mdm_api_client_production.php install_mdm_files_production.php rollback_mdm_production.php mdm_readonly_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/mdm_package/precheck_readonly.sql "$R/"
hp() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
# reference hashes of the previously delivered repo versions
REFS=""
for f in public/index.php public/index.html public/assets/css/app.css public/assets/js/api-client.js $(for j in $JS; do echo public/assets/js/$j.js; done) services/SupplierService.php services/BakeryDestinationService.php; do
  REFS="$REFS   $(git show "$MDM_BASE:$f" | sha256sum | cut -d' ' -f1)  $f (repo @ $MDM_BASE)\n"
done
python3 - "$R/collect_production_hashes_mdm.sh" "scripts/mdm_package/collect_production_hashes_mdm.sh" "$REFS" <<'PY'
import sys
t = open(sys.argv[2], encoding='utf-8').read().replace('@@REFS@@', sys.argv[3].replace('\\n', '\n').rstrip('\n'))
open(sys.argv[1], 'w', encoding='utf-8', newline='').write(t)
PY
sed "s/@@H_ROUTES@@/$(hp mdm_index_php_routes.txt)/g; s/@@H_CATOLD@@/$(hp mdm_category_old.txt)/g; s/@@H_CATNEW@@/$(hp mdm_category_new.txt)/g; s/@@H_BLK@@/$(hp mdm_app_css_block.css)/g; s/@@H_MRS@@/$(hp MasterRecordService.php)/g; s/@@H_SUP@@/$(hp SupplierService.php)/g; s/@@H_BAK@@/$(hp BakeryDestinationService.php)/g; s/@@H_COMMON@@/$(hp master-common.js)/g; s/@@H_CATJS@@/$(hp master-categories.js)/g; s/@@H_VENJS@@/$(hp master-vendors.js)/g; s/@@H_BAKJS@@/$(hp master-bakery-destinations.js)/g; s/@@H_ITEMJS@@/$(hp master-items.js)/g; s/@@H_WHJS@@/$(hp master-warehouses.js)/g; s/@@H_DIVJS@@/$(hp master-divisions.js)/g" scripts/mdm_package/README_DEPLOY_mdm.md.tpl > "$R/README_DEPLOY_mdm.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $MDM_REV, base $MDM_BASE)"; sha256sum "$OUT/$N.tar.gz"
