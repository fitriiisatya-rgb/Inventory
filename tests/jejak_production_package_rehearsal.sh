#!/usr/bin/env bash
# Rehearses the Jejak production package against a PRODUCTION-LIKE tree built from
# the pre-Jejak git revision ($BASE_REV, default: parent of the first Jejak commit)
# — never against the live dev working tree. Proves: dry-run writes nothing,
# preimage-hash gate, anchor gate, apply, byte-equality with the dev files,
# double-apply refusal, two-phase rollback, byte-exact restore.
#
# Usage: bash tests/jejak_production_package_rehearsal.sh
set -u
cd "$(dirname "$0")/.."
BASE_REV="${BASE_REV:-853cd69}"
W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT
P="$W/public"; mkdir -p "$P/assets/js" "$P/assets/css"
pass=0; fail=0
ok()  { echo "PASS - $1"; pass=$((pass+1)); }
bad() { echo "FAIL - $1"; fail=$((fail+1)); }
expect_fail() { local desc="$1"; shift; if "$@" >"$W/out.txt" 2>&1; then bad "$desc (unexpectedly succeeded)"; else ok "$desc"; fi; }
sha() { sha256sum "$1" | cut -d' ' -f1; }

git show "$BASE_REV:public/assets/js/report-opname.js" > "$P/assets/js/report-opname.js"
git show "$BASE_REV:public/assets/css/app.css"          > "$P/assets/css/app.css"
# production-like index.html: the dev one with every Jejak/stock-opname-report line reverted to the pre-Jejak layout
git show "$BASE_REV:public/index.html" > "$P/index.html"
# production does NOT load stock-opname-report.js — drop that tag to prove we don't anchor on it
grep -v 'stock-opname-report' "$P/index.html" > "$W/idx" && mv "$W/idx" "$P/index.html"
for f in assets/js/report-opname.js assets/css/app.css index.html; do cp "$P/$f" "$W/orig_$(basename "$f")"; done
H_JS=$(sha "$P/assets/js/report-opname.js"); H_CSS=$(sha "$P/assets/css/app.css"); H_IDX=$(sha "$P/index.html")

JS_PATCH=scripts/patch_report_opname_jejak_row_click_production.php
CSS_PATCH=scripts/patch_app_css_jejak_drawer_xl_production.php
IDX_PATCH=scripts/patch_index_html_jejak_script_tag_production.php
INSTALL=scripts/install_stock_opname_report_jejak_production.php
ROLLBACK=scripts/rollback_jejak_production.php
PAYLOAD=public/assets/js/stock-opname-report-jejak.js
H_PAYLOAD=$(sha "$PAYLOAD")

# --- dry runs write nothing ---
php $JS_PATCH  "$P/assets/js/report-opname.js" --expect-sha256=$H_JS  >/dev/null && ok "report-opname.js dry-run succeeds" || bad "report-opname.js dry-run"
php $CSS_PATCH "$P/assets/css/app.css"         --expect-sha256=$H_CSS >/dev/null && ok "app.css dry-run succeeds" || bad "app.css dry-run"
php $IDX_PATCH "$P/index.html"                 --expect-sha256=$H_IDX >/dev/null && ok "index.html dry-run succeeds" || bad "index.html dry-run"
php $INSTALL $PAYLOAD "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_PAYLOAD >/dev/null && ok "install dry-run succeeds" || bad "install dry-run"
[ "$(sha "$P/assets/js/report-opname.js")" = "$H_JS" ] && [ ! -e "$P/assets/js/report-opname.js.pre-patch-backup" ] && [ ! -e "$P/assets/js/stock-opname-report-jejak.js" ] && ok "dry runs wrote nothing" || bad "dry runs wrote something"

# --- fail-closed gates ---
expect_fail "wrong preimage hash refused (report-opname.js)" php $JS_PATCH "$P/assets/js/report-opname.js" --expect-sha256=$(printf 0%.0s {1..64})
expect_fail "missing --expect-sha256 refused" php $JS_PATCH "$P/assets/js/report-opname.js"
expect_fail "wrong payload hash refused" php $INSTALL $PAYLOAD "$P/assets/js/x.js" --expect-payload-sha256=$(printf 0%.0s {1..64})
sed 's/onRowClick: (row) => TraceDrawer.openOpname(row.id),/onRowClick: (row) => TraceDrawer.openOpname(row.id) \/\/ drifted/' "$P/assets/js/report-opname.js" > "$W/drift.js"
expect_fail "drifted anchor refused even with matching hash" php $JS_PATCH "$W/drift.js" --expect-sha256=$(sha "$W/drift.js")
sed 's#assets/js/report-opname.js?v=[^"]*#assets/js/other.js?v=1#' "$P/index.html" > "$W/drift.html"
expect_fail "index.html without a report-opname.js tag refused" php $IDX_PATCH "$W/drift.html" --expect-sha256=$(sha "$W/drift.html")
cp "$P/index.html" "$W/dup.html"; grep 'report-opname.js' "$P/index.html" >> "$W/dup.html"
expect_fail "index.html with two report-opname.js tags refused" php $IDX_PATCH "$W/dup.html" --expect-sha256=$(sha "$W/dup.html")

