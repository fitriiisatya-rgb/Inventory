# Laporan Pembelian — redesign on the real Stock IN V2 invoice data (NOT deployed)

The report is rebuilt from the real Stock IN V2 data (`inventory_transactions` + `purchase_invoice_headers` + `purchase_line_costs`): **KPI cards** (Total Nilai Pembelian,
Subtotal Barang, Diskon [Barang / Invoice], PPN [actual rates], Ongkos Kirim, Jumlah Supplier), **Nominal (Rp) / Kuantitas (Qty)** modes, a Harian / Mingguan / Bulanan chart,
**Detail Invoice** (one row per invoice / reference with its real subtotal, discounts, PPN, freight and total + a GRAND TOTAL row), an invoice drawer with the full arithmetic and the
per-row Stock IN V2 allocation, **Rincian per Barang** (frequency, quantity per unit, min / max / weighted-average purchase price) and exports that mirror the screen.
**Strictly read-only: no database schema change, nothing written, no existing route removed** (`GET /reports/purchase*`, Stock IN V2 posting, FIFO, inventory are untouched).
Every script **fails closed**: it refuses, changing nothing, unless the file's current SHA256 and every anchor match exactly. Dry-run is the default.
Independent of the Jejak, Dashboard, Stock IN/OUT V2, UI2, Master Data, sidebar-cleanup, Pergerakan Stok and Stock Opname packages (own state files `.pre-pur-backup` / `.pur-patch.json`).

| Target | Action |
|---|---|
| `services/PurchaseReportService.php` | NEW (SHA256 `@@H_SVC@@`) — read-only read model over the stored Stock IN V2 invoice financials |
| `public/index.php` | patch: +1 `require_once` (after `PurchaseCostingGateway`), +1 request helper `inv_pur_filters` (payload `pur_index_php_helper.txt` `@@H_HELPER@@`), +5 GET routes inserted before `GET /reports/purchase` (payload `pur_index_php_routes.txt` `@@H_ROUTES@@`): `/reports/purchase-v2/overview`, `/invoices`, `/items`, `/invoice-detail`, `/export` |
| `public/assets/js/api-client.js` | patch: +5 one-line methods after `purchaseBySupplierExportUrl` |
| `public/assets/js/report-purchase.js` | REPLACE (new SHA256 `@@H_JS@@`) |
| `public/assets/css/app.css` | APPEND the self-contained `.pur-*` block (block SHA256 `@@H_BLK@@`) |
| `public/index.html` | tags: `app.css`, `api-client.js`, `report-purchase.js` → `?v=20261015-pur` (nothing else) |
| `scripts/purchase_reconcile_check.php` | shipped for you: READ-ONLY reconciliation of the real purchases (row re-derivation, invoice identity, allocation rules, audit cross-check, report totals vs SQL) — never writes |

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).
**Prerequisite:** Stock IN V2 must already be in production (tables `purchase_invoice_headers` / `purchase_line_costs`; `precheck_readonly.sql` lists any missing column) and the existing
Laporan Pembelian page (`tab-laporan-pembelian`, `report-purchase.js`) must be present — step 0 shows it; if an anchor is missing the scripts refuse at the first gate and nothing changes: send me the step-0 output.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_pur.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 5 existing files; every anchor count as shown in brackets; "no Laporan Pembelian leftovers"; INVENTORY_VIEW held by the roles you expect;
**no `MISSING_column` rows**; the purchase counts. `report-purchase.js` is REPLACED: tell me if yours was edited locally.

