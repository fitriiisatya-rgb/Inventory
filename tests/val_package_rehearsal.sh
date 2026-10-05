#!/usr/bin/env bash
# Rehearses the Laporan Nilai Stok & HPP (dual valuation) package against PRODUCTION-LIKE trees (never the live dev tree): the four target files exactly as they were at three delivered
# revisions + a variant whose ?v= tokens another package moved + PRODLAYOUT (the real production layout: MVR tokens, api-client-v2163eod.js next to api-client.js, Jejak tags).
# Proves, per tree: dry-run writes nothing; wrong preimage / payload hash, a missing or duplicated anchor, an existing NEW-file target and a double apply are all refused; backend-first apply;
# ONLY the intended bytes change (index.php: 1 require + helper + routes; app.js: ONE line; css: block appended; index.html: new tag after the report-hpp.js tag + app.css/app.js tokens; the
# report-hpp.js tag, the api-client tags (MVR token included) and every other line byte-identical); post-apply file check; two-phase rollback (refuses on an edited target, then restores the exact
# bytes and deletes the two files the package created); collect script reports the documented anchor counts.
# Usage: [VAL_REV=<commit>] [VAL_BASE=<commit>] bash tests/val_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
VAL_REV="${VAL_REV:-a6a2515}"; VAL_BASE="${VAL_BASE:-55fc7f8}"
OLD_REVS="${OLD_REVS:-9dcb367 6775b7f}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

VAL_REV="$VAL_REV" VAL_BASE="$VAL_BASE" bash scripts/build_val_package.sh "$W/out" >/dev/null 2>&1 || { echo "FAIL - package build"; exit 1; }
tar -C "$W" -xzf "$W/out/val_production_deploy_package.tar.gz"
PK="$W/val_production_deploy_package"; PAY="$PK/payload"; S="$PK/scripts"
( cd "$PK" && sha256sum -c SHA256SUMS >/dev/null 2>&1 ) && ok "package SHA256SUMS verify" || bad "package SHA256SUMS"
! grep -rq "assets/js/api-client" "$S"/patch_val_*.php "$S"/install_val_files_production.php "$S"/rollback_val_production.php "$S"/val_readonly_check.php && ok "the package does not touch api-client.js / api-client-v2163eod.js (no patcher, no target)" || bad "package references api-client"
DEV="$W/dev"; mkdir -p "$DEV"
for f in services/InventoryValuationService.php public/index.php public/assets/css/app.css public/assets/js/report-valuation.js; do git show "$VAL_REV:$f" > "$DEV/$(basename "$f")"; done
cmp -s "$PAY/InventoryValuationService.php" "$DEV/InventoryValuationService.php" && cmp -s "$PAY/report-valuation.js" "$DEV/report-valuation.js" && ok "service + report-valuation.js payloads byte-identical to the tested dev files" || bad "payload != dev"
! grep -q "InvApi\." "$PAY/report-valuation.js" && grep -q "const ValApi" "$PAY/report-valuation.js" && ok "report-valuation.js is self-contained (own read-only GET helper, no InvApi dependency)" || bad "report js depends on InvApi"
tail -c "$(wc -c < "$PAY/val_app_css_block.css")" "$DEV/app.css" | cmp -s - "$PAY/val_app_css_block.css" && [ "$(grep -c '^/\* Nilai Stok & HPP dual valuation (report-valuation.js' "$PAY/val_app_css_block.css")" = 1 ] && ok "css block == dev app.css tail (single marker, ends at EOF)" || bad "css block"
[ "$(grep -c "'GET /reports/inventory-valuation[a-z/]*' =>" "$PAY/val_index_php_routes.txt")" = 3 ] && ! grep -q "'GET /reports/inventory-hpp" "$PAY/val_index_php_routes.txt" && ok "routes payload = the three new GET routes only" || bad "routes payload"
[ "$(grep -c '^function ' "$PAY/val_index_php_helper.txt")" = 1 ] && grep -q "^function inv_val_filters" "$PAY/val_index_php_helper.txt" && ok "helper payload = the one inv_val_filters helper only" || bad "helper payload"
! grep -qE "'(POST|PUT|PATCH|DELETE) " "$PAY/val_index_php_routes.txt" && ok "no write route in the payload (all GET)" || bad "write route in payload"
H_SVC=$(sha "$PAY/InventoryValuationService.php"); H_JS=$(sha "$PAY/report-valuation.js"); H_BLK=$(sha "$PAY/val_app_css_block.css"); H_HLP=$(sha "$PAY/val_index_php_helper.txt"); H_RT=$(sha "$PAY/val_index_php_routes.txt")
OLDL="ReportHpp.render(document.getElementById('tab-laporan-hpp'));"; NEWL="ReportValuation.render(document.getElementById('tab-laporan-hpp'));"

