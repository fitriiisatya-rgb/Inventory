# Phase V2.3C — Production HPP Report Hotfix

Base commit: `12943c5`. Branch: `claude/funny-ramanujan-wmrlig`. Reporting/costing
hotfix only — no production access, no production deployment, no production
data mutation, no schema migration, no warehouse/category/item creation or
normalization, Karang Tengah never referenced.

## 1. Root cause

Production evidence showed the HPP report for `2026-09-16..2026-09-20` producing
`ending_value = 2,649,472,690.4737` against a true company inventory value of
`2,638,047,167.9209` — a difference of `11,425,522.5528`, exactly 2× the combined
magnitude of the 5 approved migration-negative balances. Three independent,
compounding defects, all in `services/InventoryHppReportService.php`:

**Root Cause 1 — `ABS()` destroyed migration-negative sign.** Every
`FifoService::postIn()` call produces a non-negative `subtotal` **except one**:
`ImportOpeningStockService` posts a migration-negative Opening line with a
*negative* `input_qty` (only when `MigrationNegativeStockService::isWhitelisted()`
approves it), so that line's `subtotal` is genuinely negative — the correct
representation of a known negative balance. The report's `SIGNED_VALUE_SQL`
wrapped the "+direction" branch (IN/OPENING/TRANSFER_IN/PRODUCTION_OUT) in
`ABS()`, flipping that one legitimate negative value positive — a -0.5 unit
migration-negative balance was reported as if it were +0.5, doubling the error
(the balance should have subtracted from the total; instead it added).

