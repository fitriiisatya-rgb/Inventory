#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_ui2.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_ui2.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_ui2.sh <public dir> <services dir>}"
echo "== SHA256 of the 5 files the package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/assets/css/app.css" "$P/index.html" "$P/assets/js/dashboard.js" "$P/assets/js/sidebar.js" "$SV/DashboardInventoryService.php"
echo
echo "== reference hashes of the previously delivered versions (a match means that file is exactly what was delivered) =="
echo "   dashboard.js                   @@H_OLD_DASHJS@@  (Dashboard package)"
echo "   DashboardInventoryService.php  @@H_OLD_SVC@@  (Dashboard package)"
echo "   sidebar.js                     @@H_OLD_SIDE@@  (repo version before UI2; production may differ — then send me that file's hash, the patcher accepts only an exact match)"
echo
echo "== leftovers from this package (must be none) =="
ls "$P"/*.pre-ui2-backup "$P"/assets/*/*.pre-ui2-backup "$P"/*.ui2-patch.json "$P"/assets/*/*.ui2-patch.json "$SV"/*.pre-ui2-backup "$SV"/*.ui2-patch.json 2>/dev/null || echo "no UI2 leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
c "$P/assets/css/app.css" "/* Dashboard redesign (dashboard.js)" "dashboard CSS marker   [1]"
c "$P/assets/css/app.css" "--dz-kpi" "refined dashboard CSS already present   [0]"
c "$P/assets/css/app.css" "UI2 — collapsible sidebar rail" "sidebar rail CSS already present   [0]"
c "$P/assets/css/app.css" "clamp(1.15rem, 2vw, 1.75rem)" "previous dashboard sizing rule   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'assets/js/sidebar.js?v=' "sidebar.js script tag   [1]"
c "$P/index.html" 'assets/js/dashboard.js?v=' "dashboard.js script tag   [1]"
c "$P/index.html" '20261011-ui2' "new token already used   [0]"
c "$P/assets/js/sidebar.js" 'inv_sidebar_collapsed' "sidebar.js already collapsible   [0]"
c "$SV/DashboardInventoryService.php" "final_deadstock_qty" "service already reads final_deadstock_qty   [0]"
echo
echo "== the column the Dead Stock card reads comes from the Stock Opname migration (v2.14.9) =="
echo "   (checked by precheck_readonly.sql)"
echo
echo "== cache-bust lines now =="
grep -n 'app.css?v=\|sidebar.js?v=\|dashboard.js?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
