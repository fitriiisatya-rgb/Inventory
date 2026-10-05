#!/usr/bin/env bash
# Rehearses the Laporan Stock Opname audit package (v2) against PRODUCTION-LIKE trees (never the live dev tree). Trees:
#   9dcb367 / 6775b7f / <SOA_BASE>  the four files exactly as they were at those delivered revisions (these repo trees DO load stock-opname-report.js → REPLACE path, token bump)
#   later-tokens                    same, with every ?v= token moved by "another package"
#   PRODLAYOUT                      the REAL production layout reported after the MVR deployment: <SOA_BASE> files with NO stock-opname-report.js (file + tag), the live Jejak tag
#                                   (stock-opname-report-jejak.js?v=20261008-jejak3), api-client-v2163eod.js?v=stabilfix-… next to api-client.js?v=20261013-mvr,
#                                   stock-opname-v2163eod.js, app.css?v=20261013-mvr  → NEW-file path, tag INSERTED after the Jejak tag
# Proves, per tree: dry-run writes nothing; wrong preimage / payload hash, a missing / duplicated anchor, a missing Jejak tag, an existing NEW-file target, a wrong REPLACE hash and a
# double apply are all refused; backend-first apply; ONLY the intended bytes change (index.php: 1 require + helper + routes; app.js: ONE line; css: block appended; index.html: new tag
# after Jejak + app.css/app.js tokens; the Jejak tag, api-client tags and the MVR api-client token stay byte-identical); post-apply file check; two-phase rollback (refuses on any edited
# target, then restores the exact bytes — Jejak reference included — and deletes the files the package created).
# Usage: [SOA_REV=<commit>] [SOA_BASE=<commit>] bash tests/soa_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
SOA_REV="${SOA_REV:-ad6e7ac}"; SOA_BASE="${SOA_BASE:-55fc7f8}"
OLD_REVS="${OLD_REVS:-9dcb367 6775b7f}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

SOA_REV="$SOA_REV" SOA_BASE="$SOA_BASE" bash scripts/build_soa_package.sh "$W/out" >/dev/null 2>&1 || { echo "FAIL - package build"; exit 1; }
tar -C "$W" -xzf "$W/out/soa_production_deploy_package.tar.gz"
PK="$W/soa_production_deploy_package"; PAY="$PK/payload"; S="$PK/scripts"
( cd "$PK" && sha256sum -c SHA256SUMS >/dev/null 2>&1 ) && ok "package SHA256SUMS verify" || bad "package SHA256SUMS"
[ ! -e "$S/patch_soa_api_client_production.php" ] && ! grep -rq "api-client" "$S"/patch_soa_*.php "$S"/install_soa_files_production.php "$S"/rollback_soa_production.php && ok "the package does not touch api-client.js / api-client-v2163eod.js (no patcher, no reference)" || bad "package still references api-client"
DEV="$W/dev"; mkdir -p "$DEV"
for f in services/StockOpnameAuditReportService.php public/index.php public/index.html public/assets/css/app.css public/assets/js/app.js public/assets/js/stock-opname-report.js; do git show "$SOA_REV:$f" > "$DEV/$(basename "$f")"; done
cmp -s "$PAY/StockOpnameAuditReportService.php" "$DEV/StockOpnameAuditReportService.php" && cmp -s "$PAY/stock-opname-report.js" "$DEV/stock-opname-report.js" && ok "service + stock-opname-report.js payloads byte-identical to the tested dev files" || bad "payload != dev"
! grep -q "InvApi\." "$PAY/stock-opname-report.js" && grep -q "const OpnameApi" "$PAY/stock-opname-report.js" && ok "stock-opname-report.js is self-contained (own read-only GET helper, no InvApi dependency)" || bad "report js depends on InvApi"
tail -c "$(wc -c < "$PAY/soa_app_css_block.css")" "$DEV/app.css" | cmp -s - "$PAY/soa_app_css_block.css" && [ "$(grep -c '^/\* Laporan Stock Opname audit redesign (stock-opname-report.js' "$PAY/soa_app_css_block.css")" = 1 ] && ok "css block == dev app.css tail (single marker, ends at EOF)" || bad "css block"
[ "$(grep -c "'GET /reports/opname-audit/[a-z/{}-]*' =>" "$PAY/soa_index_php_routes.txt")" = 5 ] && ! grep -q "'GET /reports/opname' =>" "$PAY/soa_index_php_routes.txt" && ok "routes payload = the five new GET routes only" || bad "routes payload"
grep -q "^function inv_soa_filters" "$PAY/soa_index_php_helper.txt" && grep -q "^function inv_soa_session_ids" "$PAY/soa_index_php_helper.txt" && grep -q "^function inv_soa_line_filters" "$PAY/soa_index_php_helper.txt" && ! grep -q "function inv_so_resolve_warehouse_scope" "$PAY/soa_index_php_helper.txt" && ok "helper payload = the three inv_soa_* helpers only" || bad "helper payload"
! grep -qE "'(POST|PUT|PATCH|DELETE) " "$PAY/soa_index_php_routes.txt" && ok "no write route in the payload (all GET)" || bad "write route in payload"
H_SVC=$(sha "$PAY/StockOpnameAuditReportService.php"); H_JS=$(sha "$PAY/stock-opname-report.js"); H_BLK=$(sha "$PAY/soa_app_css_block.css"); H_HLP=$(sha "$PAY/soa_index_php_helper.txt"); H_RT=$(sha "$PAY/soa_index_php_routes.txt")
APPLINE_OLD="ReportOpname.render(document.getElementById('tab-laporan-opname'));"
APPLINE_NEW="StockOpnameReport.render(document.getElementById('tab-laporan-opname'));"

