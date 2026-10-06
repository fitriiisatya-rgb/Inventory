#!/usr/bin/env bash
# Rehearsal of the Reports v3 production package over production-like trees built from the project history (NOT production): pre-flight / dry-run / apply / verify / idempotence / rollback,
# plus the fail-closed negatives (tampered payload, unknown file version, file changed between dry-run and apply, broken markers, duplicate tags).
#   bash tests/rv3_package_rehearsal.sh [<package.tar.gz>]       (without an argument it builds the package from HEAD first)
set -u
cd "$(git rev-parse --show-toplevel)"
W="$(mktemp -d /tmp/rv3_rehearsal_XXXXXX)"; trap 'rm -rf "${W:?}"' EXIT
PHP="${PHP_BIN:-php}"
pass=0; fail=0
ok() { pass=$((pass+1)); echo "PASS - $1"; }
bad() { fail=$((fail+1)); echo "FAIL - $1"; }
chk() { if [ "$2" = "0" ]; then ok "$1"; else bad "$1"; fi; }
tree_sum() { ( cd "$1" && find . -type f ! -path './state/*' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1 ); }

PKG="${1:-}"
if [ -z "$PKG" ]; then
  "$PHP" scripts/build_rv3_package.php "$W/out" >"$W/build.log" 2>&1 || { cat "$W/build.log"; exit 1; }
  PKG="$W/out/reports_v3_production_deploy_package.tar.gz"
fi
unpack() { rm -rf "${1:?}"; mkdir -p "$1"; tar -xzf "$PKG" -C "$1"; echo "$1/reports_v3_production_deploy_package"; }
echo "package: $PKG"; sha256sum "$PKG" | cut -d' ' -f1

