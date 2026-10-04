# Stock IN V2 + Stock OUT V2 — table-first transaction workspace (NOT deployed)

Replaces the Stock IN / Stock OUT wizard screens (`#tab-transaksi`) with one compact, table-first sheet per kind, and adds the
backend they need. **No database schema change.** Stock OUT now also creates a Delivery Order + Invoice (printable, re-printable).
Every script **fails closed**: it refuses, changing nothing, unless the file's current SHA256 and every anchor match exactly.
Dry-run is the default. The legacy `POST /transactions/in` and `POST /transactions/out` routes are untouched (API compatible).
Independent of the Jejak and Dashboard packages (anchors chosen so neither matters).

| Target | Action |
|---|---|
| `services/PurchaseInvoiceService.php` | NEW (SHA256 `@@H_SVC1@@`) — multi-line purchase quote/post (per-item PPN + discount, invoice discount, shipping) |
| `services/StockOutService.php` | NEW (SHA256 `@@H_SVC2@@`) — Stock OUT quote/post + DO + Invoice via the existing Distribusi tables |
| `services/StockOutDocumentService.php` | NEW (SHA256 `@@H_SVC3@@`) — printable DO / Invoice (A4 HTML) |
| `public/index.php` | patch: +3 `require_once`, +11 routes (`/stock-in*`, `/stock-out*`); additions only |
| `public/assets/js/stock-in-sheet.js`, `stock-out-sheet.js` | NEW (SHA256 `@@H_JS1@@` / `@@H_JS2@@`) |
| `public/assets/js/transactions.js` | REPLACE the current workspace (new SHA256 `@@H_JS0@@`) |
| `public/assets/js/transaction-history.js` | patch: "Cetak DO / Cetak Invoice" for a Stock OUT V2 transaction (one anchored block) |
| `public/assets/css/app.css` | APPEND the self-contained `.tx2-*` block (block SHA256 `@@H_BLK@@`) |
| `public/index.html` | tags: transactions.js token + 2 new script tags, transaction-history.js + app.css tokens -> `20261010-tx2` |

