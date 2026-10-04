#!/usr/bin/env bash
# Rehearses the Stock IN / OUT V2 package against PRODUCTION-LIKE trees (never the live dev tree):
#   tree A = pre-Jejak files + Jejak v2                              (production today)
#   tree B = tree A + Jejak v3 + Dashboard package                    (everything else shipped first)
# Proves: dry-run writes nothing; preimage / payload / block hash, anchor, double-apply gates;
# backend-first apply; byte-equality with the tested dev files (tree B) and additions-only
# structure (tree A); two-phase rollback restoring every file byte-exactly.
# Usage: [TX_REV=<commit>] bash tests/tx2_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
BASE_REV="${BASE_REV:-853cd69}"; V2_REV="${V2_REV:-e9072ec}"; V3_REV="${V3_REV:-9dcb367}"; DASH_REV="${DASH_REV:-f4a94e8}"; TX_REV="${TX_REV:-HEAD}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

# ---- payload exactly as the package ships it (built from TX_REV)
PAY="$W/payload"; DEV="$W/dev"; mkdir -p "$PAY" "$DEV"
for f in services/PurchaseInvoiceService.php services/StockOutService.php services/StockOutDocumentService.php public/assets/js/stock-in-sheet.js public/assets/js/stock-out-sheet.js public/assets/js/transactions.js public/assets/js/transaction-history.js public/assets/css/app.css public/index.php public/index.html; do
  git show "$TX_REV:$f" > "$DEV/$(basename "$f")"
done
for f in PurchaseInvoiceService.php StockOutService.php StockOutDocumentService.php stock-in-sheet.js stock-out-sheet.js transactions.js; do cp "$DEV/$f" "$PAY/$f"; done
awk '/^\/\* Stock IN \/ OUT V2 \(transactions.js/{f=1} f' "$DEV/app.css" > "$PAY/tx2_app_css_block.css"
H_S1=$(sha "$PAY/PurchaseInvoiceService.php"); H_S2=$(sha "$PAY/StockOutService.php"); H_S3=$(sha "$PAY/StockOutDocumentService.php")
H_J1=$(sha "$PAY/stock-in-sheet.js"); H_J2=$(sha "$PAY/stock-out-sheet.js"); H_J0=$(sha "$PAY/transactions.js"); H_BLK=$(sha "$PAY/tx2_app_css_block.css")
[ "$(grep -c '^/\* Stock IN / OUT V2' "$PAY/tx2_app_css_block.css")" = 1 ] && ok "css block extracted (single marker, ends at EOF)" || bad "css block extraction"

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

CSS=scripts/patch_tx2_app_css_production.php; IDX=scripts/patch_tx2_index_html_production.php; PHPR=scripts/patch_tx2_index_php_production.php; HST=scripts/patch_tx2_transaction_history_production.php
INST=scripts/install_tx2_files_production.php; RB=scripts/rollback_tx2_production.php

