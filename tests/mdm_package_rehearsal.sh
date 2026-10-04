#!/usr/bin/env bash
# Rehearses the Master Data "Tambah ..." package against PRODUCTION-LIKE trees (never the live dev tree):
#   tree D = pre-Jejak files + Jejak v2 + v3 + Dashboard package
#   tree E = tree D + the Stock IN/OUT V2 CSS block + tags + the UI2 css/tags (every earlier CSS-touching package applied first)
# Proves: dry-run writes nothing; preimage / payload / routes / category-block hash, exact-once anchors, double-apply gates;
# backend-first apply; only the intended bytes change (index.php: 1 require + 3 routes + 1 replaced block; api-client.js: +3 lines;
# css: block appended; index.html: 9 tags); byte-equality with the tested dev files; post-apply file check; two-phase rollback.
# Usage: [MDM_REV=<commit>] bash tests/mdm_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
BASE_REV="${BASE_REV:-853cd69}"; V2_REV="${V2_REV:-e9072ec}"; V3_REV="${V3_REV:-9dcb367}"; DASH_REV="${DASH_REV:-f4a94e8}"; TX_REV="${TX_REV:-5cdf4f8}"; MDM_REV="${MDM_REV:-HEAD}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})
JSF="master-common master-categories master-vendors master-bakery-destinations master-items master-warehouses master-divisions"

# ---- the package exactly as built
MDM_REV="$MDM_REV" bash scripts/build_mdm_package.sh "$W/out" >/dev/null 2>&1 || { echo "FAIL - package build"; exit 1; }
tar -C "$W" -xzf "$W/out/mdm_production_deploy_package.tar.gz"
PK="$W/mdm_production_deploy_package"; PAY="$PK/payload"; S="$PK/scripts"
( cd "$PK" && sha256sum -c SHA256SUMS >/dev/null 2>&1 ) && ok "package SHA256SUMS verify" || bad "package SHA256SUMS"
DEV="$W/dev"; mkdir -p "$DEV"
for f in services/MasterRecordService.php services/SupplierService.php services/BakeryDestinationService.php public/index.php public/index.html public/assets/css/app.css public/assets/js/api-client.js $(for j in $JSF; do echo public/assets/js/$j.js; done); do git show "$MDM_REV:$f" > "$DEV/$(basename "$f")"; done
allsame=1; for f in MasterRecordService.php SupplierService.php BakeryDestinationService.php $(for j in $JSF; do echo $j.js; done); do cmp -s "$PAY/$f" "$DEV/$f" || allsame=0; done
[ $allsame = 1 ] && ok "all 10 payload files byte-identical to the tested dev files" || bad "payload != dev"
tail -c "$(wc -c < "$PAY/mdm_app_css_block.css")" "$DEV/app.css" | cmp -s - "$PAY/mdm_app_css_block.css" && [ "$(grep -c '^/\* Master Data compact create modal' "$PAY/mdm_app_css_block.css")" = 1 ] && ok "css block == dev app.css tail (single marker, ends at EOF)" || bad "css block"
H_RT=$(sha "$PAY/mdm_index_php_routes.txt"); H_CO=$(sha "$PAY/mdm_category_old.txt"); H_CN=$(sha "$PAY/mdm_category_new.txt"); H_BLK=$(sha "$PAY/mdm_app_css_block.css")
grep -q "'POST /items' =>" "$PAY/mdm_index_php_routes.txt" && grep -q "'POST /warehouses' =>" "$PAY/mdm_index_php_routes.txt" && grep -q "'POST /divisions' =>" "$PAY/mdm_index_php_routes.txt" && ! grep -q "'PUT /warehouses" "$PAY/mdm_index_php_routes.txt" && ok "routes payload = the three routes only" || bad "routes payload"
git show "$BASE_REV:public/index.php" | grep -c 'SELECT id FROM categories WHERE code = :c' | grep -qx 1 && ok "audited category block exists once in the production-era index.php" || bad "category anchor in base"