# production-like trees: the project history at the commits where each earlier package was delivered, + the current tree (everything already applied)
TREES="${RV3_TREES:-55fc7f8 af5ead4 f59e816 3bb9a91 HEAD}"
for rev in $TREES; do
  echo; echo "=================== tree @ $rev ==================="
  T="$W/tree_$rev"; mkdir -p "$T"; git archive "$rev" | tar -x -C "$T"
  P="$(unpack "$W/pkg_$rev")"
  before="$(tree_sum "$T")"
  if [ "$rev" = HEAD ]; then
    "$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan_$rev.txt" 2>&1; rc=$?
    grep -q "PLAN: NOTHING_TO_DO" "$W/plan_$rev.txt"; chk "[$rev] the already-final tree: dry-run reports NOTHING_TO_DO (idempotent) and exits 0" $((rc + $?))
    chk "[$rev] dry-run changed no application file" $([ "$(tree_sum "$T")" = "$before" ] && echo 0 || echo 1)
    "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/apply_$rev.txt" 2>&1; rc=$?
    grep -q "Nothing to do" "$W/apply_$rev.txt"; chk "[$rev] apply on the final tree = nothing to do, no file touched" $((rc + $?))
    "$PHP" "$P/scripts/rv3_engine.php" verify --app-root="$T" >"$W/verify_$rev.txt" 2>&1; chk "[$rev] verify passes on the final tree" $?
    continue
  fi
  "$PHP" "$P/scripts/rv3_engine.php" preflight --app-root="$T" >"$W/pre_$rev.txt" 2>&1; rc=$?
  chk "[$rev] pre-flight exits 0 (environment + integrity + dependency self-check)" $rc
  [ $rc -ne 0 ] && grep -E "^FAIL|BLOCKED|conflict" "$W/pre_$rev.txt" | head -8
  chk "[$rev] pre-flight changed no application file" $([ "$(tree_sum "$T")" = "$before" ] && echo 0 || echo 1)
  "$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan_$rev.txt" 2>&1; rc=$?
  chk "[$rev] dry-run: PLAN OK (exit 0)" $rc
  grep -q "PLAN: OK" "$W/plan_$rev.txt"; chk "[$rev] dry-run prints 'PLAN: OK'" $?
  chk "[$rev] dry-run changed no application file (plan + report live in the package folder)" $([ "$(tree_sum "$T")" = "$before" ] && echo 0 || echo 1)
  [ -f "$P/state/plan.json" ] && [ -f "$P/state/dryrun_report.txt" ]; chk "[$rev] state/plan.json and state/dryrun_report.txt written" $?
  "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" >"$W/applyno_$rev.txt" 2>&1; chk "[$rev] apply WITHOUT --yes writes nothing" $([ "$(tree_sum "$T")" = "$before" ] && echo 0 || echo 1)
  "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/apply_$rev.txt" 2>&1; rc=$?
  chk "[$rev] apply --yes succeeds" $rc; [ $rc -ne 0 ] && tail -8 "$W/apply_$rev.txt"
  after="$(tree_sum "$T")"
  [ "$after" != "$before" ]; chk "[$rev] the tree changed" $?
  "$PHP" "$P/scripts/rv3_engine.php" verify --app-root="$T" >"$W/verify_$rev.txt" 2>&1; rc=$?
  chk "[$rev] verify passes (all operations done, php -l, one tag each, one render per tab, recorded hashes)" $rc; [ $rc -ne 0 ] && grep -E "FAIL|todo|conflict" "$W/verify_$rev.txt" | head
  # the result must be the SAME tree no matter where we started: compare the files the package owns with the final (HEAD) ones
  mism=0; for f in public/assets/js/report-tools.js public/assets/js/report-pergerakan.js public/assets/js/report-pembelian-v3.js public/assets/js/report-nilai-hpp-v3.js public/assets/js/report-inout-v3.js public/assets/js/report-opname-audit.js services/ReportsV3Routes.php services/ReportExportService.php services/MovementReportV3Service.php services/PurchaseReportService.php services/InOutReportService.php services/InventoryValuationService.php services/StockOpnameAuditReportService.php; do
    cmp -s "$T/$f" <(git show HEAD:"$f") || { mism=1; echo "   differs from HEAD: $f"; }
  done
  chk "[$rev] every shipped file equals the committed one" $mism
  [ "$(grep -c "sidebar-link" "$T/public/index.html")" -gt 0 ]; chk "[$rev] index.html still has its sidebar links" $?
  $PHP -r '
    $h = file_get_contents($argv[1]); preg_match("#<div class=\"sidebar-group[^\"]*\"\s+data-group=\"laporan\">#", $h, $m, PREG_OFFSET_CAPTURE);
    $s = substr($h, $m[0][1], 4000); $sub = substr($s, 0, strpos($s, "sidebar-legacy") ?: 4000);
    preg_match_all("#data-tab=\"([^\"]+)\"#", substr($sub, 0, strpos($sub, "</div>")), $t);
    echo implode("|", $t[1]);' "$T/public/index.html" >"$W/menu_$rev.txt"
  [ "$(cat "$W/menu_$rev.txt")" = "laporan-pergerakan|laporan-inout|laporan-pembelian|laporan-hpp|laporan-opname" ]; chk "[$rev] the Laporan menu lists exactly the 5 approved reports (laporan-opname, not opname-laporan)" $?
  [ "$(grep -c 'data-tab="laporan-opname"' "$T/public/index.html")" = 1 ] && [ "$(grep -c 'data-tab="opname-laporan"' "$T/public/index.html")" -le 1 ]; chk "[$rev] no duplicated data-tab for the Stock Opname report" $?
  "$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan2_$rev.txt" 2>&1; grep -q "NOTHING_TO_DO" "$W/plan2_$rev.txt"; chk "[$rev] a second dry-run reports NOTHING_TO_DO (idempotent)" $?
  "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/apply2_$rev.txt" 2>&1; rc=$?; [ "$(tree_sum "$T")" = "$after" ]; chk "[$rev] a second apply is a no-op (double-run safe)" $((rc + $?))
  "$PHP" "$P/scripts/rv3_engine.php" rollback --app-root="$T" >"$W/rb_$rev.txt" 2>&1; rc=$?
  chk "[$rev] rollback succeeds" $rc
  [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] after rollback the tree is BYTE-IDENTICAL to the one before the apply" $?
  "$PHP" "$P/scripts/rv3_engine.php" rollback --app-root="$T" >"$W/rb2_$rev.txt" 2>&1; grep -q "Nothing to roll back" "$W/rb2_$rev.txt"; chk "[$rev] a second rollback is refused politely (nothing to roll back)" $?
  "$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/apply3_$rev.txt" 2>&1; chk "[$rev] re-apply after rollback works" $?
  [ "$(tree_sum "$T")" = "$after" ]; chk "[$rev] ... and gives exactly the same tree as the first apply (deterministic)" $?
