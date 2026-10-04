#!/usr/bin/env bash
# Rehearses the Jejak v3 production package against a PRODUCTION-LIKE tree that
# is in the state production is in NOW: the pre-Jejak files + the Jejak v2
# package applied (v2 scripts/payload are taken from git commit $V2_REV, exactly
# as they were delivered). Never touches the live dev working tree.
#
# Proves: v3 refuses a pre-v2 tree, preimage-hash + anchor + double-apply gates,
# dry-run writes nothing, apply, byte-equality with the tested dev files, the
# two-phase v3 rollback restoring the v2 state byte-exactly, and that v2's own
# rollback still works afterwards (the two packages chain cleanly).
#
# Usage: bash tests/jejak_v3_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
BASE_REV="${BASE_REV:-853cd69}"     # pre-Jejak production-like files
V2_REV="${V2_REV:-e9072ec}"         # the commit the v2 package was built from
W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT
P="$W/public"; SV="$W/services"; V2="$W/v2pkg"
mkdir -p "$P/assets/js" "$P/assets/css" "$SV" "$V2/scripts/lib" "$V2/payload"
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

# ---- pre-Jejak production-like files
git show "$BASE_REV:public/assets/js/report-opname.js" > "$P/assets/js/report-opname.js"
git show "$BASE_REV:public/assets/css/app.css" > "$P/assets/css/app.css"
git show "$BASE_REV:public/index.html" | grep -v 'stock-opname-report\.js' > "$P/index.html"
git show "$V2_REV:public/index.php" > "$P/index.php"
cp "$P/assets/css/app.css" "$W/preV2_app.css"
printf '<?php // placeholder service dir\n' > "$SV/Database.php"

# ---- apply the v2 package exactly as delivered
for f in lib/jejak_patch_common.php patch_report_opname_jejak_row_click_production.php patch_app_css_jejak_drawer_xl_production.php patch_index_html_jejak_script_tag_production.php install_stock_opname_report_jejak_production.php rollback_jejak_production.php; do
  git show "$V2_REV:scripts/$f" > "$V2/scripts/$f"
done
git show "$V2_REV:public/assets/js/stock-opname-report-jejak.js" > "$V2/payload/stock-opname-report-jejak.js"
V2_PAYLOAD_SHA=$(sha "$V2/payload/stock-opname-report-jejak.js")
[ "$V2_PAYLOAD_SHA" = "6e2c449bd40e2e2282b6d6ad44e4759b0785e5a3e194556f621a093238c4fefc" ] && ok "v2 payload from git = the SHA delivered to production (6e2c449b…)" || bad "v2 payload SHA differs: $V2_PAYLOAD_SHA"
php "$V2/scripts/patch_app_css_jejak_drawer_xl_production.php" "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply >/dev/null || bad "v2 css apply"
php "$V2/scripts/patch_report_opname_jejak_row_click_production.php" "$P/assets/js/report-opname.js" --expect-sha256=$(sha "$P/assets/js/report-opname.js") --apply >/dev/null || bad "v2 js apply"
php "$V2/scripts/patch_index_html_jejak_script_tag_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply >/dev/null || bad "v2 index apply"
php "$V2/scripts/install_stock_opname_report_jejak_production.php" "$V2/payload/stock-opname-report-jejak.js" "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$V2_PAYLOAD_SHA --apply >/dev/null || bad "v2 install"
H_JS=$(sha "$P/assets/js/stock-opname-report-jejak.js"); H_CSS=$(sha "$P/assets/css/app.css"); H_IDX=$(sha "$P/index.html"); H_PHP=$(sha "$P/index.php")
[ "$H_JS" = "$V2_PAYLOAD_SHA" ] && ok "production-like tree is in the post-Jejak-v2 state" || bad "tree not in v2 state"

JS_NEW=public/assets/js/stock-opname-report-jejak.js
SVC_NEW=services/StockOpnameJejakService.php
H_JS_NEW=$(sha "$JS_NEW"); H_SVC_NEW=$(sha "$SVC_NEW")
CSS=scripts/patch_jejak_v3_app_css_production.php; IDX=scripts/patch_jejak_v3_index_html_production.php; PHPR=scripts/patch_jejak_v3_index_php_route_production.php
INST=scripts/install_jejak_v3_files_production.php; RB=scripts/rollback_jejak_v3_production.php

# ---- v3 must refuse a PRE-v2 tree (anchors absent) even with a matching hash
expect_fail "v3 css patcher refuses the pre-v2 (original) app.css" php $CSS "$W/preV2_app.css" --expect-sha256=$(sha "$W/preV2_app.css")
expect_fail "v3 index patcher refuses an index.html without the v2 tokens" php $IDX "$W/preV2_app.css" --expect-sha256=$(sha "$W/preV2_app.css")

