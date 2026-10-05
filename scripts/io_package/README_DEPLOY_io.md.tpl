# Laporan IN / OUT / Transfer — satu halaman, tiga tab (NOT deployed)

"Laporan IN / OUT" is rebuilt as ONE page with three tabs — **Barang Masuk (IN)**, **Barang Keluar (OUT)**, **Transfer Antar Gudang** — each with its own filters, KPI cards (with the change vs the previous period of the same length),
chart (Harian / Mingguan / Bulanan), transaction table, inline item detail (OUT: FIFO layers per line; Transfer: cost layers), global table search, column picker and Excel export.
The old "Laporan Transfer" route (`laporan-transfer`, still used by the Dashboard / summary drill-downs) opens this page directly on the Transfer tab.
**Strictly read-only: no schema change, nothing written, no recalculation of stock, FIFO, costing, invoices, DO or transfers.** The old pages (`report-inout.js`, `report-transfer.js`) and every old endpoint
(`/reports/in-out*`, `/reports/transfer`) stay as they are. Every script **fails closed** (SHA256 preimage gates, exact-once anchors, dry-run by default, backup, atomic write + verify, double-apply refusal, two-phase rollback).
Own state-file suffixes `.pre-io-backup` / `.io-patch.json`; it coexists with the MVR / Pembelian / Nilai Stok & HPP / Stock Opname packages and never rewrites their tokens (only the `app.css` and `app.js` tokens it must bump).

## How the numbers are made (all server-side; the browser only renders)
* **IN** — `inventory_transactions.transaction_type = 'IN'`, grouped into the invoice one Stock IN V2 entry posted. Stock IN V2 arithmetic from `purchase_invoice_headers` / `purchase_line_costs`:
  Grand Total = Subtotal Barang (gross − diskon barang) + PPN − Diskon Invoice + Ongkos Kirim; Inventory Cost = the FIFO cost the costing gateway stored. Legacy / historical purchases only know their recorded value —
  their components show "—" and are never summed as 0. "Jenis Transaksi IN" = Stock IN V2 / Legacy / Historis (historical imports are reporting-only and shown only when selected).