# ---- earlier packages as delivered
V2="$W/v2pkg"; mkdir -p "$V2/scripts/lib" "$V2/payload"
for f in lib/jejak_patch_common.php patch_report_opname_jejak_row_click_production.php patch_app_css_jejak_drawer_xl_production.php patch_index_html_jejak_script_tag_production.php install_stock_opname_report_jejak_production.php rollback_jejak_production.php; do git show "$V2_REV:scripts/$f" > "$V2/scripts/$f"; done
git show "$V2_REV:public/assets/js/stock-opname-report-jejak.js" > "$V2/payload/stock-opname-report-jejak.js"
V3="$W/v3pkg"; mkdir -p "$V3/scripts/lib" "$V3/payload"
for f in lib/jejak_patch_common.php patch_jejak_v3_app_css_production.php patch_jejak_v3_index_html_production.php patch_jejak_v3_index_php_route_production.php install_jejak_v3_files_production.php rollback_jejak_v3_production.php; do git show "$V3_REV:scripts/$f" > "$V3/scripts/$f"; done
git show "$V3_REV:public/assets/js/stock-opname-report-jejak.js" > "$V3/payload/stock-opname-report-jejak.js"
git show "$V3_REV:services/StockOpnameJejakService.php" > "$V3/payload/StockOpnameJejakService.php"
DP="$W/dashpkg"; mkdir -p "$DP/payload"
git show "$DASH_REV:public/assets/js/dashboard.js" > "$DP/payload/dashboard.js"; git show "$DASH_REV:services/DashboardInventoryService.php" > "$DP/payload/DashboardInventoryService.php"
git show "$DASH_REV:public/assets/css/app.css" | awk '/^\/\* Dashboard redesign \(dashboard.js\)/{f=1} f' > "$DP/payload/dashboard_app_css_block.css"

mk_tree() {
  local T="$1" full="$2"; P="$T/public"; SV="$T/services"
  mkdir -p "$P/assets/js" "$P/assets/css" "$SV"
  for f in report-opname.js dashboard.js transactions.js transaction-history.js; do git show "$BASE_REV:public/assets/js/$f" > "$P/assets/js/$f"; done
  git show "$BASE_REV:public/assets/css/app.css" > "$P/assets/css/app.css"
  git show "$BASE_REV:public/index.html" | grep -v 'stock-opname-report\.js' > "$P/index.html"
  git show "$V2_REV:public/index.php" > "$P/index.php"
  printf '<?php // placeholder service dir\n' > "$SV/Database.php"
  php "$V2/scripts/patch_app_css_jejak_drawer_xl_production.php" "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply >/dev/null || bad "v2 css"
  php "$V2/scripts/patch_report_opname_jejak_row_click_production.php" "$P/assets/js/report-opname.js" --expect-sha256=$(sha "$P/assets/js/report-opname.js") --apply >/dev/null || bad "v2 js"
  php "$V2/scripts/patch_index_html_jejak_script_tag_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "v2 index"
  php "$V2/scripts/install_stock_opname_report_jejak_production.php" "$V2/payload/stock-opname-report-jejak.js" "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$(sha "$V2/payload/stock-opname-report-jejak.js") --apply >/dev/null || bad "v2 install"
  if [ "$full" = 1 ]; then
    local S="$V3/scripts" HJ; HJ=$(sha "$P/assets/js/stock-opname-report-jejak.js")
    php "$S/install_jejak_v3_files_production.php" "$V3/payload/StockOpnameJejakService.php" "$SV/StockOpnameJejakService.php" --expect-payload-sha256=$(sha "$V3/payload/StockOpnameJejakService.php") --apply >/dev/null || bad "v3 service"
    php "$S/patch_jejak_v3_index_php_route_production.php" "$P/index.php" --expect-sha256=$(sha "$P/index.php") --apply >/dev/null || bad "v3 php"
    php "$S/install_jejak_v3_files_production.php" "$V3/payload/stock-opname-report-jejak.js" "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$(sha "$V3/payload/stock-opname-report-jejak.js") --replace-expect-sha256=$HJ --apply >/dev/null || bad "v3 js"
    php "$S/patch_jejak_v3_app_css_production.php" "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply >/dev/null || bad "v3 css"
    php "$S/patch_jejak_v3_index_html_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "v3 index"
    # dashboard package
    php scripts/install_dashboard_files_production.php "$DP/payload/DashboardInventoryService.php" "$SV/DashboardInventoryService.php" --expect-payload-sha256=$(sha "$DP/payload/DashboardInventoryService.php") --apply >/dev/null || bad "dash service"
    php scripts/patch_dashboard_index_php_production.php "$P/index.php" --expect-sha256=$(sha "$P/index.php") --apply >/dev/null || bad "dash php"
    php scripts/install_dashboard_files_production.php "$DP/payload/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$(sha "$DP/payload/dashboard.js") --replace-expect-sha256=$(sha "$P/assets/js/dashboard.js") --apply >/dev/null || bad "dash js"
    php scripts/patch_dashboard_app_css_production.php "$P/assets/css/app.css" "$DP/payload/dashboard_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$(sha "$DP/payload/dashboard_app_css_block.css") --apply >/dev/null || bad "dash css"
    php scripts/patch_dashboard_index_html_production.php "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "dash index"
  fi
}



