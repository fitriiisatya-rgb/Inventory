#!/usr/bin/env bash
# Rehearses the Pergerakan Stok Harian package against PRODUCTION-LIKE trees (never the live dev tree): the five target files exactly as they were at
# four previously delivered revisions (pre-Jejak, Jejak v3, UI2 + Master Data era, and the revision right before this feature) plus a variant whose
# ?v= tokens another package moved. Proves, per tree: dry-run writes nothing; wrong preimage / payload hash, a missing or duplicated anchor, an
# existing NEW-file target, a wrong REPLACE hash and a double apply are all refused; backend-first apply; ONLY the intended bytes change
# (index.php: 1 require + helper + routes; api-client.js: +5 lines; css: block appended; index.html: 3 tags); for the pre-feature revision the result is
# byte-identical to the tested dev files; post-apply file check; two-phase rollback (refuses on any edited target, then restores the exact bytes).
# Usage: [MVR_REV=<commit>] [MVR_BASE=<commit>] bash tests/mvr_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
MVR_REV="${MVR_REV:-9c278e1}"; MVR_BASE="${MVR_BASE:-a2dceb3}"
OLD_REVS="${OLD_REVS:-853cd69 9dcb367 6775b7f}"
W="$(mktemp -d)"; trap 'rm -rf "${W:?}"' EXIT
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local d="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$d (unexpectedly succeeded)"; else ok "$d"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }
ZERO=$(printf 0%.0s {1..64})

MVR_REV="$MVR_REV" MVR_BASE="$MVR_BASE" bash scripts/build_mvr_package.sh "$W/out" >/dev/null 2>&1 || { echo "FAIL - package build"; exit 1; }
tar -C "$W" -xzf "$W/out/mvr_production_deploy_package.tar.gz"
PK="$W/mvr_production_deploy_package"; PAY="$PK/payload"; S="$PK/scripts"
( cd "$PK" && sha256sum -c SHA256SUMS >/dev/null 2>&1 ) && ok "package SHA256SUMS verify" || bad "package SHA256SUMS"
DEV="$W/dev"; mkdir -p "$DEV"
for f in services/MovementDailyReportService.php public/index.php public/index.html public/assets/css/app.css public/assets/js/api-client.js public/assets/js/report-movement.js; do git show "$MVR_REV:$f" > "$DEV/$(basename "$f")"; done
cmp -s "$PAY/MovementDailyReportService.php" "$DEV/MovementDailyReportService.php" && cmp -s "$PAY/report-movement.js" "$DEV/report-movement.js" && ok "service + report-movement.js payloads byte-identical to the tested dev files" || bad "payload != dev"
tail -c "$(wc -c < "$PAY/mvr_app_css_block.css")" "$DEV/app.css" | cmp -s - "$PAY/mvr_app_css_block.css" && [ "$(grep -c '^/\* Pergerakan Stok Harian redesign (report-movement.js' "$PAY/mvr_app_css_block.css")" = 1 ] && ok "css block == dev app.css tail (single marker, ends at EOF)" || bad "css block"
[ "$(grep -c "'GET /reports/movement/[a-z-]*' =>" "$PAY/mvr_index_php_routes.txt")" = 5 ] && ! grep -q "reconciliation/movement" "$PAY/mvr_index_php_routes.txt" && ok "routes payload = the five new GET routes only" || bad "routes payload"
grep -q "^function inv_movement_params" "$PAY/mvr_index_php_helper.txt" && ! grep -q "function inv_hpp_resolve_warehouse_scope" "$PAY/mvr_index_php_helper.txt" && ok "helper payload = inv_movement_params only" || bad "helper payload"
! grep -qE "'(POST|PUT|PATCH|DELETE) " "$PAY/mvr_index_php_routes.txt" && ok "no write route in the payload (all GET)" || bad "write route in payload"
H_SVC=$(sha "$PAY/MovementDailyReportService.php"); H_JS=$(sha "$PAY/report-movement.js"); H_BLK=$(sha "$PAY/mvr_app_css_block.css"); H_HLP=$(sha "$PAY/mvr_index_php_helper.txt"); H_RT=$(sha "$PAY/mvr_index_php_routes.txt")