done

# ---------------------------------------------------------------- fail-closed negatives (on the tree of the IO package)
echo; echo "=================== negatives ==================="
REV=3bb9a91
neg() { # name, mutate-cmd (cwd = tree), expect text in plan/apply output
  local name="$1" mut="$2" expect="$3"
  local T="$W/neg_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive "$REV" | tar -x -C "$T"
  local P; P="$(unpack "$W/neg_pkg")"
  ( cd "$T" && eval "$mut" )
  local b; b="$(tree_sum "$T")"
  "$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/neg_plan.txt" 2>&1; local rc=$?
  [ $rc -ne 0 ] && grep -q -E "$expect" "$W/neg_plan.txt"; chk "[neg] $name: dry-run refuses ($expect)" $?
  "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?
  [ $rc -ne 0 ] && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] $name: apply refuses and writes NOTHING" $?
}
neg "an unknown version of a shipped service" 'echo "// local hotfix" >> services/PurchaseReportService.php' "UNKNOWN version"
neg "a missing render statement in app.js" "sed -i \"s/tab-laporan-pembelian/tab-laporan-pembelianX/\" public/assets/js/app.js" "no render statement for tab-laporan-pembelian"
neg "a broken CSS marker pair" 'printf "\n/* ===== RV3 REPORT FAMILY 20261008 BEGIN =====\n.x{}\n" >> public/assets/css/app.css' "marker pair broken"
neg "a duplicated script tag" 'sed -i "s#<script src=\"assets/js/app.js#<script src=\"assets/js/report-tools.js?v=1\"></script>\n<script src=\"assets/js/report-tools.js?v=2\"></script>\n<script src=\"assets/js/app.js#" public/index.html' "duplicate"
neg "the dispatch anchor missing in index.php" 'sed -i "s#// ---- dispatch: exact match first, then {param} patterns ----#// moved#" public/index.php' "anchor line"
neg "a missing dependency service the payload needs" 'rm services/MovementDailyReportService.php' "FAIL|missing"

# tampered package
echo
T="$W/neg_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive "$REV" | tar -x -C "$T"
P="$(unpack "$W/neg_pkg")"; echo "// tampered" >> "$P/payload/public/assets/js/report-tools.js"; b="$(tree_sum "$T")"
"$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/neg_plan.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q -E "does not match|SHA256SUMS" "$W/neg_plan.txt"; chk "[neg] a tampered payload file: dry-run refuses (SHA256SUMS / manifest mismatch)" $?
"$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] a tampered payload: nothing written" $?

# a file changed between the dry-run and the apply
T="$W/neg_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive "$REV" | tar -x -C "$T"
P="$(unpack "$W/neg_pkg")"
"$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1
echo "<!-- touched after the dry-run -->" >> "$T/public/index.html"; b="$(tree_sum "$T")"
"$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q "changed since the dry-run" "$W/neg_apply.txt" && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] a file changed between dry-run and apply: apply refuses, nothing written" $?
# apply without any dry-run
rm -f "$P/state/plan.json"; "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q "no dry-run plan" "$W/neg_apply.txt"; chk "[neg] apply without a dry-run plan is refused" $?
# rollback refuses when a file was edited after the apply
T="$W/neg_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive "$REV" | tar -x -C "$T"; P="$(unpack "$W/neg_pkg")"
"$PHP" "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; "$PHP" "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1
echo "/* edited after apply */" >> "$T/public/assets/css/app.css"; b="$(tree_sum "$T")"
"$PHP" "$P/scripts/rv3_engine.php" rollback --app-root="$T" >"$W/neg_rb.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q "no longer byte-identical" "$W/neg_rb.txt" && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] rollback phase 1 refuses when a file was edited after the apply (nothing restored)" $?

echo; echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
