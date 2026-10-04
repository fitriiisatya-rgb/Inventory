#!/usr/bin/env bash
# Rehearses the Dashboard production package against PRODUCTION-LIKE trees. The
# package is independent of Jejak v3, so BOTH states production can be in are
# rehearsed:
#   tree A = pre-Jejak files + Jejak v2 package applied        (production today)
#   tree B = tree A + Jejak v3 package applied                  (if v3 ships first)
# Never touches the live dev working tree.
#
# Proves: dry-run writes nothing; preimage/block-hash/anchor/double-apply gates;
# backend-first apply; byte-equality with the tested dev files (tree B) and
# additions-only structural equality (tree A); the two-phase rollback restoring
# every file byte-exactly; and that Jejak's own rollbacks still chain afterwards.
#
# Usage: bash tests/dashboard_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
BASE_REV="${BASE_REV:-853cd69}"; V2_REV="${V2_REV:-e9072ec}"; V3_REV="${V3_REV:-9dcb367}"
DASH_REV="${DASH_REV:-f4a94e8}"   # the commit the dashboard package was built from (later work, e.g. Stock IN/OUT V2, is a separate package)
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

# ---- payload exactly as the package ships it
PAY="$W/payload"; DEV="$W/dev"; mkdir -p "$PAY" "$DEV"
git show "$DASH_REV:public/assets/js/dashboard.js" > "$DEV/dashboard.js"; git show "$DASH_REV:services/DashboardInventoryService.php" > "$DEV/DashboardInventoryService.php"
git show "$DASH_REV:public/assets/css/app.css" > "$DEV/app.css"; git show "$DASH_REV:public/index.php" > "$DEV/index.php"; git show "$DASH_REV:public/index.html" > "$DEV/index.html"
cp "$DEV/dashboard.js" "$PAY/dashboard.js"
cp "$DEV/DashboardInventoryService.php" "$PAY/DashboardInventoryService.php"
awk '/^\/\* Dashboard redesign \(dashboard.js\)/{f=1} f' "$DEV/app.css" > "$PAY/dashboard_app_css_block.css"
H_PJS=$(sha "$PAY/dashboard.js"); H_PSVC=$(sha "$PAY/DashboardInventoryService.php"); H_PBLK=$(sha "$PAY/dashboard_app_css_block.css")
[ "$(grep -c '^/\* Dashboard redesign' "$PAY/dashboard_app_css_block.css")" = 1 ] && ok "css block extracted from dev app.css (single marker)" || bad "css block extraction"

# ---- v2 / v3 packages as delivered (from git)
V2="$W/v2pkg"; mkdir -p "$V2/scripts/lib" "$V2/payload"
for f in lib/jejak_patch_common.php patch_report_opname_jejak_row_click_production.php patch_app_css_jejak_drawer_xl_production.php patch_index_html_jejak_script_tag_production.php install_stock_opname_report_jejak_production.php rollback_jejak_production.php; do git show "$V2_REV:scripts/$f" > "$V2/scripts/$f"; done
git show "$V2_REV:public/assets/js/stock-opname-report-jejak.js" > "$V2/payload/stock-opname-report-jejak.js"
V3="$W/v3pkg"; mkdir -p "$V3/scripts/lib" "$V3/payload"
for f in lib/jejak_patch_common.php patch_jejak_v3_app_css_production.php patch_jejak_v3_index_html_production.php patch_jejak_v3_index_php_route_production.php install_jejak_v3_files_production.php rollback_jejak_v3_production.php; do git show "$V3_REV:scripts/$f" > "$V3/scripts/$f"; done
git show "$V3_REV:public/assets/js/stock-opname-report-jejak.js" > "$V3/payload/stock-opname-report-jejak.js"
git show "$V3_REV:services/StockOpnameJejakService.php" > "$V3/payload/StockOpnameJejakService.php"