# --- apply ---
php $JS_PATCH  "$P/assets/js/report-opname.js" --expect-sha256=$H_JS  --apply >/dev/null && ok "apply report-opname.js" || bad "apply report-opname.js"
php $CSS_PATCH "$P/assets/css/app.css"         --expect-sha256=$H_CSS --apply >/dev/null && ok "apply app.css" || bad "apply app.css"
php $IDX_PATCH "$P/index.html"                 --expect-sha256=$H_IDX --apply >/dev/null && ok "apply index.html" || bad "apply index.html"
php $INSTALL $PAYLOAD "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_PAYLOAD --apply >/dev/null && ok "install stock-opname-report-jejak.js" || bad "install"

# --- patched production-like files == the tested dev files ---
cmp -s "$P/assets/js/report-opname.js" public/assets/js/report-opname.js && ok "patched report-opname.js is byte-identical to the tested dev file" || bad "report-opname.js differs from dev"
cmp -s "$P/assets/css/app.css" public/assets/css/app.css && ok "patched app.css is byte-identical to the tested dev file" || bad "app.css differs from dev"
cmp -s "$P/assets/js/stock-opname-report-jejak.js" $PAYLOAD && ok "installed jejak.js is byte-identical to the tested dev file" || bad "jejak.js differs"
grep -q 'assets/js/stock-opname-report-jejak.js?v=20261007-jejak2' "$P/index.html" && grep -q 'report-opname.js?v=20261007-jejak2' "$P/index.html" && grep -q 'app.css?v=20261007-jejak2' "$P/index.html" && ok "index.html: new tag + bumped tokens present" || bad "index.html tokens"
[ "$(grep -c 'stock-opname-report-jejak.js' "$P/index.html")" = 1 ] && ok "index.html: exactly one jejak tag" || bad "index.html jejak tag count"
! grep -q 'stock-opname-report\.js' "$P/index.html" && ok "index.html: still has NO stock-opname-report.js reference (not added)" || bad "index.html references stock-opname-report.js"
# the line right after the report-opname.js tag is the jejak tag
awk '/report-opname.js/{getline n; print n}' "$P/index.html" | grep -q 'stock-opname-report-jejak.js' && ok "index.html: jejak tag directly follows the report-opname.js tag" || bad "index.html tag order"

# --- double-apply refusal ---
expect_fail "double-apply refused (report-opname.js)" php $JS_PATCH "$P/assets/js/report-opname.js" --expect-sha256=$(sha "$P/assets/js/report-opname.js") --apply
expect_fail "double-apply refused (app.css)" php $CSS_PATCH "$P/assets/css/app.css" --expect-sha256=$(sha "$P/assets/css/app.css") --apply
expect_fail "double-apply refused (index.html)" php $IDX_PATCH "$P/index.html" --expect-sha256=$(sha "$P/index.html") --apply
expect_fail "re-install over existing file refused" php $INSTALL $PAYLOAD "$P/assets/js/stock-opname-report-jejak.js" --expect-payload-sha256=$H_PAYLOAD --apply
expect_fail "stale backup blocks a re-apply even against the ORIGINAL hash" bash -c "cp '$W/orig_report-opname.js' '$W/re.js'; cp '$P/assets/js/report-opname.js.pre-patch-backup' '$W/re.js.pre-patch-backup'; php $JS_PATCH '$W/re.js' --expect-sha256=$H_JS --apply"

# --- rollback: tamper => refuse and change nothing ---
cp "$P/index.html" "$W/index.patched"
echo "<!-- hand edit -->" >> "$P/index.html"
expect_fail "rollback refuses when a patched file was edited afterwards" php $ROLLBACK --public-dir="$P" --apply
[ -e "$P/assets/js/stock-opname-report-jejak.js" ] && [ "$(sha "$P/assets/js/report-opname.js")" != "$H_JS" ] && ok "refused rollback changed nothing (two-phase)" || bad "refused rollback changed something"
cp "$W/index.patched" "$P/index.html"

# --- rollback ---
php $ROLLBACK --public-dir="$P" >/dev/null && ok "rollback dry-run succeeds" || bad "rollback dry-run"
[ -e "$P/assets/js/stock-opname-report-jejak.js" ] && ok "rollback dry-run changed nothing" || bad "rollback dry-run changed something"
php $ROLLBACK --public-dir="$P" --apply >/dev/null && ok "rollback --apply succeeds" || bad "rollback apply"
[ "$(sha "$P/assets/js/report-opname.js")" = "$H_JS" ] && [ "$(sha "$P/assets/css/app.css")" = "$H_CSS" ] && [ "$(sha "$P/index.html")" = "$H_IDX" ] && ok "all three files restored byte-exact to the production preimage hashes" || bad "restore not byte-exact"
[ ! -e "$P/assets/js/stock-opname-report-jejak.js" ] && ok "new jejak.js removed" || bad "jejak.js still present"
expect_fail "second rollback refused (nothing to roll back)" php $ROLLBACK --public-dir="$P" --apply
# patch can be applied again after a clean rollback (stale backups are kept, so they must be moved away deliberately)
rm -f "$P"/*.pre-patch-backup "$P"/assets/js/*.pre-patch-backup "$P"/assets/css/*.pre-patch-backup
php $JS_PATCH "$P/assets/js/report-opname.js" --expect-sha256=$H_JS >/dev/null && ok "after rollback + backup cleanup the patcher is usable again" || bad "re-patch after rollback"

echo; echo "$pass passed, $fail failed."; [ "$fail" -eq 0 ]
