#!/usr/bin/env bash
# Rehearses the UI2 package (dashboard refinement + collapsible sidebar + Dead Stock from SO) against PRODUCTION-LIKE trees
# (never the live dev tree):
#   tree D = pre-Jejak files + Jejak v2 + v3 + Dashboard package          (production as you confirmed it: dashboard deployed)
#   tree E = tree D + the Stock IN/OUT V2 CSS block + index.html tokens   (if that package is applied first)
# Proves: dry-run writes nothing; preimage / payload / block-hash / exact-once-anchor / double-apply gates; apply;
# only the intended bytes change (CSS: old dashboard block replaced + sidebar block appended, nothing else; index.html: 3 tags);
# the DASH_REV css + tx2 block + this package == the tested dev app.css byte-for-byte; post-apply file check; two-phase rollback.
# Usage: [UI_REV=<commit>] bash tests/ui2_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
BASE_REV="${BASE_REV:-853cd69}"; V2_REV="${V2_REV:-e9072ec}"; V3_REV="${V3_REV:-9dcb367}"; DASH_REV="${DASH_REV:-f4a94e8}"; TX_REV="${TX_REV:-5cdf4f8}"; UI_REV="${UI_REV:-HEAD}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

# ---- package exactly as built
bash scripts/build_ui2_package.sh "$W/out" >/dev/null 2>&1 || { echo "FAIL - package build"; exit 1; }
tar -C "$W" -xzf "$W/out/ui2_production_deploy_package.tar.gz"
PK="$W/ui2_production_deploy_package"; PAY="$PK/payload"; S="$PK/scripts"
( cd "$PK" && sha256sum -c SHA256SUMS >/dev/null 2>&1 ) && ok "package SHA256SUMS verify" || bad "package SHA256SUMS"
H_OLD=$(sha "$PAY/ui2_old_dashboard_block.css"); H_NEW=$(sha "$PAY/ui2_new_dashboard_block.css"); H_SB=$(sha "$PAY/ui2_sidebar_block.css")
H_DJ=$(sha "$PAY/dashboard.js"); H_SJ=$(sha "$PAY/sidebar.js"); H_SV=$(sha "$PAY/DashboardInventoryService.php")
DEV="$W/dev"; mkdir -p "$DEV"
for f in public/assets/js/dashboard.js public/assets/js/sidebar.js services/DashboardInventoryService.php public/assets/css/app.css public/index.html; do git show "$UI_REV:$f" > "$DEV/$(basename "$f")"; done
cmp -s "$PAY/dashboard.js" "$DEV/dashboard.js" && cmp -s "$PAY/sidebar.js" "$DEV/sidebar.js" && cmp -s "$PAY/DashboardInventoryService.php" "$DEV/DashboardInventoryService.php" && ok "payload files byte-identical to the tested dev files" || bad "payload != dev"
git show "$DASH_REV:public/assets/css/app.css" | awk '/^\/\* Dashboard redesign \(dashboard.js\)/{f=1} f' | cmp -s - "$PAY/ui2_old_dashboard_block.css" && ok "old block == the dashboard CSS that was delivered ($DASH_REV)" || bad "old block"
[ "$(grep -c '^/\* UI2 — collapsible sidebar rail' "$PAY/ui2_sidebar_block.css")" = 1 ] && tail -c "$(wc -c < "$PAY/ui2_sidebar_block.css")" "$DEV/app.css" | cmp -s - "$PAY/ui2_sidebar_block.css" && ok "sidebar block == dev app.css tail" || bad "sidebar block"

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


CSS=$S/patch_ui2_app_css_production.php; IDX=$S/patch_ui2_index_html_production.php; INST=$S/install_ui2_files_production.php; RB=$S/rollback_ui2_production.php; CHK=$S/ui2_readonly_check.php
css_args() { echo "$1 $PAY/ui2_old_dashboard_block.css $PAY/ui2_new_dashboard_block.css $PAY/ui2_sidebar_block.css --expect-old-sha256=$H_OLD --expect-new-sha256=$H_NEW --expect-sidebar-sha256=$H_SB"; }

# ---- the CSS identity: delivered dashboard css (+ tx2 block) + this package == dev app.css, byte for byte
IDT="$W/identity"; mkdir -p "$IDT"; git show "$DASH_REV:public/assets/css/app.css" > "$IDT/app.css"
git show "$TX_REV:public/assets/css/app.css" | awk '/^\/\* Stock IN \/ OUT V2 \(transactions.js/{f=1} f' > "$IDT/tx2.css"
php scripts/patch_tx2_app_css_production.php "$IDT/app.css" "$IDT/tx2.css" --expect-sha256=$(sha "$IDT/app.css") --expect-block-sha256=$(sha "$IDT/tx2.css") --apply >/dev/null || bad "identity: tx2 css"
php $CSS $(css_args "$IDT/app.css") --expect-sha256=$(sha "$IDT/app.css") --apply >/dev/null || bad "identity: ui2 css"
cmp -s "$IDT/app.css" "$DEV/app.css" && ok "dashboard css + tx2 block + UI2 patch == tested dev app.css (byte-identical)" || bad "css identity"