run_tree() {
  local label="$1" T="$W/tree_$1" rev="$2" tokens="${3:-}"
  P="$T/public"; SV="$T/services"; mkdir -p "$P/assets/js" "$P/assets/css" "$SV"
  git show "$rev:public/index.php" > "$P/index.php"; git show "$rev:public/index.html" > "$P/index.html"
  git show "$rev:public/assets/css/app.css" > "$P/assets/css/app.css"; git show "$rev:public/assets/js/api-client.js" > "$P/assets/js/api-client.js"
  git show "$rev:public/assets/js/report-movement.js" > "$P/assets/js/report-movement.js"
  [ -n "$tokens" ] && sed -i -E 's/\?v=[A-Za-z0-9._-]+/?v=20261099-later/g' "$P/index.html"
  mkdir -p "$T/pre"; cp "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$P/assets/js/report-movement.js" "$T/pre/"
  local hP hH hC hA hJ; hP=$(sha "$P/index.php"); hH=$(sha "$P/index.html"); hC=$(sha "$P/assets/css/app.css"); hA=$(sha "$P/assets/js/api-client.js"); hJ=$(sha "$P/assets/js/report-movement.js")
  local PHP_ARGS="$PAY/mvr_index_php_helper.txt $PAY/mvr_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$H_RT"
  # ---- dry-runs write nothing
  local dry=1
  php "$S/install_mvr_files_production.php" "$PAY/MovementDailyReportService.php" "$SV/MovementDailyReportService.php" --expect-payload-sha256=$H_SVC >/dev/null 2>&1 || dry=0
  php "$S/patch_mvr_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$hP >/dev/null 2>&1 || dry=0
  php "$S/patch_mvr_api_client_production.php" "$P/assets/js/api-client.js" --expect-sha256=$hA >/dev/null 2>&1 || dry=0
  php "$S/install_mvr_files_production.php" "$PAY/report-movement.js" "$P/assets/js/report-movement.js" --expect-payload-sha256=$H_JS --replace-expect-sha256=$hJ >/dev/null 2>&1 || dry=0
  php "$S/patch_mvr_app_css_production.php" "$P/assets/css/app.css" "$PAY/mvr_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$H_BLK >/dev/null 2>&1 || dry=0
  php "$S/patch_mvr_index_html_production.php" "$P/index.html" --expect-sha256=$hH >/dev/null 2>&1 || dry=0
  [ $dry = 1 ] && [ "$(sha "$P/index.php")" = "$hP" ] && [ ! -e "$SV/MovementDailyReportService.php" ] && ! ls "$P"/*.mvr-patch.json "$P"/assets/*/*.pre-mvr-backup >/dev/null 2>&1 && ok "[$label] all six dry-runs succeed and write nothing" || bad "[$label] dry-runs (rc=$dry)"
  # ---- refusals
  expect_fail "[$label] index.php: wrong preimage hash refused" php "$S/patch_mvr_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$ZERO
  expect_fail "[$label] index.php: wrong routes-payload hash refused" php "$S/patch_mvr_index_php_production.php" "$P/index.php" $PAY/mvr_index_php_helper.txt $PAY/mvr_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$ZERO --expect-sha256=$hP
  python3 - "$P/index.php" "$T/noanchor.php" "$T/dup.php" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
a = "    'GET /reports/reconciliation/movement' => function () use ($pdo, $query) {\n"
assert s.count(a) == 1
open(sys.argv[2], 'w', encoding='utf-8', newline='').write(s.replace(a, "    'GET /reports/reconciliation/movement-x' => function () use ($pdo, $query) {\n"))
open(sys.argv[3], 'w', encoding='utf-8', newline='').write(s.replace(a, a + a))
PY
  expect_fail "[$label] index.php: missing route anchor refused" php "$S/patch_mvr_index_php_production.php" "$T/noanchor.php" $PHP_ARGS --expect-sha256=$(sha "$T/noanchor.php")
  expect_fail "[$label] index.php: duplicated route anchor (not exactly once) refused" php "$S/patch_mvr_index_php_production.php" "$T/dup.php" $PHP_ARGS --expect-sha256=$(sha "$T/dup.php")
  sed 's#require_once __DIR__ . ./../services/InventoryMovementReportService.php.;##' "$P/index.php" > "$T/norequire.php"
  expect_fail "[$label] index.php: missing require anchor refused" php "$S/patch_mvr_index_php_production.php" "$T/norequire.php" $PHP_ARGS --expect-sha256=$(sha "$T/norequire.php")
  expect_fail "[$label] api-client.js: wrong preimage refused" php "$S/patch_mvr_api_client_production.php" "$P/assets/js/api-client.js" --expect-sha256=$ZERO
  grep -v 'movementDailyExportUrl' "$P/assets/js/api-client.js" > "$T/noapi.js"
  expect_fail "[$label] api-client.js: missing anchor refused" php "$S/patch_mvr_api_client_production.php" "$T/noapi.js" --expect-sha256=$(sha "$T/noapi.js")
  expect_fail "[$label] report-movement.js: wrong REPLACE hash refused" php "$S/install_mvr_files_production.php" "$PAY/report-movement.js" "$P/assets/js/report-movement.js" --expect-payload-sha256=$H_JS --replace-expect-sha256=$ZERO
  expect_fail "[$label] report-movement.js: wrong payload hash refused" php "$S/install_mvr_files_production.php" "$PAY/report-movement.js" "$P/assets/js/report-movement.js" --expect-payload-sha256=$ZERO --replace-expect-sha256=$hJ
  expect_fail "[$label] app.css: wrong block hash refused" php "$S/patch_mvr_app_css_production.php" "$P/assets/css/app.css" "$PAY/mvr_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$ZERO
  expect_fail "[$label] index.html: wrong preimage refused" php "$S/patch_mvr_index_html_production.php" "$P/index.html" --expect-sha256=$ZERO
  expect_fail "[$label] rollback before apply refused (no state files)" php "$S/rollback_mvr_production.php" --public-dir="$P" --services-dir="$SV"
  echo "x" > "$SV/MovementDailyReportService.php"
  expect_fail "[$label] service install refuses an EXISTING target (NEW mode never overwrites)" php "$S/install_mvr_files_production.php" "$PAY/MovementDailyReportService.php" "$SV/MovementDailyReportService.php" --expect-payload-sha256=$H_SVC --apply
  rm -f "$SV/MovementDailyReportService.php"
  # ---- apply, backend first
  php "$S/install_mvr_files_production.php" "$PAY/MovementDailyReportService.php" "$SV/MovementDailyReportService.php" --expect-payload-sha256=$H_SVC --apply >/dev/null 2>&1 && ok "[$label] apply 1/6: service installed" || bad "[$label] apply service"
  php "$S/patch_mvr_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$hP --apply >"$W/a2.txt" 2>&1 && php -l "$P/index.php" >/dev/null 2>&1 && ok "[$label] apply 2/6: index.php patched, php -l clean" || { bad "[$label] apply index.php"; cat "$W/a2.txt"; }
  php "$S/patch_mvr_api_client_production.php" "$P/assets/js/api-client.js" --expect-sha256=$hA --apply >/dev/null 2>&1 && ok "[$label] apply 3/6: api-client.js patched" || bad "[$label] apply api-client"
  php "$S/install_mvr_files_production.php" "$PAY/report-movement.js" "$P/assets/js/report-movement.js" --expect-payload-sha256=$H_JS --replace-expect-sha256=$hJ --apply >/dev/null 2>&1 && ok "[$label] apply 4/6: report-movement.js replaced" || bad "[$label] apply js"
  php "$S/patch_mvr_app_css_production.php" "$P/assets/css/app.css" "$PAY/mvr_app_css_block.css" --expect-sha256=$hC --expect-block-sha256=$H_BLK --apply >/dev/null 2>&1 && ok "[$label] apply 5/6: app.css block appended" || bad "[$label] apply css"
  php "$S/patch_mvr_index_html_production.php" "$P/index.html" --expect-sha256=$hH --apply >/dev/null 2>&1 && ok "[$label] apply 6/6: index.html tokens (last)" || bad "[$label] apply html"
  # ---- only the intended bytes changed
  python3 - "$T/pre" "$P" "$PAY" "$SV" <<'PY' && ok "[$label] bytes: index.php == pre + 1 require + helper + routes; api-client == pre + 5 lines; css == pre + block; js == payload; service == payload" || bad "[$label] unexpected byte changes"
import sys, re
pre, P, pay, sv = sys.argv[1] + '/', sys.argv[2] + '/', sys.argv[3] + '/', sys.argv[4] + '/'
r = lambda p: open(p, encoding='utf-8').read()
php = r(pre + 'index.php')
req = "require_once __DIR__ . '/../services/InventoryMovementReportService.php';\n"
doc = '/**\n * Same "STOCK is always forced to their own warehouse, never a\n'
ra = "    'GET /reports/reconciliation/movement' => function () use ($pdo, $query) {\n"
exp = php.replace(req, req + "require_once __DIR__ . '/../services/MovementDailyReportService.php';\n", 1).replace(doc, r(pay + 'mvr_index_php_helper.txt') + doc, 1).replace(ra, r(pay + 'mvr_index_php_routes.txt') + ra, 1)
ok = exp == r(P + 'index.php')
api = r(pre + 'api-client.js'); new = r(P + 'assets/js/api-client.js')
ok = ok and len(new.splitlines()) == len(api.splitlines()) + 5 and all(l in new.splitlines() for l in api.splitlines())
css = r(pre + 'app.css'); ok = ok and r(P + 'assets/css/app.css') == css + ('' if css.endswith('\n') else '\n') + '\n' + r(pay + 'mvr_app_css_block.css')
ok = ok and r(P + 'assets/js/report-movement.js') == r(pay + 'report-movement.js') and r(sv + 'MovementDailyReportService.php') == r(pay + 'MovementDailyReportService.php')
sys.exit(0 if ok else 1)
PY
  python3 - "$T/pre/index.html" "$P/index.html" <<'PY' && ok "[$label] index.html: exactly the three ?v= tokens changed (app.css, api-client.js, report-movement.js)" || bad "[$label] index.html changes"
import sys, re
a = open(sys.argv[1], encoding='utf-8').read(); b = open(sys.argv[2], encoding='utf-8').read()
tag = lambda f: re.compile(r'((?:href|src)="assets/(?:css|js)/' + re.escape(f) + r'\?v=)[A-Za-z0-9._-]+(")')
for f in ['app.css', 'api-client.js', 'report-movement.js']:
    a = tag(f).sub(r'\g<1>20261013-mvr\g<2>', a)
sys.exit(0 if a == b else 1)
PY
  php "$S/mvr_readonly_check.php" --public-dir="$P" --services-dir="$SV" --files >"$W/files.txt" 2>&1 && ok "[$label] post-apply file check: all PASS ($(tail -1 "$W/files.txt"))" || { bad "[$label] post-apply file check"; grep FAIL "$W/files.txt"; }
  # ---- double apply
  expect_fail "[$label] second index.php apply refused" php "$S/patch_mvr_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$(sha "$P/index.php") --apply
  expect_fail "[$label] second service install refused" php "$S/install_mvr_files_production.php" "$PAY/MovementDailyReportService.php" "$SV/MovementDailyReportService.php" --expect-payload-sha256=$H_SVC --apply
  expect_fail "[$label] second css apply refused" php "$S/patch_mvr_app_css_production.php" "$P/assets/css/app.css" "$PAY/mvr_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply
  expect_fail "[$label] second index.html apply refused" php "$S/patch_mvr_index_html_production.php" "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
  # ---- rollback: two-phase (edited target => nothing changes), then exact restore
  mkdir -p "$T/post"; cp "$P/index.php" "$P/index.html" "$P/assets/css/app.css" "$P/assets/js/api-client.js" "$P/assets/js/report-movement.js" "$T/post/"
  echo "// local edit" >> "$P/assets/js/api-client.js"
  expect_fail "[$label] rollback refused when ONE target was edited after the package" php "$S/rollback_mvr_production.php" --public-dir="$P" --services-dir="$SV" --apply
  [ "$(sha "$P/index.php")" = "$(sha "$T/post/index.php")" ] && [ -f "$SV/MovementDailyReportService.php" ] && ok "[$label] two-phase: the refused rollback changed nothing (index.php and the service are untouched)" || bad "[$label] partial rollback happened"
  cp "$T/post/api-client.js" "$P/assets/js/api-client.js"
  php "$S/rollback_mvr_production.php" --public-dir="$P" --services-dir="$SV" >/dev/null 2>&1 && [ "$(sha "$P/index.php")" = "$(sha "$T/post/index.php")" ] && ok "[$label] rollback dry-run changes nothing" || bad "[$label] rollback dry-run"
  php "$S/rollback_mvr_production.php" --public-dir="$P" --services-dir="$SV" --apply >/dev/null 2>&1 && ok "[$label] rollback --apply succeeds" || bad "[$label] rollback apply"
  local same=1
  for pair in "index.php:$P/index.php" "index.html:$P/index.html" "app.css:$P/assets/css/app.css" "api-client.js:$P/assets/js/api-client.js" "report-movement.js:$P/assets/js/report-movement.js"; do
    cmp -s "$T/pre/${pair%%:*}" "${pair#*:}" || same=0
  done
  [ $same = 1 ] && [ ! -e "$SV/MovementDailyReportService.php" ] && ! ls "$P"/*.mvr-patch.json "$P"/assets/*/*.mvr-patch.json "$SV"/*.mvr-patch.json >/dev/null 2>&1 && ok "[$label] rollback restores the exact bytes of all five files, deletes the new service, removes every state file" || bad "[$label] rollback result"
  expect_fail "[$label] second rollback refused" php "$S/rollback_mvr_production.php" --public-dir="$P" --services-dir="$SV" --apply
  bash "$PK/collect_production_hashes_mvr.sh" "$P" "$SV" >"$W/collect.txt" 2>&1
  grep -q "$hP  " "$W/collect.txt" && grep -q "no Pergerakan Stok leftovers" "$W/collect.txt" && grep -Eq '^1  require_once InventoryMovementReportService' "$W/collect.txt" && grep -Eq '^0  already references MovementDailyReportService' "$W/collect.txt" && grep -Eq '^1  reconciliation/movement route' "$W/collect.txt" && ok "[$label] collect script: hash + anchor counts as documented" || bad "[$label] collect script"
}

for rev in $OLD_REVS "$MVR_BASE"; do run_tree "$rev" "$rev"; done
run_tree "later-tokens" "$MVR_BASE" tokens

# ---- byte-equality with the tested dev files for the pre-feature revision
T="$W/tree_final"; P="$T/public"; SV="$T/services"; mkdir -p "$P/assets/js" "$P/assets/css" "$SV"
git show "$MVR_BASE:public/index.php" > "$P/index.php"; git show "$MVR_BASE:public/index.html" > "$P/index.html"; git show "$MVR_BASE:public/assets/css/app.css" > "$P/assets/css/app.css"
git show "$MVR_BASE:public/assets/js/api-client.js" > "$P/assets/js/api-client.js"; git show "$MVR_BASE:public/assets/js/report-movement.js" > "$P/assets/js/report-movement.js"
PHP_ARGS="$PAY/mvr_index_php_helper.txt $PAY/mvr_index_php_routes.txt --expect-helper-sha256=$H_HLP --expect-routes-sha256=$H_RT"
php "$S/install_mvr_files_production.php" "$PAY/MovementDailyReportService.php" "$SV/MovementDailyReportService.php" --expect-payload-sha256=$H_SVC --apply >/dev/null 2>&1
php "$S/patch_mvr_index_php_production.php" "$P/index.php" $PHP_ARGS --expect-sha256=$(sha "$P/index.php") --apply >/dev/null 2>&1
php "$S/patch_mvr_api_client_production.php" "$P/assets/js/api-client.js" --expect-sha256=$(sha "$P/assets/js/api-client.js") --apply >/dev/null 2>&1
php "$S/install_mvr_files_production.php" "$PAY/report-movement.js" "$P/assets/js/report-movement.js" --expect-payload-sha256=$H_JS --replace-expect-sha256=$(sha "$P/assets/js/report-movement.js") --apply >/dev/null 2>&1
php "$S/patch_mvr_app_css_production.php" "$P/assets/css/app.css" "$PAY/mvr_app_css_block.css" --expect-sha256=$(sha "$P/assets/css/app.css") --expect-block-sha256=$H_BLK --apply >/dev/null 2>&1
eq=1
cmp -s "$P/index.php" "$DEV/index.php" || { eq=0; echo "  index.php differs"; }
cmp -s "$P/assets/js/api-client.js" "$DEV/api-client.js" || { eq=0; echo "  api-client.js differs"; }
cmp -s "$P/assets/css/app.css" "$DEV/app.css" || { eq=0; echo "  app.css differs"; }
cmp -s "$P/assets/js/report-movement.js" "$DEV/report-movement.js" || { eq=0; echo "  report-movement.js differs"; }
[ $eq = 1 ] && ok "patched $MVR_BASE index.php / api-client.js / app.css / report-movement.js are byte-identical to the tested dev files ($MVR_REV)" || bad "patched != tested dev files"

echo; echo "$pass passed, $fail failed"; [ "$fail" = 0 ]
