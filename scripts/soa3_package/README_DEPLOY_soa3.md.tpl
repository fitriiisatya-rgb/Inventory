# Laporan Stock Opname — audit redesign **V3** (NOT deployed)

V3 replaces V2 (do **not** use the V2 package / hash `ce93de3c…`). It fixes the two real-data reconciliation failures found on production Session 11 (SCM, FINDINGS_V1, POSTED, 1189 items) and adds a **safe upgrade path from the current partial state**
(*SOA V2 backend applied · SOA V2 frontend NOT applied · MVR fully deployed*). Strictly **read-only**: no schema change, no data written, sessions 11 and 12 and all posting / Jejak / inventory logic untouched.
Every script **fails closed** (SHA256 preimage gates, exact-once anchors, dry-run by default, backup, atomic write + verify, double-apply refusal, two-phase rollback). Own state-file suffixes: `.pre-soa3-backup` / `.soa3-patch.json`
(the SOA V2 package's `.soa-patch.json` / `.pre-soa-backup` files are never read, changed or removed).

## What changed in the report (the two failures)
* **D — final conditions (Rusak / Expired / Deadstock).** The authoritative persisted snapshot is `stock_opname_lines.final_{rusak,expired,deadstock}_qty` (what Jejak and posting use). In a real posted FINDINGS_V1 session it can exist **without**
  any non-VOID condition finding (Session 11: e.g. RM-AK-26-003 Deadstock 35, no finding). The report shows the snapshot **as stored** (never erased, zeroed or inferred) and lists the finding history **beside** it:
  new row fields `Sumber Kondisi` ("Snapshot final (stock_opname_lines.final_*)" / "Satu sisi saja" / …) and `Catatan Kondisi` ("Deadstock 35: snapshot final tanpa temuan kondisi di riwayat"), a banner per session, a conditions table in the item drawer
  (snapshot vs per-team findings, status cocok / tanpa temuan / berbeda) and a note in "Riwayat Hitung". The historical findings drawer still shows exactly the findings that exist — including their absence. The reconciliation check D now compares the shown values with the
  **persisted snapshot** (D2) and verifies that every difference from the findings is **disclosed** (D3) instead of requiring findings.
* **E — adjustment value.** Adjustment values are now **raw**: `qty_base_delta × unit_cost_base` per row, summed without per-row rounding (the old code rounded each row to 2 decimals, so 395 rows summed to Rp 2.233.226.305,96 instead of Rp 2.233.226.305,9173).
  Only the currency display / export formatting rounds. The checker compares the report with an independent exact-decimal SQL `SUM(qty_base_delta * unit_cost_base)` with an explicit tolerance of **Rp 0,001** — the floating-point accumulation bound of summing ~10³ exact products in a double, three orders of magnitude below any real discrepancy — and it prints what the per-row-rounded sum *would* have been.
  (Jejak keeps its own documented per-row 2-decimal rule for variance values; it is unchanged.)

## Files
| Target | Action |
|---|---|
| `services/StockOpnameAuditReportService.php` | PATH A: NEW · PATH B: REPLACE the exact V2 file (SHA256 gate). V3 SHA256 `@@H_SVC@@` |
| `public/index.php` | PATH A: +1 `require_once`, +3 helpers (`soa_index_php_helper.txt` `@@H_HELPER@@`), +5 GET routes (`soa_index_php_routes.txt` `@@H_ROUTES@@`) · PATH B: **verified, not modified** (the V2 backend is byte-identical to these payloads) |
| `public/assets/js/stock-opname-report.js` | NEW (SHA256 `@@H_JS@@`) — self-contained page (own read-only GET helper; no InvApi / api-client dependency) |
| `public/assets/js/app.js` | ONE line: `ReportOpname.render(…'tab-laporan-opname')` → `StockOpnameReport.render(…)` |
| `public/assets/css/app.css` | APPEND the `.soa-*` block (SHA256 `@@H_BLK@@`) |
| `public/index.html` | INSERT `<script src="assets/js/stock-opname-report.js?v=20261017-soa3">` right after the live Jejak tag `stock-opname-report-jejak.js?v=<any>` (exactly once, never modified); `app.css` / `app.js` tags → `?v=20261017-soa3`. `api-client.js` / `api-client-v2163eod.js` and the MVR tokens are not touched |
| `scripts/opname_audit_reconcile_check.php` | READ-ONLY reconciliation A–G of every session (READ ONLY transaction; a write is proved to be rejected first) |
| `scripts/soa3_state_check.php` | READ-ONLY: classifies the production tree (CLEAN / PARTIAL_V2_BACKEND / V3_APPLIED / refused) |

`PUB` = production `public/`, `SVC` = production `services/`, `APP` = app root. PHP 8+ CLI, run from this directory.

## 0. Collect + STATE (READ-ONLY) — send me this output before step 3
```
bash collect_production_hashes_soa3.sh "$PUB" "$SVC"        # ends with the STATE CHECK
mysql -u<user> -p <database> < precheck_readonly.sql
```
The STATE line decides the path:
* `STATE: CLEAN` → **PATH A** (nothing of the report installed).
* `STATE: PARTIAL_V2_BACKEND` → **PATH B** (V2 backend applied, no frontend). It prints the exact `--replace-expect-sha256=` value (the V2 service file).
* anything else → **REFUSED** (V2 frontend present, service edited, half-applied index.php, V3 already applied …). Nothing is changed; send me the output.

## 1. Safety copy + verify package
```
B=~/soa3_pre_$(date +%Y%m%d%H%M); mkdir -p $B
cp -p "$PUB/index.php" "$PUB/index.html" "$PUB/assets/css/app.css" "$PUB/assets/js/app.js" "$SVC/StockOpnameAuditReportService.php" $B/ 2>/dev/null
sha256sum -c SHA256SUMS
```

## 2. Dry-run everything (writes nothing)
```
H_PHP=<sha256 index.php>; H_HTML=<…index.html>; H_CSS=<…app.css>; H_APP=<…app.js>
# PATH A:  service as NEW          PATH B:  add  --replace-expect-sha256=<value printed by the state check>
php scripts/install_soa3_files_production.php payload/StockOpnameAuditReportService.php "$SVC/StockOpnameAuditReportService.php" --expect-payload-sha256=@@H_SVC@@ [--replace-expect-sha256=<V2 service sha256>]
php scripts/patch_soa3_index_php_production.php "$PUB/index.php" payload/soa_index_php_helper.txt payload/soa_index_php_routes.txt \
    --expect-sha256=$H_PHP --expect-helper-sha256=@@H_HELPER@@ --expect-routes-sha256=@@H_ROUTES@@     # prints "STATE: CLEAN" (A) or "STATE: V2 BACKEND ALREADY APPLIED" (B)
php scripts/install_soa3_files_production.php payload/stock-opname-report.js "$PUB/assets/js/stock-opname-report.js" --expect-payload-sha256=@@H_JS@@
php scripts/patch_soa3_app_js_production.php "$PUB/assets/js/app.js" --expect-sha256=$H_APP
php scripts/patch_soa3_app_css_production.php "$PUB/assets/css/app.css" payload/soa_app_css_block.css --expect-sha256=$H_CSS --expect-block-sha256=@@H_BLK@@
php scripts/patch_soa3_index_html_production.php "$PUB/index.html" --expect-sha256=$H_HTML
php scripts/rollback_soa3_production.php --public-dir="$PUB" --services-dir="$SVC"      # must FAIL now: nothing of V3 is applied yet
```

## 3. Apply — BACKEND FIRST, verify, then FRONTEND (same commands + `--apply`, in this order)
1. `install_soa3_files_production.php payload/StockOpnameAuditReportService.php …` (PATH B: with `--replace-expect-sha256`)
2. `patch_soa3_index_php_production.php …` (A: inserts the backend, then `php -l "$PUB/index.php"`; B: only records the verification)
3. **Verify the backend against the real sessions — before the frontend:**
   `php scripts/opname_audit_reconcile_check.php --app-root="$APP" --session=11,12` — **every** check of both sessions must PASS (exit 0); on Session 11 the output states
   `NOTE: kondisi final berasal dari snapshot posting …` for the items whose Deadstock has no finding, and `NOTE: Σ of per-row 2-dp rounded values would be …` for the adjustments. **Do not continue on a non-zero exit** — send me the output.
4. `install_soa3_files_production.php payload/stock-opname-report.js …`, then `patch_soa3_app_js_production.php …`
5. `patch_soa3_app_css_production.php …`
6. `patch_soa3_index_html_production.php …` (last — it is what makes browsers fetch the new files)
7. `php scripts/soa3_readonly_check.php --public-dir="$PUB" --services-dir="$SVC" --files` — must be all PASS. `php scripts/soa3_state_check.php --public-dir="$PUB" --services-dir="$SVC"` now says `V3_APPLIED`.

## 4. Verify in the browser (hard refresh; desktop + iPad + phone)
Open the Laporan Stock Opname page (the link that opened the old report — tab `laporan-opname`). Filters, KPI cards, **Ringkasan Sesi**, **Rincian Item** (wide table, sticky columns, TOTAL row, evidence gallery), item drawer
(Ringkasan incl. the new **Kondisi** block, Riwayat Hitung, Evidence, Rekonsiliasi, Adjustment, Audit), "Lihat Jejak" (the existing Jejak drawer), exports (7 sheets; the item sheet has the columns Sumber Kondisi / Catatan Kondisi).
On Session 11: the blue note above the table lists the items whose conditions come from the snapshot; Adjustment total ≈ Rp 2.233.226.305,92 (display) — the export carries the raw value.
Permissions as before: VIEWER opens the report; a STOCK / warehouse-scoped user sees only that warehouse's sessions.

## 5. Rollback (two-phase, fail-closed; returns every file to its previous bytes)
```
php scripts/rollback_soa3_production.php --public-dir="$PUB" --services-dir="$SVC"           # dry run
php scripts/rollback_soa3_production.php --public-dir="$PUB" --services-dir="$SVC" --apply
```
* **From PATH A:** removes the service and `stock-opname-report.js`, restores `index.php`, `app.js`, `app.css`, `index.html` byte for byte (the Jejak reference and the previous tokens return unchanged).
* **From PATH B:** restores the **V2 service bytes**, leaves `index.php` untouched (the V2 backend stays), removes `stock-opname-report.js`, restores `app.js` / `app.css` / `index.html` — i.e. back to *exactly the partial V2 state you started from*; the V2 state files are never touched.
Refuses (changing nothing) if any of the six files was edited after this package, or any V3 state file / backup is missing (a LATER package that changed one of them — roll that one back first).
Manual fallback: `$B/*` per file; delete `stock-opname-report.js` (and, PATH A, the service).

## Behaviour notes
* **Permissions** — every route requires `INVENTORY_VIEW` and resolves the warehouse like `GET /reports/opname` (STOCK / warehouse-scoped ADMIN forced to their warehouse; another warehouse's session or photo → 403).
* **Semantics** — Legacy: Qty Fisik Final = total counted, Rusak / Expired / Deadstock are subsets (never changed stock), Good = total − those. FINDINGS_V1: Good is the end-of-day physical stock, conditions separate, total = Good + Expired + Rusak + Deadstock; variance = Good EOD − book stock. "—" = not recorded (never 0).
* **Valuation** — HPP = `stock_opname_lines.unit_cost_base` (snapshot at session start). Adjustment value = the real posted `stock_adjustments` at raw precision (can legitimately differ from the variance value).
* **api-client** — the page does not use InvApi; whichever of `api-client.js` / `api-client-v2163eod.js` is effective makes no difference to it. (If `typeof InvApi.movementOverview` is `undefined` in the browser console, the already-deployed MVR report is affected — send me the collect AUDIT block.)
