#!/usr/bin/env bash
# READ-ONLY. Run on the production host.
# Usage: bash collect_production_hashes_tx2.sh /path/to/production/public /path/to/production/services
set -u
P="${1:?usage: collect_production_hashes_tx2.sh <public dir> <services dir>}"
SV="${2:?usage: collect_production_hashes_tx2.sh <public dir> <services dir>}"
echo "== SHA256 of the 5 files the package changes (these are the --expect-sha256 / --replace-expect-sha256 values; send them back) =="
sha256sum "$P/assets/js/transactions.js" "$P/assets/js/transaction-history.js" "$P/assets/css/app.css" "$P/index.html" "$P/index.php"
echo
echo "== must be ABSENT before the package (the 5 NEW files) =="
ls -l "$SV/PurchaseInvoiceService.php" "$SV/StockOutService.php" "$SV/StockOutDocumentService.php" "$P/assets/js/stock-in-sheet.js" "$P/assets/js/stock-out-sheet.js" 2>&1 | sed 's/^/   /'
ls "$P"/*.pre-tx2-backup "$P"/assets/*/*.pre-tx2-backup "$P"/*.tx2-patch.json "$P"/assets/*/*.tx2-patch.json 2>/dev/null || echo "no Stock IN/OUT V2 leftovers (good)"
echo
c() { printf '%s  ' "$(grep -cF -- "$2" "$1")"; echo "$3"; }
echo "== anchor counts (expected value in brackets) =="
echo "-- index.php"
c "$P/index.php" "require_once __DIR__ . '/../services/PurchaseCostingGateway.php';" "require_once PurchaseCostingGateway.php   [1]"
c "$P/index.php" "// PHASE V2.7 — read-only Cost Preview, called by the Transaksi Masuk" "Cost Preview route comment   [1]"
c "$P/index.php" "'POST /transactions/in' =>" "legacy POST /transactions/in kept   [1]"
c "$P/index.php" "'POST /transactions/out' =>" "legacy POST /transactions/out kept   [1]"
c "$P/index.php" "PurchaseInvoiceService" "PurchaseInvoiceService already referenced   [0]"
c "$P/index.php" "StockOutService" "StockOutService already referenced   [0]"
c "$P/index.php" "'POST /stock-in" "stock-in routes already present   [0]"
c "$P/index.php" "'GET /stock-out" "stock-out routes already present   [0]"
for s in NumberingService PricingPolicyService ItemPriceService PurchaseCostingGateway PurchaseCostingService FifoService InventoryService; do
  c "$P/index.php" "services/$s.php" "index.php requires $s.php   [1]"
done
echo "-- app.css"
c "$P/assets/css/app.css" ".tx2-" "'.tx2-' selectors already present   [0]"
c "$P/assets/css/app.css" "Stock IN / OUT V2 (transactions.js" "tx2 CSS marker already present   [0]"
echo "-- index.html"
c "$P/index.html" 'assets/js/transactions.js?v=' "transactions.js script tag   [1]"
c "$P/index.html" 'assets/js/transaction-history.js?v=' "transaction-history.js script tag   [1]"
c "$P/index.html" 'assets/css/app.css?v=' "app.css link   [1]"
c "$P/index.html" 'stock-in-sheet.js' "stock-in-sheet.js already referenced   [0]"
c "$P/index.html" '20261010-tx2' "new token already used   [0]"
echo "-- transaction-history.js"
c "$P/assets/js/transaction-history.js" "                    if (actionsRow.children.length) body.appendChild(actionsRow);" "actionsRow append line (patch anchor)   [1]"
c "$P/assets/js/transaction-history.js" "isOutDoc" "already patched   [0]"
echo
echo "== backend dependencies must exist =="
ls -l "$SV/Database.php" "$SV/Exceptions.php" "$SV/NumberingService.php" "$SV/PricingPolicyService.php" "$SV/ItemPriceService.php" "$SV/PurchaseCostingGateway.php" "$SV/PurchaseCostingService.php" "$SV/FifoService.php" "$SV/InventoryService.php" "$SV/UnitConversionService.php" "$SV/WarehouseGuardService.php" "$SV/AuditService.php" 2>&1 | sed 's/^/   /'
ls -l "$P/assets/images/amor-logo.jpg" 2>&1 | sed 's/^/   /'
echo
echo "== cache-bust lines now =="
grep -n 'transactions.js?v=\|transaction-history.js?v=\|app.css?v=' "$P/index.html"
echo
echo "Next: run precheck_readonly.sql against the production database (mysql ... < precheck_readonly.sql) and send the output too."
