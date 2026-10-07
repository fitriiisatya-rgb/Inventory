#!/usr/bin/env bash
# Rehearsal of the PERIOD CUTOFF + KARANG TENGAH OPENING package (RV3_MODE=cutover) over production-like trees (NOT production).
# production-like tree = a project-history tree + the VALIDATED Reports v3 recovery package applied on top (built here from commit RV3_BASE_REV, the package the owner applied).
#   bash tests/rv3_cutover_package_rehearsal.sh [<cutover package.tar.gz>]
#   PHP_ARGS="-d disable_functions=symlink,link,readlink,escapeshellarg,escapeshellcmd,exec,shell_exec" bash tests/rv3_cutover_package_rehearsal.sh     (shared hosting)
#   RV3_VALIDATE=1 ...   also runs the validators + the BEFORE / AFTER table against the test database (needs MariaDB + DB_* env)
set -u
cd "$(git rev-parse --show-toplevel)"
W="$(mktemp -d /tmp/rv3_cutreh_XXXXXX)"; trap 'rm -rf "${W:?}"' EXIT
PHP="${PHP_BIN:-php}"; PHPA="${PHP_ARGS:-}"; export PHP_ARGS="${PHP_ARGS:-}"
BASE_REV="${RV3_BASE_REV:-58a72b2}"
pass=0; fail=0
ok() { pass=$((pass+1)); echo "PASS - $1"; }
bad() { fail=$((fail+1)); echo "FAIL - $1"; }
chk() { if [ "$2" = "0" ]; then ok "$1"; else bad "$1"; fi; }
tree_sum() { ( cd "$1" && find . -type f ! -path './state/*' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1 ); }

git clone -q --no-hardlinks . "$W/old" && git -C "$W/old" checkout -q "$BASE_REV" || { echo "cannot check out $BASE_REV"; exit 1; }
( cd "$W/old" && "$PHP" scripts/build_rv3_package.php "$W/base_out" >"$W/base_build.log" 2>&1 ) || { cat "$W/base_build.log"; exit 1; }
BASEPKG="$W/base_out/reports_v3_recovery_package.tar.gz"
PKG="${1:-}"
if [ -z "$PKG" ]; then
  RV3_MODE=cutover "$PHP" scripts/build_rv3_package.php "$W/cut_out" >"$W/cut_build.log" 2>&1 || { cat "$W/cut_build.log"; exit 1; }
  PKG="$W/cut_out/period_cutoff_karang_opening_package.tar.gz"