# ---- dry runs write nothing
php $INST $JS_NEW "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_JS_NEW --replace-expect-sha256=$H_JS >/dev/null && ok "dry-run: replace jejak.js" || bad "dry-run replace"
php $INST $SVC_NEW "$SV/StockOpnameJejakService.php" --expect-payload-sha256=$H_SVC_NEW >/dev/null && ok "dry-run: install service (new file)" || bad "dry-run service"
php $CSS "$P/assets/css/app.css" --expect-sha256=$H_CSS >/dev/null && ok "dry-run: app.css" || bad "dry-run css"
php $IDX "$P/index.html" --expect-sha256=$H_IDX >/dev/null && ok "dry-run: index.html" || bad "dry-run index.html"
php $PHPR "$P/index.php" --expect-sha256=$H_PHP >/dev/null && ok "dry-run: index.php" || bad "dry-run index.php"
[ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/assets/js/stock-opname-report-jejak.js")" = "$H_JS" ] && [ ! -e "$SV/StockOpnameJejakService.php" ] && ! ls "$P"/*.pre-v3-backup "$P"/assets/*/*.pre-v3-backup >/dev/null 2>&1 && ok "dry runs wrote nothing" || bad "dry runs wrote something"

# ---- fail-closed gates
expect_fail "wrong preimage hash refused (app.css)" php $CSS "$P/assets/css/app.css" --expect-sha256=$ZERO
expect_fail "wrong preimage hash refused (index.php)" php $PHPR "$P/index.php" --expect-sha256=$ZERO
expect_fail "replace of jejak.js refused when its hash is not the v2 payload" php $INST $JS_NEW "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_JS_NEW --replace-expect-sha256=$ZERO
expect_fail "wrong payload hash refused" php $INST $JS_NEW "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$ZERO --replace-expect-sha256=$H_JS
expect_fail "service install refused over an existing file" bash -c "cp $SVC_NEW '$W/svc_existing.php'; php $INST $SVC_NEW '$W/svc_existing.php' --expect-payload-sha256=$H_SVC_NEW"
sed 's/^require_once __DIR__ . .\/..\/services\/StockOpnameReportService.php.;$/\/\/ drifted/' "$P/index.php" > "$W/drift.php"
expect_fail "index.php without the StockOpnameReportService require anchor refused (even with matching hash)" php $PHPR "$W/drift.php" --expect-sha256=$(sha "$W/drift.php")
cp "$P/index.php" "$W/dup.php"; grep -F "'GET /reports/opname/{id}' => function (array \$params) use (\$pdo, \$query) {" "$P/index.php" >> "$W/dup.php"
expect_fail "index.php with the route anchor twice refused" php $PHPR "$W/dup.php" --expect-sha256=$(sha "$W/dup.php")
sed 's/20261007-jejak2/20260101-other/g' "$P/index.html" > "$W/tok.html"
expect_fail "index.html whose tokens are not the v2 token refused" php $IDX "$W/tok.html" --expect-sha256=$(sha "$W/tok.html")

# ---- apply: backend first, then frontend (the order the README prescribes)
php $INST $SVC_NEW "$SV/StockOpnameJejakService.php" --expect-payload-sha256=$H_SVC_NEW --apply >/dev/null && ok "apply: install service" || bad "apply service"
php $PHPR "$P/index.php" --expect-sha256=$H_PHP --apply >/dev/null && ok "apply: index.php route" || bad "apply index.php"
php -l "$P/index.php" >/dev/null 2>&1 && ok "patched index.php is valid PHP" || bad "patched index.php syntax"
php $INST $JS_NEW "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_JS_NEW --replace-expect-sha256=$H_JS --apply >/dev/null && ok "apply: replace jejak.js" || bad "apply jejak.js"
php $CSS "$P/assets/css/app.css" --expect-sha256=$H_CSS --apply >/dev/null && ok "apply: app.css" || bad "apply css"
php $IDX "$P/index.html" --expect-sha256=$H_IDX --apply >/dev/null && ok "apply: index.html" || bad "apply index.html"

# ---- patched production-like files == the tested dev files
cmp -s "$P/assets/css/app.css" public/assets/css/app.css && ok "patched app.css is byte-identical to the tested dev file" || bad "app.css differs from dev"
cmp -s "$P/assets/js/stock-opname-report-jejak.js" $JS_NEW && ok "patched jejak.js is byte-identical to the tested dev file" || bad "jejak.js differs"
cmp -s "$P/index.php" public/index.php && ok "patched index.php is byte-identical to the tested dev file" || bad "index.php differs from dev"
cmp -s "$SV/StockOpnameJejakService.php" $SVC_NEW && ok "installed service is byte-identical to the tested dev file" || bad "service differs"
diff <(grep -v 'stock-opname-report\.js' public/index.html) "$P/index.html" >/dev/null && ok "patched index.html is byte-identical to the dev file (minus the dev-only stock-opname-report.js tag)" || bad "index.html differs from dev"
grep -q 'stock-opname-report-jejak.js?v=20261008-jejak3' "$P/index.html" && grep -q 'app.css?v=20261008-jejak3' "$P/index.html" && grep -q 'report-opname.js?v=20261007-jejak2' "$P/index.html" && ok "index.html: jejak+css tokens bumped to jejak3, report-opname.js token untouched" || bad "index.html tokens"
[ "$(grep -c "reports/opname/{id}/jejak" "$P/index.php")" = 1 ] && [ "$(grep -c "StockOpnameJejakService.php" "$P/index.php")" = 1 ] && ok "index.php: exactly one new route and one new require" || bad "index.php counts"
[ "$(wc -l < "$P/index.php")" -gt "$(git show "$V2_REV:public/index.php" | wc -l)" ] && [ "$(( $(wc -l < "$P/index.php") - $(git show "$V2_REV:public/index.php" | wc -l) ))" = "$(( $(wc -l < public/index.php) - $(git show "$V2_REV:public/index.php" | wc -l) ))" ] && ok "index.php: ONLY additions (line delta equals the route + require)" || bad "index.php delta"
diff <(git show "$V2_REV:public/index.php") "$P/index.php" | grep '^<' >/dev/null && bad "index.php: an existing line was removed/changed" || ok "index.php: no existing line was removed or changed (diff has additions only)"