run_tree() {
  local label="$1" withtx="$2" T="$W/tree_$1"
  echo; echo "################ tree $label (Stock IN/OUT V2 css+tags applied first = $withtx) ################"
  mk_tree "$T" 1
  git show "$DASH_REV:public/assets/js/sidebar.js" > "$P/assets/js/sidebar.js"
  if [ "$withtx" = 1 ]; then
    php scripts/patch_tx2_app_css_production.php "$P/assets/css/app.css" "$IDT/tx2.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$(sha "$IDT/tx2.css") --apply >/dev/null || bad "[$label] tx2 css"
    php scripts/patch_tx2_index_html_production.php "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "[$label] tx2 index"
  fi
  local H_CSS H_IDX H_DASH H_SIDE H_SVC
  H_CSS=$(sha "$P/assets/css/app.css"); H_IDX=$(sha "$P/index.html"); H_DASH=$(sha "$P/assets/js/dashboard.js"); H_SIDE=$(sha "$P/assets/js/sidebar.js"); H_SVC=$(sha "$SV/DashboardInventoryService.php")
  cp "$P/assets/css/app.css" "$T/pre_app.css"; cp "$P/index.html" "$T/pre_index.html"
  ok "[$label] production-like tree built"
  inst() { php $INST "$PAY/$1" "$2" --expect-payload-sha256=$3 --replace-expect-sha256=$4 "${@:5}"; }

  # ---- dry runs write nothing
  inst DashboardInventoryService.php "$SV/DashboardInventoryService.php" $H_SV $H_SVC >/dev/null && inst dashboard.js "$P/assets/js/dashboard.js" $H_DJ $H_DASH >/dev/null && inst sidebar.js "$P/assets/js/sidebar.js" $H_SJ $H_SIDE >/dev/null && ok "[$label] dry-run: 3 file replacements" || bad "[$label] dry-run installs"
  php $CSS $(css_args "$P/assets/css/app.css") --expect-sha256=$H_CSS >/dev/null && php $IDX "$P/index.html" --expect-sha256=$H_IDX >/dev/null && ok "[$label] dry-run: app.css, index.html" || bad "[$label] dry-run patchers"
  [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/assets/js/dashboard.js")" = "$H_DASH" ] && [ "$(sha "$P/assets/js/sidebar.js")" = "$H_SIDE" ] && [ "$(sha "$SV/DashboardInventoryService.php")" = "$H_SVC" ] && ! ls "$P"/*.pre-ui2-backup "$P"/assets/*/*.pre-ui2-backup "$SV"/*.pre-ui2-backup "$P"/assets/*/*.ui2-patch.json >/dev/null 2>&1 && ok "[$label] dry runs wrote nothing" || bad "[$label] dry runs wrote something"
  expect_fail "[$label] rollback before apply refused" php $RB --public-dir="$P" --services-dir="$SV"

  # ---- fail-closed gates
  expect_fail "[$label] wrong preimage hash refused (app.css)" php $CSS $(css_args "$P/assets/css/app.css") --expect-sha256=$ZERO
  expect_fail "[$label] wrong preimage hash refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$ZERO
  expect_fail "[$label] wrong new-block hash refused" php $CSS "$P/assets/css/app.css" "$PAY/ui2_old_dashboard_block.css" "$PAY/ui2_new_dashboard_block.css" "$PAY/ui2_sidebar_block.css" --expect-sha256=$H_CSS --expect-old-sha256=$H_OLD --expect-new-sha256=$ZERO --expect-sidebar-sha256=$H_SB
  expect_fail "[$label] wrong sidebar-block hash refused" php $CSS "$P/assets/css/app.css" "$PAY/ui2_old_dashboard_block.css" "$PAY/ui2_new_dashboard_block.css" "$PAY/ui2_sidebar_block.css" --expect-sha256=$H_CSS --expect-old-sha256=$H_OLD --expect-new-sha256=$H_NEW --expect-sidebar-sha256=$ZERO
  expect_fail "[$label] replace refused when the target hash is wrong (dashboard.js)" php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_DJ --replace-expect-sha256=$ZERO
  expect_fail "[$label] replace refused when the target hash is wrong (sidebar.js)" php $INST "$PAY/sidebar.js" "$P/assets/js/sidebar.js" --expect-payload-sha256=$H_SJ --replace-expect-sha256=$ZERO
  expect_fail "[$label] wrong payload hash refused" php $INST "$PAY/sidebar.js" "$P/assets/js/sidebar.js" --expect-payload-sha256=$ZERO --replace-expect-sha256=$H_SIDE
  expect_fail "[$label] install without --replace-expect-sha256 refused (this package never creates files)" php $INST "$PAY/sidebar.js" "$T/new.js" --expect-payload-sha256=$H_SJ
  sed 's/^\.dash-kpis { display: grid;/.dash-kpis { display: flex;/' "$P/assets/css/app.css" > "$T/drift.css"
  cmp -s "$T/drift.css" "$P/assets/css/app.css" && bad "[$label] drift fixture did not change" || expect_fail "[$label] app.css whose dashboard block differs from the delivered one refused (matching hash, old block not found)" php $CSS $(css_args "$T/drift.css") --expect-sha256=$(sha "$T/drift.css")
  cp "$P/assets/css/app.css" "$T/dup.css"; cat "$PAY/ui2_old_dashboard_block.css" >> "$T/dup.css"
  expect_fail "[$label] app.css with the old block twice refused" php $CSS $(css_args "$T/dup.css") --expect-sha256=$(sha "$T/dup.css")
  cp "$P/assets/css/app.css" "$T/dz.css"; echo '.x{--dz-kpi:1}' >> "$T/dz.css"
  expect_fail "[$label] app.css already containing --dz-kpi refused" php $CSS $(css_args "$T/dz.css") --expect-sha256=$(sha "$T/dz.css")
  cp "$P/index.html" "$T/dup.html"; grep -F 'sidebar.js?v=' "$P/index.html" >> "$T/dup.html"
  expect_fail "[$label] index.html with the sidebar.js tag twice refused" php $IDX "$T/dup.html" --expect-sha256=$(sha "$T/dup.html")
  grep -v 'dashboard\.js?v=' "$P/index.html" > "$T/none.html"
  expect_fail "[$label] index.html without the dashboard.js tag refused" php $IDX "$T/none.html" --expect-sha256=$(sha "$T/none.html")
  expect_fail "[$label] DB check mode refuses without arguments" php $CHK

  # ---- apply
  inst DashboardInventoryService.php "$SV/DashboardInventoryService.php" $H_SV $H_SVC --apply >/dev/null && ok "[$label] apply: service" || bad "[$label] apply service"
  php -l "$SV/DashboardInventoryService.php" >/dev/null 2>&1 && ok "[$label] patched service is valid PHP" || bad "[$label] service syntax"
  inst dashboard.js "$P/assets/js/dashboard.js" $H_DJ $H_DASH --apply >/dev/null && inst sidebar.js "$P/assets/js/sidebar.js" $H_SJ $H_SIDE --apply >/dev/null && ok "[$label] apply: dashboard.js + sidebar.js" || bad "[$label] apply js"
  php $CSS $(css_args "$P/assets/css/app.css") --expect-sha256=$H_CSS --apply >/dev/null && ok "[$label] apply: app.css" || bad "[$label] apply css"
  php $IDX "$P/index.html" --expect-sha256=$H_IDX --apply >/dev/null && ok "[$label] apply: index.html" || bad "[$label] apply index"
  node --check "$P/assets/js/dashboard.js" 2>/dev/null && node --check "$P/assets/js/sidebar.js" 2>/dev/null && ok "[$label] patched JS is valid" || bad "[$label] js syntax"

  # ---- results
  cmp -s "$P/assets/js/dashboard.js" "$DEV/dashboard.js" && cmp -s "$P/assets/js/sidebar.js" "$DEV/sidebar.js" && cmp -s "$SV/DashboardInventoryService.php" "$DEV/DashboardInventoryService.php" && ok "[$label] 3 replaced files byte-identical to the tested dev files" || bad "[$label] replaced files differ"
  python3 - "$T/pre_app.css" "$P/assets/css/app.css" "$PAY" <<'PY' && ok "[$label] app.css: ONLY the old dashboard block was replaced and the sidebar block appended (everything else byte-identical)" || bad "[$label] app.css structure"
import sys
pre, post, pay = open(sys.argv[1], encoding='utf-8').read(), open(sys.argv[2], encoding='utf-8').read(), sys.argv[3]
old, new, sb = (open(f'{pay}/{n}', encoding='utf-8').read() for n in ('ui2_old_dashboard_block.css', 'ui2_new_dashboard_block.css', 'ui2_sidebar_block.css'))
assert pre.count(old) == 1
exp = pre.replace(old, new) + ('' if pre.endswith('\n') else '\n') + '\n' + sb
assert post == exp, 'css differs from expectation'
assert old not in post and post.count('/* UI2 — collapsible sidebar rail') == 1 and post.count('--dz-kpi:') >= 1
PY
  diff "$T/pre_index.html" "$P/index.html" | grep '^[<>]' | wc -l | grep -qx 6 && diff "$T/pre_index.html" "$P/index.html" | grep '^>' | grep -c '20261011-ui2' | grep -qx 3 && ok "[$label] index.html: exactly 3 tags changed (app.css, sidebar.js, dashboard.js -> 20261011-ui2)" || bad "[$label] index.html diff"
  php $CHK --public-dir="$P" --services-dir="$SV" --files >"$W/files.txt" 2>&1 && ok "[$label] post-apply file check passes ($(tail -1 "$W/files.txt"))" || { bad "[$label] post-apply file check"; cat "$W/files.txt"; }

  # ---- double-apply refusal
  expect_fail "[$label] double-apply refused (app.css)" php $CSS $(css_args "$P/assets/css/app.css") --expect-sha256=$(sha "$P/assets/css/app.css") --apply
  expect_fail "[$label] double-apply refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  expect_fail "[$label] double-apply refused (dashboard.js, state file present)" php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_DJ --replace-expect-sha256=$(sha "$P/assets/js/dashboard.js") --apply

  # ---- rollback: tamper refusal first (changes nothing), then the real thing
  cp "$P/assets/js/sidebar.js" "$T/sb_post.js"; echo '// edited after the package' >> "$P/assets/js/sidebar.js"
  local snap; snap=$(cat "$P/assets/css/app.css" "$P/index.html" "$P/assets/js/dashboard.js" "$SV/DashboardInventoryService.php" | sha256sum)
  expect_fail "[$label] rollback refused when a file was edited after the package" php $RB --public-dir="$P" --services-dir="$SV" --apply
  [ "$(cat "$P/assets/css/app.css" "$P/index.html" "$P/assets/js/dashboard.js" "$SV/DashboardInventoryService.php" | sha256sum)" = "$snap" ] && ok "[$label] the refused rollback changed nothing (two-phase)" || bad "[$label] refused rollback changed files"
  cp "$T/sb_post.js" "$P/assets/js/sidebar.js"
  php $RB --public-dir="$P" --services-dir="$SV" >/dev/null && ok "[$label] rollback dry-run passes" || bad "[$label] rollback dry-run"
  [ "$(sha "$P/assets/js/sidebar.js")" = "$H_SJ" ] && ok "[$label] dry-run rollback wrote nothing" || bad "[$label] dry-run rollback wrote"
  php $RB --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "[$label] rollback applied" || bad "[$label] rollback apply"
  [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/assets/js/dashboard.js")" = "$H_DASH" ] && [ "$(sha "$P/assets/js/sidebar.js")" = "$H_SIDE" ] && [ "$(sha "$SV/DashboardInventoryService.php")" = "$H_SVC" ] && ok "[$label] rollback restored all 5 files byte-exactly" || bad "[$label] rollback bytes"
  ! ls "$P"/*.ui2-patch.json "$P"/assets/*/*.ui2-patch.json "$SV"/*.ui2-patch.json >/dev/null 2>&1 && ok "[$label] state files removed (backups kept)" || bad "[$label] state files remain"
  expect_fail "[$label] second rollback refused (nothing to roll back)" php $RB --public-dir="$P" --services-dir="$SV"
  # re-apply after rollback needs the leftover backups moved away (documented behaviour: refuses over a previous run)
  expect_fail "[$label] re-apply over leftover backups refused until they are moved away" php $INST "$PAY/dashboard.js" "$P/assets/js/dashboard.js" --expect-payload-sha256=$H_DJ --replace-expect-sha256=$H_DASH --apply
}

run_tree D 0
run_tree E 1

# ---- readme + collect script sanity
grep -q "$H_DJ" "$PK/README_DEPLOY_ui2.md" && grep -q "$H_SJ" "$PK/README_DEPLOY_ui2.md" && grep -q "$H_SV" "$PK/README_DEPLOY_ui2.md" && grep -q "$H_OLD" "$PK/README_DEPLOY_ui2.md" && ! grep -q '@@' "$PK/README_DEPLOY_ui2.md" "$PK/collect_production_hashes_ui2.sh" && ok "README + collect script carry the real hashes (no unreplaced placeholders)" || bad "README placeholders"
bash -n "$PK/collect_production_hashes_ui2.sh" && ok "collect script is valid bash" || bad "collect syntax"

echo; echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
