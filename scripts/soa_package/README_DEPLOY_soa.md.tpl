# Laporan Stock Opname — audit redesign on real session data (NOT deployed)

The report is rebuilt as a complete audit report from the real Stock Opname data: **Ringkasan Sesi** (petugas P1 / P2, supervisor, finalizer, real timestamps,
Match / Mismatch, signed variance value, adjustment status) and **Rincian Item** (wide horizontal table: system vs final quantity, Good / Expired / Rusak /
Deadstock, HPP and value, variance, petugas hitung / verifikasi, timestamps, evidence thumbnails + gallery, notes, adjustment linkage), an item drawer
(count history incl. voided findings, evidence, reconciliation, adjustment, audit events), column visibility, and exports that mirror the screen.
It is built **on the existing Jejak read model** (`StockOpnameJejakService`) — no second formula. **Strictly read-only: no database schema change, nothing written,
no existing route removed**; Jejak, Proses Stock Opname, posting, sessions 11 / 12, adjustments and inventory are untouched.
Every script **fails closed**: it refuses, changing nothing, unless the file's current SHA256 and every anchor match exactly. Dry-run is the default.
Independent of the Jejak, Dashboard, Stock IN/OUT V2, UI2, Master Data, sidebar-cleanup, Pergerakan Stok (MVR) and Pembelian packages (own state files `.pre-soa-backup` / `.soa-patch.json`). **v2 — built against the real production layout**: it recognises the live Jejak tag `assets/js/stock-opname-report-jejak.js?v=…` (kept untouched), coexists with the MVR tokens (`app.css?v=20261013-mvr`, `api-client.js?v=20261013-mvr`, which it never rewrites except the `app.css` token it must bump), and does **not** touch `api-client.js` / `api-client-v2163eod.js` at all (the page issues its own read-only GETs).

| Target | Action |
|---|---|
| `services/StockOpnameAuditReportService.php` | NEW (SHA256 `@@H_SVC@@`) — read-only audit read model on `StockOpnameJejakService` + `StockOpnameBookStockService` |
| `public/index.php` | patch: +1 `require_once`, +3 request helpers (payload `soa_index_php_helper.txt` `@@H_HELPER@@`), +5 GET routes inserted before `GET /reports/opname` (payload `soa_index_php_routes.txt` `@@H_ROUTES@@`): `/reports/opname-audit/sessions`, `/items`, `/item-detail`, `/photo/{id}`, `/export` |
| `public/assets/js/stock-opname-report.js` | NEW in production (SHA256 `@@H_JS@@`) — the new report page (`StockOpnameReport`). If the file already exists the installer switches to REPLACE mode behind a hash gate |
| `public/assets/js/app.js` | patch: **one line** — the route of the existing Laporan Stock Opname page (`laporan-opname`, container `#tab-laporan-opname`) `ReportOpname.render(…)` → `StockOpnameReport.render(…)`. The sidebar link, permission, container and the old `report-opname.js` stay as they are |
| `public/assets/css/app.css` | APPEND the self-contained `.soa-*` block (block SHA256 `@@H_BLK@@`) |
| `public/index.html` | (1) INSERT `<script src="assets/js/stock-opname-report.js?v=20261014-soa">` right after the live Jejak tag `stock-opname-report-jejak.js?v=<any>` (that tag must match exactly once and is never modified); (2) `app.css` and `app.js` tags → `?v=20261014-soa`. Nothing else |
| `scripts/opname_audit_reconcile_check.php` | shipped for you: READ-ONLY reconciliation of every session (A–G, incl. sessions 11 and 12) — runs in a READ ONLY transaction, a write is proved to be rejected first |

**Why the Jejak file is not renamed or replaced:** `stock-opname-report-jejak.js` is the Jejak *drawer* component (`StockOpnameJejak`); the new page calls it ("Lihat Jejak"). It stays loaded exactly as it is, so Jejak keeps working and a rollback leaves its reference byte-identical. The page that was active before (`report-opname.js`, tab `laporan-opname`) is *re-routed* by the one app.js line; its file and script tag are left in place.

PHP 8+ CLI. Run from this directory. `PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root (contains `services/`, `config/`, `public/`).
**Prerequisites** (step 0 shows each): the Jejak service + its drawer script tag (`stock-opname-report-jejak.js`), and the `laporan-opname` route line in `app.js`. If an anchor is not found exactly as documented the scripts refuse at that gate and nothing changes — send me the step-0 output.

## 0. Collect (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_soa.sh "$PUB" "$SVC"
mysql -u<user> -p <database> < precheck_readonly.sql
```
Needed: SHA256 of the 4 existing files (`index.php`, `index.html`, `app.css`, `app.js`); whether `stock-opname-report.js` is absent; every anchor count as shown in brackets; the **api-client AUDIT** block; "no Laporan Stock Opname leftovers"; INVENTORY_VIEW held by the roles you expect;
**no `MISSING_column` rows**; the sessions list (11 and 12 POSTED). 