# make_tree <label> <rev> <variant: plain|tokens|prod>
make_tree() {
  local T="$W/tree_$1" rev="$2" variant="$3"
  P="$T/public"; SV="$T/services"; mkdir -p "$P/assets/js" "$P/assets/css" "$SV"
  git show "$rev:public/index.php" > "$P/index.php"; git show "$rev:public/index.html" > "$P/index.html"
  git show "$rev:public/assets/css/app.css" > "$P/assets/css/app.css"; git show "$rev:public/assets/js/app.js" > "$P/assets/js/app.js"
  git show "$rev:public/assets/js/stock-opname-report.js" > "$P/assets/js/stock-opname-report.js"
  [ "$variant" = tokens ] && sed -i -E 's/\?v=[A-Za-z0-9._-]+/?v=20261099-later/g' "$P/index.html"
  if [ "$variant" = prod ]; then
    rm -f "$P/assets/js/stock-opname-report.js"
    python3 - "$P/index.html" <<'PY'
import re, sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
s, n = re.subn(r'<script src="assets/js/stock-opname-report\.js\?v=[^"]+"></script>\n?', '', s); assert n == 1
s, n = re.subn(r'<script src="assets/js/stock-opname\.js\?v=[^"]+"></script>', '<script src="assets/js/stock-opname-v2163eod.js?v=20261003-stabilization-final1"></script>', s); assert n == 1
s, n = re.subn(r'<script src="assets/js/api-client\.js\?v=[^"]+"></script>', '<script src="assets/js/api-client-v2163eod.js?v=stabilfix-20261004091227"></script>\n<script src="assets/js/api-client.js?v=20261013-mvr"></script>', s); assert n == 1
s, n = re.subn(r'(href="assets/css/app\.css\?v=)[^"]+', r'\g<1>20261013-mvr', s); assert n == 1
s, n = re.subn(r'(<script src="assets/js/stock-opname-report-jejak\.js\?v=)[^"]+', r'\g<1>20261008-jejak3', s); assert n == 1
open(p, 'w', encoding='utf-8', newline='').write(s)
PY
  fi
  mkdir -p "$T/pre"; cp "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/app.js" "$T/pre/"
  [ -f "$P/assets/js/stock-opname-report.js" ] && cp "$P/assets/js/stock-opname-report.js" "$T/pre/"
  TREE="$T"
}