## 1. Safety copy + verify package
```
B=~/pur_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB/assets/js/report-purchase.js" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_API=<…api-client.js>; H_JS=<…report-purchase.js>
php scripts/install_pur_files_production.php payload/PurchaseReportService.php "$SVC/PurchaseReportService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_pur_index_php_production.php "$PUB/index.php" payload/pur_index_php_helper.txt payload/pur_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@
php scripts/patch_pur_api_client_production.php "$PUB/assets/js/api-client.js" --expect-sha256=$H_API
php scripts/install_pur_files_production.php payload/report-purchase.js "$PUB/assets/js/report-purchase.js" --expect-payload-sha256=@@H_JS@@ --replace-expect-sha256=$H_JS
php scripts/patch_pur_app_css_production.php "$PUB/assets/css/app.css" payload/pur_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_pur_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_pur_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 3. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_pur_files_production.php payload/PurchaseReportService.php …`
2. `patch_pur_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend against the real purchases before touching the frontend** (the old screen keeps working meanwhile — it does not call the new routes):
   `php scripts/purchase_reconcile_check.php --app-root="$APP" --start=<first purchase date> --end=<today>` (add `--warehouse=<code>` per warehouse, `--historical=all` to include imports) —
   every line must PASS (exit 0). Send me the output. A FAIL prints the invoice / amount / difference: **do not continue** on a non-zero exit.
4. `patch_pur_api_client_production.php …`, then `install_pur_files_production.php payload/report-purchase.js …`
5. `patch_pur_app_css_production.php …`
6. `patch_pur_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/pur_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone)
Log in as an admin and open **Laporan → Laporan Pembelian**:
* Filters: Periode, Gudang, Supplier, Kategori, Barang (kode / nama), Data (Live saja / Historis saja / Live + Historis), Terapkan / Reset, 7 Hari / 30 Hari / Bulan Ini.
* KPI cards (Nominal): Total Nilai Pembelian, Subtotal Barang, Diskon (Barang / Invoice), PPN (the actual rates present, e.g. "0% / 11%"), Ongkos Kirim, Jumlah Supplier. Qty mode: Jumlah Transaksi, Jumlah SKU, Jumlah Supplier and Qty **per unit** (never one mixed total).
* Detail Invoice: one row per invoice / reference; GRAND TOTAL row equals the KPI total; VOID invoices are struck through and add nothing; legacy purchases show their value with "—" for the components that were never stored.
* Invoice drawer (eye icon): header, the per-row allocation table and the arithmetic block (Gross − Diskon Barang = Subtotal Barang; + PPN − Diskon Invoice + Ongkos Kirim = Grand Total).
* Rincian per Barang: frequency, quantity per unit, min / max / weighted-average purchase price, GRAND TOTAL equal to the KPI total.
* Compare 2–3 invoices with their original paper invoices / the Stock IN screen: totals must be identical.
* Export Excel: Ringkasan, Detail Invoice, Detail Barang, Baris Invoice-Barang (+ CSVs); always all business columns and the same filters as the screen.
* Log in as a VIEWER (INVENTORY_VIEW): the report opens. As a STOCK / warehouse-scoped user: only that warehouse's invoices (the API ignores another `warehouse_id`; another warehouse's invoice-detail → 403).

## 5. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_pur_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_pur_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB/assets/js/report-purchase.js"   # must equal the hashes from step 0
```
Refuses (changing nothing) if any of the six files was edited after this package (a LATER package that changed one of them — roll that one back first; the sidebar, Pergerakan
Stok and Stock Opname packages also edit `index.html` / `app.css` / `api-client.js` / `index.php`) or any state file / backup is missing. The new service file is deleted; the five changed files are restored byte-exactly. Nothing else needs undoing: no data was written.
Manual fallback: copy `$B/*` back per file and delete `PurchaseReportService.php`.

## Behaviour notes
* **Permissions** — every new route requires `INVENTORY_VIEW` and resolves the warehouse like the other reports (STOCK and a warehouse-scoped ADMIN are forced to their own warehouse; another warehouse's invoice → 403).
* **What counts** — POSTED stock IN (purchase) transactions that are not historical imports (historical imports only through the "Historis" filters); VOID invoices are listed, struck through, and excluded from every total; transfers, production, opening balances and adjustments never count.
* **Values are invoice values (what is paid), not inventory (FIFO) values** — Stock IN V2 figures come from the stored `purchase_line_costs` / `purchase_invoice_headers` (nothing re-computed with a guessed rate): gross − item discount = DPP; invoice discount is defined on the PPN-inclusive subtotal and shown on DPP; PPN is on the DPP after discount (rates 0 / 5 / 11 % as stored per row); freight is allocated by net DPP. Total = Subtotal Barang + PPN − Diskon Invoice + Ongkos Kirim. The FIFO inventory cost (which may exclude creditable PPN / expensed freight) is shown separately in the drawer, labelled as not the invoice value.
* **Legacy purchases** (before Stock IN V2, no component breakdown) — Total = the stored line values, tagged "Legacy"; Subtotal / Diskon / PPN / Ongkos Kirim show "—" (never a fabricated 0).
* **Quantities** — always per base unit; mixed units are never summed (KPI / table footer list KG · LTR · PCS separately; the quantity chart appears only when one item is selected).
* **Weighted-average price** — Σ(price × qty) / Σ qty per item and unit (not the simple average); min and max prices are kept so both historical prices stay visible.