# ---- double-apply refusal
expect_fail "double-apply refused (app.css)" php $CSS "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply
expect_fail "double-apply refused (index.html)" php $IDX "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
expect_fail "double-apply refused (index.php)" php $PHPR "$P/index.php" --expect-sha256=$(sha "$P/index.php") --apply
expect_fail "double-apply refused (jejak.js replace)" php $INST $JS_NEW "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_JS_NEW --replace-expect-sha256=$H_JS_NEW --apply
expect_fail "double-apply refused (service)" php $INST $SVC_NEW "$SV/StockOpnameJejakService.php" --expect-payload-sha256=$H_SVC_NEW --apply
expect_fail "stale v3 backup blocks a re-apply against the ORIGINAL hash" bash -c "cp '$W/preV2_app.css' '$W/re.css'; cp '$P/assets/css/app.css.pre-v3-backup' '$W/re.css.pre-v3-backup'; php $CSS '$W/re.css' --expect-sha256=\$(sha256sum '$W/re.css' | cut -d' ' -f1) --apply"

# ---- v2 state files untouched by v3
[ -e "$P/assets/css/app.css.pre-patch-backup" ] && [ -e "$P/index.html.jejak-patch.json" ] && ok "v2 backups/meta still present beside the v3 ones" || bad "v2 state files missing"

# ---- rollback: tamper => refuse, change nothing
cp "$P/index.php" "$W/index.php.v3"
echo "// hand edit" >> "$P/index.php"
expect_fail "v3 rollback refuses when a patched file was edited afterwards" php $RB --public-dir="$P" --services-dir="$SV" --apply
[ -e "$SV/StockOpnameJejakService.php" ] && [ "$(sha "$P/assets/css/app.css")" != "$H_CSS" ] && ok "refused rollback changed nothing (two-phase)" || bad "refused rollback changed something"
cp "$W/index.php.v3" "$P/index.php"
rm "$P/assets/js/stock-opname-report-jejak.js.jejak-v3-patch.json"
expect_fail "v3 rollback refuses when a v3 state file is missing" php $RB --public-dir="$P" --services-dir="$SV" --apply
printf '{"target":"x"}' > "$W/dummy"; # restore the removed meta by re-deriving it from the backup/current hashes
php -r 'file_put_contents($argv[1], json_encode(["target"=>"stock-opname-report-jejak.js","preimage_sha256"=>$argv[2],"postimage_sha256"=>$argv[3]]));' "$P/assets/js/stock-opname-report-jejak.js.jejak-v3-patch.json" "$H_JS" "$H_JS_NEW"

# ---- rollback
php $RB --public-dir="$P" --services-dir="$SV" >/dev/null && ok "v3 rollback dry-run succeeds" || bad "rollback dry-run"
[ -e "$SV/StockOpnameJejakService.php" ] && ok "dry-run changed nothing" || bad "dry-run changed something"
php $RB --public-dir="$P" --services-dir="$SV" --apply >/dev/null && ok "v3 rollback --apply succeeds" || bad "rollback apply"
[ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && [ "$(sha "$P/index.php")" = "$H_PHP" ] && [ "$(sha "$P/assets/js/stock-opname-report-jejak.js")" = "$H_JS" ] && ok "all four files restored byte-exact to the post-Jejak-v2 hashes" || bad "restore not byte-exact"
[ ! -e "$SV/StockOpnameJejakService.php" ] && ok "new service file removed" || bad "service still present"
expect_fail "second v3 rollback refused (nothing to roll back)" php $RB --public-dir="$P" --services-dir="$SV" --apply

# ---- chain integrity: v2's own rollback still works after the v3 rollback
php "$V2/scripts/rollback_jejak_production.php" --public-dir="$P" >/dev/null && ok "v2 rollback dry-run still works on the restored tree" || bad "v2 rollback broken"
php "$V2/scripts/rollback_jejak_production.php" --public-dir="$P" --apply >/dev/null && cmp -s "$P/assets/css/app.css" "$W/preV2_app.css" && ok "v2 rollback --apply restores the ORIGINAL pre-Jejak app.css byte-exact" || bad "v2 rollback apply"

echo; echo "$pass passed, $fail failed."; [ "$fail" -eq 0 ]