IDX_PHP=$S/patch_mdm_index_php_production.php; API=$S/patch_mdm_api_client_production.php; CSS=$S/patch_mdm_app_css_production.php; HTML=$S/patch_mdm_index_html_production.php; INST=$S/install_mdm_files_production.php; RB=$S/rollback_mdm_production.php; CHK=$S/mdm_readonly_check.php
php_args() { echo "$1 $PAY/mdm_index_php_routes.txt $PAY/mdm_category_old.txt $PAY/mdm_category_new.txt --expect-routes-sha256=$H_RT --expect-catold-sha256=$H_CO --expect-catnew-sha256=$H_CN"; }

# ---- tx2 + ui2 css blocks to pre-apply in tree E (they must not interfere)
bash scripts/build_ui2_package.sh "$W/ui2out" >/dev/null 2>&1 || { bad "ui2 package build"; }
tar -C "$W" -xzf "$W/ui2out/ui2_production_deploy_package.tar.gz" 2>/dev/null; U2="$W/ui2_production_deploy_package"
git show "$TX_REV:public/assets/css/app.css" | awk '/^\/\* Stock IN \/ OUT V2 \(transactions.js/{f=1} f' > "$W/tx2.css"

run_tree() {
  local label="$1" withall="$2" T="$W/tree_$1"
  echo; echo "################ tree $label (Stock IN/OUT V2 + UI2 css/tags applied first = $withall) ################"
  mk_tree "$T" 1
  # master files + api-client + the two services exactly as production had them before this package (production-era revision)
  for f in $JSF; do git show "$BASE_REV:public/assets/js/$f.js" > "$P/assets/js/$f.js"; done
  git show "$BASE_REV:public/assets/js/api-client.js" > "$P/assets/js/api-client.js"
  git show "$BASE_REV:services/SupplierService.php" > "$SV/SupplierService.php"; git show "$BASE_REV:services/BakeryDestinationService.php" > "$SV/BakeryDestinationService.php"
  git show "$BASE_REV:services/MasterDataSafetyService.php" > "$SV/MasterDataSafetyService.php" 2>/dev/null || printf '<?php // placeholder\n' > "$SV/MasterDataSafetyService.php"
  if [ "$withall" = 1 ]; then
    php scripts/patch_tx2_app_css_production.php "$P/assets/css/app.css" "$W/tx2.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$(sha "$W/tx2.css") --apply >/dev/null || bad "[$label] tx2 css"
    php scripts/patch_tx2_index_html_production.php "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "[$label] tx2 index"
    php $U2/scripts/patch_ui2_app_css_production.php "$P/assets/css/app.css" $U2/payload/ui2_old_dashboard_block.css $U2/payload/ui2_new_dashboard_block.css $U2/payload/ui2_sidebar_block.css --expect-sha256=$(sha "$P/assets/css/app.css") --expect-old-sha256=$(sha "$U2/payload/ui2_old_dashboard_block.css") --expect-new-sha256=$(sha "$U2/payload/ui2_new_dashboard_block.css") --expect-sidebar-sha256=$(sha "$U2/payload/ui2_sidebar_block.css") --apply >/dev/null || bad "[$label] ui2 css"
    php $U2/scripts/patch_ui2_index_html_production.php "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "[$label] ui2 index"
  fi
  grep -q "'PUT /warehouses/{id}'" "$P/index.php" && grep -q "services/MasterDataSafetyService.php" "$P/index.php" || bad "[$label] production-era index.php lacks the audited anchors"
  declare -A H0
  for k in index.php index.html css api; do :; done
  local H_PHP H_HTML H_CSS H_API H_SUP H_BKS; declare -A HJ
  H_PHP=$(sha "$P/index.php"); H_HTML=$(sha "$P/index.html"); H_CSS=$(sha "$P/assets/css/app.css"); H_API=$(sha "$P/assets/js/api-client.js"); H_SUP=$(sha "$SV/SupplierService.php"); H_BKS=$(sha "$SV/BakeryDestinationService.php")
  for f in $JSF; do HJ[$f]=$(sha "$P/assets/js/$f.js"); done
  cp "$P/index.php" "$T/pre_index.php"; cp "$P/index.html" "$T/pre_index.html"; cp "$P/assets/css/app.css" "$T/pre_app.css"; cp "$P/assets/js/api-client.js" "$T/pre_api.js"
  ok "[$label] production-like tree built"
  inst() { php $INST "$PAY/$1" "$2" --expect-payload-sha256=$3 "${@:4}"; }

  # ---- dry runs write nothing
  inst MasterRecordService.php "$SV/MasterRecordService.php" $(sha "$PAY/MasterRecordService.php") >/dev/null && inst SupplierService.php "$SV/SupplierService.php" $(sha "$PAY/SupplierService.php") --replace-expect-sha256=$H_SUP >/dev/null && inst BakeryDestinationService.php "$SV/BakeryDestinationService.php" $(sha "$PAY/BakeryDestinationService.php") --replace-expect-sha256=$H_BKS >/dev/null && ok "[$label] dry-run: 1 new + 2 replaced services" || bad "[$label] dry-run services"
  local jsok=1; for f in $JSF; do inst $f.js "$P/assets/js/$f.js" $(sha "$PAY/$f.js") --replace-expect-sha256=${HJ[$f]} >/dev/null || jsok=0; done; [ $jsok = 1 ] && ok "[$label] dry-run: 7 JS replacements" || bad "[$label] dry-run JS"
  php $IDX_PHP $(php_args "$P/index.php") --expect-sha256=$H_PHP >/dev/null && php $API "$P/assets/js/api-client.js" --expect-sha256=$H_API >/dev/null && php $CSS "$P/assets/css/app.css" "$PAY/mdm_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$H_BLK >/dev/null && php $HTML "$P/index.html" --expect-sha256=$H_HTML >/dev/null && ok "[$label] dry-run: index.php, api-client.js, app.css, index.html" || bad "[$label] dry-run patchers"
  [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_HTML" ] && [ "$(sha "$P/assets/js/api-client.js")" = "$H_API" ] && [ ! -e "$SV/MasterRecordService.php" ] && ! ls "$P"/*.pre-mdm-backup "$P"/assets/*/*.pre-mdm-backup "$SV"/*.pre-mdm-backup >/dev/null 2>&1 && ok "[$label] dry runs wrote nothing" || bad "[$label] dry runs wrote something"
  expect_fail "[$label] rollback before apply refused" php $RB --public-dir="$P" --services-dir="$SV"

  # ---- fail-closed gates
  expect_fail "[$label] wrong preimage hash refused (index.php)" php $IDX_PHP $(php_args "$P/index.php") --expect-sha256=$ZERO
  expect_fail "[$label] wrong routes payload hash refused" php $IDX_PHP "$P/index.php" "$PAY/mdm_index_php_routes.txt" "$PAY/mdm_category_old.txt" "$PAY/mdm_category_new.txt" --expect-sha256=$H_PHP --expect-routes-sha256=$ZERO --expect-catold-sha256=$H_CO --expect-catnew-sha256=$H_CN
  expect_fail "[$label] wrong category-block payload hash refused" php $IDX_PHP "$P/index.php" "$PAY/mdm_index_php_routes.txt" "$PAY/mdm_category_old.txt" "$PAY/mdm_category_new.txt" --expect-sha256=$H_PHP --expect-routes-sha256=$H_RT --expect-catold-sha256=$ZERO --expect-catnew-sha256=$H_CN
  expect_fail "[$label] wrong preimage hash refused (api-client.js)" php $API "$P/assets/js/api-client.js" --expect-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (app.css)" php $CSS "$P/assets/css/app.css" "$PAY/mdm_app_css_block.css" --expect-sha256=$ZERO --expect-block-sha256=$H_BLK
  expect_fail "[$label] wrong CSS block hash refused" php $CSS "$P/assets/css/app.css" "$PAY/mdm_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (index.html)" php $HTML "$P/index.html" --expect-sha256=$ZERO
  expect_fail "[$label] replace refused when the target hash is wrong (master-items.js)" php $INST "$PAY/master-items.js" "$P/assets/js/master-items.js" --expect-payload-sha256=$(sha "$PAY/master-items.js") --replace-expect-sha256=$ZERO
  expect_fail "[$label] replace refused when the target hash is wrong (SupplierService.php)" php $INST "$PAY/SupplierService.php" "$SV/SupplierService.php" --expect-payload-sha256=$(sha "$PAY/SupplierService.php") --replace-expect-sha256=$ZERO
  expect_fail "[$label] wrong payload hash refused" php $INST "$PAY/MasterRecordService.php" "$SV/MasterRecordService.php" --expect-payload-sha256=$ZERO
  expect_fail "[$label] new-file install refused over an existing file" bash -c "cp '$PAY/MasterRecordService.php' '$T/existing.php'; php $INST '$PAY/MasterRecordService.php' '$T/existing.php' --expect-payload-sha256=$(sha "$PAY/MasterRecordService.php")"
  sed "s#^require_once __DIR__ . '/../services/MasterDataSafetyService.php';\$#// drifted#" "$P/index.php" > "$T/drift1.php"
  expect_fail "[$label] index.php without the MasterDataSafetyService require anchor refused (matching hash)" php $IDX_PHP $(php_args "$T/drift1.php") --expect-sha256=$(sha "$T/drift1.php")
  sed "s#'PUT /warehouses/{id}' => function#'PUT /warehouses/{wid}' => function#" "$P/index.php" > "$T/drift2.php"
  expect_fail "[$label] index.php without the PUT /warehouses/{id} anchor refused (matching hash)" php $IDX_PHP $(php_args "$T/drift2.php") --expect-sha256=$(sha "$T/drift2.php")
  cp "$P/index.php" "$T/dup.php"; grep -F "'PUT /warehouses/{id}' => function (array \$params) use (\$pdo, \$input) {" "$P/index.php" >> "$T/dup.php"
  expect_fail "[$label] index.php with the route anchor twice refused" php $IDX_PHP $(php_args "$T/dup.php") --expect-sha256=$(sha "$T/dup.php")
  sed "s#SELECT id FROM categories WHERE code = :c#SELECT id FROM categories WHERE code = :code#" "$P/index.php" > "$T/drift3.php"
  expect_fail "[$label] index.php whose POST /categories block differs from the audited text refused (matching hash)" php $IDX_PHP $(php_args "$T/drift3.php") --expect-sha256=$(sha "$T/drift3.php")
  cp "$P/index.php" "$T/already.php"; echo "// MasterRecordService" >> "$T/already.php"
  expect_fail "[$label] index.php already referencing MasterRecordService refused" php $IDX_PHP $(php_args "$T/already.php") --expect-sha256=$(sha "$T/already.php")
  grep -v "createCategory:" "$P/assets/js/api-client.js" > "$T/api_none.js"
  expect_fail "[$label] api-client.js without the createCategory anchor refused" php $API "$T/api_none.js" --expect-sha256=$(sha "$T/api_none.js")
  cp "$P/assets/js/api-client.js" "$T/api_dup.js"; grep -F "createCategory:" "$P/assets/js/api-client.js" >> "$T/api_dup.js"
  expect_fail "[$label] api-client.js with the anchor twice refused" php $API "$T/api_dup.js" --expect-sha256=$(sha "$T/api_dup.js")
  cp "$P/assets/css/app.css" "$T/css_dup.css"; echo '.mdm-x{}' >> "$T/css_dup.css"
  expect_fail "[$label] app.css already containing .mdm- refused" php $CSS "$T/css_dup.css" "$PAY/mdm_app_css_block.css" --expect-sha256=$(sha "$T/css_dup.css") --expect-block-sha256=$H_BLK
  cp "$P/index.html" "$T/dup.html"; grep -F 'master-items.js?v=' "$P/index.html" >> "$T/dup.html"
  expect_fail "[$label] index.html with a master-items.js tag twice refused" php $HTML "$T/dup.html" --expect-sha256=$(sha "$T/dup.html")
  grep -v 'master-divisions\.js?v=' "$P/index.html" > "$T/none.html"
  expect_fail "[$label] index.html without the master-divisions.js tag refused" php $HTML "$T/none.html" --expect-sha256=$(sha "$T/none.html")
  expect_fail "[$label] DB check mode refuses without arguments" php $CHK

  # ---- apply: backend first, then frontend
  inst MasterRecordService.php "$SV/MasterRecordService.php" $(sha "$PAY/MasterRecordService.php") --apply >/dev/null && inst SupplierService.php "$SV/SupplierService.php" $(sha "$PAY/SupplierService.php") --replace-expect-sha256=$H_SUP --apply >/dev/null && inst BakeryDestinationService.php "$SV/BakeryDestinationService.php" $(sha "$PAY/BakeryDestinationService.php") --replace-expect-sha256=$H_BKS --apply >/dev/null && ok "[$label] apply: 3 services" || bad "[$label] apply services"
  php $IDX_PHP $(php_args "$P/index.php") --expect-sha256=$H_PHP --apply >/dev/null && ok "[$label] apply: index.php" || bad "[$label] apply index.php"
  php -l "$P/index.php" >/dev/null 2>&1 && for s in MasterRecordService SupplierService BakeryDestinationService; do php -l "$SV/$s.php" >/dev/null 2>&1 || bad "[$label] $s syntax"; done && ok "[$label] patched index.php + services are valid PHP" || bad "[$label] php syntax"
  php $API "$P/assets/js/api-client.js" --expect-sha256=$H_API --apply >/dev/null && ok "[$label] apply: api-client.js" || bad "[$label] apply api-client"
  local jsa=1; for f in $JSF; do inst $f.js "$P/assets/js/$f.js" $(sha "$PAY/$f.js") --replace-expect-sha256=${HJ[$f]} --apply >/dev/null || jsa=0; done; [ $jsa = 1 ] && ok "[$label] apply: 7 JS replacements" || bad "[$label] apply JS"
  php $CSS "$P/assets/css/app.css" "$PAY/mdm_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$H_BLK --apply >/dev/null && ok "[$label] apply: app.css" || bad "[$label] apply css"
  php $HTML "$P/index.html" --expect-sha256=$H_HTML --apply >/dev/null && ok "[$label] apply: index.html" || bad "[$label] apply html"
  local nj=1; for f in $JSF; do node --check "$P/assets/js/$f.js" 2>/dev/null || nj=0; done; node --check "$P/assets/js/api-client.js" 2>/dev/null || nj=0; [ $nj = 1 ] && ok "[$label] patched JS files are valid JS" || bad "[$label] js syntax"

  # ---- results
  local same=1; for f in $JSF; do cmp -s "$P/assets/js/$f.js" "$DEV/$f.js" || same=0; done; for s in MasterRecordService SupplierService BakeryDestinationService; do cmp -s "$SV/$s.php" "$DEV/$s.php" || same=0; done
  [ $same = 1 ] && ok "[$label] 7 JS + 3 services byte-identical to the tested dev files" || bad "[$label] replaced files differ from dev"
  python3 - "$T/pre_index.php" "$P/index.php" "$PAY" <<'PY' && ok "[$label] index.php: exactly 1 require + 3 routes inserted + the category block replaced (everything else byte-identical)" || bad "[$label] index.php structure"
import sys
pre, post, pay = open(sys.argv[1], encoding='utf-8').read(), open(sys.argv[2], encoding='utf-8').read(), sys.argv[3]
rt, co, cn = (open(f'{pay}/{n}', encoding='utf-8').read() for n in ('mdm_index_php_routes.txt', 'mdm_category_old.txt', 'mdm_category_new.txt'))
req = "require_once __DIR__ . '/../services/MasterDataSafetyService.php';\n"
anc = "    'PUT /warehouses/{id}' => function (array $params) use ($pdo, $input) {\n"
exp = pre.replace(req, req + "require_once __DIR__ . '/../services/MasterRecordService.php';\n", 1).replace(anc, rt + anc, 1).replace(co, cn, 1)
assert post == exp, 'index.php differs from expectation'
assert post.count("'POST /items' =>") == 1 and post.count("MasterRecordService.php") == 1 and co not in post
PY
  [ "$(diff "$T/pre_api.js" "$P/assets/js/api-client.js" | grep -c '^>')" = 3 ] && [ "$(diff "$T/pre_api.js" "$P/assets/js/api-client.js" | grep -c '^<')" = 0 ] && ok "[$label] api-client.js: exactly 3 lines added, none removed" || bad "[$label] api-client diff"
  diff "$T/pre_app.css" "$P/assets/css/app.css" | grep '^<' >/dev/null && bad "[$label] app.css: an existing line was removed/changed" || ok "[$label] app.css: additions only"
  tail -c "$(wc -c < "$PAY/mdm_app_css_block.css")" "$P/assets/css/app.css" | cmp -s - "$PAY/mdm_app_css_block.css" && ok "[$label] app.css ends with exactly the tested block" || bad "[$label] app.css tail"
  [ "$(diff "$T/pre_index.html" "$P/index.html" | grep '^>' | grep -c '20261012-mdm')" = 9 ] && [ "$(diff "$T/pre_index.html" "$P/index.html" | grep -c '^[<>]')" = 18 ] && ok "[$label] index.html: exactly 9 tags changed (app.css + 8 scripts -> 20261012-mdm)" || bad "[$label] index.html diff"
  php $CHK --public-dir="$P" --services-dir="$SV" --files >"$W/files.txt" 2>&1 && ok "[$label] post-apply file check passes ($(tail -1 "$W/files.txt"))" || { bad "[$label] post-apply file check"; cat "$W/files.txt"; }

  # ---- double-apply refusal
  expect_fail "[$label] double-apply refused (index.php)" php $IDX_PHP $(php_args "$P/index.php") --expect-sha256=$(sha "$P/index.php") --apply
  expect_fail "[$label] double-apply refused (api-client.js)" php $API "$P/assets/js/api-client.js" --expect-sha256=$(sha "$P/assets/js/api-client.js") --apply
  expect_fail "[$label] double-apply refused (app.css)" php $CSS "$P/assets/css/app.css" "$PAY/mdm_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply
  expect_fail "[$label] double-apply refused (index.html)" php $HTML "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  expect_fail "[$label] double-apply refused (master-items.js, state file present)" php $INST "$PAY/master-items.js" "$P/assets/js/master-items.js" --expect-payload-sha256=$(sha "$PAY/master-items.js") --replace-expect-sha256=$(sha "$P/assets/js/master-items.js") --apply
  expect_fail "[$label] new-file install refused when the service already exists" php $INST "$PAY/MasterRecordService.php" "$SV/MasterRecordService.php" --expect-payload-sha256=$(sha "$PAY/MasterRecordService.php") --apply

  # ---- rollback: tamper refusal first (changes nothing), then the real thing
  cp "$P/assets/js/master-vendors.js" "$T/mv_post.js"; echo '// edited after the package' >> "$P/assets/js/master-vendors.js"
  local snap; snap=$(cat "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$SV/SupplierService.php" "$SV/MasterRecordService.php" | sha256sum)
  expect_fail "[$label] rollback refused when a file was edited after the package" php $RB --public-dir="$P" --services-dir="$SV" --apply
  [ "$(cat "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$SV/SupplierService.php" "$SV/MasterRecordService.php" | sha256sum)" = "$snap" ] && [ -e "$SV/MasterRecordService.php" ] && ok "[$label] the refused rollback changed nothing (two-phase)" || bad "[$label] refused rollback changed files"
  cp "$T/mv_post.js" "$P/assets/js/master-vendors.js"
  php $RB --public-dir="$P" --services-dir="$SV" >/dev/null && ok "[$label] rollback dry-run passes (14 targets)" || bad "[$label] rollback dry-run"
  [ -e "$SV/MasterRecordService.php" ] && [ "$(sha "$P/assets/js/master-vendors.js")" = "$(sha "$PAY/master-vendors.js")" ] && ok "[$label] dry-run rollback wrote nothing" || bad "[$label] dry-run rollback wrote"
  php $RB --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] rollback applied" || bad "[$label] rollback apply"
  local rb=1
  [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/index.html")" = "$H_HTML" ] && [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/assets/js/api-client.js")" = "$H_API" ] && [ "$(sha "$SV/SupplierService.php")" = "$H_SUP" ] && [ "$(sha "$SV/BakeryDestinationService.php")" = "$H_BKS" ] || rb=0
  for f in $JSF; do [ "$(sha "$P/assets/js/$f.js")" = "${HJ[$f]}" ] || rb=0; done
  [ $rb = 1 ] && [ ! -e "$SV/MasterRecordService.php" ] && ok "[$label] rollback restored all 13 files byte-exactly and removed the new service" || bad "[$label] rollback bytes"
  ! ls "$P"/*.mdm-patch.json "$P"/assets/*/*.mdm-patch.json "$SV"/*.mdm-patch.json >/dev/null 2>&1 && ok "[$label] state files removed (backups kept)" || bad "[$label] state files remain"
  expect_fail "[$label] second rollback refused (nothing to roll back)" php $RB --public-dir="$P" --services-dir="$SV"
}

run_tree D 0
run_tree E 1

grep -q "$H_RT" "$PK/README_DEPLOY_mdm.md" && grep -q "$H_BLK" "$PK/README_DEPLOY_mdm.md" && grep -q "$(sha "$PAY/MasterRecordService.php")" "$PK/README_DEPLOY_mdm.md" && grep -q "$(sha "$PAY/master-items.js")" "$PK/README_DEPLOY_mdm.md" && ! grep -q '@@' "$PK/README_DEPLOY_mdm.md" "$PK/collect_production_hashes_mdm.sh" && ok "README + collect script carry the real hashes (no unreplaced placeholders)" || bad "README placeholders"
bash -n "$PK/collect_production_hashes_mdm.sh" && ok "collect script is valid bash" || bad "collect syntax"
grep -q "repo @" "$PK/collect_production_hashes_mdm.sh" && ok "collect script lists the reference hashes of the previous repo versions" || bad "collect refs"

echo; echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
