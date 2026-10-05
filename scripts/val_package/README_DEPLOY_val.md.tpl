# Laporan Nilai Stok & HPP — dual valuation FIFO + Average (NOT deployed)

The page "Laporan Nilai Stok & HPP" is rebuilt with a **method toggle [ FIFO | Average ]** (default **FIFO**, "Metode operasional sistem: FIFO") and two modes **Per Barang / Per Hari**, KPI cards per method, the FIFO layer panels
("Layer Aktif", "Layer yang Sudah Terpakai", visual FIFO queue), the Average formula panel with a real worked example, a FIFO-vs-Average comparison (overall and for the selected item), and Excel export of the selected method.
**Strictly read-only: no schema change, nothing written, no recalculation of stock, batch costs, FIFO allocations, item_price_history or stored HPP.** The old report page (`report-hpp.js`) and every `/reports/inventory-hpp/*` endpoint stay as they are.
Every script **fails closed** (SHA256 preimage gates, exact-once anchors, dry-run by default, backup, atomic write + verify, double-apply refusal, two-phase rollback). Own state-file suffixes `.pre-val-backup` / `.val-patch.json`;
it coexists with the MVR / Pembelian / Stock Opname packages and never rewrites their tokens (only the `app.css` and `app.js` tokens it must bump).

