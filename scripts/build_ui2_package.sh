#!/usr/bin/env bash
# Builds the fail-closed UI2 (dashboard refinement + collapsible sidebar + Dead Stock from SO) production package (NOT a deploy).
# Usage: [UI_REV=<commit>] bash scripts/build_ui2_package.sh <output dir>   -> <out>/ui2_production_deploy_package.tar.gz
# DASH_REV is the commit whose dashboard files were delivered to production (the "old" side of the CSS replacement).
set -eu
cd "$(dirname "$0")/.."
UI_REV="${UI_REV:-6775b7f}"   # pinned: the commit the delivered UI2 package was built from (later blocks are appended after the sidebar block)
DASH_REV="${DASH_REV:-f4a94e8}"
OUT="${1:?usage: build_ui2_package.sh <output dir>}"
N=ui2_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$UI_REV:public/assets/js/dashboard.js" > "$R/payload/dashboard.js"
git show "$UI_REV:public/assets/js/sidebar.js" > "$R/payload/sidebar.js"
git show "$UI_REV:services/DashboardInventoryService.php" > "$R/payload/DashboardInventoryService.php"
git show "$DASH_REV:public/assets/css/app.css" | awk '/^\/\* Dashboard redesign \(dashboard.js\)/{f=1} f' > "$R/payload/ui2_old_dashboard_block.css"
git show "$UI_REV:public/assets/css/app.css" > "$D/ui_app.css"
python3 - "$D/ui_app.css" "$R/payload" <<'PY'
import sys
css = open(sys.argv[1], encoding='utf-8').read()
out = sys.argv[2]
a = css.index('/* Dashboard redesign (dashboard.js)')
b = css.index('/* Stock IN / OUT V2 (transactions.js')
c = css.index('/* UI2 — collapsible sidebar rail')
assert a < b < c, 'unexpected block order in app.css'
open(out + '/ui2_new_dashboard_block.css', 'w', encoding='utf-8', newline='').write(css[a:b].rstrip('\n') + '\n')
open(out + '/ui2_sidebar_block.css', 'w', encoding='utf-8', newline='').write(css[c:])
PY
for f in patch_ui2_app_css_production.php patch_ui2_index_html_production.php install_ui2_files_production.php rollback_ui2_production.php ui2_readonly_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/ui2_package/precheck_readonly.sql "$R/"
h() { sha256sum "$1" | cut -d' ' -f1; }
hp() { h "$R/payload/$1"; }
ho() { git show "$DASH_REV:$1" | sha256sum | cut -d' ' -f1; }
sed "s/@@H_OLD_DASHJS@@/$(ho public/assets/js/dashboard.js)/g; s/@@H_OLD_SVC@@/$(ho services/DashboardInventoryService.php)/g; s/@@H_OLD_SIDE@@/$(ho public/assets/js/sidebar.js)/g" scripts/ui2_package/collect_production_hashes_ui2.sh > "$R/collect_production_hashes_ui2.sh"
sed "s/@@H_DASHJS@@/$(hp dashboard.js)/g; s/@@H_SIDEJS@@/$(hp sidebar.js)/g; s/@@H_SVC@@/$(hp DashboardInventoryService.php)/g; s/@@H_OLDBLK@@/$(hp ui2_old_dashboard_block.css)/g; s/@@H_NEWBLK@@/$(hp ui2_new_dashboard_block.css)/g; s/@@H_SIDEBLK@@/$(hp ui2_sidebar_block.css)/g" scripts/ui2_package/README_DEPLOY_ui2.md.tpl > "$R/README_DEPLOY_ui2.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $UI_REV, old side $DASH_REV)"; sha256sum "$OUT/$N.tar.gz"