# mk_tree <dir> <with_v3: 0|1>  -> leaves $P/$SV for the caller
mk_tree() {
  local T="$1" v3="$2"; P="$T/public"; SV="$T/services"
  mkdir -p "$P/assets/js" "$P/assets/css" "$SV"
  git show "$BASE_REV:public/assets/js/report-opname.js" > "$P/assets/js/report-opname.js"
  git show "$BASE_REV:public/assets/js/dashboard.js" > "$P/assets/js/dashboard.js"
  git show "$BASE_REV:public/assets/css/app.css" > "$P/assets/css/app.css"
  git show "$BASE_REV:public/index.html" | grep -v 'stock-opname-report\.js' > "$P/index.html"
  git show "$V2_REV:public/index.php" > "$P/index.php"
  printf '<?php // placeholder service dir\n' > "$SV/Database.php"
  php "$V2/scripts/patch_app_css_jejak_drawer_xl_production.php" "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply >/dev/null || bad "v2 css"
  php "$V2/scripts/patch_report_opname_jejak_row_click_production.php" "$P/assets/js/report-opname.js" --expect-sha256=$(sha "$P/assets/js/report-opname.js") --apply >/dev/null || bad "v2 js"
  php "$V2/scripts/patch_index_html_jejak_script_tag_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "v2 index"
  php "$V2/scripts/install_stock_opname_report_jejak_production.php" "$V2/payload/stock-opname-report-jejak.js" "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$(sha "$V2/payload/stock-opname-report-jejak.js") --apply >/dev/null || bad "v2 install"
  if [ "$v3" = 1 ]; then
    local S="$V3/scripts" HJ; HJ=$(sha "$P/assets/js/stock-opname-report-jejak.js")
    php "$S/install_jejak_v3_files_production.php" "$V3/payload/StockOpnameJejakService.php" "$SV/StockOpnameJejakService.php" --expect-payload-sha256=$(sha "$V3/payload/StockOpnameJejakService.php") --apply >/dev/null || bad "v3 service"
    php "$S/patch_jejak_v3_index_php_route_production.php" "$P/index.php" --expect-sha256=$(sha "$P/index.php") --apply >/dev/null || bad "v3 php"
    php "$S/install_jejak_v3_files_production.php" "$V3/payload/stock-opname-report-jejak.js" "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$(sha "$V3/payload/stock-opname-report-jejak.js") --replace-expect-sha256=$HJ --apply >/dev/null || bad "v3 js"
    php "$S/patch_jejak_v3_app_css_production.php" "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply >/dev/null || bad "v3 css"
    php "$S/patch_jejak_v3_index_html_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "v3 index"
  fi
}

CSS=scripts/patch_dashboard_app_css_production.php; IDX=scripts/patch_dashboard_index_html_production.php; PHPR=scripts/patch_dashboard_index_php_production.php
INST=scripts/install_dashboard_files_production.php; RB=scripts/rollback_dashboard_production.php

