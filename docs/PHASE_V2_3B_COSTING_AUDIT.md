# Phase V2.3B — Final V2.3 Costing Logic Sign-Off

Scope: **one focused audit of the accounting logic** behind "Laporan Nilai
Stok & HPP" (`services/InventoryHppReportService.php`, built in Phase
V2.3, commit `ecdc601`). No visual redesign. No production deployment —
this phase only changes code on the feature branch.

This document is the answer to the 9-point sign-off request, in order.
Every claim below is proven by a real, automated test against a real
MySQL database (`tests/inventory_hpp_costing_audit_test.php`), not by
reasoning alone — that discipline is what caught the two real defects
this audit found (§10).

---

## 1. The two HPP numbers, defined

| Term | Meaning | Formula |
|---|---|---|
| **FIFO HPP** (a.k.a. "Barang Keluar FIFO / HPP Aktual") | The **actual** cost of goods that left — every rupiah traces to a real FIFO batch allocation. | `SUM(fifo_allocations.subtotal)` for allocations whose consuming transaction is `type='OUT'`, `status='POSTED'`, in `[start, end]`. |
| **HPP Rekonsiliasi** (control value) | A **derived control figure**, not a re-measurement of the same thing. It is what the ending stock value *would have to be* if the only things that ever happened were "opening stock" and "external purchases." | `Opening Value + External Purchase − Ending Value` |
| **Variance** | `HPP Rekonsiliasi − FIFO HPP` | Never forced to zero. Explained by every other movement in the period (§5, §8). |

**HPP Rekonsiliasi is not automatically equal to FIFO HPP.** They agree
only when nothing except a plain purchase-then-sale happened in the
period (Case A, §6). The moment there is an adjustment, opname, reversal,
production run, transfer, or mid-period opening entry, Reconciliation and
FIFO HPP diverge by construction — that divergence **is** the Variance,
and it is always individually itemized, never hidden inside either
number.

---

## 2. Historical Opening Value — the method, proven

**Definition:** the value strictly **before** the given date's start —
i.e. `transaction_date < start_date 00:00:00`. Never "today's current
value."

```php
// services/InventoryHppReportService.php — signedValueBefore()
$where = ["t.status IN ('POSTED','VOID')", 't.inventory_effect = 1', 't.transaction_date < :before'];
...
SELECT COALESCE(SUM(<SIGNED_VALUE_SQL>), 0) AS v
FROM inventory_transaction_lines l
JOIN inventory_transactions t ON t.id = l.transaction_id
WHERE t.status IN ('POSTED','VOID') AND t.inventory_effect = 1
  AND t.transaction_date < :before   -- exclusive, strictly before
  [AND l.warehouse_id = :wh]
```

`SIGNED_VALUE_SQL` (verbatim from `InventoryService`, the system's single
source of truth for the sign convention):

```sql
CASE
  WHEN t.transaction_type IN ('IN','OPENING','TRANSFER_IN','PRODUCTION_OUT') THEN ABS(l.subtotal)
  WHEN t.transaction_type IN ('OUT','TRANSFER_OUT','PRODUCTION_IN') THEN -ABS(l.subtotal)
  ELSE l.subtotal   -- ADJUSTMENT, REVERSAL: already signed at post time
END
```

**Cutoff logic:** `Opening('2026-09-19')` sums every line dated before
`2026-09-19 00:00:00`, regardless of when the *query* is run — today, or a
year from now, produces the identical number, because the filter is on
`transaction_date`, not on "current" anything.

**Status logic — the part this audit corrected:** the filter is
`status IN ('POSTED','VOID')`, not `status = 'POSTED'` alone (§10.1
explains why).

**FIFO/batch logic:** Opening Value does **not** re-run FIFO. It sums the
already-posted, already-costed ledger lines exactly as they were recorded
at post time — the same convention `InventoryService::ledger()` (the
existing, already-shipped movement display) uses. No batch is
re-evaluated; no cost is re-derived.