## How the numbers are made (all server-side; the browser only renders)
* **FIFO (operational)** — the real ledger value of every transaction (signed like `InventoryHppReportService::SIGNED_VALUE_SQL`); HPP out = the actual `fifo_allocations` of the OUT line (layer, qty, cost); Layer Aktif / remaining value are rebuilt per date from `inventory_batches.original_qty_base` minus the allocations dated up to that date (a REVERSAL's mirror allocation restores / removes by sign).
* **Average (analytical moving weighted average)**, per item **per warehouse** ("Semua Gudang" = the sum of the per-warehouse results; never one average across different SKUs): inbound = recorded cost (Stock IN V2 inventory cost, opening value, adjustment cost), new average = (value before + value in) / (qty before + qty in); outbound = qty × current average and never changes it; TRANSFER_IN carries the paired TRANSFER_OUT's average value so internal transfers net to zero; a void reverses the original's value.
  Items whose history cannot support an average (e.g. an outbound larger than the stock known at that moment — negative / migration-negative stock) show **"Average tidak dapat direkonstruksi"** with the reason; they are excluded from every Average total and from the comparison (disclosed), never invented.
* Opening + Cost In − HPP ± Transfer ± Adjustment = Closing holds exactly on every day for both methods (a VOID transaction and its reversal net to zero in "Adjustment & Koreksi"). Quantities of different items / units are never added.
* **Reconciliation** (shown in the report payload and by `scripts/valuation_reconcile_check.php`): layer qty = ledger qty; layer value = FIFO stock value; layers = `inventory_batches.qty_base` (when the period reaches today); allocation qty = OUT qty; allocation cost = OUT HPP; Average: opening + in − out ± … = closing and closing ≈ qty × moving average; internal transfers net to 0 company-wide.

## Files
| Target | Action |
|---|---|
| `services/InventoryValuationService.php` | NEW (SHA256 `@@H_SVC@@`) — read-only valuation engine |
| `public/index.php` | patch: +1 `require_once` (after `InventoryHppReportService`), +1 request helper `inv_val_filters` (payload `val_index_php_helper.txt` `@@H_HELPER@@`), +3 GET routes before `GET /reports/inventory-hpp/summary` (payload `val_index_php_routes.txt` `@@H_ROUTES@@`): `/reports/inventory-valuation`, `/item`, `/export` |
| `public/assets/js/report-valuation.js` | NEW (SHA256 `@@H_JS@@`) — self-contained page (own read-only GET helper; no InvApi / api-client dependency) |
| `public/assets/js/app.js` | ONE line: `ReportHpp.render(…'tab-laporan-hpp')` → `ReportValuation.render(…)` |
| `public/assets/css/app.css` | APPEND the `.val-*` block (SHA256 `@@H_BLK@@`) |
| `public/index.html` | INSERT `<script src="assets/js/report-valuation.js?v=20261016-val">` right after the `report-hpp.js` tag (exactly once, never modified); `app.css` / `app.js` tags → `?v=20261016-val`. `api-client*.js` and every other token untouched |
| `scripts/valuation_reconcile_check.php` | READ-ONLY reconciliation (READ ONLY transaction; a write is proved to be rejected first) |

`PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root. PHP 8+ CLI, run from this directory.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_val.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 4 existing files; every anchor count as shown in brackets; "no … leftovers"; INVENTORY_VIEW held by the roles you expect; **no `MISSING_column` rows**; the counts (the last one tells how many item/warehouse pairs have a negative balance → their Average will read "tidak dapat direkonstruksi").

## 1. Safety copy + verify package
```
B=~/val_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_APP=<…app.js>
php scripts/install_val_files_production.php payload/InventoryValuationService.php "$SVC/InventoryValuationService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_val_index_php_production.php "$PUB/index.php" payload/val_index_php_helper.txt payload/val_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@
php scripts/install_val_files_production.php payload/report-valuation.js "$PUB/assets/js/report-valuation.js" --expect-payload-sha256=@@H_JS@@
php scripts/patch_val_app_js_production.php "$PUB/assets/js/app.js" --expect-sha256=$H_APP
php scripts/patch_val_app_css_production.php "$PUB/assets/css/app.css" payload/val_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_val_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_val_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 3. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_val_files_production.php payload/InventoryValuationService.php …`
2. `patch_val_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend against the real ledger before the frontend** (the old screen keeps working meanwhile):
   `php scripts/valuation_reconcile_check.php --app-root="$APP" --start=<first movement date> --end=<today>` (add `--warehouse=<code>` per warehouse) — every check must PASS (exit 0). Send me the output; it also lists the items whose Average cannot be reconstructed.
   A FAIL prints the item / difference: **do not continue** on a non-zero exit.
4. `install_val_files_production.php payload/report-valuation.js …`, then `patch_val_app_js_production.php …`
5. `patch_val_app_css_production.php …`
6. `patch_val_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/val_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone) — Laporan → Laporan Nilai HPP
* Title "Laporan Nilai Stok & HPP — FIFO" with the badge "Metode operasional sistem: FIFO"; toggle **Average** → the title, KPI labels, table headings, explanation panel and export change; period / warehouse / category / search / selected item are kept.
* FIFO KPI: Nilai Stok Akhir FIFO, HPP Keluar FIFO, Jumlah Layer Aktif, SKU Memiliki Stok, Variance (ledger − layer, must be Rp 0). Average KPI: Nilai Stok Awal, Pembelian / Cost In, Pemakaian / Barang Keluar, Nilai Stok Akhir Average, HPP Average, Selisih / Rekonsiliasi (no "Layer Aktif").
* Per Barang: pick an item → FIFO shows the history with "Layer Terpakai (80 @ Rp x)", Layer Aktif / Terpakai panels and the FIFO queue; Average shows the history with Average Cost sebelum / sesudah (changes highlighted), the formula panel with the item's REAL example and the numbered explanation. Compare "FIFO vs Average" for the same item.
* Per Hari (Harian / Minggu / Bulanan): Opening + Cost In − HPP ± Transfer ± Adjustment = Closing on every row; Average Cost End-of-Day only for a single selected item.
* Pick an item with a known history (e.g. buy 100 @ 1.000 on day 1, 100 @ 1.100 on day 2, sell 80 on day 3): FIFO HPP Rp 80.000 / end 130.000 vs Average 1.050 / HPP Rp 84.000 / end 126.000.
* Export Excel (FIFO: Ringkasan FIFO, Nilai Stok per Barang, Riwayat FIFO, Layer Aktif, Layer Terpakai, Rekonsiliasi, FIFO vs Average · Average: Ringkasan Average, Average per Barang, Riwayat Average, Per Hari, Rekonsiliasi, FIFO vs Average); the metadata states the method.
* VIEWER (INVENTORY_VIEW) opens it; a STOCK / warehouse-scoped user sees only that warehouse (the API ignores another `warehouse_id`).

## 5. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_val_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_val_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js"   # must equal the hashes from step 0; report-valuation.js and the service must be gone
```
Refuses (changing nothing) if any of the six files was edited after this package (a LATER package that changed one of them — roll that one back first) or any state file / backup is missing.
Manual fallback: copy `$B/*` back per file and delete `InventoryValuationService.php` and `report-valuation.js`. Nothing else needs undoing: no data was written.

## Behaviour notes
* **Permissions** — every new route requires `INVENTORY_VIEW` and resolves the warehouse like the other reports (STOCK / warehouse-scoped ADMIN forced to their warehouse).
* **Labels** — "FIFO = metode HPP operasional berdasarkan layer stok paling awal. Average = moving weighted average untuk analisis nilai persediaan." The Average report never modifies posting or accounting.
* **OPENING at exactly the period start 00:00:00** is beginning inventory (not a movement), the same rule as the existing HPP report; historical imports (`inventory_effect = 0`) never count.