run_tree() {
  local label="$1" v3="$2" T="$W/tree_$1"
  echo; echo "################ tree $label (Jejak v3 applied = $v3) ################"
  mk_tree "$T" "$v3"
  local H_JS H_CSS H_IDX H_PHP
  H_JS=$(sha "$P/assets/js/dashboard.js"); H_CSS=$(sha "$P/assets/css/app.css"); H_IDX=$(sha "$P/index.html"); H_PHP=$(sha "$P/index.php")
  cp "$P/index.php" "$T/pre_index.php"; cp "$P/assets/css/app.css" "$T/pre_app.css"; cp "$P/index.html" "$T/pre_index.html"
  [ -n "$H_JS" ] && ok "[$label] production-like tree built"

  # ---- dry runs write nothing
  php $INST "$PAY/DashboardInventoryService.php" "$SV/DashboardInventoryService.php" --expect-payload-sha256=$H_PSVC >/dev/null && ok "[$label] dry-run: install service (new file)" || bad "[$label] dry-run service"
  php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_PJS --replace-expect-sha256=$H_JS >/dev/null && ok "[$label] dry-run: replace dashboard.js" || bad "[$label] dry-run js"
  php $PHPR "$P/index.php" --expect-sha256=$H_PHP >/dev/null && ok "[$label] dry-run: index.php" || bad "[$label] dry-run php"
  php $CSS "$P/assets/css/app.css" "$PAY/dashboard_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$H_PBLK >/dev/null && ok "[$label] dry-run: app.css" || bad "[$label] dry-run css"
  php $IDX "$P/index.html" --expect-sha256=$H_IDX >/dev/null && ok "[$label] dry-run: index.html" || bad "[$label] dry-run index"
  [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/assets/js/dashboard.js")" = "$H_JS" ] && [ ! -e "$SV/DashboardInventoryService.php" ] && ! ls "$P"/*.pre-dash-backup "$P"/assets/*/*.pre-dash-backup >/dev/null 2>&1 && ok "[$label] dry runs wrote nothing" || bad "[$label] dry runs wrote something"

  # ---- fail-closed gates
  expect_fail "[$label] wrong preimage hash refused (app.css)" php $CSS "$P/assets/css/app.css" "$PAY/dashboard_app_css_block.css" --expect-sha256=$ZERO --expect-block-sha256=$H_PBLK
  expect_fail "[$label] wrong CSS block hash refused" php $CSS "$P/assets/css/app.css" "$PAY/dashboard_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$ZERO
  expect_fail "[$label] app.css already containing .dash- refused (even with matching hash)" bash -c "cp '$P/assets/css/app.css' '$T/dup.css'; echo '.dash-x{}' >> '$T/dup.css'; php $CSS '$T/dup.css' '$PAY/dashboard_app_css_block.css' --expect-sha256=\$(sha256sum '$T/dup.css' | cut -d' ' -f1) --expect-block-sha256=$H_PBLK"
  expect_fail "[$label] wrong preimage hash refused (index.php)" php $PHPR "$P/index.php" --expect-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$ZERO
  expect_fail "[$label] replace of dashboard.js refused when its hash is wrong" php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_PJS --replace-expect-sha256=$ZERO
  expect_fail "[$label] wrong payload hash refused" php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$ZERO --replace-expect-sha256=$H_JS
  expect_fail "[$label] service install refused over an existing file" bash -c "cp '$PAY/DashboardInventoryService.php' '$T/svc_existing.php'; php $INST '$PAY/DashboardInventoryService.php' '$T/svc_existing.php' --expect-payload-sha256=$H_PSVC"
  sed "s#^require_once __DIR__ . '/../services/InventoryHppReportService.php';\$#// drifted#" "$P/index.php" > "$T/drift.php"
  expect_fail "[$label] index.php without the InventoryHppReportService require anchor refused (matching hash)" php $PHPR "$T/drift.php" --expect-sha256=$(sha "$T/drift.php")
  cp "$P/index.php" "$T/dup.php"; grep -F "'GET /inventory/value' => function () use (\$pdo) {" "$P/index.php" >> "$T/dup.php"
  expect_fail "[$label] index.php with the route anchor twice refused" php $PHPR "$T/dup.php" --expect-sha256=$(sha "$T/dup.php")
  grep -F 'dashboard.js?v=' "$P/index.html" >> /dev/null && { cp "$P/index.html" "$T/dup.html"; grep -F 'dashboard.js?v=' "$P/index.html" >> "$T/dup.html"; }
  expect_fail "[$label] index.html with the dashboard.js tag twice refused" php $IDX "$T/dup.html" --expect-sha256=$(sha "$T/dup.html")
  grep -v 'dashboard\.js?v=' "$P/index.html" > "$T/none.html"
  expect_fail "[$label] index.html without the dashboard.js tag refused" php $IDX "$T/none.html" --expect-sha256=$(sha "$T/none.html")

  # ---- apply: backend first, then frontend
  php $INST "$PAY/DashboardInventoryService.php" "$SV/DashboardInventoryService.php" --expect-payload-sha256=$H_PSVC --apply >/dev/null && ok "[$label] apply: install service" || bad "[$label] apply service"
  php $PHPR "$P/index.php" --expect-sha256=$H_PHP --apply >/dev/null && ok "[$label] apply: index.php routes" || bad "[$label] apply php"
  php -l "$P/index.php" >/dev/null 2>&1 && php -l "$SV/DashboardInventoryService.php" >/dev/null 2>&1 && ok "[$label] patched index.php + service are valid PHP" || bad "[$label] php syntax"
  php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_PJS --replace-expect-sha256=$H_JS --apply >/dev/null && ok "[$label] apply: replace dashboard.js" || bad "[$label] apply js"
  php $CSS "$P/assets/css/app.css" "$PAY/dashboard_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$H_PBLK --apply >/dev/null && ok "[$label] apply: app.css" || bad "[$label] apply css"
  php $IDX "$P/index.html" --expect-sha256=$H_IDX --apply >/dev/null && ok "[$label] apply: index.html" || bad "[$label] apply index"

  # ---- results
  cmp -s "$P/assets/js/dashboard.js" "$DEV/dashboard.js" && ok "[$label] dashboard.js byte-identical to the tested dev file" || bad "[$label] dashboard.js differs"
  cmp -s "$SV/DashboardInventoryService.php" "$DEV/DashboardInventoryService.php" && ok "[$label] service byte-identical to the tested dev file" || bad "[$label] service differs"
  [ "$(grep -c "services/DashboardInventoryService.php" "$P/index.php")" = 1 ] && [ "$(grep -c "'GET /dashboard/inventory' =>" "$P/index.php")" = 1 ] && [ "$(grep -c "'GET /dashboard/inventory/detail' =>" "$P/index.php")" = 1 ] && ok "[$label] index.php: exactly one require and two routes" || bad "[$label] index.php counts"
  diff "$T/pre_index.php" "$P/index.php" | grep '^<' >/dev/null && bad "[$label] index.php: an existing line was removed/changed" || ok "[$label] index.php: additions only (no existing line removed or changed)"
  diff "$T/pre_app.css" "$P/assets/css/app.css" | grep '^<' >/dev/null && bad "[$label] app.css: an existing line was removed/changed" || ok "[$label] app.css: additions only"
  tail -c "$(wc -c < "$PAY/dashboard_app_css_block.css")" "$P/assets/css/app.css" | cmp -s - "$PAY/dashboard_app_css_block.css" && ok "[$label] app.css: file ends with exactly the tested block" || bad "[$label] app.css tail"
  [ "$(diff "$T/pre_index.html" "$P/index.html" | grep -c '^>')" = 2 ] && [ "$(diff "$T/pre_index.html" "$P/index.html" | grep -c '^<')" = 2 ] && grep -q 'dashboard.js?v=20261009-dash1' "$P/index.html" && grep -q 'app.css?v=20261009-dash1' "$P/index.html" && ok "[$label] index.html: exactly the two tags changed, both on 20261009-dash1" || bad "[$label] index.html diff"
  if [ "$v3" = 1 ]; then
    cmp -s "$P/index.php" "$DEV/index.php" && ok "[$label] index.php byte-identical to the tested dev file" || bad "[$label] index.php differs from dev"
    cmp -s "$P/assets/css/app.css" "$DEV/app.css" && ok "[$label] app.css byte-identical to the tested dev file" || bad "[$label] app.css differs from dev"
    diff <(grep -v 'stock-opname-report\.js' "$DEV/index.html") "$P/index.html" >/dev/null && ok "[$label] index.html byte-identical to dev (minus the dev-only stock-opname-report.js tag)" || bad "[$label] index.html differs from dev"
  fi

  # ---- double-apply refusal
  expect_fail "[$label] double-apply refused (app.css)" php $CSS "$P/assets/css/app.css" "$PAY/dashboard_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_PBLK --apply
  expect_fail "[$label] double-apply refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  expect_fail "[$label] double-apply refused (index.php)" php $PHPR "$P/index.php" --expect-sha256=$(sha "$P/index.php") --apply
  expect_fail "[$label] double-apply refused (dashboard.js)" php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_PJS --replace-expect-sha256=$H_PJS --apply
  expect_fail "[$label] double-apply refused (service)" php $INST "$PAY/DashboardInventoryService.php" "$SV/DashboardInventoryService.php" --expect-payload-sha256=$H_PSVC --apply

  # ---- rollback: tamper => refuse, change nothing
  cp "$P/index.php" "$T/index.php.dash"
  echo "// hand edit" >> "$P/index.php"
  expect_fail "[$label] rollback refuses when a patched file was edited afterwards" php $RB --public-dir="$P" --services-dir="$SV" --apply
  [ -e "$SV/DashboardInventoryService.php" ] && [ "$(sha "$P/assets/css/app.css")" != "$H_CSS" ] && ok "[$label] refused rollback changed nothing (two-phase)" || bad "[$label] refused rollback changed something"
  cp "$T/index.php.dash" "$P/index.php"
  mv "$P/assets/js/dashboard.js.dashboard-patch.json" "$T/meta.bak"
  expect_fail "[$label] rollback refuses when a state file is missing" php $RB --public-dir="$P" --services-dir="$SV" --apply
  mv "$T/meta.bak" "$P/assets/js/dashboard.js.dashboard-patch.json"

  # ---- rollback
  php $RB --public-dir="$P" --services-dir="$SV" >/dev/null && ok "[$label] rollback dry-run succeeds" || bad "[$label] rollback dry-run"
  [ -e "$SV/DashboardInventoryService.php" ] && ok "[$label] rollback dry-run changed nothing" || bad "[$label] dry-run changed something"
  php $RB --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] rollback --apply succeeds" || bad "[$label] rollback apply"
  [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/assets/js/dashboard.js")" = "$H_JS" ] && ok "[$label] all four files restored byte-exact to the pre-dashboard hashes" || bad "[$label] restore not byte-exact"
  [ ! -e "$SV/DashboardInventoryService.php" ] && ok "[$label] new service file removed" || bad "[$label] service still present"
  expect_fail "[$label] second rollback refused (nothing to roll back)" php $RB --public-dir="$P" --services-dir="$SV" --apply

  # ---- chain integrity: Jejak rollbacks still work on the restored tree
  if [ "$v3" = 1 ]; then
    php "$V3/scripts/rollback_jejak_v3_production.php" --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] Jejak v3 rollback still works after the dashboard rollback" || bad "[$label] v3 rollback broken"
  fi
  php "$V2/scripts/rollback_jejak_production.php" --public-dir="$P" --apply >/dev/null && ok "[$label] Jejak v2 rollback still works afterwards" || bad "[$label] v2 rollback broken"
}

run_tree A 0
run_tree B 1

echo; echo "$pass passed, $fail failed."; [ "$fail" -eq 0 ]