## 1. Safety copy + verify package
```
B=~/soa_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js" $B/
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_APP=<…app.js>
php scripts/install_soa_files_production.php payload/StockOpnameAuditReportService.php "$SVC/StockOpnameAuditReportService.php" --expect-payload-sha256=@@H_SVC@@
php scripts/patch_soa_index_php_production.php "$PUB/index.php" payload/soa_index_php_helper.txt payload/soa_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@
php scripts/install_soa_files_production.php payload/stock-opname-report.js "$PUB/assets/js/stock-opname-report.js" --expect-payload-sha256=@@H_JS@@      # NEW file (add --replace-expect-sha256=<its sha256> ONLY if step 0 showed it exists)
php scripts/patch_soa_app_js_production.php "$PUB/assets/js/app.js" --expect-sha256=$H_APP
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
4. `install_soa_files_production.php payload/stock-opname-report.js …`, then `patch_soa_app_js_production.php …`
5. `patch_soa_app_css_production.php …`
6. `patch_soa_index_html_production.php …` (last — it is what makes browsers fetch the new files; it inserts the new tag after the Jejak tag and bumps the app.css / app.js tokens)
7. `php scripts/soa_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone)
Log in as an admin and open the Laporan Stock Opname page (the link that opened the old report — `laporan-opname`; with the sidebar-cleanup package it is the "Laporan Stock Opname" entry **only if** step 0 showed the `opname-laporan` tab/container/route exist — see the note below):
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
sha256sum "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js"   # must equal the hashes from step 0; stock-opname-report.js and the service must be gone
grep -n "stock-opname-report-jejak.js\|app.css?v=\|app.js?v=" "$PUB/index.html"   # the Jejak reference and the MVR token are back exactly as before
```
Refuses (changing nothing) if any of the six files was edited after this package (a LATER package that changed one of them — roll that one back first; the sidebar and Pergerakan
Stok packages also edit `index.html` / `app.css`) or any state file / backup is missing. The two new files (service, `stock-opname-report.js`) are deleted; the four changed files are restored byte-exactly — so the Jejak tag and the previous tokens return unchanged. Nothing else needs undoing: no data was written.
Manual fallback: copy `$B/*` back per file and delete `StockOpnameAuditReportService.php` and `stock-opname-report.js`.

## Behaviour notes
* **Permissions** — every new route requires `INVENTORY_VIEW` (HPP is shown under INVENTORY_VIEW exactly like Jejak and the HPP reports) and resolves the warehouse like `GET /reports/opname` (STOCK and a warehouse-scoped ADMIN are forced to their own warehouse; another warehouse's session or photo → 403).
* **Semantics (never mixed silently; each row shows its model)** — Legacy: Qty Fisik Final = total counted, Rusak / Expired / Deadstock are subsets of it (they never changed stock), Good = total − those. FINDINGS_V1: Good is the end-of-day physical stock, conditions are separate, total = Good + Expired + Rusak + Deadstock; variance = Good EOD − book stock. A legacy line where no condition was ever recorded shows "—", not 0.
* **Match / Mismatch** — the production per-line status: P1 count = P2 count (Match), both counted but different (Mismatch), resolved by a recount (Recount).
* **Timestamps** — FINDINGS_V1: the min / max `counted_at` (else `created_at`) of the NON-voided findings per team and line; legacy: `p1_submitted_at` / `p2_submitted_at` / `recount_submitted_at`. A voided finding is never shown as an actor or a time. Never inferred from the session date.
* **Valuation** — HPP = `stock_opname_lines.unit_cost_base` (snapshot at session start), never today's price. Adjustment value = the real posted `stock_adjustments` (FIFO cost) and can legitimately differ from the variance value.
* **Evidence** — only photos attached to a finding; images are served by the report's own route (INVENTORY_VIEW + warehouse scope). Exports carry the URL / reference, uploader and timestamp (images are not embedded). Legacy sessions have no evidence storage: "—".

## Note — sidebar-cleanup package (SBC) and the `opname-laporan` tab
The SBC package points its "Laporan Stock Opname" link at the tab `opname-laporan`. That tab only exists where the V2.16.4 monthly report was deployed. Step 0 prints
`name === 'opname-laporan'` (app.js), `id="tab-opname-laporan"` (index.html) and `data-tab="laporan-opname"` counts. If `opname-laporan` is missing in production, do NOT apply SBC as-is
(tell me — I will re-target its link to `laporan-opname`); the page re-pointed by this package is `laporan-opname`.

## Note — why production has both `api-client-v2163eod.js` and `api-client.js` (audit, nothing removed)
History in this repo (`patch_api_client_v2163eod_production.php`): the Oct-2/3 production hotfix established that the browser was executing `api-client-v2163eod.js` (an older InvApi copy that lacked `listUnits`),
while `api-client.js` was not referenced. Later packages (Dashboard, Stock IN/OUT, UI2, Master Data, MVR…) patch `api-client.js` and bump *its* token; production now loads both tags.
Two classic scripts that both declare `const InvApi` cannot coexist (the second throws a SyntaxError and does not run), so which one is effective depends on the tag order and on how the hotfix file declares it —
that cannot be proven from here without the production files. The step-0 AUDIT block prints, per file, its hash, the number of `InvApi` declarations and which feature methods it contains, plus two console probes
(`typeof InvApi.movementOverview`, `typeof InvApi.listUnits`). Neither file is removed by any package. **This package is unaffected by the answer** (it does not use InvApi), but please send the audit output: if `movementOverview` is `undefined`, the MVR report would be calling a method that the effective InvApi does not have.
