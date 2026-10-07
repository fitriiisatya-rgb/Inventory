#!/usr/bin/env bash
# Rehearsal of the FRONTEND-ONLY "UI correction" package (RV3_MODE=ui) over production-like trees (NOT production).
# A production-like tree = a project-history tree + the VALIDATED Reports v3 package applied on top (that package is built here from commit 5c6d0aa, the one the owner applied), i.e. the state production is in.
#   bash tests/rv3_ui_package_rehearsal.sh [<ui package.tar.gz>]       (without an argument the UI package is built from HEAD first)
#   PHP_ARGS="-d disable_functions=symlink,link,readlink,escapeshellarg,escapeshellcmd,exec,shell_exec" bash tests/rv3_ui_package_rehearsal.sh      (the shared-hosting case)
#   RV3_VALIDATE=1 ... also runs the read-only validator of the UI package against the test database (needs MariaDB + DB_* env)
set -u
cd "$(git rev-parse --show-toplevel)"
W="$(mktemp -d /tmp/rv3_uireh_XXXXXX)"; trap 'rm -rf "${W:?}"' EXIT
PHP="${PHP_BIN:-php}"; PHPA="${PHP_ARGS:-}"; export PHP_ARGS="${PHP_ARGS:-}"
BASE_REV="${RV3_BASE_REV:-5c6d0aa}"
pass=0; fail=0
ok() { pass=$((pass+1)); echo "PASS - $1"; }
bad() { fail=$((fail+1)); echo "FAIL - $1"; }
chk() { if [ "$2" = "0" ]; then ok "$1"; else bad "$1"; fi; }
tree_sum() { ( cd "$1" && find . -type f ! -path './state/*' -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1 ); }

# the VALIDATED package (what production already runs): built from its own commit with that commit's own builder
git clone -q --no-hardlinks . "$W/old" && git -C "$W/old" checkout -q "$BASE_REV" || { echo "cannot check out $BASE_REV"; exit 1; }
( cd "$W/old" && "$PHP" scripts/build_rv3_package.php "$W/base_out" >"$W/base_build.log" 2>&1 ) || { cat "$W/base_build.log"; exit 1; }
BASEPKG="$W/base_out/reports_v3_production_deploy_package.tar.gz"
UIPKG="${1:-}"
if [ -z "$UIPKG" ]; then
  RV3_MODE=ui "$PHP" scripts/build_rv3_package.php "$W/ui_out" >"$W/ui_build.log" 2>&1 || { cat "$W/ui_build.log"; exit 1; }
  UIPKG="$W/ui_out/reports_v3_ui_correction_package.tar.gz"