State files: `.pre-tx2-backup`, `.tx2-patch.json` (Jejak / Dashboard state files are never read or modified). PHP 8+ CLI. Run from this directory.
`PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_tx2.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of transactions.js, transaction-history.js, app.css, index.html, index.php; every anchor count as shown in brackets; "no Stock IN/OUT V2 leftovers";
12 tables present and no `MISSING_column` rows; the amor-logo.jpg present. The last SQL lines tell you how many active bakery destinations exist (Bakery Tujuan is REQUIRED on the new
Stock OUT), which markup defaults exist and how many active items have no purchase price yet (they cannot be sold: no Harga Modal).

## 1. Wiring check against the real database (READ-ONLY, BEFORE deploying anything)
Uses the package copies of the three services; READ ONLY transaction (it first proves the server rejects a write); builds a Stock IN quote and a Stock OUT quote for real data and checks the arithmetic:
```
php scripts/tx2_readonly_check.php --app-root="$APP" --service-dir=payload
```
Send me the output. Any FAIL → do not deploy.

## 2. Safety copy + verify package
```
B=~/tx2_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/transactions.js" "$PUB/assets/js/transaction-history.js" $B/
sha256sum -c SHA256SUMS
```

## 3. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_CSS=<…app.css>; H_IDX=<…index.html>; H_TX=<…transactions.js>; H_HIST=<…transaction-history.js>
php scripts/install_tx2_files_production.php payload/PurchaseInvoiceService.php "$SVC/PurchaseInvoiceService.php" --expect-payload-sha256=@@H_SVC1@@
php scripts/install_tx2_files_production.php payload/StockOutService.php "$SVC/StockOutService.php" --expect-payload-sha256=@@H_SVC2@@
php scripts/install_tx2_files_production.php payload/StockOutDocumentService.php "$SVC/StockOutDocumentService.php" --expect-payload-sha256=@@H_SVC3@@
php scripts/patch_tx2_index_php_production.php "$PUB/index.php" --expect-sha256=$H_PHP
php scripts/install_tx2_files_production.php payload/stock-in-sheet.js "$PUB/assets/js/stock-in-sheet.js" --expect-payload-sha256=@@H_JS1@@
php scripts/install_tx2_files_production.php payload/stock-out-sheet.js "$PUB/assets/js/stock-out-sheet.js" --expect-payload-sha256=@@H_JS2@@
php scripts/install_tx2_files_production.php payload/transactions.js "$PUB/assets/js/transactions.js" --expect-payload-sha256=@@H_JS0@@ --replace-expect-sha256=$H_TX
php scripts/patch_tx2_transaction_history_production.php "$PUB/assets/js/transaction-history.js" --expect-sha256=$H_HIST
php scripts/patch_tx2_app_css_production.php "$PUB/assets/css/app.css" payload/tx2_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_tx2_index_html_production.php "$PUB/index.html" --expect-sha256=$H_IDX
php scripts/rollback_tx2_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 4. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. the three `install_tx2_files_production.php payload/*Service.php …`
2. `patch_tx2_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend before touching the frontend** (the old screens keep working meanwhile — they do not call these routes): re-run step 1 with `--service-dir="$SVC"`; logged in as a Stock OUT user,
   `GET /api/stock-out/recent` returns JSON (`success:true`).
4. `install_tx2_files_production.php` for `stock-in-sheet.js`, `stock-out-sheet.js`, then `transactions.js` (REPLACE, with `--replace-expect-sha256=$H_TX`)
5. `patch_tx2_transaction_history_production.php …`
6. `patch_tx2_app_css_production.php …`
7. `patch_tx2_index_html_production.php …` (last — it is what makes browsers fetch the new files)

## 5. Verify in the browser (hard refresh, iPad + desktop)
*Stock IN*: header (Gudang, Vendor, Referensi, Tanggal, Catatan); table columns No / Nama Barang / Satuan / Qty / Harga Beli / PPN / Diskon / Total / Aksi; pick an item by NAME → unit list + default Harga Beli of that unit;
edit the price (a hint shows the reference price); PPN 0/11/custom; item discount % or Rp; Diskon Invoice % or Rp; Biaya Kirim; Grand Total = Subtotal − Diskon Invoice + Biaya Kirim; Review shows the server's numbers; save → transactions appear
in History Transaksi (one per item, shared reference). *Stock OUT*: Gudang Asal, Bakery Tujuan (required), markup cards appear per category in the sheet (fill 0 if none), Harga Jual/Total per row, Stok Tersedia follows the unit,
insufficient stock is blocked with requested vs available, Preview DO / Preview Invoice, save → DO + Invoice numbers, "Cetak DO / Cetak Invoice", "Riwayat" and History Transaksi → detail → Cetak. The Invoice must show **no** Harga Modal / HPP / markup.
DevTools → Network: only `/stock-in/quote`, `/stock-in`, `/stock-out/*` writes.

## 6. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_tx2_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_tx2_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/transactions.js" "$PUB/assets/js/transaction-history.js"   # must equal H_PHP H_IDX H_CSS H_TX H_HIST
```
Refuses (changing nothing) if any of the ten files was edited after this package or any state file/backup is missing. The five NEW files are deleted; the five changed ones are restored byte-exactly.
Order matters if other packages were applied later: roll those back first. **Data is never rolled back**: transactions, Delivery Orders and Invoices already created stay (they are ordinary rows in the existing tables and remain visible in
History Transaksi / Distribusi Bakery). Manual fallback: copy `$B/*` back per file and delete the five new files.

## Behaviour notes (what the numbers mean — see also the code comments)
* **Stock IN**: Harga Beli default = latest real purchase price of the selected unit (`item_price_history` via `ItemPriceService`; a unit never bought is derived through the approved conversion). Editing it affects only that transaction; there is no
  master purchase price to overwrite. Every posted line appends its (cost-equivalent) price to `item_price_history` exactly as before. **Row Total** = DPP + PPN; **Subtotal** = Σ Total; **Diskon Invoice** is taken from the PPN-inclusive Subtotal
  and split by row total (remainder to the last row), re-expressed on each DPP (÷ 1+PPN); **Biaya Kirim** is split by net DPP. **Inventory (FIFO) cost** = net DPP (+ PPN only if you declare it non-creditable) (+ shipping only if you tick "masuk HPP").
  Defaults (PPN recoverable, shipping expensed) equal the cost behaviour a plain Stock IN has always had; PPN default per row is 11 % (the seeded default rate) and can be 0 / custom per row.
  One entry posts one transaction per item (all-or-nothing, shared reference, per-line idempotency key); invoice-level inputs are recorded in `audit_logs` (`PURCHASE_INVOICE_POST`).
* **Stock OUT**: Harga Modal = reference purchase price of the selected unit (same source), **not** FIFO cost. Markup is per category per transaction (prefilled from the Distribusi pricing policy if one exists, always editable, never silently applied;
  a category without a value blocks the sheet, 0 is allowed). Harga Jual = Modal × (1 + %) or Modal + Rp (per selected unit); Grand Total = Σ Total + Biaya Kirim (no PPN / discount). Stock leaves through the unchanged FIFO engine; the OUT lines carry the real HPP.
  The DO is created `DISPATCHED` and the Invoice `ISSUED` in the existing Distribusi tables (cost basis `reference_purchase_price` and selling basis are stored side by side; the printed Invoice never reads the cost columns).
  Numbers `DO-YYYYMMDD-####` / `INV-YYYYMMDD-####` come from the existing `NumberingService` (atomic counter, concurrency-safe).
* **Not included / decisions for you**: Stock OUT now REQUIRES a Bakery Tujuan, so an OUT to a *division* (no bakery) is no longer possible from this screen (the old API route still exists); the "izinkan stok negatif" override is gone from the sheet;
  there is no Import/Paste and no "Simpan Draft".
