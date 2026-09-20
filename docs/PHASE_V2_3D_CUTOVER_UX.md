# Phase V2.3D — Cutover-Aware HPP Report UX

Base commit: `15e8437`. Branch: `claude/funny-ramanujan-wmrlig`. No production access, no
deployment, no inventory data mutation, no schema migration.

## 1. Exact business rule

```
requested_start_date, requested_end_date   = exactly what the user/caller asked for
live_opening_date                          = derived from data (§2)
effective_start_date = MAX(requested_start_date, live_opening_date)
```

Every economic formula (Opening, External Purchase, FIFO HPP, Ending, HPP
Reconciliation, Variance) runs on `effective_start_date`, never
`requested_start_date`. A period whose entire requested range falls before
`live_opening_date` (`effective_start_date > requested_end_date`) cannot be
clamped into a valid window at all — `is_pre_go_live_period` is set and
every economic figure returns as a clean, honest `0`, never a fabricated
movement/HPP/variance. A period at or entirely after go-live is completely
unaffected: `effective_start_date` just equals `requested_start_date`, byte
-identical to Phase V2.3C's behavior — the original 16 Sep opening is never
forced into a report that has genuinely moved past it.

## 2. How the live opening date is derived

`InventoryHppReportService::liveOpeningDate()` — never hardcoded:

```php
EARLIEST of:
  (a) MIN(cutoff_date) FROM stock_openings WHERE status = 'COMMITTED'
  (b) MIN(DATE(transaction_date)) FROM inventory_transactions
      WHERE transaction_type = 'OPENING' AND status = 'POSTED' AND inventory_effect = 1
```

A genuine MIN across both sources, not a fixed priority order — an import
batch recorded later than a directly-posted Opening transaction (or vice
versa) can never hide the earlier of the two real dates. Source (a) is the
authoritative go-live event for data that went through the Opening Stock
import UI (`stock_openings.status='COMMITTED'` is only ever set by
`ImportOpeningStockService::commit()`); source (b) covers data that never
went through that UI (this project's own lower-level test fixtures, which
post `OPENING`-type transactions directly via `FifoService`). Returns
`null` when neither exists — nothing has gone live yet, so no clamping
applies anywhere and the report behaves exactly as it did before this
phase. Proven empirically (not just reasoned about) in the new test
suite's Part 0: the fallback source is proven correct in isolation before
any `stock_openings` row exists, then the primary source is added and
shown to agree.

## 3. Files changed

| File | Change |
|---|---|
| `services/InventoryHppReportService.php` | `liveOpeningDate()`, `cutoverContext()`, `emptyPreGoLiveSummary()` added. `summary()`, `warehouseBreakdown()`, `dailyRecap()`/`buildDailyRows()`, `varianceBridge()`, `buildExportSheets()` all made cutover-aware. `warehouseBreakdown()`'s return shape changed to `{cutover, panels}`. `buildDailyRows()`'s return shape changed to `{rows, cutover}`. |
| `public/index.php` | `/reports/inventory-hpp/warehouses` route simplified — no longer double-wraps `panels` (the service returns the full shape itself). |
| `public/assets/js/report-hpp.js` | Cutover info banner (never styled as an error), "Opening Go-Live" note on the Stok Awal card, pre-go-live row styling + badge in the daily table, default-start-date self-correction on cold load, a short cutover note in the Variance bridge drawer. |
| `public/assets/css/app.css` | `.hpp-cutover-banner`, `.hpp-pre-go-live-badge`, `.hpp-pre-go-live-value`. |
| `public/index.html` | Cache-busting version bump for the changed CSS/JS. |
| `tests/inventory_hpp_report_test.php` | Updated 2 call sites for `warehouseBreakdown()`'s new return shape (pre-existing V2.3 test, not new behavior). |
| `tests/inventory_hpp_v23d_cutover_test.php` | New — 48 assertions. |
| `tests/run_mysql_tests.sh` | Added the new test file. |
| `docs/PHASE_V2_3D_CUTOVER_UX.md` | This document. |

## 4. Screen behavior before / at / after cutover

| Requested range | `effective_start_date` | Banner shown? | Opening Value |
|---|---|---|---|
| Entirely before go-live (e.g. 01 Aug – 10 Sep, go-live 16 Sep) | N/A — `is_pre_go_live_period=true` | Yes, info-styled: *"Periode yang dipilih ... seluruhnya sebelum Opening Go-Live ..."* | Rp 0 (honest, not fabricated) |
| Straddles go-live (e.g. 01 Sep – 30 Sep) | 16 Sep (clamped) | Yes, info-styled: *"Periode efektif inventory: 16 Sep – 30 Sep"* | Full go-live balance, e.g. Rp 2.638.047.167,92 |
| Starts exactly at go-live (16 Sep – 30 Sep) | 16 Sep (unclamped, already equal) | No | Same full go-live balance — byte-identical to Phase V2.3C |
| Starts after go-live (20 Sep – 30 Sep) | 20 Sep (unclamped) | No | The item's own real point-in-time balance as of 20 Sep — proven in the browser to differ from the bare go-live figure (Rp 2.641.047.167,92 vs Rp 2.638.047.167,92), never forced back to it |

