# Laporan Stock Opname — audit redesign on real session data (NOT deployed)

The report is rebuilt as a complete audit report from the real Stock Opname data: **Ringkasan Sesi** (petugas P1 / P2, supervisor, finalizer, real timestamps,
Match / Mismatch, signed variance value, adjustment status) and **Rincian Item** (wide horizontal table: system vs final quantity, Good / Expired / Rusak /
Deadstock, HPP and value, variance, petugas hitung / verifikasi, timestamps, evidence thumbnails + gallery, notes, adjustment linkage), an item drawer
(count history incl. voided findings, evidence, reconciliation, adjustment, audit events), column visibility, and exports that mirror the screen.
It is built **on the existing Jejak read model** (`StockOpnameJejakService`) — no second formula. **Strictly read-only: no database schema change, nothing written,
no existing route removed**; Jejak, Proses Stock Opname, posting, sessions 11 / 12, adjustments and inventory are untouched.
Every script **fails closed**: it refuses, changing nothing, unless the file's current SHA256 and every anchor match exactly. Dry-run is the default.
Independent of the Jejak, Dashboard, Stock IN/OUT V2, UI2, Master Data, sidebar-cleanup and Pergerakan Stok packages (own state files `.pre-soa-backup` / `.soa-patch.json`).

| Target | Action |
|---|---|
| `services/StockOpnameAuditReportService.php` | NEW (SHA256 `@@H_SVC@@`) — read-only audit read model on `StockOpnameJejakService` + `StockOpnameBookStockService` |
| `public/index.php` | patch: +1 `require_once`, +3 request helpers (payload `soa_index_php_helper.txt` `@@H_HELPER@@`), +5 GET routes inserted before `GET /reports/opname` (payload `soa_index_php_routes.txt` `@@H_ROUTES@@`): `/reports/opname-audit/sessions`, `/items`, `/item-detail`, `/photo/{id}`, `/export` |
| `public/assets/js/api-client.js` | patch: +4 one-line methods after `stockOpnameReportPrintUrl` |
| `public/assets/js/stock-opname-report.js` | REPLACE (new SHA256 `@@H_JS@@`) |
| `public/assets/css/app.css` | APPEND the self-contained `.soa-*` block (block SHA256 `@@H_BLK@@`) |
| `public/index.html` | tags: `app.css`, `api-client.js`, `stock-opname-report.js` → `?v=20261014-soa` (nothing else) |
| `scripts/opname_audit_reconcile_check.php` | shipped for you: READ-ONLY reconciliation of every session (A–G, incl. sessions 11 and 12) — never writes |

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).
**Prerequisite:** the Laporan Stock Opname page (`opname-laporan`, `stock-opname-report.js`) and the Jejak service must already be in production — step 0 shows it; if
`stock-opname-report.js` or its `<script>` tag is absent, the scripts refuse at the first gate and nothing changes: send me the step-0 output.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_soa.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 5 existing files; every anchor count as shown in brackets; "no Laporan Stock Opname leftovers"; INVENTORY_VIEW held by the roles you expect;
**no `MISSING_column` rows**; the sessions list (11 and 12 POSTED). `stock-opname-report.js` is REPLACED: tell me if yours was edited locally.

## 1. Safety copy + verify package
```
B=~/soa_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB/assets/js/stock-opname-report.js" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_API=<…api-client.js>; H_JS=<…stock-opname-report.js>
php scripts/install_soa_files_production.php payload/StockOpnameAuditReportService.php "$SVC/StockOpnameAuditReportService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_soa_index_php_production.php "$PUB/index.php" payload/soa_index_php_helper.txt payload/soa_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@
php scripts/patch_soa_api_client_production.php "$PUB/assets/js/api-client.js" --expect-sha256=$H_API
php scripts/install_soa_files_production.php payload/stock-opname-report.js "$PUB/assets/js/stock-opname-report.js" --expect-payload-sha256=@@H_JS@@ --replace-expect-sha256=$H_JS
php scripts/patch_soa_app_css_production.php "$PUB/assets/css/app.css" payload/soa_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_soa_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_soa_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing is patched yet
```

## 3. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_soa_files_production.php payload/StockOpnameAuditReportService.php …`
2. `patch_soa_index_php_production.php …`, then `php -l "$PUB/index.php"`
3. **Verify the backend against the real sessions before touching the frontend** (the old screen keeps working meanwhile — it does not call the new routes):
   `php scripts/opname_audit_reconcile_check.php --app-root="$APP" --session=11,12` — every line must PASS (exit 0); then without `--session` for every session. Send me the output.
   A FAIL prints the session / SKU / difference: **do not continue** on a non-zero exit.