**Root Cause 2 — VOID'd IN counted as External Purchase.** The report's movement
totals correctly use `status IN ('POSTED','VOID')` for historical ledger
reconstruction (Phase V2.3B's fix — a voided transaction's original entry and
its REVERSAL must both count, each on its own real date, to net back to the
true current state). But "Pembelian Eksternal" (External Purchase) is a
business-facing KPI, not a reconstruction figure — it inherited the same
broadened filter, so a smoke-tested VOID'd IN in production (`external_purchase
= 400`) was counted as if it were an effective purchase.

**Root Cause 3 — OPENING period-start boundary.** `signedValueBefore()` used
`transaction_date < start_date 00:00:00` uniformly. Production's live stock
Opening is dated exactly at the start of `2026-09-16` — the report's own query
start — so it failed the strict `<` and was excluded from `opening_value`
entirely, then swept into `opening_mid_period` as if the entire live opening
balance (≈2.65B) were a movement that happened *during* the reported period.
That misclassification, combined with Root Cause 1's sign flip on the
migration-negative layers *within* that same swept-in balance, produced the
compounded 2× discrepancy observed in production.

## 2. Files changed

| File | Change |
|---|---|
| `services/InventoryHppReportService.php` | All three root-cause fixes (below), a new `voided_in_net` bridge bucket, updated class/method docblocks. No new tables, no schema change. |
| `tests/inventory_hpp_v23c_production_hotfix_test.php` | New — 32 assertions reproducing the production scenario and proving all three fixes plus the 13-point fixture checklist. |
| `tests/run_mysql_tests.sh` | Added the new test file to the regression run. |
| `docs/PHASE_V2_3C_PRODUCTION_HOTFIX.md` | This document. |

## 3. Exact sign convention table (audited against the real posting code, not assumed)

| Transaction type | Posted by | `base_qty`/`subtotal` sign in practice | Report's signed-value rule |
|---|---|---|---|
| `IN` | `FifoService::postIn()` | Always > 0 (`input_qty > 0` enforced) | `l.subtotal` as-is (never `ABS()`) |
| `OPENING` (normal) | `FifoService::postIn()` (`ImportOpeningStockService`) | Always > 0 | `l.subtotal` as-is |
| `OPENING` (migration-negative) | `FifoService::postIn()` with `allow_migration_negative_opening=true`, only when whitelisted | **Genuinely < 0** — the one deliberate exception in the whole system | `l.subtotal` as-is (this is the exact fix — never `ABS()`) |
| `TRANSFER_IN` | `TransferService::receive()` → `postIn()` | Always > 0 (copies the exact source layer's positive qty/cost) | `l.subtotal` as-is |
| `PRODUCTION_OUT` | `ProductionService::create()` → `postIn()` | Always ≥ 0 (derived cost) | `l.subtotal` as-is |
| `OUT` | `FifoService::postOut()` | Always ≥ 0 (`subtotal` accumulated from `qty * cost` sums, provably never negative) | `-ABS(l.subtotal)` — kept intentionally; a documented no-op, not blind dead code |
| `TRANSFER_OUT` | `TransferService::create()` → `postOut()` | Always ≥ 0 | `-ABS(l.subtotal)` |
| `PRODUCTION_IN` | `ProductionService::create()` → `postOut()` | Always ≥ 0 | `-ABS(l.subtotal)` |
| `ADJUSTMENT` | `StockAdjustmentService::post()` | Signed at post time (`qty_base_delta`, positive or negative) | `l.subtotal` as-is (unchanged from V2.3B) |
| `REVERSAL` | `VoidService::void()` | Signed opposite to whatever it reverses | `l.subtotal` as-is (unchanged from V2.3B) |
| Historical import (`inventory_effect=0`) | `ImportHistoricalTransactionService` | N/A — no batch, no allocation created | Excluded entirely via `inventory_effect = 1` filter |

**ONE canonical expression** (`InventoryHppReportService::SIGNED_VALUE_SQL`) implements
this table and is the only place the +/- direction logic lives; every report
surface (`summary`, `signedValueBefore`, `buildDailyRows`, `warehouseBreakdown`,
export) resolves through it or through `periodTotals()`'s single shared movement
query — never a second, independently-maintained copy.

## 4. Exact VOID business-KPI rule

Two different filters on the same ledger, applied deliberately:

- **Historical reconstruction** (Opening/Ending Value, and every bridge
  disclosure bucket except Purchase): `status IN ('POSTED','VOID')` — a voided
  transaction's original entry and its REVERSAL both count, on their own real
  dates, netting back to the true current state (Phase V2.3B).
- **"Pembelian Eksternal" (External Purchase) KPI only**: `status = 'POSTED'`
  AND `type = 'IN'` — a cancelled purchase must never inflate what the business
  reads as "purchases made this period."

The resulting gap — a voided IN's original entry no longer self-cancels against
Purchase the way it used to — is closed by a new, explicitly disclosed bridge
bucket, `voided_in_net`, exactly mirroring the `voided_out_net` bucket V2.3B
already added for the symmetric voided-OUT case. Never silently absorbed, never
left unexplained; `unexplained` stays mathematically guaranteed to be 0 for
every scenario this method accounts for, and is never forced to zero if a case
it doesn't yet account for appears.

## 5. Exact OPENING period-start boundary rule

`signedValueBefore($isOpeningBoundary = true)` — passed ONLY for a period's own
`opening_value` computation, never for `ending_value` (computed via the same
helper at `end_date + 1 day`, always with the flag left `false`:

```sql
-- isOpeningBoundary = true:
(t.transaction_date < :before OR (t.transaction_type = 'OPENING' AND t.transaction_date = :before))
-- isOpeningBoundary = false (default, used for Ending):
t.transaction_date < :before
```

Never a blanket `<` → `<=`: only `type='OPENING'` gets the boundary inclusion:
any other type's row dated at that exact same timestamp (a same-instant
IN/ADJUSTMENT/etc., however unlikely) correctly stays excluded from Opening,
exactly as before. Every movement-window query that could otherwise double-count
that same now-reclassified row (`periodTotals()`'s `opening_mid_period` bucket,
`buildDailyRows()`'s day-1 net, `exportNonHppSheet()`'s disclosure) explicitly
excludes it via `NOT (t.transaction_type = 'OPENING' AND t.transaction_date =
:start_boundary)`. Proven scoped correctly (not overly broad) by a negative
control: an OPENING dated one day *after* the boundary still correctly shows as
a mid-period movement, not Opening Value.

## 6. Test results

**New V2.3C fixture** (`tests/inventory_hpp_v23c_production_hotfix_test.php`):
**32/32 PASSED** — production numeric target (SCM/CIBADAK/company), OPENING
boundary (positive and negative control), clean-scenario control equation,
internal transfer mechanism, VOID IN, VOID OUT, historical rows, daily
roll-forward across the exact production date window, and a read-only guarantee.

**Full regression** (`tests/run_mysql_tests.sh`, 17 files + concurrency):

```
mysql_smoke_test.php                      4/4
mysql_integration_test.php               35/35
mysql_importer_test.php                  17/17
mysql_void_test.php                      15/15
mysql_security_test.php                  11/11
opening_g_data_2_test.php                20/20
migration_negative_stock_test.php        31/31
warehouse_isolation_regression_test.php  28/28
stock_policy_test.php                    34/34
master_data_v2_test.php                  27/27
stock_report_test.php                    26/26
transaction_history_test.php             32/32
master_data_v2_1_test.php                54/54
trace_test.php                          111/111
inventory_hpp_report_test.php            50/50
inventory_hpp_costing_audit_test.php     51/51
inventory_hpp_v23c_production_hotfix_test.php 32/32  (new)
concurrency_test.sh                       5/5
---------------------------------------------------
TOTAL                                   583/583 PASSED, 0 FAILED
```

## 7. Production-like expected values — before / after / expected

For the production-equivalent fixture (2 warehouses, positive opening filler +
the 5 real approved migration-negative balances, dated exactly at the report's
own `2026-09-16` start boundary, queried `2026-09-16..2026-09-20`):

| Metric | Before (production defect, reproduced) | After (this hotfix) | Expected |
|---|---:|---:|---:|
| Opening Value (company) | Rp 0 *(swept into opening_mid_period)* | Rp 2.638.047.167,9209 | Rp 2.638.047.167,9209 |
| External Purchase | Rp 400 *(includes a VOID'd smoke IN)* | Rp 0 *(scenario has no effective purchase)* | Rp 0 |
| FIFO HPP | Rp 0 | Rp 0 | Rp 0 |
| Ending Value (company) | Rp 2.649.472.690,4737 | Rp 2.638.047.167,9209 | Rp 2.638.047.167,9209 |
| HPP Reconciliation | (undefined given the Rp 0 opening) | Rp 2.638.047.167,9209 | matches Ending given no other movement |
| Variance | large, unexplained | Rp 0 | Rp 0 |
| Unexplained | — | Rp 0 | Rp 0 |
| SCM value | — | Rp 2.330.669.085,7833 | Rp 2.330.669.085,7833 |
| CIBADAK value | — | Rp 307.378.082,1376 | Rp 307.378.082,1376 |
| Company value | Rp 2.649.472.690,4737 | Rp 2.638.047.167,9209 | Rp 2.638.047.167,9209 |

The "Before" Ending Value figure (`2,649,472,690.4737`) is exactly this
fixture's positive-composition total with the 5 migration-negative balances'
sign flipped positive instead of negative — reproducing the production
symptom precisely (differs from the correct total by exactly 2× the combined
migration-negative magnitude, `11,425,522.5528`, matching the production
evidence's own reported difference).

## 8. Screenshots (browser verification)

Captured against a live `php -S` instance of this exact code, logged in as a
throwaway SUPERADMIN account, no production access:

- Consolidated view, range starting exactly on the opening date
  (`2026-09-16..2026-09-20`): Nilai Stok Awal correctly shows the full
  boundary-inclusive opening value on day 1 of the Rekap Harian (not Rp 0),
  Variance = Rp 0 with a green "Seimbang" indicator.
- Consolidated view, range starting after the opening date
  (`2026-09-18..2026-09-20`).
- SCM only: Nilai Stok Awal = Rp 2.338.169.085,78 (target composition + this
  fixture's own transfer-test opening); Variance shows a non-zero, disclosed
  figure (the transfer's one-sided leg at single-warehouse level) with an
  orange warning indicator and "Klik untuk lihat rincian" — never hidden.
- CIBADAK only: Nilai Stok Awal = **Rp 307.378.082,14** — matches the exact
  production-like target to the cent.
- Variance bridge drawer: live-rendered "Dijelaskan oleh" list showing
  `Reversal: Rp 160.000` and the new `IN Dibatalkan (Voided, tidak dihitung
  sebagai Pembelian Eksternal): Rp -160.000`, netting to `Unexplained: Rp 0` —
  the new bucket rendering correctly end-to-end with zero frontend code
  changes (the drawer iterates the API's `components` array generically).
- Excel export: triggered a real download, valid ZIP/XLSX container with all
  5 expected worksheet parts.
- Zero browser console errors across every screenshot.

## 9. Final commit

See branch `claude/funny-ramanujan-wmrlig`, commit following this document in
the same push. Not deployed to production, per the explicit instruction.
