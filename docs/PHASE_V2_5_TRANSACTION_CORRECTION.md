# Phase V2.5 — Transaction Correction / Void / Transfer Reversal

Base: branch `claude/funny-ramanujan-wmrlig`, on top of `56f3ee7` (V2.3D
cutover-aware HPP) plus everything that already existed there — Trace
Center/TraceDrawer, FIFO protections, warehouse isolation, and the current
production transaction-history UI. No production access, no deployment, no
production data mutation, no destructive migration.

## 1. Implementation summary

A safe, auditable correction system for SUPERADMIN (and ADMIN, following the
codebase's existing two-tier privileged-role pattern — see §6). Wrong
transactions are never edited or hard-deleted: a **Void** flips a POSTED
IN/OUT/ADJUSTMENT to VOID and creates a real REVERSAL transaction; a
**Reverse Transfer** flips a RECEIVED transfer's whole TRANSFER_OUT/
TRANSFER_IN chain to REVERSED and restores the source FIFO layers. Both are
blocked outright — never partially/unsafely applied — when the stock they'd
touch has already been consumed downstream, with the blocking transaction
IDs surfaced to the caller.

Most of the underlying machinery already existed from Phase D0.1
(`VoidService`) and Phase C2 (`TransferService::cancel()`): this phase
**closes the two gaps** that made the prior system unsafe for open-ended
correction — no dependency check before reversing an IN's batch, and no
correction path at all for a RECEIVED (post-receipt) transfer — and adds the
UI to reach both.

## 2. Business rules implemented

| Rule | Where |
|---|---|
| OPENING is never voidable through this flow, any role | `VoidService::void()` — `OPENING_PROTECTED` |
| A historical-import row (`inventory_effect=0`) is never voidable — it never touched live stock | `VoidService::void()` |
| Voiding an IN (or positive ADJUSTMENT) whose batch has already been partially/fully consumed is **blocked**, never a naive negative subtraction | `VoidService::reverseBatchCreation()` — dependency check against `original_qty_base` |
| Voiding an OUT (or negative ADJUSTMENT) always restores the **exact** original FIFO-consumed batches/costs — never latest/average price | `VoidService::reverseBatchConsumption()` (pre-existing, unchanged) |
| Adjustment reversal (positive or negative) reuses the same generic void flow — no special case | `VoidService::void()` (already supported `ADJUSTMENT`) |
| A finalized/POSTED Stock Opname is never voidable directly — correction is only through the variance's own generated ADJUSTMENT transaction | `StockOpnameService` exposes no void/cancel/delete method; the ADJUSTMENT it posts is voidable like any other |
| PENDING transfer → **Batalkan Transfer** (unchanged, pre-existing `TransferService::cancel()`) | `TransferService::cancel()` |
| RECEIVED transfer → **Reverse Transfer**, reversing the whole chain atomically | `TransferService::reverse()` (new) |
| A RECEIVED transfer's reversal is **blocked** if its destination stock was already consumed downstream (OUT, production, another transfer, adjustment, opname) | `TransferService::reverse()` dependency check |
| CANCELLED / REVERSED transfers are read-only — no second action | Existing status-gated actions; `reverse()`/`cancel()` both check current status first |
| Double void / double cancel / double reverse (new `request_uuid`) is rejected; the *same* `request_uuid` replays idempotently | `TransactionAlreadyVoidException`, `TransferAlreadyCancelledException` (existing), `TransferAlreadyReversedException` (new) |
| A void/reversal reason is mandatory, minimum 5 characters | `VoidService::void()`, `TransferService::cancel()`, `TransferService::reverse()` |
| STOCK can never void or reverse a transfer; may still cancel a PENDING transfer they created (existing `WAREHOUSE_TRANSFER_MANAGE` scope, unchanged) | Permission grants — see §6 |
| `warehouse_id` is never trusted from the frontend | Every route re-derives scope server-side; the void/reverse routes need no per-warehouse check at all since STOCK never holds the permission in the first place |
| No hard delete anywhere — `DELETE FROM inventory_transactions` is never issued by this system | Confirmed by code review of every new/changed method |
| Every correction runs inside one DB transaction — all-or-nothing | `Database::transaction()` wraps every void/reverse call (pre-existing pattern, reused) |

## 3. Files changed

| File | Change |
|---|---|
| `database/schema.sql` | `warehouse_transfers.status` enum gains `REVERSED`; new `reverse_reason`/`reversed_by`/`reversed_at`/`reverse_request_uuid` columns + FK; new `TRANSFER_REVERSE` permission granted to SUPERADMIN/ADMIN. |
| `database/migrations/2026_09_20_v2_5_transaction_correction.sql` (+`_rollback.sql`) | Staged, additive migration mirroring the schema.sql change, for later owner-approved production application — **not run against production**. |
| `services/Exceptions.php` | `OpeningProtectedException`, `TransactionAlreadyVoidException`, `VoidHasDownstreamDependenciesException`, `TransferAlreadyReversedException`, `TransferReversalHasDownstreamDependenciesException`. |
| `services/VoidService.php` | OPENING guard, historical-import guard, structured already-void error, reason min-length, **new downstream-dependency check** in `reverseBatchCreation()` (the core safety fix). |
| `services/TransferService.php` | New `reverse()` method (13-step chain per spec); reason min-length added to `cancel()` too. |
| `services/TraceService.php` | `transferTrace()` now also joins `reversed_by_username`. |
| `public/index.php` | `inv_error()` gains an optional additive `$details` param (for the `dependencies` list — frozen `{code,message}` shape unchanged for every caller that doesn't pass it); new exception→error-code mappings; new `POST /transfers/{id}/reverse` route. |
| `public/assets/js/modal.js` | New `Modal.form()` — info rows + warning + reason textarea with live min-length validation + danger styling, used by both new confirmation modals. |
| `public/assets/js/api-client.js` | `reverseTransfer()` wrapper; `ApiError` now carries a `dependencies` array when the server sends one. |
| `public/assets/js/transaction-history.js` | "Void Transaksi" button + confirmation modal wired into the existing transaction detail drawer. |
| `public/assets/js/transfers.js` | "Reverse Transfer" button + confirmation modal on RECEIVED rows. |
| `public/assets/js/ui.js` | `REVERSED` added to `badgeClass()`'s status map. |
| `public/assets/css/app.css` | `.badge-reversed`; **`.modal`'s z-index raised above `.drawer`/`.drawer-backdrop`** (real pre-existing stacking bug this phase's UI was the first to expose — a confirmation modal opened from within an already-open drawer used to render behind it). |
| `public/index.html` | Cache-busting version bump for the changed CSS/JS. |
| `tests/mysql_void_test.php` | One `catch` clause updated for the new, more specific exception type (same rejection, not a behavior change). |
| `tests/inventory_v25_transaction_correction_test.php` | New — 81 assertions, the 30 required cases. |
| `tests/run_mysql_tests.sh` | New test file added to the suite. |
| `docs/PHASE_V2_5_TRANSACTION_CORRECTION.md` | This document. |

## 4. Migrations

One additive migration (`database/migrations/2026_09_20_v2_5_transaction_correction.sql`,
with a guarded rollback). It widens an ENUM and adds four nullable columns
plus one FK to `warehouse_transfers`, and inserts one new permission row —
no existing column is retyped incompatibly, renamed, or dropped, and no
existing row's data changes. `inventory_transactions.status` already had
`REVERSED` from the original schema (used by `TransferService::cancel()`'s
source leg), so nothing to add there. **Not run against production** — staged
for later owner-approved execution, same convention as the V2/V2.1
migrations before it.

## 5. API routes

- `POST /transactions/{id}/void` — unchanged route, existing permission
  (`TRANSACTION_VOID`), now with the dependency-safety check and the new
  structured error codes.
- `POST /transfers/{id}/reverse` — **new**. Gated on the new `TRANSFER_REVERSE`
  permission. Payload: `{ request_uuid?, reason }`.

## 6. Permission rules

- `TRANSACTION_VOID` — pre-existing, granted to SUPERADMIN + ADMIN, never
  STOCK. Unchanged.
- `TRANSFER_REVERSE` — **new**, granted to SUPERADMIN + ADMIN only (mirrors
  `TRANSACTION_VOID`'s exact grant pattern), never STOCK, never DIVISION,
  never VIEWER.
- STOCK retains `WAREHOUSE_TRANSFER_MANAGE`, so a STOCK operator can still
  cancel a PENDING transfer they created (unchanged, pre-existing) — but
  cannot reach `/transfers/{id}/reverse` at all (403 FORBIDDEN, proven over
  real HTTP in the test suite).
- Every check is enforced **server-side**; `warehouse_id` is never trusted
  from the request body for scope decisions.

## 7. FIFO reversal strategy

- **OUT reversal** (existing, unchanged): restores exactly the
  `fifo_allocations` rows the original OUT consumed, at their **original**
  cost — unconditionally safe, since a batch is never deleted and adding
  stock back to it can never corrupt anything.
- **IN reversal** (the new safety fix): before reducing the batch the IN
  created, compares its current `qty_base` to its `original_qty_base`. If
  anything (any later OUT/TRANSFER_OUT/PRODUCTION_IN/negative ADJUSTMENT)
  has already taken from it, the void is **blocked** with
  `VOID_HAS_DOWNSTREAM_DEPENDENCIES` and the list of blocking transaction
  IDs — never a partial/unsafe reversal. Voiding the downstream dependents
  first (in their own right) restores the batch to its original quantity,
  at which point the IN's own void becomes safe. This ordering constraint is
  enforced by the check itself, not by convention.

## 8. Transfer reversal dependency strategy

`TransferService::reverse()` runs the same check per destination batch the
transfer created (one batch per source FIFO layer — a multi-layer transfer
consumed creates one `warehouse_transfer_lines` row, and one destination
batch, per layer, exactly preserving each layer's original cost rather than
re-averaging). If **any** destination batch's `qty_base` no longer equals
its `original_qty_base`, the whole reversal is blocked with
`TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES` and the list of consuming
transaction IDs — nothing is partially reversed. When safe, the chain runs
atomically: destination batches zeroed, TRANSFER_IN/TRANSFER_OUT legs
flipped to REVERSED, source FIFO layers restored exactly (matched by each
line's own qty+cost, the same disambiguation the pre-existing `cancel()`
already used for the identical shared-`out_transaction_line_id` shape — a
real double-restore bug was caught and fixed here during testing, see §14),
and the transfer itself flipped to REVERSED.

## 9. HPP impact

No change to `InventoryHppReportService`'s formulas. A VOID+REVERSAL pair
(or a REVERSED transfer's two legs) already economically nets to zero under
the existing V2.3B/C sign convention and `status IN ('POSTED','VOID')`
reconstruction filter — proven in the new test suite's Case 26 (variance
exactly 0 across a same-report-window VOID+REVERSAL pair). One property to
remember when reading a narrow report window: `VoidService` always dates the
REVERSAL at real wall-clock "now" (documented, deliberate — never
backdated), so a VOID'd OUT and its REVERSAL can land in *different*
reporting windows if the window doesn't span both dates; that's a property
of the reporting window, not a defect in the correction or in HPP
reconciliation.

## 10. Audit / trace behavior

- Every correction logs through the existing `AuditService` — no parallel
  audit framework. `TRANSACTION_VOID` and `TRANSFER_REVERSE` audit rows
  carry `action_type`, `original_entity_type`, `original_transaction_id`/
  `transfer_id`, `reversal_transaction_id`/`transfer_out_transaction_ids`+
  `transfer_in_transaction_ids`, `warehouse_id`, `before_status`,
  `after_status`, and the reason — all inside the existing `before_data`/
  `after_data`/`reason` columns.
- Trace is **entirely reused** — `TraceService::transactionTrace()` and
  `transferTrace()` (unchanged in shape, `transferTrace()` gained one extra
  username join) already surface `reversal_of`/`reversed_by` and per-leg
  transaction status. `TraceDrawer.openTransaction()` is the single entry
  point for every row, exactly matching the established
  `transaction-history.js` convention — no second trace implementation was
  built.

## 11. Test totals

**New suite** (`tests/inventory_v25_transaction_correction_test.php`):
**81/81 PASSED** — all 30 spec'd cases, several with multiple assertions:
void IN/OUT (simple, value, FIFO-batch, cost-preservation), the
dependency-block-then-succeed round trip, double-void, OPENING block,
historical-import block, positive/negative adjustment reversal, opname
structural protection, PENDING cancel, cancelled-cannot-cancel-again,
RECEIVED reverse (chain/source-restore/destination-zero), dependency-block
on a received reverse, reversed-cannot-reverse-again, audit rows, reason
validation, TraceService chain, stock/value/HPP reconciliation, warehouse
isolation, SUPERADMIN HTTP access, atomic rollback, and idempotency
(service-level and HTTP-level).

## 12. Full regression totals

```
mysql_smoke_test.php                             4/4
mysql_integration_test.php                      35/35
mysql_importer_test.php                         17/17
mysql_void_test.php                             15/15
mysql_security_test.php                         11/11
opening_g_data_2_test.php                       20/20
migration_negative_stock_test.php               31/31
warehouse_isolation_regression_test.php         28/28
stock_policy_test.php                           34/34
master_data_v2_test.php                         27/27
stock_report_test.php                           26/26
transaction_history_test.php                    32/32
master_data_v2_1_test.php                       54/54
trace_test.php                                 111/111
inventory_hpp_report_test.php                   50/50
inventory_hpp_costing_audit_test.php            51/51
inventory_hpp_v23c_production_hotfix_test.php   32/32
inventory_hpp_v23d_cutover_test.php             48/48
inventory_v25_transaction_correction_test.php   81/81  (new)
concurrency_test.sh                              5/5
-------------------------------------------------------
TOTAL                                         712/712 PASSED, 0 FAILED
```

## 13. Browser screenshots

Captured against a live `php -S` instance seeded with fresh SUPERADMIN
fixtures (a plain POSTED IN, a plain POSTED OUT, an OPENING, an
already-VOID'd transaction, a PENDING transfer, a RECEIVED transfer), logged
in as a throwaway SUPERADMIN account, zero console errors:

- **History Transaksi, POSTED IN and POSTED OUT** — "Void Transaksi" button
  present on both.
- **OPENING detail** — no "Void Transaksi" button.
- **Already-VOID/REVERSAL row detail** — no void button (a REVERSAL is not
  itself voidable — the practical "no second void" for this list, since
  `TransactionHistoryService`'s pre-existing list view only shows
  `status='POSTED'` rows, so the original VOID'd row itself isn't
  independently reachable there, only its REVERSAL is).
- **Void confirmation modal** — Transaksi/Jenis/Tanggal/Gudang/Reference/
  Dibuat Oleh/Nilai info block, warning line, required reason textarea,
  Batal/Konfirmasi Void buttons — matches the spec's exact layout.
- **After void** — drawer refreshes in place showing status=VOID, void
  button gone, audit metadata table shows both the original POST and the
  VOID entries.
- **Trace Center chain** — Overview tab shows Status=VOID, Alasan, Waktu;
  the transaction list shows the REVERSAL row (VOID-2) directly underneath.
- **Transfers list** — PENDING row shows "Batalkan"; RECEIVED row shows
  "Reverse Transfer".
- **Reverse Transfer modal** — Transfer/Gudang Asal/Gudang Tujuan/Tanggal/
  Status/Total Item/Total Nilai, warning line, required reason textarea,
  Batal/Reverse Transfer buttons.
- **After reverse** — success toast, transfer row shows the new purple
  REVERSED badge, PENDING row unaffected.

Sent to the user directly: the void confirmation modal, the reverse
transfer modal, the post-reverse transfers list, and the trace chain.

## 14. Known limitations

- **IN/transfer-reversal dependency blocking is all-or-nothing.** Per the
  spec's own sanctioned alternative ("either perform a mathematically
  correct dependency-aware reversal, or BLOCK"), this phase always blocks
  rather than attempting partial netting — the caller must void/reverse the
  downstream dependents first. A future phase could add true partial-netting
  math if the business ever needs it; it was deliberately not attempted here
  given the FIFO-corruption risk of getting it wrong.
- **OPENING correction has no automated path.** Per the spec, this is
  intentional — "it must use a separate controlled cutover/opening
  correction procedure," not built by this phase.
- **PRODUCTION_IN/PRODUCTION_OUT and OPNAME-type transactions are still not
  directly voidable** through this generic flow (pre-existing D0.1 scope,
  unchanged) — a production run's own dedicated lifecycle, and an opname
  session's generated ADJUSTMENT (which *is* voidable), are the sanctioned
  correction paths for each.
- **Two real bugs were found and fixed during this phase's own testing**,
  both now covered by regression: (a) `TransferService::reverse()`'s first
  draft double-restored source FIFO on any multi-layer transfer line (fixed
  by matching `cancel()`'s existing per-line qty+cost disambiguation); (b)
  the confirmation modal rendered behind an already-open drawer (a
  pre-existing z-index gap this phase's UI was the first to expose, fixed by
  raising `.modal`'s z-index above `.drawer`).

## 15. Final commit

`341cd512e6d23c12b17106af56fae2194c81eea4` on branch
`claude/funny-ramanujan-wmrlig`.

## 16. Push status

Pushed to `origin/claude/funny-ramanujan-wmrlig`. Not deployed to
production, per the explicit instruction.