run_tree() {
  local label="$1" full="$2" T="$W/tree_$1"
  echo; echo "################ tree $label (Jejak v3 + Dashboard applied = $full) ################"
  mk_tree "$T" "$full"
  local H_PHP H_CSS H_IDX H_TX H_HIST
  H_PHP=$(sha "$P/index.php"); H_CSS=$(sha "$P/assets/css/app.css"); H_IDX=$(sha "$P/index.html"); H_TX=$(sha "$P/assets/js/transactions.js"); H_HIST=$(sha "$P/assets/js/transaction-history.js")
  cp "$P/index.php" "$T/pre_index.php"; cp "$P/assets/css/app.css" "$T/pre_app.css"; cp "$P/index.html" "$T/pre_index.html"; cp "$P/assets/js/transaction-history.js" "$T/pre_hist.js"
  ok "[$label] production-like tree built"
  inst_new() { php $INST "$PAY/$1" "$2" --expect-payload-sha256=$3 "${@:4}"; }

  # ---- dry runs write nothing
  inst_new PurchaseInvoiceService.php "$SV/PurchaseInvoiceService.php" $H_S1 >/dev/null && inst_new StockOutService.php "$SV/StockOutService.php" $H_S2 >/dev/null && inst_new StockOutDocumentService.php "$SV/StockOutDocumentService.php" $H_S3 >/dev/null && ok "[$label] dry-run: 3 services (new files)" || bad "[$label] dry-run services"
  inst_new stock-in-sheet.js "$P/assets/js/stock-in-sheet.js" $H_J1 >/dev/null && inst_new stock-out-sheet.js "$P/assets/js/stock-out-sheet.js" $H_J2 >/dev/null && ok "[$label] dry-run: 2 new JS modules" || bad "[$label] dry-run js new"
  inst_new transactions.js "$P/assets/js/transactions.js" $H_J0 --replace-expect-sha256=$H_TX >/dev/null && ok "[$label] dry-run: replace transactions.js" || bad "[$label] dry-run transactions"
  php $PHPR "$P/index.php" --expect-sha256=$H_PHP >/dev/null && php $HST "$P/assets/js/transaction-history.js" --expect-sha256=$H_HIST >/dev/null && php $CSS "$P/assets/css/app.css" "$PAY/tx2_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$H_BLK >/dev/null && php $IDX "$P/index.html" --expect-sha256=$H_IDX >/dev/null && ok "[$label] dry-run: index.php, transaction-history.js, app.css, index.html" || bad "[$label] dry-run patchers"
  [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/assets/js/transactions.js")" = "$H_TX" ] && [ "$(sha "$P/assets/js/transaction-history.js")" = "$H_HIST" ] && [ ! -e "$SV/StockOutService.php" ] && [ ! -e "$P/assets/js/stock-in-sheet.js" ] && ! ls "$P"/*.pre-tx2-backup "$P"/assets/*/*.pre-tx2-backup >/dev/null 2>&1 && ok "[$label] dry runs wrote nothing" || bad "[$label] dry runs wrote something"

  # ---- fail-closed gates
  expect_fail "[$label] wrong preimage hash refused (index.php)" php $PHPR "$P/index.php" --expect-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (transaction-history.js)" php $HST "$P/assets/js/transaction-history.js" --expect-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (app.css)" php $CSS "$P/assets/css/app.css" "$PAY/tx2_app_css_block.css" --expect-sha256=$ZERO --expect-block-sha256=$H_BLK
  expect_fail "[$label] wrong CSS block hash refused" php $CSS "$P/assets/css/app.css" "$PAY/tx2_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$ZERO
  expect_fail "[$label] replace of transactions.js refused when its hash is wrong" php $INST "$PAY/transactions.js" "$P/assets/js/transactions.js" --expect-payload-sha256=$H_J0 --replace-expect-sha256=$ZERO
  expect_fail "[$label] wrong payload hash refused" php $INST "$PAY/stock-in-sheet.js" "$P/assets/js/stock-in-sheet.js" --expect-payload-sha256=$ZERO
  expect_fail "[$label] new-file install refused over an existing file" bash -c "cp '$PAY/StockOutService.php' '$T/existing.php'; php $INST '$PAY/StockOutService.php' '$T/existing.php' --expect-payload-sha256=$H_S2"
  sed "s#^require_once __DIR__ . '/../services/PurchaseCostingGateway.php';\$#// drifted#" "$P/index.php" > "$T/drift.php"
  expect_fail "[$label] index.php without the PurchaseCostingGateway require anchor refused (matching hash)" php $PHPR "$T/drift.php" --expect-sha256=$(sha "$T/drift.php")
  cp "$P/index.php" "$T/dup.php"; grep -F "// PHASE V2.7 — read-only Cost Preview, called by the Transaksi Masuk" "$P/index.php" >> "$T/dup.php"
  expect_fail "[$label] index.php with the route anchor twice refused" php $PHPR "$T/dup.php" --expect-sha256=$(sha "$T/dup.php")
  cp "$P/index.html" "$T/dup.html"; grep -F 'transactions.js?v=' "$P/index.html" >> "$T/dup.html"
  expect_fail "[$label] index.html with the transactions.js tag twice refused" php $IDX "$T/dup.html" --expect-sha256=$(sha "$T/dup.html")
  grep -v 'transaction-history\.js?v=' "$P/index.html" > "$T/none.html"
  expect_fail "[$label] index.html without the transaction-history.js tag refused" php $IDX "$T/none.html" --expect-sha256=$(sha "$T/none.html")
  expect_fail "[$label] transaction-history.js without the anchor refused (matching hash)" bash -c "echo '// other' > '$T/h.js'; php $HST '$T/h.js' --expect-sha256=\$(sha256sum '$T/h.js' | cut -d' ' -f1)"
  expect_fail "[$label] app.css already containing .tx2- refused (matching hash)" bash -c "cp '$P/assets/css/app.css' '$T/dup.css'; echo '.tx2-x{}' >> '$T/dup.css'; php $CSS '$T/dup.css' '$PAY/tx2_app_css_block.css' --expect-sha256=\$(sha256sum '$T/dup.css' | cut -d' ' -f1) --expect-block-sha256=$H_BLK"

  # ---- apply: backend first, then frontend
  inst_new PurchaseInvoiceService.php "$SV/PurchaseInvoiceService.php" $H_S1 --apply >/dev/null && inst_new StockOutService.php "$SV/StockOutService.php" $H_S2 --apply >/dev/null && inst_new StockOutDocumentService.php "$SV/StockOutDocumentService.php" $H_S3 --apply >/dev/null && ok "[$label] apply: 3 services" || bad "[$label] apply services"
  php $PHPR "$P/index.php" --expect-sha256=$H_PHP --apply >/dev/null && ok "[$label] apply: index.php" || bad "[$label] apply php"
  php -l "$P/index.php" >/dev/null 2>&1 && for s in PurchaseInvoiceService StockOutService StockOutDocumentService; do php -l "$SV/$s.php" >/dev/null 2>&1 || bad "[$label] $s syntax"; done && ok "[$label] patched index.php + services are valid PHP" || bad "[$label] php syntax"
  inst_new stock-in-sheet.js "$P/assets/js/stock-in-sheet.js" $H_J1 --apply >/dev/null && inst_new stock-out-sheet.js "$P/assets/js/stock-out-sheet.js" $H_J2 --apply >/dev/null && inst_new transactions.js "$P/assets/js/transactions.js" $H_J0 --replace-expect-sha256=$H_TX --apply >/dev/null && ok "[$label] apply: new JS modules + replace transactions.js" || bad "[$label] apply js"
  php $HST "$P/assets/js/transaction-history.js" --expect-sha256=$H_HIST --apply >/dev/null && ok "[$label] apply: transaction-history.js" || bad "[$label] apply history"
  php $CSS "$P/assets/css/app.css" "$PAY/tx2_app_css_block.css" --expect-sha256=$H_CSS --expect-block-sha256=$H_BLK --apply >/dev/null && ok "[$label] apply: app.css" || bad "[$label] apply css"
  php $IDX "$P/index.html" --expect-sha256=$H_IDX --apply >/dev/null && ok "[$label] apply: index.html" || bad "[$label] apply index"
  node --check "$P/assets/js/transaction-history.js" 2>/dev/null && ok "[$label] patched transaction-history.js is valid JS" || bad "[$label] history syntax"

  # ---- results
  for f in stock-in-sheet.js stock-out-sheet.js transactions.js; do cmp -s "$P/assets/js/$f" "$DEV/$f" && ok "[$label] $f byte-identical to the tested dev file" || bad "[$label] $f differs"; done
  for s in PurchaseInvoiceService StockOutService StockOutDocumentService; do cmp -s "$SV/$s.php" "$DEV/$s.php" && ok "[$label] $s.php byte-identical to the tested dev file" || bad "[$label] $s differs"; done
  [ "$(grep -c "'POST /stock-in' =>" "$P/index.php")" = 1 ] && [ "$(grep -c "'POST /stock-out' =>" "$P/index.php")" = 1 ] && [ "$(grep -c "'GET /stock-out/{id}/print/invoice' =>" "$P/index.php")" = 1 ] && [ "$(grep -c "services/StockOutService.php" "$P/index.php")" = 1 ] && ok "[$label] index.php: routes + requires present exactly once" || bad "[$label] index.php counts"
  diff "$T/pre_index.php" "$P/index.php" | grep '^<' >/dev/null && bad "[$label] index.php: an existing line was removed/changed" || ok "[$label] index.php: additions only"
  diff "$T/pre_app.css" "$P/assets/css/app.css" | grep '^<' >/dev/null && bad "[$label] app.css: an existing line was removed/changed" || ok "[$label] app.css: additions only"
  tail -c "$(wc -c < "$PAY/tx2_app_css_block.css")" "$P/assets/css/app.css" | cmp -s - "$PAY/tx2_app_css_block.css" && ok "[$label] app.css ends with exactly the tested block" || bad "[$label] app.css tail"
  diff "$T/pre_hist.js" "$P/assets/js/transaction-history.js" | grep '^<' | grep -v 'if (actionsRow.children.length) body.appendChild(actionsRow);' >/dev/null && bad "[$label] transaction-history.js: a line other than the anchor changed" || ok "[$label] transaction-history.js: only the anchor line replaced, rest additions"
  grep -q 'transactions.js?v=20261010-tx2' "$P/index.html" && grep -q 'stock-in-sheet.js?v=20261010-tx2' "$P/index.html" && grep -q 'stock-out-sheet.js?v=20261010-tx2' "$P/index.html" && grep -q 'transaction-history.js?v=20261010-tx2' "$P/index.html" && grep -q 'app.css?v=20261010-tx2' "$P/index.html" && ok "[$label] index.html: tokens + 2 new script tags in place" || bad "[$label] index.html tags"
  if [ "$full" = 1 ]; then
    cmp -s "$P/index.php" "$DEV/index.php" && ok "[$label] index.php byte-identical to the tested dev file" || bad "[$label] index.php differs from dev"
    cmp -s "$P/assets/css/app.css" "$DEV/app.css" && ok "[$label] app.css byte-identical to the tested dev file" || bad "[$label] app.css differs from dev"
    cmp -s "$P/assets/js/transaction-history.js" "$DEV/transaction-history.js" && ok "[$label] transaction-history.js byte-identical to the tested dev file" || bad "[$label] transaction-history.js differs"
    diff <(grep -v 'stock-opname-report\.js' "$DEV/index.html") "$P/index.html" >/dev/null && ok "[$label] index.html byte-identical to dev (minus the dev-only stock-opname-report.js tag)" || bad "[$label] index.html differs from dev"
  fi

  # ---- double-apply refusal
  expect_fail "[$label] double-apply refused (index.php)" php $PHPR "$P/index.php" --expect-sha256=$(sha "$P/index.php") --apply
  expect_fail "[$label] double-apply refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  expect_fail "[$label] double-apply refused (app.css)" php $CSS "$P/assets/css/app.css" "$PAY/tx2_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply
  expect_fail "[$label] double-apply refused (transaction-history.js)" php $HST "$P/assets/js/transaction-history.js" --expect-sha256=$(sha "$P/assets/js/transaction-history.js") --apply
  expect_fail "[$label] double-apply refused (transactions.js replace)" php $INST "$PAY/transactions.js" "$P/assets/js/transactions.js" --expect-payload-sha256=$H_J0 --replace-expect-sha256=$H_J0 --apply
  expect_fail "[$label] double-apply refused (service)" php $INST "$PAY/StockOutService.php" "$SV/StockOutService.php" --expect-payload-sha256=$H_S2 --apply

  # ---- rollback: tamper => refuse, change nothing; then restore
  cp "$P/index.php" "$T/index.php.tx2"
  echo "// hand edit" >> "$P/index.php"
  expect_fail "[$label] rollback refuses when a patched file was edited afterwards" php $RB --public-dir="$P" --services-dir="$SV" --apply
  [ -e "$SV/StockOutService.php" ] && [ "$(sha "$P/assets/css/app.css")" != "$H_CSS" ] && ok "[$label] refused rollback changed nothing (two-phase)" || bad "[$label] refused rollback changed something"
  cp "$T/index.php.tx2" "$P/index.php"
  mv "$P/assets/js/transactions.js.tx2-patch.json" "$T/meta.bak"
  expect_fail "[$label] rollback refuses when a state file is missing" php $RB --public-dir="$P" --services-dir="$SV" --apply
  mv "$T/meta.bak" "$P/assets/js/transactions.js.tx2-patch.json"
  php $RB --public-dir="$P" --services-dir="$SV" >/dev/null && ok "[$label] rollback dry-run succeeds" || bad "[$label] rollback dry-run"
  [ -e "$SV/StockOutService.php" ] && ok "[$label] rollback dry-run changed nothing" || bad "[$label] dry-run changed something"
  php $RB --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] rollback --apply succeeds" || bad "[$label] rollback apply"
  [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/assets/js/transactions.js")" = "$H_TX" ] && [ "$(sha "$P/assets/js/transaction-history.js")" = "$H_HIST" ] && ok "[$label] all five files restored byte-exact to the pre-package hashes" || bad "[$label] restore not byte-exact"
  [ ! -e "$SV/StockOutService.php" ] && [ ! -e "$SV/PurchaseInvoiceService.php" ] && [ ! -e "$SV/StockOutDocumentService.php" ] && [ ! -e "$P/assets/js/stock-in-sheet.js" ] && [ ! -e "$P/assets/js/stock-out-sheet.js" ] && ok "[$label] the five new files are removed" || bad "[$label] new files still present"
  expect_fail "[$label] second rollback refused (nothing to roll back)" php $RB --public-dir="$P" --services-dir="$SV" --apply
  if [ "$full" = 1 ]; then
    php scripts/rollback_dashboard_production.php --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] the Dashboard package's own rollback still works afterwards" || bad "[$label] dashboard rollback broken"
    php "$V3/scripts/rollback_jejak_v3_production.php" --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] Jejak v3 rollback still works afterwards" || bad "[$label] v3 rollback broken"
  fi
  php "$V2/scripts/rollback_jejak_production.php" --public-dir="$P" --apply >/dev/null && ok "[$label] Jejak v2 rollback still works afterwards" || bad "[$label] v2 rollback broken"
}

run_tree A 0
run_tree B 1

echo; echo "$pass passed, $fail failed."; [ "$fail" -eq 0 ]
