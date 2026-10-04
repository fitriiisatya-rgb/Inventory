#!/usr/bin/env bash
# Builds the fail-closed Stock IN / OUT V2 production package (NOT a deploy).
# Usage: [TX_REV=<commit>] bash scripts/build_tx2_package.sh <output dir>   -> <out>/tx2_production_deploy_package.tar.gz
set -eu
cd "$(dirname "$0")/.."
TX_REV="${TX_REV:-HEAD}"
OUT="${1:?usage: build_tx2_package.sh <output dir>}"
N=tx2_production_deploy_package
D="$(mktemp -d)"; trap 'rm -rf "${D:?}"' EXIT
R="$D/$N"; mkdir -p "$R/scripts/lib" "$R/payload"
for f in services/PurchaseInvoiceService.php services/StockOutService.php services/StockOutDocumentService.php public/assets/js/stock-in-sheet.js public/assets/js/stock-out-sheet.js public/assets/js/transactions.js; do
  git show "$TX_REV:$f" > "$R/payload/$(basename "$f")"
done
git show "$TX_REV:public/assets/css/app.css" | awk '/^\/\* Stock IN \/ OUT V2 \(transactions.js/{f=1} f' > "$R/payload/tx2_app_css_block.css"
for f in patch_tx2_app_css_production.php patch_tx2_index_html_production.php patch_tx2_index_php_production.php patch_tx2_transaction_history_production.php install_tx2_files_production.php rollback_tx2_production.php tx2_readonly_check.php lib/jejak_patch_common.php; do cp "scripts/$f" "$R/scripts/$f"; done
cp scripts/tx2_package/collect_production_hashes_tx2.sh scripts/tx2_package/precheck_readonly.sql "$R/"
h() { sha256sum "$R/payload/$1" | cut -d' ' -f1; }
sed "s/@@H_SVC1@@/$(h PurchaseInvoiceService.php)/g; s/@@H_SVC2@@/$(h StockOutService.php)/g; s/@@H_SVC3@@/$(h StockOutDocumentService.php)/g; s/@@H_JS1@@/$(h stock-in-sheet.js)/g; s/@@H_JS2@@/$(h stock-out-sheet.js)/g; s/@@H_JS0@@/$(h transactions.js)/g; s/@@H_BLK@@/$(h tx2_app_css_block.css)/g" scripts/tx2_package/README_DEPLOY_tx2.md.tpl > "$R/README_DEPLOY_tx2.md"
( cd "$R" && find . -type f ! -name SHA256SUMS | sort | xargs sha256sum | sed 's#  \./#  #' > SHA256SUMS )
mkdir -p "$OUT"
tar -C "$D" -czf "$OUT/$N.tar.gz" "$N"
echo "built $OUT/$N.tar.gz (from $TX_REV)"; sha256sum "$OUT/$N.tar.gz"