run_tree() {
  local label="$1" rev="$2" variant="${3:-plain}"
  make_tree "$label" "$rev" "$variant"; local T="$TREE"
  local hP hH hC hA hJ="" haveJs=0
  hP=$(sha "$P/index.php"); hH=$(sha "$P/index.html"); hC=$(sha "$P/assets/css/app.css"); hA=$(sha "$P/assets/js/app.js")
  local JSTARGET="$P/assets/js/stock-opname-report.js" JSARGS=""
  if [ -f "$JSTARGET" ]; then haveJs=1; hJ=$(sha "$JSTARGET"); JSARGS="--replace-expect-sha256=$hJ"; fi
  local PHP_ARGS="$PAY/soa_index_php_helper.txt $PAY/soa_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$H_RT"
  local JEJ; JEJ=$(grep -o '<script src="assets/js/stock-opname-report-jejak.js?v=[A-Za-z0-9._-]*"></script>' "$P/index.html")
  [ -n "$JEJ" ] && ok "[$label] the tree has the live Jejak tag ($JEJ)" || bad "[$label] no Jejak tag in the tree"
  # ---- dry-runs write nothing
  local dry=1
  php "$S/install_soa_files_production.php" "$PAY/StockOpnameAuditReportService.php" "$SV/StockOpnameAuditReportService.php" --expect-payload-sha256=$H_SVC >/dev/null 2>&1 || dry=0
  php "$S/patch_soa_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$hP >/dev/null 2>&1 || dry=0
  php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$JSTARGET" --expect-payload-sha256=$H_JS $JSARGS >/dev/null 2>&1 || dry=0
  php "$S/patch_soa_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$hA >/dev/null 2>&1 || dry=0
  php "$S/patch_soa_app_css_production.php" "$P/assets/css/app.css" "$PAY/soa_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$H_BLK >/dev/null 2>&1 || dry=0
  php "$S/patch_soa_index_html_production.php" "$P/index.html" --expect-sha256=$hH >/dev/null 2>&1 || dry=0
  [ $dry = 1 ] && [ "$(sha "$P/index.php")" = "$hP" ] && [ "$(sha "$P/index.html")" = "$hH" ] && [ ! -e "$SV/StockOpnameAuditReportService.php" ] && ! ls "$P"/*.soa-patch.json "$P"/assets/*/*.soa-patch.json "$P"/assets/*/*.pre-soa-backup >/dev/null 2>&1 && { [ $haveJs = 1 ] || [ ! -e "$JSTARGET" ]; } && ok "[$label] all six dry-runs succeed and write nothing" || bad "[$label] dry-runs (rc=$dry)"
  # ---- refusals
  expect_fail "[$label] index.php: wrong preimage hash refused" php "$S/patch_soa_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$ZERO
  expect_fail "[$label] index.php: wrong routes-payload hash refused" php "$S/patch_soa_index_php_production.php" "$P/index.php" $PAY/soa_index_php_helper.txt $PAY/soa_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$ZERO --expect-sha256=$hP
  python3 - "$P/index.php" "$T/noanchor.php" "$T/dup.php" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
a = "    'GET /reports/opname' => function () use ($pdo, $query) {\n"
assert s.count(a) == 1
open(sys.argv[2], 'w', encoding='utf-8', newline='').write(s.replace(a, "    'GET /reports/opname-x' => function () use ($pdo, $query) {\n"))
open(sys.argv[3], 'w', encoding='utf-8', newline='').write(s.replace(a, a + a))
PY
  expect_fail "[$label] index.php: missing route anchor refused" php "$S/patch_soa_index_php_production.php" "$T/noanchor.php" $PHP_ARGS --expect-sha256=$(sha "$T/noanchor.php")
  expect_fail "[$label] index.php: duplicated route anchor (not exactly once) refused" php "$S/patch_soa_index_php_production.php" "$T/dup.php" $PHP_ARGS --expect-sha256=$(sha "$T/dup.php")
  sed 's#require_once __DIR__ . ./../services/StockOpnameJejakService.php.;##' "$P/index.php" > "$T/norequire.php"
  expect_fail "[$label] index.php: missing require anchor refused" php "$S/patch_soa_index_php_production.php" "$T/norequire.php" $PHP_ARGS --expect-sha256=$(sha "$T/norequire.php")
  expect_fail "[$label] app.js: wrong preimage refused" php "$S/patch_soa_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$ZERO
  grep -vF "$APPLINE_OLD" "$P/assets/js/app.js" > "$T/noapp.js"
  expect_fail "[$label] app.js: missing route line refused" php "$S/patch_soa_app_js_production.php" "$T/noapp.js" --expect-sha256=$(sha "$T/noapp.js")
  { cat "$P/assets/js/app.js"; echo "    $APPLINE_OLD"; } > "$T/dupapp.js"
  expect_fail "[$label] app.js: duplicated route line (not exactly once) refused" php "$S/patch_soa_app_js_production.php" "$T/dupapp.js" --expect-sha256=$(sha "$T/dupapp.js")
  { cat "$P/assets/js/app.js"; echo "    $APPLINE_NEW"; } > "$T/doneapp.js"
  expect_fail "[$label] app.js: already re-pointed refused (double apply)" php "$S/patch_soa_app_js_production.php" "$T/doneapp.js" --expect-sha256=$(sha "$T/doneapp.js")
  expect_fail "[$label] stock-opname-report.js: wrong payload hash refused" php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$JSTARGET" --expect-payload-sha256=$ZERO $JSARGS
  if [ $haveJs = 1 ]; then
    expect_fail "[$label] stock-opname-report.js: wrong REPLACE hash refused" php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$JSTARGET" --expect-payload-sha256=$H_JS --replace-expect-sha256=$ZERO
    expect_fail "[$label] stock-opname-report.js: NEW mode refuses an EXISTING target" php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$JSTARGET" --expect-payload-sha256=$H_JS --apply
  else
    echo "stale" > "$JSTARGET"
    expect_fail "[$label] stock-opname-report.js: NEW mode refuses an EXISTING target (never overwrites)" php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$JSTARGET" --expect-payload-sha256=$H_JS --apply
    rm -f "$JSTARGET"
  fi
  expect_fail "[$label] app.css: wrong block hash refused" php "$S/patch_soa_app_css_production.php" "$P/assets/css/app.css" "$PAY/soa_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$ZERO
  expect_fail "[$label] index.html: wrong preimage refused" php "$S/patch_soa_index_html_production.php" "$P/index.html" --expect-sha256=$ZERO
  sed -E 's#<script src="assets/js/stock-opname-report-jejak\.js\?v=[^"]*"></script>##' "$P/index.html" > "$T/nojejak.html"
  expect_fail "[$label] index.html: MISSING Jejak tag refused (anchor matched 0 times) — nothing written" php "$S/patch_soa_index_html_production.php" "$T/nojejak.html" --expect-sha256=$(sha "$T/nojejak.html")
  { cat "$P/index.html"; echo "$JEJ"; } > "$T/dupjejak.html"
  expect_fail "[$label] index.html: DUPLICATED Jejak tag refused" php "$S/patch_soa_index_html_production.php" "$T/dupjejak.html" --expect-sha256=$(sha "$T/dupjejak.html")
  { cat "$P/index.html"; echo '<script src="assets/js/stock-opname-report.js?v=a"></script>'; echo '<script src="assets/js/stock-opname-report.js?v=b"></script>'; } > "$T/tworep.html"
  expect_fail "[$label] index.html: two stock-opname-report.js tags refused" php "$S/patch_soa_index_html_production.php" "$T/tworep.html" --expect-sha256=$(sha "$T/tworep.html")
  sed -E 's#(assets/js/app\.js\?v=)[A-Za-z0-9._-]+#\1x y#' "$P/index.html" > "$T/noappjs.html"
  expect_fail "[$label] index.html: unparsable app.js tag refused" php "$S/patch_soa_index_html_production.php" "$T/noappjs.html" --expect-sha256=$(sha "$T/noappjs.html")
  expect_fail "[$label] rollback before apply refused (no state files)" php "$S/rollback_soa_production.php" --public-dir="$P" --services-dir="$SV"
  echo "x" > "$SV/StockOpnameAuditReportService.php"
  expect_fail "[$label] service install refuses an EXISTING target (NEW mode never overwrites)" php "$S/install_soa_files_production.php" "$PAY/StockOpnameAuditReportService.php" "$SV/StockOpnameAuditReportService.php" --expect-payload-sha256=$H_SVC --apply
  rm -f "$SV/StockOpnameAuditReportService.php"
  # ---- apply, backend first
  php "$S/install_soa_files_production.php" "$PAY/StockOpnameAuditReportService.php" "$SV/StockOpnameAuditReportService.php" --expect-payload-sha256=$H_SVC --apply >/dev/null 2>&1 && ok "[$label] apply 1/6: service installed" || bad "[$label] apply service"
  php "$S/patch_soa_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$hP --apply >"$W/a2.txt" 2>&1 && php -l "$P/index.php" >/dev/null 2>&1 && ok "[$label] apply 2/6: index.php patched, php -l clean" || { bad "[$label] apply index.php"; cat "$W/a2.txt"; }
  php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$JSTARGET" --expect-payload-sha256=$H_JS $JSARGS --apply >/dev/null 2>&1 && ok "[$label] apply 3/6: stock-opname-report.js $([ $haveJs = 1 ] && echo replaced || echo created)" || bad "[$label] apply js"
  php "$S/patch_soa_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$hA --apply >/dev/null 2>&1 && ok "[$label] apply 4/6: app.js route re-pointed" || bad "[$label] apply app.js"
  php "$S/patch_soa_app_css_production.php" "$P/assets/css/app.css" "$PAY/soa_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$H_BLK --apply >/dev/null 2>&1 && ok "[$label] apply 5/6: app.css block appended" || bad "[$label] apply css"
  php "$S/patch_soa_index_html_production.php" "$P/index.html" --expect-sha256=$hH --apply >/dev/null 2>&1 && ok "[$label] apply 6/6: index.html (last)" || bad "[$label] apply html"
  # ---- only the intended bytes changed
  python3 - "$T/pre" "$P" "$PAY" "$SV" "$haveJs" <<'PY' && ok "[$label] bytes: index.php == pre + 1 require + helpers + routes; app.js == pre with the ONE line replaced; css == pre + block; js == payload; service == payload" || bad "[$label] unexpected byte changes"
import sys
pre, P, pay, sv = sys.argv[1] + '/', sys.argv[2] + '/', sys.argv[3] + '/', sys.argv[4] + '/'
r = lambda p: open(p, encoding='utf-8').read()
php = r(pre + 'index.php')
req = "require_once __DIR__ . '/../services/StockOpnameJejakService.php';\n"
doc = '/**\n * PHASE V2.14.9.1 — same rule as inv_require_so_warehouse_scope(), but for\n'
ra = "    'GET /reports/opname' => function () use ($pdo, $query) {\n"
exp = php.replace(req, req + "require_once __DIR__ . '/../services/StockOpnameAuditReportService.php';\n", 1).replace(doc, r(pay + 'soa_index_php_helper.txt') + doc, 1).replace(ra, r(pay + 'soa_index_php_routes.txt') + ra, 1)
ok = exp == r(P + 'index.php')
old = "ReportOpname.render(document.getElementById('tab-laporan-opname'));"
new = "StockOpnameReport.render(document.getElementById('tab-laporan-opname'));"
app = r(pre + 'app.js')
ok = ok and app.count(old) == 1 and app.replace(old, new, 1) == r(P + 'assets/js/app.js')
css = r(pre + 'app.css'); ok = ok and r(P + 'assets/css/app.css') == css + ('' if css.endswith('\n') else '\n') + '\n' + r(pay + 'soa_app_css_block.css')
ok = ok and r(P + 'assets/js/stock-opname-report.js') == r(pay + 'stock-opname-report.js') and r(sv + 'StockOpnameAuditReportService.php') == r(pay + 'StockOpnameAuditReportService.php')
sys.exit(0 if ok else 1)
PY
  python3 - "$T/pre/index.html" "$P/index.html" <<'PY' && ok "[$label] index.html: ONLY the new tag (inserted after / replacing the report tag) and the app.css + app.js tokens changed; the Jejak tag, the api-client tags (MVR token included) and every other line are byte-identical" || bad "[$label] index.html changes"
import re, sys
a = open(sys.argv[1], encoding='utf-8').read(); b = open(sys.argv[2], encoding='utf-8').read()
new = '<script src="assets/js/stock-opname-report.js?v=20261014-soa"></script>'
jej = re.compile(r'<script src="assets/js/stock-opname-report-jejak\.js\?v=[A-Za-z0-9._-]+"></script>')
rep = re.compile(r'<script src="assets/js/stock-opname-report\.js\?v=[A-Za-z0-9._-]+"></script>')
exp = a
if len(rep.findall(exp)) == 0:
    exp = jej.sub(lambda m: m.group(0) + '\n' + new, exp, count=1)
else:
    exp = rep.sub(new, exp, count=1)
exp = re.sub(r'(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)', r'\g<1>20261014-soa\g<2>', exp, count=1)
exp = re.sub(r'(<script src="assets/js/app\.js\?v=)[A-Za-z0-9._-]+("></script>)', r'\g<1>20261014-soa\g<2>', exp, count=1)
same_jejak = jej.findall(a) == jej.findall(b) and len(jej.findall(b)) == 1
api_a = re.findall(r'<script src="assets/js/api-client[^"]*"></script>', a); api_b = re.findall(r'<script src="assets/js/api-client[^"]*"></script>', b)
sys.exit(0 if exp == b and same_jejak and api_a == api_b else 1)
PY
  grep -qF "$JEJ" "$P/index.html" && [ "$(grep -cF "$JEJ" "$P/index.html")" = 1 ] && ok "[$label] the Jejak script reference is still present exactly once, unchanged" || bad "[$label] Jejak reference changed"
  if [ "$variant" = prod ]; then
    grep -q 'api-client-v2163eod.js?v=stabilfix-20261004091227' "$P/index.html" && grep -q 'api-client.js?v=20261013-mvr' "$P/index.html" && grep -q 'stock-opname-v2163eod.js?v=20261003-stabilization-final1' "$P/index.html" && ok "[$label] MVR api-client token, api-client-v2163eod and stock-opname-v2163eod tags untouched" || bad "[$label] production tags changed"
    python3 - "$P/index.html" <<'PY' && ok "[$label] order: Jejak tag, then the new stock-opname-report.js tag (soa token), app.css token now 20261014-soa" || bad "[$label] tag order"
import re, sys
s = open(sys.argv[1], encoding='utf-8').read()
m = re.search(r'stock-opname-report-jejak\.js\?v=20261008-jejak3"></script>\n<script src="assets/js/stock-opname-report\.js\?v=20261014-soa"></script>', s)
sys.exit(0 if m and 'app.css?v=20261014-soa' in s else 1)
PY
  fi
  php "$S/soa_readonly_check.php" --public-dir="$P" --services-dir="$SV" --files >"$W/files.txt" 2>&1 && ok "[$label] post-apply file check: all PASS ($(tail -1 "$W/files.txt"))" || { bad "[$label] post-apply file check"; grep FAIL "$W/files.txt"; }
  # ---- double apply
  expect_fail "[$label] second index.php apply refused" php "$S/patch_soa_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$(sha "$P/index.php") --apply
  expect_fail "[$label] second service install refused" php "$S/install_soa_files_production.php" "$PAY/StockOpnameAuditReportService.php" "$SV/StockOpnameAuditReportService.php" --expect-payload-sha256=$H_SVC --apply
  expect_fail "[$label] second app.js apply refused" php "$S/patch_soa_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$(sha "$P/assets/js/app.js") --apply
  expect_fail "[$label] second css apply refused" php "$S/patch_soa_app_css_production.php" "$P/assets/css/app.css" "$PAY/soa_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply
  expect_fail "[$label] second index.html apply refused" php "$S/patch_soa_index_html_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  # ---- rollback: two-phase (edited target => nothing changes), then exact restore
  mkdir -p "$T/post"; cp "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/app.js" "$T/post/"
  echo "// local edit" >> "$P/assets/js/app.js"
  expect_fail "[$label] rollback refused when ONE target was edited after the package" php "$S/rollback_soa_production.php" --public-dir="$P" --services-dir="$SV" --apply
  [ "$(sha "$P/index.php")" = "$(sha "$T/post/index.php")" ] && [ -f "$SV/StockOpnameAuditReportService.php" ] && [ -f "$JSTARGET" ] && ok "[$label] two-phase: the refused rollback changed nothing (index.php, the service and the report js are untouched)" || bad "[$label] partial rollback happened"
  cp "$T/post/app.js" "$P/assets/js/app.js"
  php "$S/rollback_soa_production.php" --public-dir="$P" --services-dir="$SV" >/dev/null 2>&1 && [ "$(sha "$P/index.php")" = "$(sha "$T/post/index.php")" ] && ok "[$label] rollback dry-run changes nothing" || bad "[$label] rollback dry-run"
  php "$S/rollback_soa_production.php" --public-dir="$P" --services-dir="$SV" --apply >/dev/null 2>&1 && ok "[$label] rollback --apply succeeds" || bad "[$label] rollback apply"
  local same=1
  for pair in "index.php:$P/index.php" "index.html:$P/index.html" "app.css:$P/assets/css/app.css" "app.js:$P/assets/js/app.js"; do
    cmp -s "$T/pre/${pair%%:*}" "${pair#*:}" || same=0
  done
  if [ $haveJs = 1 ]; then cmp -s "$T/pre/stock-opname-report.js" "$JSTARGET" || same=0; else [ ! -e "$JSTARGET" ] || same=0; fi
  [ $same = 1 ] && [ ! -e "$SV/StockOpnameAuditReportService.php" ] && ! ls "$P"/*.soa-patch.json "$P"/assets/*/*.soa-patch.json "$SV"/*.soa-patch.json >/dev/null 2>&1 && ok "[$label] rollback restores the exact bytes of index.php / index.html (Jejak reference + previous tokens) / app.css / app.js, $([ $haveJs = 1 ] && echo 'restores the previous report js' || echo 'deletes the report js it created'), deletes the new service, removes every state file" || bad "[$label] rollback result"
  grep -qF "$JEJ" "$P/index.html" && ok "[$label] after rollback the Jejak tag is exactly as before: $JEJ" || bad "[$label] Jejak tag after rollback"
  expect_fail "[$label] second rollback refused" php "$S/rollback_soa_production.php" --public-dir="$P" --services-dir="$SV" --apply
  bash "$PK/collect_production_hashes_soa.sh" "$P" "$SV" >"$W/collect.txt" 2>&1
  grep -q "$hP  " "$W/collect.txt" && grep -q "$hA  " "$W/collect.txt" && grep -q "no Laporan Stock Opname leftovers" "$W/collect.txt" && grep -Eq '^1  require_once StockOpnameJejakService' "$W/collect.txt" && grep -Eq '^0  already references StockOpnameAuditReportService' "$W/collect.txt" && grep -Eq '^1  GET /reports/opname route' "$W/collect.txt" \
    && grep -Eq "^1  ReportOpname route of tab-laporan-opname" "$W/collect.txt" && grep -Eq '^1  Jejak drawer script tag' "$W/collect.txt" && grep -Eq "^$([ $variant = prod ] && echo 0 || echo 1)  stock-opname-report.js script tag" "$W/collect.txt" && grep -q "AUDIT: which api-client file defines InvApi" "$W/collect.txt" && ok "[$label] collect script: hashes + anchor counts as documented (+ api-client audit block)" || bad "[$label] collect script"
}

for rev in $OLD_REVS "$SOA_BASE"; do run_tree "$rev" "$rev" plain; done
run_tree "later-tokens" "$SOA_BASE" tokens
run_tree "PRODLAYOUT" "$SOA_BASE" prod

# ---- the final bytes of the plain base tree equal the tested dev files (app.js: dev with the ONE line replaced; index.html differs by design)
make_tree final "$SOA_BASE" plain; T="$TREE"; SV="$T/services"
php "$S/install_soa_files_production.php" "$PAY/StockOpnameAuditReportService.php" "$SV/StockOpnameAuditReportService.php" --expect-payload-sha256=$H_SVC --apply >/dev/null 2>&1
php "$S/patch_soa_index_php_production.php" "$P/index.php" $PAY/soa_index_php_helper.txt $PAY/soa_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$H_RT --expect-sha256=$(sha "$P/index.php") --apply >/dev/null 2>&1
php "$S/install_soa_files_production.php" "$PAY/stock-opname-report.js" "$P/assets/js/stock-opname-report.js" --expect-payload-sha256=$H_JS --replace-expect-sha256=$(sha "$P/assets/js/stock-opname-report.js") --apply >/dev/null 2>&1
php "$S/patch_soa_app_js_production.php" "$P/assets/js/app.js" --expect-sha256=$(sha "$P/assets/js/app.js") --apply >/dev/null 2>&1
php "$S/patch_soa_app_css_production.php" "$P/assets/css/app.css" "$PAY/soa_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply >/dev/null 2>&1
eq=1
cmp -s "$P/index.php" "$DEV/index.php" || { eq=0; echo "  index.php differs"; }
cmp -s "$P/assets/css/app.css" "$DEV/app.css" || { eq=0; echo "  app.css differs"; }
cmp -s "$P/assets/js/stock-opname-report.js" "$DEV/stock-opname-report.js" || { eq=0; echo "  stock-opname-report.js differs"; }
python3 - "$P/assets/js/app.js" "$DEV/app.js" <<'PY' || { eq=0; echo "  app.js differs"; }
import sys
a = open(sys.argv[1], encoding='utf-8').read(); d = open(sys.argv[2], encoding='utf-8').read()
old = "ReportOpname.render(document.getElementById('tab-laporan-opname'));"; new = "StockOpnameReport.render(document.getElementById('tab-laporan-opname'));"
sys.exit(0 if d.count(old) == 1 and d.replace(old, new, 1) == a else 1)
PY
[ $eq = 1 ] && ok "patched $SOA_BASE index.php / app.css / stock-opname-report.js are byte-identical to the tested dev files ($SOA_REV); app.js == dev with the one route line re-pointed" || bad "patched != tested dev files"

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