**Opening batches:** an `OPENING`-type transaction is just another line in
the same sum (`+ABS(subtotal)`), whether it lands before the query's start
date (counted in "Opening Value") or inside the period (counted in the
`opening_mid_period` disclosure bucket instead — see §5).

**Historical imports:** `inventory_effect = 1` excludes every historical
import row unconditionally (proven empirically, Case H, §6).

**Reversals/transfers:** both flow through the same uniform sum — a
transfer's two legs (`TRANSFER_OUT` on the source, `TRANSFER_IN` on the
destination) and a reversal's line are ordinary rows with their own
`transaction_date`, nothing special-cased.

**Proof (multi-day test, `tests/inventory_hpp_costing_audit_test.php`,
"HISTORICAL OPENING" section):** a fixture posts an IN on `2026-05-02`
worth 30,000 on top of a 100,000 opening. Querying
`summary('2026-05-02','2026-05-02', wh)['opening_value']` returns exactly
**100,000** — the 05-02 IN itself is correctly *not yet* counted (it's the
same day, not before it). The test then confirms this historical figure
differs from the item's real **current** value (123,000, after several
more days of movement) — proving the cutoff is genuinely point-in-time,
not a disguised "now."

---

## 3. Historical Ending Value — the method, proven

**Definition:** the value at the **end** of the given date — i.e.
`transaction_date <= end_date 23:59:59`, implemented as
`transaction_date < (end_date + 1 day) 00:00:00`.

```php
$ending = self::signedValueBefore($pdo, date('Y-m-d', strtotime($endDate . ' +1 day')), $warehouseId, ...);
```

Same `signedValueBefore()` method, same status/inventory_effect filters,
same sign convention — Ending is not a separately-implemented code path;
it is Opening evaluated at `end_date + 1 day` instead of `start_date`.
This is deliberate: one formula, one place a bug could hide, not two.

**Proof — "Ending Day N == Opening Day N+1" (multi-day test):** a fixture
with movement on 5 consecutive days (`2026-05-01`..`2026-05-05`: an IN, an
OUT, a DAMAGE adjustment, another IN) is queried **one single day at a
time** (`start_date == end_date`) for every day in the range, with no
overnight corrections in between:

```
2026-05-01: opening=100000 ending=100000
2026-05-02: opening=100000 ending=130000
2026-05-03: opening=130000 ending=115000
2026-05-04: opening=115000 ending=112000
2026-05-05: opening=112000 ending=123000
```

Every day's `opening_value` exactly equals the **previous** day's
`ending_value` — asserted programmatically, not eyeballed. The test also
confirms the whole 5-day range's `ending_value` (queried in one call)
equals the last single day's `ending_value` (queried alone) — proving the
day-by-day and range formulas agree.

---

## 4. External Purchase classification matrix

"Included in External Purchase" only ever means `type = 'IN'` — nothing
else, by construction of the SQL (`SUM(...) WHERE t.transaction_type =
'IN'`). No other type can ever leak into it.