run_tree() {
  local label="$1" rev="$2" variant="${3:-plain}" T="$W/tree_$1"
  P="$T/public"; SV="$T/services"; mkdir -p "$P/assets/js" "$P/assets/css" "$SV" "$T/pre"
  git show "$rev:public/index.php" > "$P/index.php"; git show "$rev:public/index.html" > "$P/index.html"
  git show "$rev:public/assets/css/app.css" > "$P/assets/css/app.css"; git show "$rev:public/assets/js/app.js" > "$P/assets/js/app.js"
  [ "$variant" = tokens ] && sed -i -E 's/\?v=[A-Za-z0-9._-]+/?v=20261099-later/g' "$P/index.html"
  if [ "$variant" = prod ]; then
    python3 - "$P/index.html" <<'PY'
import re, sys
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
s, n = re.subn(r'<script src="assets/js/api-client\.js\?v=[^"]+"></script>', '<script src="assets/js/api-client-v2163eod.js?v=stabilfix-20261004091227"></script>\n<script src="assets/js/api-client.js?v=20261013-mvr"></script>', s); assert n == 1
s, n = re.subn(r'(href="assets/css/app\.css\?v=)[^"]+', r'\g<1>20261013-mvr', s); assert n == 1
open(p, 'w', encoding='utf-8', newline='').write(s)
PY
  fi
  cp "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/app.js" "$T/pre/"
  local hP hH hC hA; hP=$(sha "$P/index.php"); hH=$(sha "$P/index.html"); hC=$(sha "$P/assets/css/app.css"); hA=$(sha "$P/assets/js/app.js")
  local JS="$P/assets/js/report-valuation.js" SVT="$SV/InventoryValuationService.php"
  local PHP_ARGS="$PAY/val_index_php_helper.txt $PAY/val_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$H_RT"
  local HPPTAG; HPPTAG=$(grep -o '<script src="assets/js/report-hpp.js?v=[A-Za-z0-9._-]*"></script>' "$P/index.html")
  [ -n "$HPPTAG" ] && ok "[$label] the tree has the report-hpp.js tag ($HPPTAG)" || bad "[$label] no report-hpp.js tag"
  local dry=1
  php "$S/install_val_files_production.php" "$PAY/InventoryValuationService.php" "$SVT" --expect-payload-sha256=$H_SVC >/dev/null 2>&1 || dry=0
  php "$S/patch_val_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$hP >/dev/null 2>&1 || dry=0
  php "$S/install_val_files_production.php" "$PAY/report-valuation.js" "$JS" --expect-payload-sha256=$H_JS >/dev/null 2>&1 || dry=0
  php "$S/patch_val_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$hA >/dev/null 2>&1 || dry=0
  php "$S/patch_val_app_css_production.php" "$P/assets/css/app.css" "$PAY/val_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$H_BLK >/dev/null 2>&1 || dry=0
  php "$S/patch_val_index_html_production.php" "$P/index.html" --expect-sha256=$hH >/dev/null 2>&1 || dry=0
  [ $dry = 1 ] && [ "$(sha "$P/index.php")" = "$hP" ] && [ "$(sha "$P/index.html")" = "$hH" ] && [ ! -e "$SVT" ] && [ ! -e "$JS" ] && ! ls "$P"/*.val-patch.json "$P"/assets/*/*.val-patch.json "$P"/assets/*/*.pre-val-backup >/dev/null 2>&1 && ok "[$label] all six dry-runs succeed and write nothing" || bad "[$label] dry-runs (rc=$dry)"
  expect_fail "[$label] index.php: wrong preimage hash refused" php "$S/patch_val_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$ZERO
  expect_fail "[$label] index.php: wrong routes-payload hash refused" php "$S/patch_val_index_php_production.php" "$P/index.php" $PAY/val_index_php_helper.txt $PAY/val_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$ZERO --expect-sha256=$hP
  python3 - "$P/index.php" "$T/noanchor.php" "$T/dup.php" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
a = "    'GET /reports/inventory-hpp/summary' => function () use ($pdo, $query) {\n"
assert s.count(a) == 1
open(sys.argv[2], 'w', encoding='utf-8', newline='').write(s.replace(a, "    'GET /reports/inventory-hpp/summary-x' => function () use ($pdo, $query) {\n"))
open(sys.argv[3], 'w', encoding='utf-8', newline='').write(s.replace(a, a + a))
PY
  expect_fail "[$label] index.php: missing route anchor refused" php "$S/patch_val_index_php_production.php" "$T/noanchor.php" $PHP_ARGS --expect-sha256=$(sha "$T/noanchor.php")
  expect_fail "[$label] index.php: duplicated route anchor refused" php "$S/patch_val_index_php_production.php" "$T/dup.php" $PHP_ARGS --expect-sha256=$(sha "$T/dup.php")
  sed "s#require_once __DIR__ . ./../services/InventoryHppReportService.php.;##" "$P/index.php" > "$T/norequire.php"
  expect_fail "[$label] index.php: missing require anchor refused" php "$S/patch_val_index_php_production.php" "$T/norequire.php" $PHP_ARGS --expect-sha256=$(sha "$T/norequire.php")
  expect_fail "[$label] app.js: wrong preimage refused" php "$S/patch_val_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$ZERO
  grep -vF "$OLDL" "$P/assets/js/app.js" > "$T/noapp.js"
  expect_fail "[$label] app.js: missing route line refused" php "$S/patch_val_app_js_production.php" "$T/noapp.js" --expect-sha256=$(sha "$T/noapp.js")
  { cat "$P/assets/js/app.js"; echo "    $OLDL"; } > "$T/dupapp.js"
  expect_fail "[$label] app.js: duplicated route line refused" php "$S/patch_val_app_js_production.php" "$T/dupapp.js" --expect-sha256=$(sha "$T/dupapp.js")
  { cat "$P/assets/js/app.js"; echo "    $NEWL"; } > "$T/doneapp.js"
  expect_fail "[$label] app.js: already re-pointed refused" php "$S/patch_val_app_js_production.php" "$T/doneapp.js" --expect-sha256=$(sha "$T/doneapp.js")
  expect_fail "[$label] report-valuation.js: wrong payload hash refused" php "$S/install_val_files_production.php" "$PAY/report-valuation.js" "$JS" --expect-payload-sha256=$ZERO
  echo stale > "$JS"
  expect_fail "[$label] report-valuation.js: an EXISTING target is never overwritten" php "$S/install_val_files_production.php" "$PAY/report-valuation.js" "$JS" --expect-payload-sha256=$H_JS --apply
  rm -f "$JS"
  expect_fail "[$label] app.css: wrong block hash refused" php "$S/patch_val_app_css_production.php" "$P/assets/css/app.css" "$PAY/val_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$ZERO
  { cat "$P/assets/css/app.css"; echo ".val-x { color: red; }"; } > "$T/valcss.css"
  expect_fail "[$label] app.css: a pre-existing .val- selector (collision) refused" php "$S/patch_val_app_css_production.php" "$T/valcss.css" "$PAY/val_app_css_block.css" --expect-sha256=$(sha "$T/valcss.css") --expect-block-sha256=$H_BLK
  expect_fail "[$label] index.html: wrong preimage refused" php "$S/patch_val_index_html_production.php" "$P/index.html" --expect-sha256=$ZERO
  sed -E 's#<script src="assets/js/report-hpp\.js\?v=[^"]*"></script>##' "$P/index.html" > "$T/nohpp.html"
  expect_fail "[$label] index.html: MISSING report-hpp.js tag refused" php "$S/patch_val_index_html_production.php" "$T/nohpp.html" --expect-sha256=$(sha "$T/nohpp.html")
  { cat "$P/index.html"; echo "$HPPTAG"; } > "$T/duphpp.html"
  expect_fail "[$label] index.html: DUPLICATED report-hpp.js tag refused" php "$S/patch_val_index_html_production.php" "$T/duphpp.html" --expect-sha256=$(sha "$T/duphpp.html")
  { cat "$P/index.html"; echo '<script src="assets/js/report-valuation.js?v=a"></script>'; echo '<script src="assets/js/report-valuation.js?v=b"></script>'; } > "$T/twoval.html"
  expect_fail "[$label] index.html: two report-valuation.js tags refused" php "$S/patch_val_index_html_production.php" "$T/twoval.html" --expect-sha256=$(sha "$T/twoval.html")
  expect_fail "[$label] rollback before apply refused (no state files)" php "$S/rollback_val_production.php" --public-dir="$P" --services-dir="$SV"
  echo "x" > "$SVT"
  expect_fail "[$label] service install refuses an EXISTING target" php "$S/install_val_files_production.php" "$PAY/InventoryValuationService.php" "$SVT" --expect-payload-sha256=$H_SVC --apply
  rm -f "$SVT"
  php "$S/install_val_files_production.php" "$PAY/InventoryValuationService.php" "$SVT" --expect-payload-sha256=$H_SVC --apply >/dev/null 2>&1 && ok "[$label] apply 1/6: service installed" || bad "[$label] apply service"
  php "$S/patch_val_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$hP --apply >"$W/a2.txt" 2>&1 && php -l "$P/index.php" >/dev/null 2>&1 && ok "[$label] apply 2/6: index.php patched, php -l clean" || { bad "[$label] apply index.php"; cat "$W/a2.txt"; }
  php "$S/install_val_files_production.php" "$PAY/report-valuation.js" "$JS" --expect-payload-sha256=$H_JS --apply >/dev/null 2>&1 && ok "[$label] apply 3/6: report-valuation.js created" || bad "[$label] apply js"
  php "$S/patch_val_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$hA --apply >/dev/null 2>&1 && ok "[$label] apply 4/6: app.js route re-pointed" || bad "[$label] apply app.js"
  php "$S/patch_val_app_css_production.php" "$P/assets/css/app.css" "$PAY/val_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$H_BLK --apply >/dev/null 2>&1 && ok "[$label] apply 5/6: app.css block appended" || bad "[$label] apply css"
  php "$S/patch_val_index_html_production.php" "$P/index.html" --expect-sha256=$hH --apply >/dev/null 2>&1 && ok "[$label] apply 6/6: index.html (last)" || bad "[$label] apply html"
  python3 - "$T/pre" "$P" "$PAY" "$SV" <<'PY' && ok "[$label] bytes: index.php == pre + 1 require + helper + routes; app.js == pre with the ONE line replaced; css == pre + block; js == payload; service == payload" || bad "[$label] unexpected byte changes"
import sys
pre, P, pay, sv = sys.argv[1] + '/', sys.argv[2] + '/', sys.argv[3] + '/', sys.argv[4] + '/'
r = lambda p: open(p, encoding='utf-8').read()
php = r(pre + 'index.php')
req = "require_once __DIR__ . '/../services/InventoryHppReportService.php';\n"
doc = '/**\n * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok.'
ra = "    'GET /reports/inventory-hpp/summary' => function () use ($pdo, $query) {\n"
exp = php.replace(req, req + "require_once __DIR__ . '/../services/InventoryValuationService.php';\n", 1).replace(doc, r(pay + 'val_index_php_helper.txt') + doc, 1).replace(ra, r(pay + 'val_index_php_routes.txt') + ra, 1)
ok = exp == r(P + 'index.php')
old = "ReportHpp.render(document.getElementById('tab-laporan-hpp'));"; new = "ReportValuation.render(document.getElementById('tab-laporan-hpp'));"
app = r(pre + 'app.js'); ok = ok and app.count(old) == 1 and app.replace(old, new, 1) == r(P + 'assets/js/app.js')
css = r(pre + 'app.css'); ok = ok and r(P + 'assets/css/app.css') == css + ('' if css.endswith('\n') else '\n') + '\n' + r(pay + 'val_app_css_block.css')
ok = ok and r(P + 'assets/js/report-valuation.js') == r(pay + 'report-valuation.js') and r(sv + 'InventoryValuationService.php') == r(pay + 'InventoryValuationService.php')
sys.exit(0 if ok else 1)
PY
  python3 - "$T/pre/index.html" "$P/index.html" <<'PY' && ok "[$label] index.html: ONLY the new tag (after the report-hpp.js tag) and the app.css + app.js tokens changed; the report-hpp.js tag, api-client tags and every other line are byte-identical" || bad "[$label] index.html changes"
import re, sys
a = open(sys.argv[1], encoding='utf-8').read(); b = open(sys.argv[2], encoding='utf-8').read()
new = '<script src="assets/js/report-valuation.js?v=20261016-val"></script>'
hpp = re.compile(r'<script src="assets/js/report-hpp\.js\?v=[A-Za-z0-9._-]+"></script>')
exp = hpp.sub(lambda m: m.group(0) + '\n' + new, a, count=1)
exp = re.sub(r'(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)', r'\g<1>20261016-val\g<2>', exp, count=1)
exp = re.sub(r'(<script src="assets/js/app\.js\?v=)[A-Za-z0-9._-]+("></script>)', r'\g<1>20261016-val\g<2>', exp, count=1)
api_a = re.findall(r'<script src="assets/js/api-client[^"]*"></script>', a); api_b = re.findall(r'<script src="assets/js/api-client[^"]*"></script>', b)
sys.exit(0 if exp == b and hpp.findall(a) == hpp.findall(b) and api_a == api_b else 1)
PY
  [ "$(grep -cF "$HPPTAG" "$P/index.html")" = 1 ] && ok "[$label] the report-hpp.js reference is still present exactly once, unchanged" || bad "[$label] report-hpp tag changed"
  if [ "$variant" = prod ]; then
    grep -q 'api-client-v2163eod.js?v=stabilfix-20261004091227' "$P/index.html" && grep -q 'api-client.js?v=20261013-mvr' "$P/index.html" && ok "[$label] MVR api-client token and the api-client-v2163eod tag untouched" || bad "[$label] production tags changed"
  fi
  php "$S/val_readonly_check.php" --public-dir="$P" --services-dir="$SV" --files >"$W/files.txt" 2>&1 && ok "[$label] post-apply file check: all PASS ($(tail -1 "$W/files.txt"))" || { bad "[$label] post-apply file check"; grep FAIL "$W/files.txt"; }
  expect_fail "[$label] second index.php apply refused" php "$S/patch_val_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$(sha "$P/index.php") --apply
  expect_fail "[$label] second service install refused" php "$S/install_val_files_production.php" "$PAY/InventoryValuationService.php" "$SVT" --expect-payload-sha256=$H_SVC --apply
  expect_fail "[$label] second app.js apply refused" php "$S/patch_val_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$(sha "$P/assets/js/app.js") --apply
  expect_fail "[$label] second css apply refused" php "$S/patch_val_app_css_production.php" "$P/assets/css/app.css" "$PAY/val_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply
  expect_fail "[$label] second index.html apply refused" php "$S/patch_val_index_html_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  mkdir -p "$T/post"; cp "$P/index.php" "$P/assets/js/app.js" "$T/post/"
  echo "// local edit" >> "$P/assets/js/app.js"
  expect_fail "[$label] rollback refused when ONE target was edited after the package" php "$S/rollback_val_production.php" --public-dir="$P" --services-dir="$SV" --apply
  [ "$(sha "$P/index.php")" = "$(sha "$T/post/index.php")" ] && [ -f "$SVT" ] && [ -f "$JS" ] && ok "[$label] two-phase: the refused rollback changed nothing" || bad "[$label] partial rollback happened"
  cp "$T/post/app.js" "$P/assets/js/app.js"
  php "$S/rollback_val_production.php" --public-dir="$P" --services-dir="$SV" >/dev/null 2>&1 && [ "$(sha "$P/index.php")" = "$(sha "$T/post/index.php")" ] && ok "[$label] rollback dry-run changes nothing" || bad "[$label] rollback dry-run"
  php "$S/rollback_val_production.php" --public-dir="$P" --services-dir="$SV" --apply >/dev/null 2>&1 && ok "[$label] rollback --apply succeeds" || bad "[$label] rollback apply"
  local same=1
  for pair in "index.php:$P/index.php" "index.html:$P/index.html" "app.css:$P/assets/css/app.css" "app.js:$P/assets/js/app.js"; do cmp -s "$T/pre/${pair%%:*}" "${pair#*:}" || same=0; done
  [ $same = 1 ] && [ ! -e "$SVT" ] && [ ! -e "$JS" ] && ! ls "$P"/*.val-patch.json "$P"/assets/*/*.val-patch.json "$SV"/*.val-patch.json >/dev/null 2>&1 && ok "[$label] rollback restores the exact bytes of index.php / index.html / app.css / app.js, deletes the service and the report js, removes every state file" || bad "[$label] rollback result"
  expect_fail "[$label] second rollback refused" php "$S/rollback_val_production.php" --public-dir="$P" --services-dir="$SV" --apply
  bash "$PK/collect_production_hashes_val.sh" "$P" "$SV" >"$W/collect.txt" 2>&1
  grep -q "$hP  " "$W/collect.txt" && grep -q "$hA  " "$W/collect.txt" && grep -q "no Laporan Nilai Stok & HPP leftovers" "$W/collect.txt" && grep -Eq '^1  require_once InventoryHppReportService' "$W/collect.txt" && grep -Eq '^0  already references InventoryValuationService' "$W/collect.txt" && grep -Eq '^1  GET /reports/inventory-hpp/summary route' "$W/collect.txt" && grep -Eq '^1  ReportHpp route of tab-laporan-hpp' "$W/collect.txt" && grep -Eq '^1  report-hpp.js script tag' "$W/collect.txt" && grep -Eq '^0  report-valuation.js script tag' "$W/collect.txt" && ok "[$label] collect script: hashes + anchor counts as documented" || bad "[$label] collect script"
}

for rev in $OLD_REVS "$VAL_BASE"; do run_tree "$rev" "$rev" plain; done
run_tree "later-tokens" "$VAL_BASE" tokens
run_tree "PRODLAYOUT" "$VAL_BASE" prod

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
