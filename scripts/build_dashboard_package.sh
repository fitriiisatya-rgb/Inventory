#!/usr/bin/env bash
# Builds the fail-closed Dashboard production package (NOT a deploy).
# Usage: bash scripts/build_dashboard_package.sh <output dir>   -> <out>/dashboard_production_deploy_package.tar.gz
set -eu
cd "$(dirname "$0")/.."
DASH_REV="${DASH_REV:-f4a94e8}"   # commit the dashboard package is built from
OUT="${1:?usage: build_dashboard_package.sh <output dir>}"
N=dashboard_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
git show "$DASH_REV:public/assets/js/dashboard.js" > "$R/payload/dashboard.js"
git show "$DASH_REV:services/DashboardInventoryService.php" > "$R/payload/DashboardInventoryService.php"
git show "$DASH_REV:public/assets/css/app.css" | awk '/^\/\* Dashboard redesign \(dashboard.js\)/{f=1} f' > "$R/payload/dashboard_app_css_block.css"
for f in patch_dashboard_app_css_production.php patch_dashboard_index_html_production.php patch_dashboard_index_php_production.php install_dashboard_files_production.php rollback_dashboard_production.php dashboard_reconcile_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/dashboard_package/collect_production_hashes_dashboard.sh scripts/dashboard_package/precheck_readonly.sql "$R/"
H_SVC=$(sha256sum "$R/payload/DashboardInventoryService.php" | cut -d' ' -f1)
H_JS=$(sha256sum "$R/payload/dashboard.js" | cut -d' ' -f1)
H_BLK=$(sha256sum "$R/payload/dashboard_app_css_block.css" | cut -d' ' -f1)
sed "s/@@H_SVC@@/$H_SVC/g; s/@@H_JS@@/$H_JS/g; s/@@H_BLK@@/$H_BLK/g" scripts/dashboard_package/README_DEPLOY_dashboard.md.tpl > "$R/README_DEPLOY_dashboard.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz"; sha256sum "$OUT/$N.tar.gz"