4. `patch_soa_api_client_production.php …`, then `install_soa_files_production.php payload/stock-opname-report.js …`
5. `patch_soa_app_css_production.php …`
6. `patch_soa_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/soa_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone)
Log in as an admin and open **Laporan → Laporan Stock Opname** (under Stock Opname → "Laporan Stock Opname" if the sidebar package is not applied):
* Filters: Dari / Sampai Tanggal, Gudang, Status (Open / Finalized / Posted / Cancelled), Pencarian (no. sesi, SKU, barang, petugas, catatan); Terapkan / Reset; Export Excel menu.
* KPI cards: Total Sesi, Item Diverifikasi (Match / Mismatch / Recount, % match), Total Selisih Nilai (signed), Good / Expired / Rusak / Deadstock (SKU count + quantity **per unit**, never one mixed total).
* **Ringkasan Sesi**: one row per session with petugas P1 / P2, supervisor, finalizer, start / end times, Match / Mismatch, Selisih (per satuan + nilai), adjustment status. Click a row → **Rincian Item Opname** of that session appears below; **Lihat Jejak** opens the existing Jejak drawer; **Cetak** the finance print; **Excel Final** (supervisors only) the existing final export.
* **Rincian Item**: wide table scrolls sideways inside its box (sticky No. / SKU / Nama + header + TOTAL row); evidence thumbnails open a gallery (image, SKU, session, petugas, timestamp, condition); "Detail" opens the drawer (Ringkasan, Riwayat Hitung, Evidence, Rekonsiliasi, Adjustment, Audit).
* Sessions 11 (SCM) and 12 (Cibadak): compare Total Item, Match / Mismatch, Selisih Nilai and Adjustment with the Jejak drawer of the same session — they must be identical.
* Export Excel: 7 sheets (Ringkasan Sesi, Rincian Item, Evidence, Riwayat Hitung, Adjustment, Audit Log, Info). Export always contains ALL business columns and follows the filters + the selected session.
* Log in as a VIEWER (INVENTORY_VIEW only): the report opens; no "Excel Final". As a STOCK / warehouse-scoped user: only that warehouse's sessions (the API ignores another `warehouse_id`; another warehouse's evidence photo → 403).

## 5. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_soa_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_soa_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/api-client.js" "$PUB/assets/js/stock-opname-report.js"   # must equal the hashes from step 0
```
Refuses (changing nothing) if any of the six files was edited after this package (a LATER package that changed one of them — roll that one back first; the sidebar and Pergerakan
Stok packages also edit `index.html`) or any state file / backup is missing. The new service file is deleted; the five changed files are restored byte-exactly. Nothing else needs undoing: no data was written.
Manual fallback: copy `$B/*` back per file and delete `StockOpnameAuditReportService.php`.

## Behaviour notes
* **Permissions** — every new route requires `INVENTORY_VIEW` (HPP is shown under INVENTORY_VIEW exactly like Jejak and the HPP reports) and resolves the warehouse like `GET /reports/opname` (STOCK and a warehouse-scoped ADMIN are forced to their own warehouse; another warehouse's session or photo → 403).
* **Semantics (never mixed silently; each row shows its model)** — Legacy: Qty Fisik Final = total counted, Rusak / Expired / Deadstock are subsets of it (they never changed stock), Good = total − those. FINDINGS_V1: Good is the end-of-day physical stock, conditions are separate, total = Good + Expired + Rusak + Deadstock; variance = Good EOD − book stock. A legacy line where no condition was ever recorded shows "—", not 0.
* **Match / Mismatch** — the production per-line status: P1 count = P2 count (Match), both counted but different (Mismatch), resolved by a recount (Recount).
* **Timestamps** — FINDINGS_V1: the min / max `counted_at` (else `created_at`) of the NON-voided findings per team and line; legacy: `p1_submitted_at` / `p2_submitted_at` / `recount_submitted_at`. A voided finding is never shown as an actor or a time. Never inferred from the session date.
* **Valuation** — HPP = `stock_opname_lines.unit_cost_base` (snapshot at session start), never today's price. Adjustment value = the real posted `stock_adjustments` (FIFO cost) and can legitimately differ from the variance value.
* **Evidence** — only photos attached to a finding; images are served by the report's own route (INVENTORY_VIEW + warehouse scope). Exports carry the URL / reference, uploader and timestamp (images are not embedded). Legacy sessions have no evidence storage: "—".