fi
echo "validated base package: $(sha256sum "$BASEPKG" | cut -d' ' -f1)  (built from $BASE_REV)"
echo "UI package            : $(sha256sum "$UIPKG" | cut -d' ' -f1)"
unpack() { rm -rf "${2:?}"; mkdir -p "$2"; tar -xzf "$1" -C "$2"; ls -d "$2"/*/ | head -1 | sed 's#/$##'; }
base_apply() { # tree
  local P; P="$(unpack "$BASEPKG" "$W/basepkg_$$")"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$1" >/dev/null 2>&1
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$1" --yes >"$W/base_apply.txt" 2>&1
}
OWNED="public/assets/js/report-tools.js public/assets/js/report-pergerakan.js public/assets/js/report-pembelian-v3.js public/assets/js/report-nilai-hpp-v3.js public/assets/js/report-inout-v3.js public/assets/js/report-opname-audit.js"
BACKEND="services/ReportsV3Routes.php services/ReportExportService.php services/MovementReportV3Service.php services/PurchaseReportService.php services/InOutReportService.php services/InventoryValuationService.php services/StockOpnameAuditReportService.php services/MovementDailyReportService.php"

TREES="${RV3_TREES:-3bb9a91 f59e816 af5ead4 39fc986}"
for rev in $TREES; do
  echo; echo "=================== production-like tree: history @ $rev + the validated package ==================="
  T="$W/tree_$rev"; mkdir -p "$T"; git archive "$rev" | tar -x -C "$T"
  P="$(unpack "$UIPKG" "$W/pkg_$rev")"
  # 0. the UI package on a tree WITHOUT the validated backend: fail-closed
  b0="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/gate_plan.txt" 2>&1; rc=$?
  [ $rc -ne 0 ] && grep -q "BLOCKED" "$W/gate_plan.txt" && grep -q -E "backend (package|file)|Reports v3 marker|not installed" "$W/gate_plan.txt"; chk "[$rev] WITHOUT the validated backend the dry-run is BLOCKED (the package will not overwrite / guess the backend)" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; rc=$?
  [ $rc -ne 0 ] && [ "$(tree_sum "$T")" = "$b0" ]; chk "[$rev] ... and apply refuses and writes NOTHING" $?
  rm -f "$P/state/plan.json"
  # 1. production state = validated package applied
  base_apply "$T"; chk "[$rev] (setup) the validated Reports v3 package applied to this tree" $?
  for f in $BACKEND; do cmp -s "$T/$f" <(git -C "$W/old" show "$BASE_REV:$f") || { echo "   backend differs from validated: $f"; bad "[$rev] (setup) $f is the validated version"; }; done
  before="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" preflight --app-root="$T" >"$W/pre_$rev.txt" 2>&1; rc=$?
  chk "[$rev] pre-flight exits 0 (environment, integrity, dependency self-check, every gate done)" $rc; [ $rc -ne 0 ] && grep -E "^FAIL|BLOCKED|conflict" "$W/pre_$rev.txt" | head -8
  grep -q "PLAN: OK" "$W/pre_$rev.txt"; chk "[$rev] pre-flight: PLAN OK" $?
  [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] pre-flight changed no application file" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan_$rev.txt" 2>&1; rc=$?
  chk "[$rev] dry-run exits 0" $rc
  [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] dry-run changed no application file" $?
  # the dry-run touches ONLY frontend files and never a backend file
  if grep -E "^\s+\[write" "$W/plan_$rev.txt" | grep -q -E "services/|public/index.php"; then bad "[$rev] the plan writes a backend file"; else ok "[$rev] the plan writes no backend file (services/*, index.php are gates only)"; fi
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] apply WITHOUT --yes writes nothing" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/apply_$rev.txt" 2>&1; rc=$?
  chk "[$rev] apply --yes succeeds" $rc; [ $rc -ne 0 ] && tail -8 "$W/apply_$rev.txt"
  after="$(tree_sum "$T")"; [ "$after" != "$before" ]; chk "[$rev] the tree changed" $?
  for f in $BACKEND public/index.php; do cmp -s "$T/$f" <(git -C "$W/old" show "$BASE_REV:$f") || { [ "$f" = public/index.php ] && continue; bad "[$rev] backend file touched: $f"; }; done
  ok "[$rev] no backend file was modified by the apply (byte-identical to the validated versions)"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" verify --app-root="$T" >"$W/verify_$rev.txt" 2>&1; rc=$?
  chk "[$rev] verify passes (operations done, php -l, one tag each, one render per tab, sidebar = 5 reports, recorded hashes)" $rc; [ $rc -ne 0 ] && grep -E "FAIL|todo|conflict" "$W/verify_$rev.txt" | head
  grep -q "the Laporan menu in public/index.html shows exactly the five approved reports" "$W/verify_$rev.txt" && grep -q "^PASS - the Laporan menu in public/index.html" "$W/verify_$rev.txt"; chk "[$rev] verify checked the sidebar: exactly five report links" $?
  mism=0; for f in $OWNED; do cmp -s "$T/$f" <(git show HEAD:"$f") || { mism=1; echo "   differs from HEAD: $f"; }; done
  chk "[$rev] every shipped frontend file equals the committed one" $mism
  grep -q "RV3 UI CORRECTION 20261019 BEGIN" "$T/public/assets/css/app.css" && [ "$(grep -c 'RV3 UI CORRECTION 20261019 BEGIN' "$T/public/assets/css/app.css")" = 1 ]; chk "[$rev] the UI-correction CSS block is installed exactly once" $?
  grep -q "'laporan-hpp': 'Laporan Nilai HPP'" "$T/public/assets/js/app.js"; chk "[$rev] app.js label: Laporan Nilai HPP" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/plan2_$rev.txt" 2>&1; grep -q "NOTHING_TO_DO" "$W/plan2_$rev.txt"; chk "[$rev] a second dry-run reports NOTHING_TO_DO (idempotent)" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; rc=$?; [ "$(tree_sum "$T")" = "$after" ]; chk "[$rev] a second apply is a no-op" $((rc + $?))
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" rollback --app-root="$T" >"$W/rb_$rev.txt" 2>&1; rc=$?
  chk "[$rev] rollback succeeds" $rc
  [ "$(tree_sum "$T")" = "$before" ]; chk "[$rev] after rollback the tree is BYTE-IDENTICAL to the validated production state" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1
  [ "$(tree_sum "$T")" = "$after" ]; chk "[$rev] re-apply after rollback gives exactly the same tree (deterministic)" $?
  # the page the visitor gets: PHP scan of the final index.html
  $PHP -r 'require $argv[1]."/scripts/rv3_lib.php"; $m=json_decode(file_get_contents($argv[1]."/manifest.json"),true); foreach($m["ops"] as $o){ if($o["type"]==="html_sidebar") $op=$o; }
    $r=rv3_sidebar_report_links(file_get_contents($argv[2]), $op); echo implode("|",$r["visible"]), "#", count($r["old_visible"]);' "$P" "$T/public/index.html" >"$W/menu_$rev.txt"
  [ "$(cat "$W/menu_$rev.txt")" = "Laporan Pergerakan Stok|Laporan IN / OUT|Laporan Pembelian|Laporan Nilai HPP|Laporan Stock Opname#0" ]; chk "[$rev] final index.html: visible report links = the five approved labels, 0 old links visible" $?
done

# ---------------------------------------------------------------- the reported production layout: every old link visible in the Laporan menu, no hidden container
echo; echo "=================== stale sidebar (the layout the owner reported) ==================="
T="$W/stale_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive 3bb9a91 | tar -x -C "$T"; base_apply "$T"
P="$(unpack "$UIPKG" "$W/stale_pkg")"
$PHP -r '
  $h = file_get_contents($argv[1]); $g = strpos($h, "data-group=\"laporan\""); $s = strpos($h, "<div class=\"sidebar-submenu\"", $g); $e = strpos($h, "</div>", $s); $open = substr($h, $s, strpos($h, ">", $s) - $s + 1);
  $l = fn($t, $n) => "<a class=\"sidebar-link\" data-tab=\"$t\" data-require-permission=\"INVENTORY_VIEW\"><span class=\"icon\">x</span> $n</a>";
  $old = implode("\n", [$l("laporan-ringkasan","Ringkasan Inventory"), $l("laporan-pergerakan","Pergerakan Stok Harian"), $l("laporan-stok","Laporan Stok"), $l("laporan-transfer","Laporan Transfer"), $l("laporan-adjustment","Adjustment / Selisih"), $l("laporan-expiry","Expired / Near Expired"), $l("laporan-supplier","Pembelian per Supplier"), $l("laporan-bakery","Distribusi per Bakery"), $l("laporan-slow-movement","Slow / No Movement"), $l("laporan-rekonsiliasi","Rekonsiliasi Arus Stok"), $l("laporan-audit","Audit Transaksi"), $l("laporan-inout","Laporan IN / OUT"), $l("laporan-pembelian","Laporan Pembelian"), $l("laporan-hpp","Nilai Stok & HPP"), $l("laporan-opname","Laporan Stock Opname")]);
  $h = substr($h, 0, $s) . $open . "\n" . $old . "\n" . substr($h, $e);
  $c = strpos($h, "<div class=\"sidebar-legacy-routes\""); if ($c !== false) { $d = 0; preg_match_all("#<(/?)div\b[^>]*>#", $h, $m, PREG_OFFSET_CAPTURE, $c); foreach ($m[0] as $i => $x) { $d += $m[1][$i][0] === "/" ? -1 : 1; if ($d === 0) { $h = substr($h, 0, $c) . substr($h, $x[1] + strlen($x[0])); break; } } }
  file_put_contents($argv[1], $h);' "$T/public/index.html"
before="$(tree_sum "$T")"
$PHP -r 'require $argv[1]."/scripts/rv3_lib.php"; $m=json_decode(file_get_contents($argv[1]."/manifest.json"),true); foreach($m["ops"] as $o){ if($o["type"]==="html_sidebar") $op=$o; } $r=rv3_sidebar_report_links(file_get_contents($argv[2]), $op); echo count($r["old_visible"]);' "$P" "$T/public/index.html" >"$W/stale_before.txt"
[ "$(cat "$W/stale_before.txt")" -ge 8 ]; chk "[stale] setup: the stale index.html shows $(cat "$W/stale_before.txt") old report links (the reported problem)" $?
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/stale_plan.txt" 2>&1; chk "[stale] dry-run OK" $?
"$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/stale_apply.txt" 2>&1; chk "[stale] apply --yes OK" $?
$PHP -r 'require $argv[1]."/scripts/rv3_lib.php"; $m=json_decode(file_get_contents($argv[1]."/manifest.json"),true); foreach($m["ops"] as $o){ if($o["type"]==="html_sidebar") $op=$o; } $r=rv3_sidebar_report_links(file_get_contents($argv[2]), $op); echo implode("|",$r["visible"]),"#",count($r["old_visible"]),"#",count($r["hidden"]);' "$P" "$T/public/index.html" >"$W/stale_after.txt"
[ "$(cut -d'#' -f1 "$W/stale_after.txt")" = "Laporan Pergerakan Stok|Laporan IN / OUT|Laporan Pembelian|Laporan Nilai HPP|Laporan Stock Opname" ] && [ "$(cut -d'#' -f2 "$W/stale_after.txt")" = 0 ]; chk "[stale] after apply: exactly the five approved links are visible, no old link visible" $?
[ "$(cut -d'#' -f3 "$W/stale_after.txt")" -ge 9 ]; chk "[stale] the old routes are kept (hidden container holds $(cut -d'#' -f3 "$W/stale_after.txt") links)" $?
"$PHP" $PHPA "$P/scripts/rv3_engine.php" verify --app-root="$T" >"$W/stale_verify.txt" 2>&1; chk "[stale] verify passes" $?
"$PHP" $PHPA "$P/scripts/rv3_engine.php" rollback --app-root="$T" >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$before" ]; chk "[stale] rollback restores the stale file byte-identically" $?

# ---------------------------------------------------------------- fail-closed negatives (on a production-like tree)
echo; echo "=================== negatives ==================="
neg_tree() { local T="$W/neg_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive 3bb9a91 | tar -x -C "$T"; base_apply "$T"; echo "$T"; }
neg() { # name, mutate-cmd (cwd = tree), expect text
  local name="$1" mut="$2" expect="$3"
  local T; T="$(neg_tree)"; local P; P="$(unpack "$UIPKG" "$W/neg_pkg")"
  ( cd "$T" && eval "$mut" ); local b; b="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/neg_plan.txt" 2>&1; local rc=$?
  [ $rc -ne 0 ] && grep -q -E "$expect" "$W/neg_plan.txt"; chk "[neg] $name: dry-run refuses ($expect)" $?
  "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?
  [ $rc -ne 0 ] && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] $name: apply refuses and writes NOTHING" $?
}
neg "a hot-fixed backend service (validated version required)" 'echo "// local hotfix" >> services/PurchaseReportService.php' "not at the validated version"
neg "the route include removed from index.php" 'sed -i "s#// >>> RV3 reports_v3 BEGIN#// >>> removed#" public/index.php' "marker"
neg "an unknown version of a shipped page (hand-edited JS)" 'echo "// local edit" >> public/assets/js/report-pergerakan.js' "UNKNOWN version"
neg "a missing render statement in app.js" "sed -i \"s/tab-laporan-pembelian/tab-laporan-pembelianX/\" public/assets/js/app.js" "no render statement for tab-laporan-pembelian"
neg "a broken CSS marker pair" 'printf "\n/* ===== RV3 UI CORRECTION 20261019 BEGIN =====\n.x{}\n" >> public/assets/css/app.css' "marker pair broken"
neg "a duplicated script tag" 'sed -i "s#<script src=\"assets/js/app.js#<script src=\"assets/js/report-tools.js?v=1\"></script>\n<script src=\"assets/js/report-tools.js?v=2\"></script>\n<script src=\"assets/js/app.js#" public/index.html' "duplicate"
neg "the Laporan sidebar group missing" 'sed -i "s#data-group=\"laporan\"#data-group=\"reports\"#" public/index.html' "Laporan sidebar group"
T="$(neg_tree)"; P="$(unpack "$UIPKG" "$W/neg_pkg")"; echo "// tampered" >> "$P/payload/public/assets/js/report-tools.js"; b="$(tree_sum "$T")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/neg_plan.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q -E "does not match|SHA256SUMS" "$W/neg_plan.txt"; chk "[neg] a tampered payload file: dry-run refuses (SHA256SUMS mismatch)" $?
"$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >/dev/null 2>&1; [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] a tampered payload: nothing written" $?
T="$(neg_tree)"; P="$(unpack "$UIPKG" "$W/neg_pkg")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >/dev/null 2>&1; echo "<!-- touched after the dry-run -->" >> "$T/public/index.html"; b="$(tree_sum "$T")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q "changed since the dry-run" "$W/neg_apply.txt" && [ "$(tree_sum "$T")" = "$b" ]; chk "[neg] a file changed between dry-run and apply: apply refuses, nothing written" $?
rm -f "$P/state/plan.json"; "$PHP" $PHPA "$P/scripts/rv3_engine.php" apply --app-root="$T" --yes >"$W/neg_apply.txt" 2>&1; rc=$?
[ $rc -ne 0 ] && grep -q "no dry-run plan" "$W/neg_apply.txt"; chk "[neg] apply without a dry-run plan is refused" $?

# ---------------------------------------------------------------- the final (HEAD) tree: nothing to do
T="$W/head_tree"; mkdir -p "$T"; git archive HEAD | tar -x -C "$T"; P="$(unpack "$UIPKG" "$W/head_pkg")"; b="$(tree_sum "$T")"
"$PHP" $PHPA "$P/scripts/rv3_engine.php" plan --app-root="$T" >"$W/head_plan.txt" 2>&1; rc=$?
grep -q "PLAN: NOTHING_TO_DO" "$W/head_plan.txt"; chk "[HEAD] the already-final tree: dry-run reports NOTHING_TO_DO and exits 0" $((rc + $?))
[ "$(tree_sum "$T")" = "$b" ]; chk "[HEAD] dry-run changed nothing" $?

# ---------------------------------------------------------------- read-only validator of the UI package (installed backend; needs the test database: RV3_VALIDATE=1)
if [ "${RV3_VALIDATE:-0}" = 1 ]; then
  echo; echo "=================== read-only validator (UI package; installed validated backend) ==================="
  T="$W/val_tree"; rm -rf "${T:?}"; mkdir -p "$T"; git archive 3bb9a91 | tar -x -C "$T"; base_apply "$T"
  P="$(unpack "$UIPKG" "$W/val_pkg")"
  mysql -uroot -e "DROP DATABASE IF EXISTS ${DB_DATABASE:-inventory_test}; CREATE DATABASE ${DB_DATABASE:-inventory_test} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" && mysql -uroot "${DB_DATABASE:-inventory_test}" < database/schema.sql && php tests/browser/seed_so_audit_v3.php >/dev/null 2>&1
  mkdir -p "$T/storage"; cp -r storage/stock_opname_photos "$T/storage/" 2>/dev/null
  before="$(tree_sum "$T")"
  "$PHP" $PHPA "$P/scripts/rv3_readonly_check.php" --app-root="$T" --session=1,2 --start=2026-09-01 --end=2026-09-30 >"$W/val_all.txt" 2>&1; rc=$?
  chk "[validator] readonly_validate (installed backend, before and after the UI apply is irrelevant: SELECT only): exit 0" $rc
  [ "$(grep -cE '^PASS +[0-9]+ / [0-9]+ ' "$W/val_all.txt")" = 6 ]; chk "[validator] all six reports PASS (Stock Opname, Movement V3, Movement Daily, IN/OUT/Transfer, Pembelian, Nilai HPP)" $?
  [ "$(tree_sum "$T")" = "$before" ]; chk "[validator] the application tree is byte-identical after the validator (nothing written)" $?
fi

echo; echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