* **OUT** — `transaction_type = 'OUT'`; a Stock OUT V2 document = DO + Invoice linked line by line (`distribution_order_lines.out_transaction_line_id`). **HPP** = the line's stored FIFO cost (= Σ its `fifo_allocations`).
  **Nilai Jual** = the invoice line stored at posting (reference purchase price + the markup actually used) — never today's master price. **Margin = Nilai Jual − HPP** (goods only). The invoice stores shipping only in its header,
  so "Alokasi Ongkir" per line is a labelled DERIVED figure (proportional to the line's selling subtotal, remainder on the last line) and always adds up to the invoice shipping exactly. A legacy OUT without DO / invoice shows
  HPP only; Nilai Jual / Margin are "—". A reversed DO (CANCELLED) is listed struck through and not counted.
* **TRANSFER** — `warehouse_transfers` / `warehouse_transfer_lines` (one row per FIFO layer). Cost is preserved: TRANSFER_OUT value = TRANSFER_IN value; PENDING = in transit. Receive is full-only, so Qty Diterima = Qty Transfer
  for RECEIVED and "—" while PENDING. Lead time = diterima − dispatch (the real TRANSFER_OUT posting time) and is only shown when both exist. Cancelled / reversed transfers are listed, not counted.
* Quantities of different units are never added (cells show "KG 5 · PCS 12"). Unknown values are "—", never 0. Period = the document's business date (transfers: ship date).
* **Reconciliation** (`scripts/inout_reconcile_check.php`, also the service's own `reconcile()`): IN invoice arithmetic · every OUT line Σ FIFO qty = qty OUT and Σ FIFO value = stored HPP · Σ Nilai Jual = invoice subtotal, + shipping = grand total ·
  derived shipping = header · every RECEIVED transfer out = in (value + qty) · out = in + in-transit (net zero) · KPI = table footer = Excel — plus three independent raw-SQL checks.

## Files
| Target | Action |
|---|---|
| `services/InOutReportService.php` | NEW (SHA256 `@@H_SVC@@`) — read-only report engine |
| `public/index.php` | patch: +1 `require_once` (after `TransferReportService`), +1 request helper `inv_io_filters` (payload `io_index_php_helper.txt` `@@H_HELPER@@`), +5 GET routes before the "Report 12 — Distribusi per Bakery" comment (payload `io_index_php_routes.txt` `@@H_ROUTES@@`): `/reports/io/options`, `/overview`, `/list`, `/detail`, `/export` |
| `public/assets/js/report-io.js` | NEW (SHA256 `@@H_JS@@`) — self-contained page (own read-only GET helper; no InvApi / api-client dependency) |
| `public/assets/js/app.js` | TWO lines: `ReportInOut.render(…'tab-laporan-inout')` → `ReportIO.render(…, { tab: 'in' })` and `ReportTransferList.render(…'tab-laporan-transfer')` → `ReportIO.render(…, { tab: 'transfer' })` |
| `public/assets/css/app.css` | APPEND the `.io-*` block (SHA256 `@@H_BLK@@`) |
| `public/index.html` | INSERT `<script src="assets/js/report-io.js?v=20261017-io">` right after the `report-inout.js` tag (exactly once, never modified); `app.css` / `app.js` tags → `?v=20261017-io`. `api-client*.js` and every other token untouched |
| `scripts/inout_reconcile_check.php` | READ-ONLY reconciliation (READ ONLY transaction; a write is proved to be rejected first) |

`PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root. PHP 8+ CLI, run from this directory.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_io.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 4 existing files; every anchor count as shown in brackets; "no … leftovers"; INVENTORY_VIEW held by the roles you expect; **no `MISSING_column` rows**; the counts (how many Stock IN V2 invoices, DO-linked OUT lines and transfers per status the tabs will read).

## 1. Safety copy + verify package
```
B=~/io_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_APP=<…app.js>
php scripts/install_io_files_production.php payload/InOutReportService.php "$SVC/InOutReportService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_io_index_php_production.php "$PUB/index.php" payload/io_index_php_helper.txt payload/io_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@
php scripts/install_io_files_production.php payload/report-io.js "$PUB/assets/js/report-io.js" --expect-payload-sha256=@@H_JS@@
php scripts/patch_io_app_js_production.php "$PUB/assets/js/app.js" --expect-sha256=$H_APP
php scripts/patch_io_app_css_production.php "$PUB/assets/css/app.css" payload/io_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_io_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_io_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 3. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_io_files_production.php payload/InOutReportService.php …`
2. `patch_io_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend against the real data before the frontend** (the old screens keep working meanwhile):
   `php scripts/inout_reconcile_check.php --app-root="$APP" --start=<first movement date> --end=<today>` (add `--warehouse=<code>` per warehouse) — every check must PASS (exit 0). Send me the output.
   A FAIL prints the document / difference: **do not continue** on a non-zero exit (do NOT edit data to make it pass — send me the output).
4. `install_io_files_production.php payload/report-io.js …`, then `patch_io_app_js_production.php …`
5. `patch_io_app_css_production.php …`
6. `patch_io_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/io_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone) — Laporan → Laporan IN / OUT
* Three tabs in ONE row (also on iPad landscape); switching keeps period / warehouse / category / item filters and swaps the tab-specific filters, KPI, chart, table and export.
* **IN**: KPI Total Nilai Masuk, Jumlah Transaksi, SKU, Supplier, PPN, Ongkos Kirim (with "vs periode lalu"); click a row → Rincian Item with the Stock IN V2 arithmetic (Gross − Diskon Barang = Subtotal + PPN − Diskon Invoice + Ongkos Kirim = GRAND TOTAL) and Inventory Cost.
* **OUT**: KPI HPP Keluar, Nilai Jual, Margin, Transaksi, Bakery, Ongkir; click a row → item lines with Harga Modal Referensi, HPP FIFO Aktual, Markup Type/Value, Harga Jual, Subtotal Jual, Alokasi Ongkir; "▸ n layer" opens the FIFO layers (Σ qty = qty OUT, Σ value = HPP — shown as ✔).
* **Transfer**: KPI Total / Pending / Diterima / SKU / Nilai Cost / Rata-rata Lead Time; detail with Qty Transfer / Qty Diterima / Selisih, unit cost and the cost layers; PENDING shows "—" for received fields; cancelled / reversed rows are struck through.
* Export Excel per tab (IN: Ringkasan IN, Transaksi IN, Rincian Item IN · OUT: Ringkasan OUT, Transaksi OUT, Rincian Item OUT, FIFO Allocation · Transfer: Ringkasan Transfer, Daftar Transfer, Rincian Item Transfer, Layer Cost Transfer): the filter metadata is on the first sheet and the totals equal the screen.
* VIEWER (INVENTORY_VIEW) opens it; a STOCK / warehouse-scoped user sees only their warehouse (the API ignores another `warehouse_id`; detail of another warehouse's document is 404).

## 5. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_io_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_io_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js"   # must equal the hashes from step 0; report-io.js and the service must be gone
```
Refuses (changing nothing) if any of the six files was edited after this package (a LATER package that changed one of them — roll that one back first) or any state file / backup is missing.
Manual fallback: copy `$B/*` back per file and delete `InOutReportService.php` and `report-io.js`. Nothing else needs undoing: no data was written.

## Behaviour notes
* **Permissions** — every new route requires `INVENTORY_VIEW` (the old transfer report required `WAREHOUSE_TRANSFER_MANAGE`; the new Transfer tab follows the spec: `INVENTORY_VIEW` + warehouse scope) and resolves the warehouse like the other reports (STOCK / warehouse-scoped ADMIN forced to their warehouse; for transfers either leg).
* **Posted-by** — the IN posting stores one actor (`created_by`) and the posting time (`posting_date`); there is no separate "posted by" field, so the table shows "Dibuat Oleh" and "Diposting Pada" (hidden column) rather than inventing a second actor.
* **Harga Modal Referensi** — the OUT invoice stores the reference purchase price used at posting (`item_price_history`, the same source as Stock IN's default price). That source is also written by transfers and by a voided purchase, so it can differ from the FIFO HPP; Margin therefore uses the HPP actually consumed, never the reference price.
* **OPENING / historical** — historical imports (`inventory_effect = 0`) never count; they are shown only when "Historis" is selected and are labelled.
