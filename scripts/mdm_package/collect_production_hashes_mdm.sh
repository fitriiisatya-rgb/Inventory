#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_mdm.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_mdm.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_mdm.sh <public dir> <services dir>}"
echo "== SHA256 of the 13 existing files the package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" \
  "$P/assets/js/master-common.js" "$P/assets/js/master-categories.js" "$P/assets/js/master-vendors.js" "$P/assets/js/master-bakery-destinations.js" \
  "$P/assets/js/master-items.js" "$P/assets/js/master-warehouses.js" "$P/assets/js/master-divisions.js" \
  "$SV/SupplierService.php" "$SV/BakeryDestinationService.php"
echo
echo "== reference hashes of the previously delivered repo versions (a match means that file is exactly what the repo had before this package) =="
cat <<REFS
@@REFS@@
REFS
echo
echo "== must be ABSENT before the package (the 1 NEW file) =="
ls -l "$SV/MasterRecordService.php" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-mdm-backup "$P"/assets/*/*.pre-mdm-backup "$SV"/*.pre-mdm-backup "$P"/*.mdm-patch.json "$P"/assets/*/*.mdm-patch.json "$SV"/*.mdm-patch.json 2>/dev/null || echo "no Master Data leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/MasterDataSafetyService.php';" "require_once MasterDataSafetyService.php   [1]"
c "$P/index.php" "'PUT /warehouses/{id}' => function (array \$params) use (\$pdo, \$input) {" "PUT /warehouses/{id} route (insert anchor)   [1]"
c "$P/index.php" "SELECT id FROM categories WHERE code = :c" "POST /categories duplicate-code check   [1]"
c "$P/index.php" "INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)" "POST /categories insert (old form)   [1]"
c "$P/index.php" "MasterRecordService" "already references MasterRecordService   [0]"
c "$P/index.php" "'POST /items'" "POST /items route already present   [0]"
c "$P/index.php" "'POST /warehouses'" "POST /warehouses route already present   [0]"
c "$P/index.php" "'POST /divisions'" "POST /divisions route already present   [0]"
c "$P/index.php" "'POST /suppliers' =>" "POST /suppliers kept   [1]"
c "$P/index.php" "'POST /bakery-destinations' =>" "POST /bakery-destinations kept   [1]"
echo "-- api-client.js"
c "$P/assets/js/api-client.js" "        createCategory: (payload) => request('POST', '/categories', payload)," "createCategory line (patch anchor)   [1]"
c "$P/assets/js/api-client.js" "createItem:" "createItem already present   [0]"
echo "-- app.css"
c "$P/assets/css/app.css" ".mdm-" "'.mdm-' selectors already present   [0]"
echo "-- index.html"
for f in api-client master-common master-categories master-vendors master-bakery-destinations master-items master-warehouses master-divisions; do c "$P/index.html" "assets/js/$f.js?v=" "$f.js script tag   [1]"; done
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" '20261012-mdm' "new token already used   [0]"
echo "-- the three master services' dependencies"
ls -l "$SV/Database.php" "$SV/Exceptions.php" "$SV/AuditService.php" "$SV/UnitConversionService.php" "$SV/MasterDataSafetyService.php" 2>&1 | sed 's/^/   /'
echo
echo "== cache-bust lines now =="
grep -n 'app.css?v=\|api-client.js?v=\|master-[a-z-]*.js?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
