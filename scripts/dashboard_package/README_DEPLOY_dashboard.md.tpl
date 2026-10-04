# Dashboard redesign — Inventory Pro (NOT deployed)

Replaces ONLY the main Dashboard (`#tab-dashboard`). READ-ONLY backend: two new GET endpoints, no schema change, no write to
stock / transactions / transfers / stock opname / adjustments / master data / unit conversions. Independent of the Jejak packages:
works whether production is on Jejak v2 or v3 (anchors are chosen so neither matters). Every script **fails closed**: it refuses,
changing nothing, unless the file's current SHA256 and every anchor match exactly. Dry-run is the default.

| Target | Action |
|---|---|
| `services/DashboardInventoryService.php` | NEW file (payload SHA256 `@@H_SVC@@`) |
| `public/index.php` | patch: +1 `require_once`, +2 read-only routes `GET /dashboard/inventory`, `GET /dashboard/inventory/detail` (additions only) |
| `public/assets/js/dashboard.js` | REPLACE the current dashboard (new SHA256 `@@H_JS@@`) |
| `public/assets/css/app.css` | APPEND the self-contained `.dash-*` block (block SHA256 `@@H_BLK@@`) — no existing rule touched |
| `public/index.html` | cache tokens of the `dashboard.js` tag + `app.css` link -> `20261009-dash1` |

State files use their own suffixes (`.pre-dash-backup`, `.dashboard-patch.json`); Jejak's state files are never read or modified.
PHP 8+ CLI required. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_dashboard.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of dashboard.js, app.css, index.html, index.php; every anchor count as shown in brackets; "no dashboard leftovers";
all 14 tables present and no `MISSING_column` rows.

## 1. Reconcile against the real database (READ-ONLY, BEFORE deploying anything)
Uses the package copy of the service; opens a READ ONLY transaction (first proves the server rejects a write):
```
php scripts/dashboard_reconcile_check.php --app-root=<app root containing services/ and config/> --service=payload/DashboardInventoryService.php
```
For the company and every active warehouse, for Hari Ini and Bulan Ini it checks: ledger identity (Awal + Pembelian − Keluar ± Lain = Akhir),
Stok Akhir == current batch valuation, SUM of every drill-down row == its card (all four), company == Σ warehouses. **If any line is FAIL, stop and send me the output —
do not deploy** (e.g. an unreconstructable historical opening is reported, never papered over). Send me the output either way.

## 2. Safety copy + verify package
```
B=~/dashboard_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/dashboard.js" $B/
sha256sum -c SHA256SUMS
```

## 3. Dry-run all five (writes nothing)
```
H_PHP=<sha256 of production index.php>; H_CSS=<…app.css>; H_IDX=<…index.html>; H_JS=<…dashboard.js>
php scripts/install_dashboard_files_production.php payload/DashboardInventoryService.php "$SVC/DashboardInventoryService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_dashboard_index_php_production.php "$PUB/index.php" --expect-sha256=$H_PHP
php scripts/install_dashboard_files_production.php payload/dashboard.js "$PUB/assets/js/dashboard.js" --expect-payload-sha256=@@H_JS@@ --replace-expect-sha256=$H_JS
php scripts/patch_dashboard_app_css_production.php "$PUB/assets/css/app.css" payload/dashboard_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_dashboard_index_html_production.php "$PUB/index.html" --expect-sha256=$H_IDX
php scripts/rollback_dashboard_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is dashboard-patched yet
```

## 4. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_dashboard_files_production.php payload/DashboardInventoryService.php …`
2. `patch_dashboard_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend before touching the frontend** (the old dashboard keeps working meanwhile — it does not call these routes): logged in as a user with
   INVENTORY_VIEW open `/api/dashboard/inventory` and `/api/dashboard/inventory/detail?type=closing_stock` (JSON, `success:true`), and re-run step 1 with
   `--service=<app root>/services/DashboardInventoryService.php`.
4. `install_dashboard_files_production.php payload/dashboard.js … --replace-expect-sha256=$H_JS`
5. `patch_dashboard_app_css_production.php …`
6. `patch_dashboard_index_html_production.php …` (last — it is what makes browsers fetch the new files)

## 5. Verify in the browser (hard refresh, iPad + desktop)
Dashboard: Gudang filter (Semua Gudang / SCM / Cibadak / Karang Tengah; a warehouse-scoped user sees only their own, locked), Refresh + "Data diperbarui";
4 KPI cards open their drawers; Ringkasan Pergerakan Stok — period Hari Ini / Bulan Ini / Custom, the 4 cards show nominal Rupiah, "Pergerakan lain" discloses
the remaining movements, no red reconciliation warning; click each of the 4 cards: the drawer's **GRAND TOTAL equals the card**, search/category/warehouse filters and
paging work, tables scroll sideways, the drawer scrolls vertically and closing it restores page scroll; Item Perlu Perhatian cards open their lists; no Rupiah overflow.
DevTools → Network: only GET. Compare the numbers with Laporan HPP / Stok Barang for the same warehouse.

## 6. Rollback (two-phase, fail-closed; returns production to the exact previous files)
```
php scripts/rollback_dashboard_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_dashboard_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/dashboard.js"   # must equal H_PHP H_IDX H_CSS H_JS
```
Refuses (changing nothing) if any of the five files was edited after this package or any state file/backup is missing. The service file is deleted; the other four
are restored byte-exactly. Order matters if Jejak v3 is also deployed later: roll back the dashboard package FIRST, then Jejak. Manual fallback: copy `$B/*` back per file and delete
`$SVC/DashboardInventoryService.php`.

## Behaviour notes (what the numbers mean)
* **Stok Awal / Stok Akhir** are reconstructed from the inventory ledger (POSTED + VOID lines, boundary-exact OPENING counted as beginning inventory), NOT from today's stock.
  **Pembelian** = POSTED purchase IN only (transfer receipts are *Pergerakan lain*, never purchases). **Barang Keluar** = OUT usage only (transfer out is *Pergerakan lain*).
  Company-wide figures are the sum over warehouses; an internal transfer nets to zero.
* The identity Awal + Pembelian − Keluar ± Pergerakan lain = Akhir is *measured*, never forced; a non-zero difference shows a red warning.
* Company-wide **Stok Akhir** is on-hand only; the **Nilai Stok** KPI additionally includes goods in transit (shown as "Termasuk transit").
* Mixed-unit quantities are never summed — the movement cards are nominal Rupiah.
* "Rusak" is a recorded Stock Opname finding (latest POSTED session per warehouse), not a live balance.
* A warehouse appears in the Gudang filter only if it is active (`GET /warehouses`).