| Transaction Type | External Purchase? | FIFO HPP? | Non-HPP Movement? | Reason |
|---|:---:|:---:|:---:|---|
| `IN` | **YES** | No | No | The only type Purchase counts — a real external receipt. |
| `OUT` | No | **YES** (if `status='POSTED'`) | No (when POSTED); **YES, `voided_out_net`** (when `status='VOID'`) | The actual sale/consumption cost trail. |
| `TRANSFER_OUT` | No | No | **YES** (`transfer_out`) | Internal movement, never a purchase. |
| `TRANSFER_IN` | No | No | **YES** (`transfer_in`) | Internal movement, never a purchase. Company-level net with `TRANSFER_OUT` is 0 (§10.2 — this required a real code fix). |
| `ADJUSTMENT` | No | No (its own `fifo_allocations`, if any, are excluded since `transaction_type≠'OUT'`) | **YES** (`adjustment_net`, and `opname_net` if `stock_adjustments.adjustment_type='OPNAME'`) | A correction, never a sale. |
| `OPNAME` | *(not its own transaction_type — see below)* | No | **YES** (`opname_net`, a disclosed sub-slice of `adjustment_net`) | Ledger-wise an `ADJUSTMENT`; `stock_adjustments.adjustment_type='OPNAME'` is what distinguishes it. |
| `PRODUCTION_IN` | No | No | **YES** (`production_net`, negative leg — raw material consumed) | Internal conversion, not a sale. |
| `PRODUCTION_OUT` | No | No | **YES** (`production_net`, positive leg — finished good created) | Internal conversion, not a purchase. |
| `OPENING` | No | No | **YES** (`opening_mid_period`, only if dated inside the query period) | A balance-forward entry, not a purchase. |
| `REVERSAL` | No | No | **YES** (`reversal_net`) | The exact offsetting entry for a voided transaction — see §10.1. |
| `VOID` (status, not type) | *(the original transaction keeps its own type)* | Excluded from FIFO HPP if the voided transaction was `OUT`; still counted in Purchase if it was `IN` (naturally, since Purchase is type-only, not status-filtered beyond `IN('POSTED','VOID')`) | Its own original contribution is counted via the broadened status filter (§10.1); its `REVERSAL` counterpart cancels it. | See §5, §10.1 for the full sign mechanics. |
| Historical import rows (`is_historical_import=1`, `inventory_effect=0`) | No | No | No — completely invisible | `inventory_effect = 1` is required everywhere in this report; these rows have `inventory_effect=0` and (by `ImportHistoricalTransactionService`'s own design) never get an `inventory_batches` row or `fifo_allocations` either. Proven empirically, Case H. |

**Internal warehouse transfers are never External Purchase at
consolidated-company level** — proven in Case B (§6): after fixing the
`TransferService::receive()` bug (§10.2), a received transfer posts as
`TRANSFER_IN`, which the Purchase SQL's `type='IN'` filter structurally
cannot match.

---

## 5. Non-HPP movement sign logic

All of the following are **disclosed**, never silently absorbed into
either FIFO HPP or Reconciliation:

| Bucket | Sign convention | Effect on Ending | Effect on Reconciliation | Effect on FIFO HPP | Effect on Variance |
|---|---|---|---|---|---|
| **Adjustment+** (gain) | `l.subtotal` positive (as posted) | `+` | `+` (via Ending) | none | `-adjustment_net` (negative — a gain shrinks the unexplained gap the same amount it grew Ending) |
| **Adjustment−** (shrinkage) | `l.subtotal` negative | `-` | `-` (via Ending) | none | `-adjustment_net` (positive — Variance grows to explain the loss) |
| **Opname gain/loss** | Same as Adjustment (it *is* one, ledger-wise) — disclosed separately via `stock_adjustments.adjustment_type='OPNAME'`, never re-summed on top of `adjustment_net` | same as above | same as above | none | `-opname_net`, its own bridge line — never mislabeled as if it were sales HPP (Case E) |
| **Production (net)** | `PRODUCTION_OUT: +ABS`, `PRODUCTION_IN: -ABS` — combined into one `production_net` figure | `PRODUCTION_OUT` raises Ending, `PRODUCTION_IN` lowers it | via Ending | none — production is never a sale | `-production_net` |
| **Reversal** | `l.subtotal` as posted by `VoidService` (opposite sign of what it reverses) | offsets the voided original's contribution | via Ending | none (a `REVERSAL` transaction is never `type='OUT'`) | `-reversal_net` |
| **Transfer IN** | `+ABS(subtotal)` | `+` | via Ending only (never Purchase) | none | `-(transfer_in+transfer_out)`, net ≈ 0 at company level |
| **Transfer OUT** | `-ABS(subtotal)` | `-` | via Ending only | none | same bucket as above |
| **Voided OUT (original)** | `-ABS(subtotal)`, counted only for `status='VOID'` rows | `-` (its original economic effect is still real history) | not directly (OUT was never a Reconciliation variable) | **excluded** — `fifoHppTotal()` stays `status='POSTED'`-only by design | `-voided_out_net` — the bridge component this audit added (§10.1) |

---

## 6. The reconciliation equation, proven — 8 deterministic cases

All 8 run in `tests/inventory_hpp_costing_audit_test.php`, each in its own
isolated item/warehouse fixture (51 assertions total, all passing).

| Case | Scenario | Result |
|---|---|---|
| **A** | Opening 100k → IN 50@1200 (Purchase 60k) → OUT 30 (FIFO HPP 30k) | Ending 130k, Reconciliation 30k, **Variance = 0** ✅ |
| **B** | Opening 100k, then transfer 20 units to a second warehouse (create + receive, fully within-period) | Per-warehouse: transfer_out=-20k / transfer_in=+20k, each warehouse's own Variance (±20,000) fully explained by the bridge's Transfer component. **Consolidated (company-wide) External Purchase, FIFO HPP, and Variance all show zero delta** across the transfer — proven by before/after snapshots, not an absolute assumption. |
| **C** | Opening 100k → ADJUSTMENT −8 (DAMAGE) | FIFO HPP stays 0 (no OUT happened), adjustment_net=−8,000, **Variance = +8,000**, bridge fully explained. |
| **D** | Opening 100k → ADJUSTMENT +6 (CORRECTION, found stock) | FIFO HPP stays 0, adjustment_net=+6,000, **Variance = −6,000** (correctly negative — a gain reduces the reconciliation-vs-FIFO gap). |
| **E** | Opening 100k → ADJUSTMENT −4 (adjustment_type=OPNAME) | fifo_hpp=0 (never counted as a sale), `opname_net`=−4,000 in its **own** bridge line ("Opname (Gain/Loss)"), distinct from "Adjustment (Non-Opname)" which correctly shows 0 for this fixture — no double count, no mislabeling. |
| **F1** | IN 100@1000, then **voided** (reverseBatchCreation path) | Report `ending_value` matches `InventoryService::currentStock()` exactly (both 0). `reversal_net = -100,000`, bridge fully explained. |
| **F2** | Opening 100@1000, OUT 40, then **voided** (reverseBatchConsumption path) | Report `ending_value` matches real `currentStock()` exactly (both 100,000). `fifo_hpp = 0` for the period (those goods never actually left). Bridge fully explained via the new `voided_out_net` component. |
| **G** | Opening 100@1000 (raw material) → Production consumes 30 raw (PRODUCTION_IN −30,000), creates 30 finished good at derived cost (PRODUCTION_OUT +30,000) | `production_net ≈ 0`, Ending unchanged (100,000 — pure internal conversion, no value created or destroyed), FIFO HPP=0, **Variance = 0**. Raw stock correctly reduced to 70 units; finished good correctly created at 30,000. |
| **H** | A raw-SQL-mimicked historical import row (`is_historical_import=1, inventory_effect=0`) inserted mid-period | Opening, External Purchase, Ending, FIFO HPP, and Variance are **byte-identical** before/after the historical row exists. Real `currentStock()` also unaffected (no batch/allocation was ever created for it). |

---

## 7. UI labels

Already updated in Phase V2.3B's frontend pass (`public/assets/js/report-hpp.js`):

- **"Barang Keluar FIFO / HPP Aktual"** — subtitled "Biaya aktual dari FIFO allocation — angka HPP yang sesungguhnya."
- **"HPP Rekonsiliasi (Nilai Kontrol)"** — subtitled "Formula kontrol (Stok Awal + Pembelian − Stok Akhir) — BUKAN otomatis sama dengan HPP FIFO aktual."
- **"Variance FIFO vs Rekonsiliasi"** — a clickable KPI card (`.hpp-kpi-clickable`) that opens the bridge drawer described in §8.
- The formula strip's result chip and note text were both updated to the same "nilai kontrol, bukan otomatis sama" language, and explicitly state transfers are never counted as company purchases.

No visual redesign was done this phase (per the request) — only label/copy corrections plus the new clickable Variance interaction that was already built in the immediately-preceding pass.

---

## 8. Variance Trace (the bridge)

`GET /reports/inventory-hpp/variance-bridge` → `InventoryHppReportService::varianceBridge()`.
Clicking the Variance card opens a `Drawer` (the existing shared component,
not a new one) showing, in order:

1. **HPP Rekonsiliasi**
2. **FIFO HPP**
3. **Variance**
4. **"Dijelaskan oleh:"** — an itemized list of components (only shown
   when `|explains| >= 0.5`, to keep the panel readable):
   - Adjustment (Non-Opname)
   - Opname (Gain/Loss)
   - Reversal
   - Production (net)
   - Opening (mid-periode)
   - Transfer (net, harus ~0 di level perusahaan)
   - **OUT Dibatalkan (Voided, entri asli dipulihkan)** — new in this phase, §10.1
5. **"Unexplained"** — `variance − SUM(components' "explains")`, always
   computed and always shown, **never forced to zero**. If nonzero, the
   panel renders a red warning block: *"⚠️ Ada selisih yang belum
   terjelaskan. Variance TIDAK dipaksa menjadi nol — periksa pergerakan
   non-HPP lain (mis. jenis transaksi baru) sebelum melaporkan angka ini."*

The frontend renders `components` generically (iterates the array the API
returns — no hardcoded label list), so the new `voided_out_net` bucket
this audit added required **zero frontend code changes** to appear.

---

## 9. Test results

**New costing audit suite** — `tests/inventory_hpp_costing_audit_test.php`:
**51/51 PASSED** (Cases A–H + the multi-day Opening/Ending proof +
the historical-cutoff proof).

**Full regression** (`tests/run_mysql_tests.sh`, 17 files including the
new one, each against a freshly reset database):

```
mysql_smoke_test.php                    4/4
mysql_integration_test.php             35/35
mysql_importer_test.php                17/17
mysql_void_test.php                    15/15
mysql_security_test.php                11/11
opening_g_data_2_test.php              20/20
migration_negative_stock_test.php      31/31
warehouse_isolation_regression_test.php 28/28
stock_policy_test.php                  34/34
master_data_v2_test.php                27/27
stock_report_test.php                  26/26
transaction_history_test.php           32/32
master_data_v2_1_test.php              54/54
trace_test.php                        111/111
inventory_hpp_report_test.php          50/50
inventory_hpp_costing_audit_test.php   51/51  (new)
concurrency_test.sh                     5/5
-----------------------------------------------
TOTAL                                 551/551 PASSED, 0 FAILED
```

## 10. Defects found and fixed by this audit

Both were caught because this audit insisted on **empirical proof**
(a throwaway probe script against a real database, then a formal
regression test) rather than accepting the formulas' correctness by
inspection alone — exactly the discipline a "final sign-off" demands.

### 10.1 — VOID + REVERSAL left a phantom residue in Ending/Opening Value

**Before this phase:** `signedValueBefore()` and `periodTotals()` filtered
`status = 'POSTED'` only. `VoidService` never deletes or backdates
anything — it flips the original transaction's status to `VOID` and posts
a brand-new `REVERSAL` transaction (dated at the real moment of voiding,
never backdated) with the exact opposite signed line. Filtering out
`VOID`-status rows meant the **original's** real economic contribution was
zeroed out while the **reversal's** opposite-signed contribution still
counted on its own — a net residue instead of a clean cancellation.

Empirically reproduced: post an IN(100 @ 1000), void it, then compare the
report's `ending_value` against the real, trusted
`InventoryService::currentStock()`:

```
real currentStock value = 0
my report ending_value  = -100000
MISMATCH — BUG CONFIRMED
```

**Fix:** broadened the status filter to `status IN ('POSTED','VOID')` in
`signedValueBefore()`, `periodTotals()`'s movement query, `opnameNet()`,
`buildDailyRows()`'s movement query, and `exportNonHppSheet()`. The
OUT-side FIFO HPP total (`fifoHppTotal()`, `dayDetail()`,
`buildDailyRows()`'s FIFO query, `exportFifoDetailSheet()`) deliberately
**stays** `status = 'POSTED'`-only — a voided OUT means those goods never
actually left, so excluding it there is correct, not an oversight.

That asymmetry (IN is a Reconciliation variable and self-heals when
broadened; OUT is not, and a voided OUT's original entry had no home in
the bridge) is exactly what surfaced a second, smaller gap: a
`voided_out_net` bucket had to be added to `varianceBridge()` so a voided
OUT's original contribution is itemized rather than showing up as a
mystery `unexplained` residue. Both are proven fixed in Case F1/F2 (§6).

Re-verified after the fix: `real currentStock value = 0`, `my report
ending_value = 0`, **MATCH**.

**Scope note:** `InventoryService::ledger()` (the existing, already-shipped
movement display) shares the same original `status = 'POSTED'`-only
convention and was **not** touched by this fix — it was out of this
audit's explicit scope (the Inventory Value & HPP report), and changing an
already-shipped, already-trusted display was not requested. It is flagged
here for awareness, not fixed.

### 10.2 — `TransferService::receive()` posted every received transfer as a plain purchase (`type='IN'`)

**Before this phase:** `TransferService::receive()`'s call to
`FifoService::postIn()` never passed a `transaction_type` override, so it
silently defaulted to `FifoService`'s own default, `'IN'` — not
`TRANSFER_IN`. The schema's own comment
(`database/schema.sql:679`, `in_transaction_line_id` — "TRANSFER_IN line
... NULL until received") documents the intended design; the code simply
never implemented it. This was previously noticed and documented as a
"pre-existing characteristic" in `tests/trace_test.php` (Phase V2.2B) but
left unfixed, since it was out of scope for that phase's tracing work.

**Impact:** this directly violated §4's explicit requirement — every
completed transfer inflated `External Purchase` at the company level, and
was invisible to the `transfer_in` disclosure bucket. This is not a
report-layer bug; it is in `TransferService.php` itself, so it would have
also affected `InventoryService::ledger()`'s type display and
`StockReportService`'s `last_in` ("last purchase date") figure for any
warehouse that ever received a transfer.

**Fix:** added `'transaction_type' => 'TRANSFER_IN'` to the
`FifoService::postIn()` call in `TransferService::receive()` — a
one-line, minimal, surgical fix. Verified: Case B's consolidated
External Purchase delta across a transfer is now exactly 0 (it was
20,000 before the fix). Updated the stale comment in `trace_test.php`
that had documented the old behavior as accepted, and strengthened its
assertion to check for `TRANSFER_IN` explicitly.

**Not fixed as part of this audit (flagged for the owner's awareness):**
any transfer **already received in production** before this fix was
posted as `type='IN'`, not `TRANSFER_IN` — meaning production's own
historical `External Purchase`/`last_in`/HPP-report figures (once V2.3 is
deployed) would already be affected for any such transfer. This audit does
not touch production data or attempt a retroactive data fix; that
decision belongs to the owner once this phase is reviewed.

---

## Final commit

All changes in this phase are described in the git log on branch
`claude/funny-ramanujan-wmrlig`. **Not deployed to production** — per the
explicit "STOP afterward. DO NOT DEPLOY PRODUCTION" instruction.
