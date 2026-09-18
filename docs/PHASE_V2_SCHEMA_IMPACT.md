# Phase V2 — Schema Impact Documentation

Companion to `database/migrations/2026_09_18_v2_schema.sql` (Phase 3b).
Covers what changed, why each change is backward-compatible, and the full
investigation behind the one schema-level behavioral addition (a CHECK
constraint) the owner asked to be evaluated rather than assumed.

**This migration has been run only against a local throwaway test
database in this sandbox (`inventory_test`) — never against production.**
The same DDL has also been folded directly into `database/schema.sql`
(the project's single source-of-truth file, per its own header comment),
matching how every prior schema change in this project was handled — see
`git log -- database/schema.sql`. The standalone migration/rollback/
precheck/postcheck files remain the artifact for applying this to an
already-live production database later, under owner approval.

## 1. What changed (all additive — see Section 2 for the proof)

| Change | Type |
|---|---|
| New table `categories` | additive |
| New table `item_warehouse_stock_policy` | additive |
| New table `bakery_destinations` | additive |
| `items.category_id` (nullable, FK to `categories`) | additive column |
| `items` gains `idx_items_category`, `idx_items_name` indexes | additive index |
| `suppliers.address`, `suppliers.email` (both nullable) | additive columns |
| `inventory_transactions.bakery_destination_id` (nullable, FK to `bakery_destinations`) | additive column |
| `inventory_transactions` gains `chk_tx_bakery_destination_out_only` CHECK | additive constraint |
| `inventory_transactions` gains `idx_tx_bakery_destination` index | additive index |

Nothing existing is dropped, renamed, narrowed, or repurposed:
`items.category` and `items.minimum_stock` keep their exact original
meaning and are read as fallbacks (Section 4), not replaced.

## 2. Backward-compatibility proof

- **Local regression run**: the full existing test suite (`mysql_smoke`,
  `mysql_integration`, `mysql_importer`, `mysql_void`, `mysql_security`,
  `opening_g_data_2`, `migration_negative_stock`, `concurrency`) plus the
  new `warehouse_isolation_regression_test.php` — **166/166 assertions
  pass** against the schema with all V2 additions applied, identical to
  the pre-V2 baseline run immediately before this migration was written.
  No existing behavior changed.
- **Row-count proof**: `scripts/v2_schema_precheck.php` snapshots row
  counts for every pre-existing table touched by this migration
  (`items`, `suppliers`, `inventory_transactions`, `inventory_batches`,
  `warehouses`, `users`) before running it; `scripts/v2_schema_postcheck.php`
  re-counts the same tables afterward and fails loudly if any count
  differs. Verified identical in this session's local run.
- **Rollback proof**: `database/migrations/2026_09_18_v2_schema_rollback.sql`
  was run against the migrated local test DB and confirmed (via
  `information_schema` queries) to remove every added table/column with
  zero trace left behind — the migration is reversible by construction,
  not just by intent.

## 3. The `bakery_destination_id` CHECK constraint — investigation and decision

The owner's Phase 2 approval explicitly asked this to be **evaluated, not
assumed**: "First inspect the actual production-compatible transaction_type
values and existing data. If the constraint is safe and backward-compatible,
include it in the proposed migration. If not, keep service validation +
automated tests and document the reason."

**Investigation performed:**

1. **`transaction_type` values** (from `database/schema.sql`'s
   `inventory_transactions` definition): `IN`, `OUT`, `TRANSFER_OUT`,
   `TRANSFER_IN`, `ADJUSTMENT`, `OPNAME`, `PRODUCTION_IN`, `PRODUCTION_OUT`,
   `OPENING`, `REVERSAL`. Of these, `TRANSFER_OUT` and `PRODUCTION_IN` also
   flow through `FifoService::postOut()` (the same function `OUT` uses),
   but they represent a warehouse transfer and a production-input
   consumption respectively — not a distribution to an external bakery.
   "A real OUT transaction" (the owner's phrase) is `transaction_type =
   'OUT'` specifically.
2. **Existing data**: `bakery_destination_id` is a brand-new nullable
   column. Every row that existed before this migration — real production
   data included — has it `NULL` by definition (the column didn't exist
   to have any other value). A constraint of the shape
   `bakery_destination_id IS NULL OR transaction_type = 'OUT'` is
   therefore satisfied by **100% of pre-existing rows, unconditionally**,
   regardless of what real production `transaction_type` values exist —
   there is no scenario where adding this column+constraint together can
   reject anything that already exists.
3. **Mutation pattern**: `inventory_transactions` rows are never UPDATEd
   after posting except for `status`/`void_reason`/`voided_by`/`voided_at`
   (`services/VoidService.php`, the only code path that `UPDATE`s this
   table post-insert). `transaction_type` and (once added)
   `bakery_destination_id` are set once at INSERT and never change — so
   there is no later-mutation path that could put a row into a state the
   CHECK would need to re-validate against.
4. **The REVERSAL case specifically checked**: `VoidService`'s reversal
   insert (`services/VoidService.php` lines ~77–80) explicitly lists its
   own column set — `transaction_uuid, transaction_type, transaction_date,
   posting_date, warehouse_id, reference_no, status, reversal_of_id,
   is_historical_import, inventory_effect, created_by, created_at` — and
   does **not** include `division_id`, `supplier_id`, or (now)
   `bakery_destination_id`. A REVERSAL row for a voided bakery-distribution
   OUT therefore always inserts with `bakery_destination_id = NULL`,
   trivially satisfying the constraint regardless of what the original
   OUT's `bakery_destination_id` was.
5. **Engine support**: this project targets MySQL 8.0+ / MariaDB 10.4+
   (`database/schema.sql` header). MariaDB enforces CHECK constraints
   from 10.2.1 onward; MySQL enforces them from 8.0.16 onward — both
   comfortably below this project's stated minimum. `v2_schema_precheck.php`
   additionally verifies the connected server's version at migration time
   and refuses to proceed if it's below the enforcement threshold, so this
   is checked mechanically, not just documented.
6. **Enforcement verified empirically**, not just reasoned about:
   `v2_schema_postcheck.php` attempts, inside a transaction that is always
   rolled back, to insert a `TRANSFER_OUT` row with a non-NULL
   `bakery_destination_id` and asserts the insert is rejected. Run against
   the local test DB in this session: **the insert was correctly rejected
   with MySQL/MariaDB error 4025** (`CONSTRAINT chk_tx_bakery_destination_out_only
   failed`), confirming the constraint is not just present but actively
   enforced by the connected server.

**Decision: INCLUDED.** The constraint is safe, proven backward-compatible
against both existing data (trivially, since the column is new) and the
existing codebase's write patterns (checked the one path — `VoidService`'s
reversal insert — that could plausibly have copied it), and its
enforcement was verified empirically rather than assumed from documentation.

This is defense-in-depth on top of, not instead of, service-layer
validation: `FifoService::postOut()` only ever binds
`bakery_destination_id` when handling `transaction_type='OUT'`
(Phase 3e), so the constraint should never actually fire in normal
operation — its job is to make a *future* bug (e.g. someone wiring the
field into `TransferService`'s call to `postOut()`) fail loudly at the
database level immediately, rather than silently mislabel a warehouse
transfer as a bakery distribution.

If a future requirement ever needs `bakery_destination_id` on
`TRANSFER_OUT`/`PRODUCTION_IN` too, this constraint must be dropped or
widened as its own deliberate, reviewed change — never worked around
silently by a caller.

## 4. Fallback semantics (for the columns that introduce "resolve from
elsewhere when absent" behavior)

- `items.category_id IS NULL` → item is "Tanpa Kategori" (uncategorized).
  Never inferred from `items.category`'s free text automatically.
- No `item_warehouse_stock_policy` row for a given (item, warehouse) →
  fall back to that item's `items.minimum_stock`, with `buffer_stock_base`
  treated as unset (not zero — see `docs/PHASE_V2_TECHNICAL_DESIGN.md`
  Section 10 for the full status-calculation rule this feeds).
- `inventory_transactions.bakery_destination_id IS NULL` → transaction has
  no external distribution destination recorded (normal for every
  transaction type except a bakery-bound `OUT`).

## 5. Karang Tengah safety note

Nothing in this migration creates, activates, or references Karang
Tengah. `item_warehouse_stock_policy` rows are only ever backfilled for
SCM and Cibadak (Phase 3d, a separate data-only script that explicitly
refuses any warehouse code other than those two) — the schema change
itself is warehouse-agnostic and touches zero warehouse rows.
