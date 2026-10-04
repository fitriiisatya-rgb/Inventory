# Pergerakan Stok Harian — redesign on real ledger data (NOT deployed)

The report is rebuilt on item-level quantity + value straight from the ledger: Nominal / Kuantitas toggle (quantities are never added across units), five KPI cards,
daily chart, daily table, "Rincian Per Barang" for the chosen day, item transaction trail, CSV export (summary / detail / transactions) and an independent
reconciliation warning. **Strictly read-only: no database schema change, no data written, no existing route removed** (the old `/reports/movement/*` routes stay).
Every script **fails closed**: it refuses, changing nothing, unless the file's current SHA256 and every anchor match exactly. Dry-run is the default.
Independent of the Jejak, Dashboard, Stock IN/OUT V2, UI2, Master Data and sidebar-cleanup packages (own state files `.pre-mvr-backup` / `.mvr-patch.json`).

| Target | Action |
|---|---|
| `services/MovementDailyReportService.php` | NEW (SHA256 `@@H_SVC@@`) — read-only report engine; reuses `InventoryHppReportService` (cutover, signed ledger value) and `InventoryMovementReportService::dailyMovement` |
| `public/index.php` | patch: +1 `require_once`, +`inv_movement_params()` helper (payload `mvr_index_php_helper.txt` `@@H_HELPER@@`), +5 GET routes inserted before `GET /reports/reconciliation/movement` (payload `mvr_index_php_routes.txt` `@@H_ROUTES@@`) |
| `public/assets/js/api-client.js` | patch: +5 one-line methods (after `movementHistoricalTransactions` and `movementDailyExportUrl`) |
| `public/assets/js/report-movement.js` | REPLACE (new SHA256 `@@H_JS@@`) |
| `public/assets/css/app.css` | APPEND the self-contained `.mvr-*` block (block SHA256 `@@H_BLK@@`) |
| `public/index.html` | tags: `app.css`, `api-client.js`, `report-movement.js` → `?v=20261013-mvr` (nothing else) |
| `scripts/movement_reconcile_check.php` | shipped for you: READ-ONLY reconciliation against the real ledger (never writes) |

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_mvr.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 5 existing files; every anchor count as shown in brackets; "no Pergerakan Stok leftovers"; INVENTORY_VIEW held by the roles you expect;
**no `MISSING_column` rows**. The reference-hash list tells which of your files equal the repo's previous version (a file that differs is still fine — the patchers only
need its *current* hash — but `report-movement.js` is REPLACED, so tell me if yours was edited locally).

## 1. Safety copy + verify package
```
B=~/mvr_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB/assets/js/report-movement.js" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_API=<…api-client.js>; H_JS=<…report-movement.js>
php scripts/install_mvr_files_production.php payload/MovementDailyReportService.php "$SVC/MovementDailyReportService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_mvr_index_php_production.php "$PUB/index.php" payload/mvr_index_php_helper.txt payload/mvr_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@
php scripts/patch_mvr_api_client_production.php "$PUB/assets/js/api-client.js" --expect-sha256=$H_API
php scripts/install_mvr_files_production.php payload/report-movement.js "$PUB/assets/js/report-movement.js" --expect-payload-sha256=@@H_JS@@ --replace-expect-sha256=$H_JS
php scripts/patch_mvr_app_css_production.php "$PUB/assets/css/app.css" payload/mvr_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_mvr_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_mvr_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 3. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_mvr_files_production.php payload/MovementDailyReportService.php …`
2. `patch_mvr_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend against the real ledger before touching the frontend** (the old screen keeps working meanwhile — it does not call the new routes):
   `php scripts/movement_reconcile_check.php --app-root="$APP" --start=<first day of last month> --end=<today>` — every line must PASS (exit 0). Send me the output.
   A FAIL prints the warehouse / date / amount of the difference: **do not continue** on a non-zero exit.
4. `patch_mvr_api_client_production.php …`, then `install_mvr_files_production.php payload/report-movement.js …`
5. `patch_mvr_app_css_production.php …`
6. `patch_mvr_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/mvr_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone)
Log in as an admin and open **Laporan → Laporan Pergerakan Stok** (or "Pergerakan Stok Harian" if the sidebar package is not applied):
* Filters: Periode, Gudang, Kategori, Barang, Nominal / Kuantitas, 7 / 30 Hari / Bulan Ini, Terapkan, Reset; Export CSV menu.
* Five cards (Saldo Awal, Barang Masuk, Barang Keluar, Adjustment / Lain, Saldo Akhir) — the Saldo Akhir card equals the Nilai Stok you see on the Dashboard when the period ends today and all warehouses are selected.
* **Kuantitas**: cards show SKU / transaction counts and quantities per unit (KG, PCS, LTR …) — never one mixed total; the chart appears only for one item ("Pilih barang atau satuan untuk melihat grafik kuantitas." otherwise).
* Click a day (or the eye): "Rincian Per Barang" for that date; click an item: its transactions of that day with the running balance; click a transaction: the existing trace drawer.
* "Semua Gudang": transfers between warehouses are NOT counted as Masuk/Keluar; with one warehouse selected they are.
* Export CSV (Ringkasan / Detail Barang / Transaksi): the TOTAL row equals the cards.
* Log in as a STOCK user: the warehouse selector is locked to their warehouse (and the API ignores a different `warehouse_id`).
* Any reconciliation difference is shown as a red warning on top of the report — it is never hidden.

## 5. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_mvr_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_mvr_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB/assets/js/report-movement.js"   # must equal the hashes from step 0
```
Refuses (changing nothing) if any of the six files was edited after this package (a LATER package that changed one of them — roll that one back first, e.g. the sidebar
package also edits `index.html`) or any state file / backup is missing. The new service file is deleted; the five changed files are restored byte-exactly. Nothing else needs undoing: no data was written.
Manual fallback: copy `$B/*` back per file and delete `MovementDailyReportService.php`.

## Behaviour notes
* **Permissions** — every new route requires `INVENTORY_VIEW` and resolves the warehouse through the same scope helper as the other reports (STOCK is forced to its own warehouse).
* **Buckets** — Masuk = Stock IN (posted); Keluar = Stock OUT at actual FIFO HPP; Adjustment / Lain = adjustments (incl. Stock Opname corrections), voids/reversals, production, mid-period opening balances and — for "Semua Gudang" only — the in-transit part of transfers; the identity Saldo Awal + Masuk − Keluar ± Adjustment/Lain = Saldo Akhir is recomputed independently from the ledger and any difference is flagged.
* **Go-live** — days before the warehouse go-live show honest zeros; historical imports (no inventory effect) are disclosed separately, never mixed in.