fi
echo "validated base package: $(sha256sum "$BASEPKG" | cut -d' ' -f1)  (built from $BASE_REV)"
echo "cutover package        : $(sha256sum "$PKG" | cut -d' ' -f1)"
unpack() { rm -rf "${2:?}"; mkdir -p "$2"; tar -xzf "$1" -C "$2"; ls -d "$2"/*/ | head -1 | sed 's#/$##'; }
base_apply() { local P; P="$(unpack "$BASEPKG" "$W/basepkg_$$")"; "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$1" >/dev/null 2>&1; "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$1" --yes >"$W/base_apply.txt" 2>&1; }
OWNED="services/InventoryEffectiveDateService.php services/MovementDailyReportService.php services/MovementReportV3Service.php services/InventoryValuationService.php services/InventoryHppReportService.php services/DashboardInventoryService.php public/assets/js/dashboard.js"
BACKEND="services/ReportsV3Routes.php services/ReportExportService.php services/PurchaseReportService.php services/InOutReportService.php services/StockOpnameAuditReportService.php"

for rev in ${RV3_TREES:-3bb9a91 f59e816 af5ead4 39fc986}; do
  echo; echo "=================== production-like tree: history @ $rev + the validated recovery package ==================="
  T="$W/tree_$rev"; mkdir -p "$T"; git archive "$rev" | tar -x -C "$T"
  P="$(unpack "$PKG" "$W/pkg_$rev")"
  b0="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/gate_plan.txt" 2>&1; rc=$?
  [ $rc -ne 0 ] && grep -q "BLOCKED" "$W/gate_plan.txt" && grep -q -E "backend (package|file)|not installed|Reports v3 marker" "$W/gate_plan.txt"; chk "[$rev] WITHOUT the validated Reports v3 backend the dry-run is BLOCKED (the dashboard would call a service that is not there)" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; rc=$?; [ $rc -ne 0 ] && [ "$(tree_sum "$T")" = "$b0" ]; chk "[$rev] ... and apply refuses and writes NOTHING" $?
  rm -f "$P/state/plan.json"
  base_apply "$T"; chk "[$rev] (setup) the validated recovery package applied" $?
  before="$(tree_sum "$T")"
  rm -rf "${W:?}/svc_before"; cp -r "$T/services" "$W/svc_before"; cp "$T/public/index.php" "$W/index_before.php"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" preflight --app-root="$T" >"$W/pre_$rev.txt" 2>&1; rc=$?
  chk "[$rev] pre-flight exits 0 (gates done, dependency self-check with the packaged dashboard service)" $rc; [ $rc -ne 0 ] && grep -E "^FAIL|BLOCKED|conflict" "$W/pre_$rev.txt" | head -6
  grep -qE "^ +CREATE +services/InventoryEffectiveDateService.php" "$W/pre_$rev.txt"; chk "[$rev] the INSTALL PLAN names CREATE for the new InventoryEffectiveDateService.php" $?
  ok_up=0; for f in MovementDailyReportService MovementReportV3Service InventoryValuationService InventoryHppReportService DashboardInventoryService; do grep -qE "^ +(UPDATE|UNCHANGED) +services/$f.php" "$W/pre_$rev.txt" || { ok_up=1; echo "   plan lacks $f"; }; done; chk "[$rev] ... and UPDATE for the five period-aware report / dashboard services (installed sha256 = the project commit it equals)" $ok_up
  grep -qE "^ +UPDATE +public/assets/js/dashboard.js" "$W/pre_$rev.txt"; chk "[$rev] ... and UPDATE dashboard.js (the dashboard ops are part of this package, idempotent)" $?
  grep -qE "^ +EDIT +public/assets/css/app.css" "$W/pre_$rev.txt" && grep -qE "^ +EDIT +public/index.html" "$W/pre_$rev.txt"; chk "[$rev] ... EDIT app.css (one block) and index.html (cache-bust tokens)" $?
  grep -qE "^ +(CREATE|UPDATE|EDIT) +(services/(ReportExport|Purchase|InOut|StockOpnameAudit|ReportsV3Routes)|public/index.php|database/)" "$W/pre_$rev.txt"; [ $? -ne 0 ]; chk "[$rev] ... and NO other backend file / index.php / schema is touched (the DB table is created ONLY by the controlled script)" $?
  [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] pre-flight changed no application file" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan_$rev.txt" 2>&1; chk "[$rev] dry-run exits 0" $?
  [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] dry-run changed no application file" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" >"$W/applyno_$rev.txt" 2>&1; rcno=$?; [ $rcno -eq 10 ] && grep -q "NOT APPLIED" "$W/applyno_$rev.txt" && [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] apply WITHOUT --yes: exit 10 NOT APPLIED, nothing written" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/apply_$rev.txt" 2>&1; rc=$?
  chk "[$rev] apply --yes succeeds" $rc; [ $rc -ne 0 ] && tail -8 "$W/apply_$rev.txt"
  after="$(tree_sum "$T")"; [ "$after" != "$before" ]; chk "[$rev] the tree changed" $?
  grep -q "INSTALLED-CHECK: every packaged file exists" "$W/apply_$rev.txt"; chk "[$rev] apply ended with the INSTALLED-CHECK line" $?
  touched=0; for f in $BACKEND; do cmp -s "$T/$f" "$W/svc_before/$(basename "$f")" || { touched=1; echo "   touched: $f"; }; done; cmp -s "$T/public/index.php" "$W/index_before.php" || { touched=1; echo "   touched: public/index.php"; }
  chk "[$rev] the Reports v3 files this package does not own (export, purchase, in/out, stock opname audit, routes) and index.php are byte-identical to before the apply" $touched
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" verify --app-root="$T" >"$W/verify_$rev.txt" 2>&1; rc=$?; chk "[$rev] verify passes (incl. INSTALLED assertions: gates + dashboard files)" $rc; [ $rc -ne 0 ] && grep -E "FAIL|todo|conflict" "$W/verify_$rev.txt" | head
  mism=0; for f in $OWNED; do cmp -s "$T/$f" <(git show HEAD:"$f") || { mism=1; echo "   differs from HEAD: $f"; }; done
  grep -q "InventoryEffectiveDateService::col" "$T/services/MovementDailyReportService.php" && grep -q "InventoryEffectiveDateService::col" "$T/services/InventoryHppReportService.php"; chk "[$rev] the installed report services read the reporting date through InventoryEffectiveDateService" $?; chk "[$rev] the seven owned files equal the committed ones" $mism
  [ "$(grep -c 'RV3 DASHBOARD MOVEMENT 20261020 BEGIN' "$T/public/assets/css/app.css")" = 1 ]; chk "[$rev] the CSS block is installed exactly once" $?
  grep -q 'dashboard.js?v=20261020-dmv' "$T/public/index.html" && grep -q 'app.css?v=20261020-dmv' "$T/public/index.html"; chk "[$rev] index.html cache-bust tokens bumped (dashboard.js, app.css)" $?
  grep -q "Pergerakan lain" "$T/public/assets/js/dashboard.js" && grep -q "other_movements" "$T/public/assets/js/dashboard.js"; [ $? -ne 0 ]; chk "[$rev] the installed dashboard.js has no 'Pergerakan lain' / other_movements" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan2_$rev.txt" 2>&1; grep -q "NOTHING_TO_DO" "$W/plan2_$rev.txt"; chk "[$rev] a second dry-run: NOTHING_TO_DO (idempotent)" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" rollback --app-root="$T" >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] rollback: BYTE-IDENTICAL to the validated production state" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$after" ]; chk "[$rev] re-apply after rollback: same tree (deterministic)" $?
done

echo; echo "=================== negatives ==================="
neg_tree() { local T="$W/neg_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive 3bb9a91 | tar -x -C "$T"; base_apply "$T"; echo "$T"; }
neg() {
  local name="$1" mut="$2" expect="$3"
  local T; T="$(neg_tree)"; local P; P="$(unpack "$PKG" "$W/neg_pkg")"
  ( cd "$T" && eval "$mut" ); local b; b="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/neg_plan.txt" 2>&1; local rc=$?
  [ $rc -ne 0 ] && grep -q -E "$expect" "$W/neg_plan.txt"; chk "[neg] $name: dry-run refuses ($expect)" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; [ $? -ne 0 ] && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] $name: apply refuses and writes NOTHING" $?
}
neg "a hot-fixed Reports v3 service the package does not own (validated version required)" 'echo "// local hotfix" >> services/ReportExportService.php' "not at the validated version"
neg "a hand-edited MovementDailyReportService.php (unknown version, never overwritten blindly)" 'echo "// local edit" >> services/MovementDailyReportService.php' "UNKNOWN version"
neg "a hand-edited InventoryHppReportService.php" 'echo "// local edit" >> services/InventoryHppReportService.php' "UNKNOWN version"
neg "the route include removed from index.php" 'sed -i "s#// >>> RV3 reports_v3 BEGIN#// >>> gone#" public/index.php' "marker"
neg "a hand-edited dashboard.js (unknown version, never overwritten blindly)" 'echo "// local edit" >> public/assets/js/dashboard.js' "UNKNOWN version"
neg "a hand-edited DashboardInventoryService.php" 'echo "// local edit" >> services/DashboardInventoryService.php' "UNKNOWN version"
neg "a broken CSS marker pair" 'printf "\n/* ===== RV3 DASHBOARD MOVEMENT 20261020 BEGIN =====\n.x{}\n" >> public/assets/css/app.css' "marker pair broken"
neg "the dashboard script tag missing from index.html" 'sed -i "s#assets/js/dashboard.js#assets/js/dashboardX.js#" public/index.html' "dashboard.js referenced 0 time"
T="$(neg_tree)"; P="$(unpack "$PKG" "$W/neg_pkg")"; echo "// tampered" >> "$P/payload/public/assets/js/dashboard.js"; b="$(tree_sum "$T")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/neg_plan.txt" 2>&1; rc=$?; [ $rc -ne 0 ] && grep -q -E "does not match|SHA256SUMS" "$W/neg_plan.txt"; chk "[neg] a tampered payload file: dry-run refuses" $?
"$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] a tampered payload: nothing written" $?
T="$(neg_tree)"; P="$(unpack "$PKG" "$W/neg_pkg")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; echo "<!-- touched -->" >> "$T/public/index.html"; b="$(tree_sum "$T")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?; [ $rc -ne 0 ] && grep -q "changed since the dry-run" "$W/neg_apply.txt" && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] a file changed between dry-run and apply: refused, nothing written" $?

T="$W/head_tree"; mkdir -p "$T"; git archive HEAD | tar -x -C "$T"; P="$(unpack "$PKG" "$W/head_pkg")"; b="$(tree_sum "$T")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/head_plan.txt" 2>&1; rc=$?; grep -q "PLAN: NOTHING_TO_DO" "$W/head_plan.txt"; chk "[HEAD] the already-final tree: NOTHING_TO_DO, exit 0" $((rc + $?))

if [ "${RV3_VALIDATE:-0}" = 1 ]; then
  echo; echo "=================== validators + read-only previews (test database) ==================="
  T="$W/val_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive 3bb9a91 | tar -x -C "$T"; base_apply "$T"
  P="$(unpack "$PKG" "$W/val_pkg")"
  mysql -uroot -e "DROP DATABASE IF EXISTS ${DB_DATABASE:-inventory_test}; CREATE DATABASE ${DB_DATABASE:-inventory_test} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" && mysql -uroot "${DB_DATABASE:-inventory_test}" < database/schema.sql && php tests/browser/seed_dashboard_real.php >/dev/null 2>&1
  mkdir -p "$T/storage"; cp -r storage/stock_opname_photos "$T/storage/" 2>/dev/null
  before="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_readonly_check.php" --app-root="$T" --mode=predeploy --package-dir="$P" --session=1,2 >"$W/pre_val.txt" 2>&1; rc=$?; chk "[predeploy] PRE-DEPLOY validator (candidate services from the payload) exit 0" $rc; [ $rc -ne 0 ] && grep -E "^FAIL|FAILED" "$W/pre_val.txt" | head -5
  grep -q 'Period cutoff / opening balance' "$W/pre_val.txt" && grep -q 'Dashboard "Ringkasan Pergerakan Stok"' "$W/pre_val.txt"; chk "[predeploy] it includes the period-cutoff continuity check and the dashboard == report reconciliation" $?
  [ "$(tree_sum "$T")" = "$before" ]; chk "[predeploy] it wrote nothing into the application" $?
  "$PHP" $PHPA "$P/scripts/rv3_readonly_check.php" --app-root="$T" --mode=installed --session=1,2 >"$W/inst0.txt" 2>&1; rc=$?
  [ $rc -ne 0 ] && grep -q "InventoryEffectiveDateService" "$W/inst0.txt"; chk "[installed] BEFORE the apply the INSTALLED validator FAILS (InventoryEffectiveDateService is not installed)" $?
  bash "$P/scripts/karang_preview.sh" "$T" >"$W/kt_prev.txt" 2>&1; rc=$?
  [ $rc -eq 11 ] && grep -q "PREVIEW SHA256" "$W/kt_prev.txt" && grep -q "380" "$W/kt_prev.txt" && grep -q "STATUS POSTING : DIBLOKIR" "$W/kt_prev.txt"; chk "[karang] preview against this DB (no Karang master): exit 11, 380 rows read, blockers listed, preview sha printed" $?
  [ -s "$P/out/karang_mapping_all_rows.csv" ] && [ "$(wc -l < "$P/out/karang_mapping_all_rows.csv")" = 381 ] && [ -s "$P/out/karang_blockers.csv" ] && [ -s "$P/out/karang_summary.json" ]; chk "[karang] the mapping csv has 380 rows + header, plus blockers.csv and summary.json (written OUTSIDE the application)" $?
  [ "$(tree_sum "$T")" = "$before" ]; chk "[karang] preview wrote nothing into the application" $?
  bash "$P/scripts/period_cutoff_preview.sh" "$T" --sessions=1,2 --cutoff=2026-09-30 >"$W/pc_prev.txt" 2>&1; rc=$?
  grep -q "PERIOD CUTOFF — PREVIEW" "$W/pc_prev.txt" && grep -q "PREVIEW SHA256" "$W/pc_prev.txt"; chk "[period cutoff] preview runs against the real tables (exit $rc), prints the plan and the preview sha" $?
  [ "$(mysql -uroot -N "${DB_DATABASE:-inventory_test}" -e "SELECT COUNT(*) FROM inventory_effective_dates")" = 0 ]; chk "[period cutoff] preview wrote no override row" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/val_apply.txt" 2>&1; chk "[installed] apply --yes (files only)" $?
  bash "$P/scripts/installed_verify.sh" "$T" --session=1,2 >"$W/inst1.txt" 2>&1; rc=$?; chk "[installed] AFTER the apply installed_verify.sh passes" $rc; [ $rc -ne 0 ] && grep -E "^FAIL|FAILED" "$W/inst1.txt" | head -5
  [ "$(grep -cE '^PASS +[0-9]+ / [0-9]+ ' "$W/inst1.txt")" = 8 ]; chk "[installed] eight reconciliations PASS (six reports + dashboard + period cutoff continuity)" $?
  grep -q "PASS - App.Services.InventoryEffectiveDateService is loaded from .*/services/InventoryEffectiveDateService.php" "$W/inst1.txt"; chk "[installed] Reflection: InventoryEffectiveDateService is loaded from <APP>/services/" $?
  grep -q "PASS - no file of the package payload was loaded" "$W/inst1.txt"; chk "[installed] no package payload file was loaded" $?
  [ "$(mysql -uroot -N "${DB_DATABASE:-inventory_test}" -e "SELECT COUNT(*) FROM inventory_effective_dates")" = 0 ] && [ "$(mysql -uroot -N "${DB_DATABASE:-inventory_test}" -e "SELECT COUNT(*) FROM inventory_transactions WHERE reference_no = 'KARANG_TENGAH_SO_20261001'")" = 0 ]; chk "[installed] installing the files wrote NO data (no override row, no Karang opening)" $?
fi
echo; echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
