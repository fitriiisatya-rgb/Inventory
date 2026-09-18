# POLICY CORRECTION — Keep Known Migration-Negative Stock Visible

Owner decision, implemented on top of Phase G-DATA 2/3 and the YUPI
base-unit override. Supersedes the earlier default (final opening rejects
*every* negative quantity, no exceptions) for exactly 5 owner-named
SKU+warehouse rows — and only those 5.

## 1. The whitelist

SCM: `100304` = -0.5 KG, `777419` = -0.5 KG.
CIBADAK: `400201` = -466.5 KG, `555410` = -250 PCS, `800401` = -162 LTR.

These are the same 5 rows (a subset of the 8 seeded by Phase G-DATA 2's
`scripts/seed_movement_reconciliation_review.php`) already carrying
`historical_calculated_ending < 0` from the real September reconciliation.
No new table was introduced: `movement_reconciliation_reviews` gained
`is_migration_negative_approved`, `migration_negative_approved_by_name`,
`migration_negative_note` (schema.sql Section 5A), and
`scripts/approve_migration_negative_whitelist.php` flips exactly these 5
rows on — after first re-verifying each one's `historical_calculated_ending`
still matches the owner's own figures exactly (refuses to write on any
mismatch, same "safety check before write" pattern used in every prior
override round). Verified live against a fresh schema: all 5 approved,
values matched exactly.

`services/MigrationNegativeStockService.php` is the single place that reads
this whitelist — by joining `sku`/`warehouse_code` against the real
`items`/`warehouses` master, so it resolves correctly whether or not the
item master has been imported yet (no promotion step). "Status" is never
stored: a whitelisted item+warehouse reads `MIGRATION_NEGATIVE_REVIEW` /
`NEEDS_STOCK_OPNAME` only while its live balance is `<= 0`; once an audited
correction brings it back above zero, both flags clear themselves.

## 2. LIVE Opening stays negative — no zeroing, no invented adjustment

- `OpeningValidationService::validateRow()` still rejects every negative
  opening quantity as `ERROR` **except** a whitelisted row, which is
  downgraded to `WARNING` (`MIGRATION_NEGATIVE_REVIEW: ...`) and allowed
  through to commit.
- `FifoService::postIn()` gained `allow_migration_negative_opening` — the
  only way a negative `input_qty` is ever accepted, and only set by
  `ImportOpeningStockService::commit()`, which re-checks the live whitelist
  itself before setting it (defense-in-depth: staging validation already
  refuses a negative row unless whitelisted, but commit() never trusts a
  staged status blindly). The resulting batch is created with
  `is_negative_layer = 1`, at the actual calculated balance — never
  adjusted to zero, never given an invented cost/quantity.

## 3. FIFO safety

`Database::lockFifoBatches()` already excludes non-positive batches from
consumption (pre-existing behavior — a negative layer was never eligible to
be drawn from). `FifoService::postOut()` (the single method behind OUT,
TRANSFER_OUT, and PRODUCTION_IN raw-material consumption — verified by
reading `TransferService`/`ProductionService`, both call it directly) now
adds one more guard: while a whitelisted item+warehouse's available
quantity is `<= 0`, it throws the new
`NegativeMigrationStockRequiresAdjustmentException`
(`NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT`, 422) **before** the normal
insufficient-stock/negative-override logic runs — so `allow_negative_stock`
can no longer be used to push a whitelisted item further negative or spawn
another negative FIFO batch for it. `IN` (`FifoService::postIn`),
`StockOpnameService`, and `StockAdjustmentService` are all untouched by this
guard and remain fully usable.

## 4. Resolving it — audited only, never a direct DB edit

`stock_adjustments` gained `migration_issue_reference` (nullable, optional)
so a correction can record which migration case it resolves — e.g.
`MigrationNegativeStockService::issueReference('100304','SCM')` →
`MIGRATION-100304-SCM`. Every other required field
(`before_qty_base`, `after_qty_base` = physical count, `qty_base_delta`,
`reason`, `created_by` = PIC, `created_at` = timestamp) already existed on
`StockAdjustmentService::post()` from Phase C2 — nothing here bypasses it;
there is still no code path that writes `inventory_batches.qty_base`
directly outside `FifoService`/`StockAdjustmentService`.

## 5. GO_LIVE_READY

`OpeningReconciliationService::report()` now splits negative opening rows
into two buckets (`splitNegativeRows()`): `checks.negative_qty` counts only
**unknown/unapproved** negative rows (still blocks `go_live_ready`), while
every whitelisted row is reported separately via the new
`migration_negative_count` + `migration_negative_rows` (sku, item_name,
warehouse_code, qty_base, status, needs_stock_opname) —
**ALLOW_WITH_WARNING**, never blocking. SCM/Cibadak can therefore reach
`GO_LIVE_READY = true` when the only negative rows present are exactly
these 5 approved exceptions. Verified live (`tests/migration_negative_stock_test.php`,
section G): a staged+committed opening batch whose only negative row is
whitelisted reports `negative_qty=0`, `migration_negative_count=1`, and
`go_live_ready=true`.