The "Opening Go-Live 16 Sep 2026" subnote on the Stok Awal card appears
only when the report's Opening figure is actually anchored at the go-live
boundary (`effective_start_date === live_opening_date`) — never shown for
a genuinely-later point-in-time query, so it can't misleadingly imply
every report is "the go-live number."

**Daily recap**: every requested calendar day still appears (Option A from
the request — never a gap in a table whose whole design promise is "every
calendar day"). Days before `effective_start_date` are flagged
`is_pre_go_live: true`, rendered dimmed with a "Histori Audit" badge, and
every column is a real, honest zero — never a fabricated HPP, purchase,
variance, or movement. The go-live day itself shows the full opening
balance with reconciliation/variance correctly at zero (no phantom gap);
days after continue the normal running ledger unmodified.

**Export**: the Ringkasan sheet's first three rows are literally
"Periode Diminta (Requested Period)", "Periode Efektif Inventory
(Effective Inventory Period)", and "Tanggal Opening Go-Live (Live Opening
Date)". Every economic figure below them is read from the exact same
`summary()`/`warehouseBreakdown()`/`buildDailyRows()` calls the screen
uses — proven identical in the test suite (Case 10), not just assumed.

## 5. Test totals

**New V2.3D suite** (`tests/inventory_hpp_v23d_cutover_test.php`):
**48/48 PASSED** — derivation proof (Part 0, both sources), Cases 1–10
exactly as the sign-off spec numbered them (before/at/after/entirely-before
go-live; consolidated/SCM/CIBADAK; historical-row exclusion; daily recap;
Excel export consistency), plus a read-only guarantee.

## 6. Full regression total

```
mysql_smoke_test.php                            4/4
mysql_integration_test.php                     35/35
mysql_importer_test.php                        17/17
mysql_void_test.php                            15/15
mysql_security_test.php                        11/11
opening_g_data_2_test.php                      20/20
migration_negative_stock_test.php              31/31
warehouse_isolation_regression_test.php        28/28
stock_policy_test.php                          34/34
master_data_v2_test.php                        27/27
stock_report_test.php                          26/26
transaction_history_test.php                   32/32
master_data_v2_1_test.php                      54/54
trace_test.php                                111/111
inventory_hpp_report_test.php                  50/50
inventory_hpp_costing_audit_test.php           51/51
inventory_hpp_v23c_production_hotfix_test.php  32/32
inventory_hpp_v23d_cutover_test.php            48/48  (new)
concurrency_test.sh                             5/5
-----------------------------------------------------
TOTAL                                         631/631 PASSED, 0 FAILED
```

## 7. Screenshots (browser verification)

Captured against a live `php -S` instance seeded with a real `stock_openings`
COMMITTED batch (cutoff 2026-09-16) plus the same production-like SCM/CIBADAK
composition Phase V2.3C used, logged in as a throwaway SUPERADMIN account,
zero console errors on every screenshot:

- **Cold load, default range** — the naive "current month" default (01 Sep)
  self-corrected to the real go-live date (16 Sep) with no visible flash of
  the wrong range; Stok Awal card shows the "Opening Go-Live 16 Sep 2026" note.
- **Straddling range (01–30 Sep)** — effective-period banner shown, Stok Awal
  = Rp 2.638.047.167,92 (not Rp 0), Variance = Rp 0 "Seimbang", daily table's
  first ten rows dimmed with "HISTORI AUDIT" badges, all Rp 0.
- **Entirely before go-live (01 Aug – 10 Sep)** — clean info-styled empty
  state, all KPI figures Rp 0, never presented as an error.
- **After go-live (20–30 Sep)** — no banner, no go-live subnote, Stok Awal =
  Rp 2.641.047.167,92 — its own genuine point-in-time balance, provably
  different from the bare go-live figure.
- **SCM only / CIBADAK only** — Rp 2.330.669.085,78 / Rp 307.378.082,14
  respectively, matching the production-like target exactly, same banner
  and daily-table behavior as consolidated.
- **Excel export** — triggered a real download, valid file.

Sent to the user directly: the straddling-range, entirely-pre-go-live, and
after-go-live screenshots.

## 8. Final commit

See branch `claude/funny-ramanujan-wmrlig`, commit following this document
in the same push. Not deployed to production, per the explicit instruction.