## 6. Company totals

`InventoryService::companyTotalValue()` was already summing every batch
(negative ones included) honestly — nothing was ever excluded or zeroed.
It now additionally reports `contains_unresolved_migration_negative_stock`
(bool) so a report can label the total rather than hide the condition.

## 7. Visibility — dashboard / stock list / SKU detail / reconciliation / admin review

`InventoryService::currentStock()` and `currentStockAllWarehouses()` — the
project's single source of truth for every stock figure the API reads —
now return `migration_negative_review`, `needs_stock_opname`, and
`migration_issue_reference` inline on every response, so any existing
screen reading stock through them (dashboard, stock list, SKU detail) gets
the flags for free with no separate lookup. A new read-only
`GET /migration-negative-review` (`MigrationNegativeStockService::reviewList()`)
is the admin review list: all 5 whitelisted rows with live current
balance/value, computed `status`, and the `historical_*` Opening+IN/OUT
evidence to drill back into. `GET /import/opening-stock/{id}/reconciliation`
carries the same `migration_negative_count`/`migration_negative_rows` for
the reconciliation report screen.

## 8. Historical trace

`movement_reconciliation_reviews.historical_opening/in/out/calculated_ending`
(Phase G-DATA 2, never posted as real transactions — evidence only, so
there is no `inventory_transactions` row and no `inventory_effect` to set
for it) remains untouched and readable via both
`GET /movement-reconciliation-reviews` and the new
`GET /migration-negative-review`, keyed by the same `sku`+`warehouse_code`
as the live negative opening — the `migration_issue_reference` string
(`MIGRATION-<sku>-<warehouse_code>`) is the drill-down key connecting a
live negative balance back to this historical evidence.

## 9. Test result

`tests/migration_negative_stock_test.php` (new, 29 assertions) plus the
full existing suite re-run clean:

| Suite | Result |
|---|---|
| `tests/run.php` (offline SQLite, Section 27 scenarios) | 13/13 PASS |
| `tests/mysql_smoke_test.php` + integration scenarios | 35/35 PASS |
| `tests/mysql_importer_test.php` | 12/12 PASS |
| `tests/mysql_void_test.php` | 15/15 PASS |
| `tests/mysql_security_test.php` | 11/11 PASS |
| `tests/opening_g_data_2_test.php` | 15/15 PASS |
| `tests/migration_negative_stock_test.php` (new) | 29/29 PASS |
| `tests/concurrency_test.sh` (5 runs) | 5/5 PASS |

**135/135 total.** `scripts/assert_database_clean.php` re-run against a
freshly-rebuilt schema (after running the two setup scripts below) still
reports `DATABASE CLEAN = PASS`.

## 10. Setup scripts (idempotent, safe to re-run)

1. `php scripts/seed_movement_reconciliation_review.php` — seeds the 8
   historical rows (unchanged from Phase G-DATA 2).
2. `php scripts/approve_migration_negative_whitelist.php` — flips exactly
   the 5 owner-named rows to `is_migration_negative_approved = 1`, after
   re-verifying their `historical_calculated_ending` against the owner's
   own figures. Verified live: all 5 approved, values matched exactly:
   `100304/SCM=-0.5, 777419/SCM=-0.5, 400201/CIBADAK=-466.5,
   555410/CIBADAK=-250, 800401/CIBADAK=-162`.

Neither script touches `stock_opening_lines`, `inventory_batches`, or any
other production-facing table — they only flag rows in the staging/review
table.

## 11. What did NOT change

- No SKU is zeroed, no provisional adjustment is invented — every one of
  the 5 rows carries its actual calculated balance forward exactly.
- No historical movement evidence was modified.
- No production opening import happened. Karang Tengah remains outside
  this fast-track (`PENDING_CUTOVER` — no IN/OUT file yet); nothing here
  changes that.
- Every other item/warehouse still gets `ERROR` on a negative opening
  quantity, exactly as before (`tests/opening_g_data_2_test.php` Test D,
  and `migration_negative_stock_test.php` Test B, both still pass).

---

## STOP

Per the instruction: SCM + Cibadak can now reach `GO_LIVE_READY` under this
policy (mechanism built and verified above), and Karang Tengah stays
`PENDING_CUTOVER`. **Stopping here before any production posting** — no
real opening import has been run, and none will be without explicit owner
approval on top of `GO_LIVE_READY` being true. `scripts/assert_database_clean.php`
confirms the actual project database remains untouched.
