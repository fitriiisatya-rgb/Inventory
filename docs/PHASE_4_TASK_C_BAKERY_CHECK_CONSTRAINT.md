# Phase 4 — Task C: `bakery_destination_id` CHECK Constraint Evidence

**Status: COMPLETE — CHECK constraint verified safe, kept in the migration.**
No compatibility risk was found, so the constraint is NOT removed from
`database/migrations/2026_09_18_v2_schema.sql`. All verification below was
run against disposable/test databases only — no production DB access was
used or required.

## 1. Exact CHECK SQL

```sql
ALTER TABLE inventory_transactions
    ADD COLUMN bakery_destination_id INT UNSIGNED NULL AFTER division_id,
    ADD CONSTRAINT fk_tx_bakery_destination FOREIGN KEY (bakery_destination_id) REFERENCES bakery_destinations(id),
    ADD INDEX idx_tx_bakery_destination (bakery_destination_id),
    ADD CONSTRAINT chk_tx_bakery_destination_out_only
        CHECK (bakery_destination_id IS NULL OR transaction_type = 'OUT');
```

(`database/migrations/2026_09_18_v2_schema.sql:116-121`; the same
definition is baked directly into `database/schema.sql:304,322-323` for
fresh installs, so both paths produce the identical constraint.)

As actually stored by the server (`SHOW CREATE TABLE inventory_transactions`
after migration, MariaDB 10.11.14):

```sql
CONSTRAINT `chk_tx_bakery_destination_out_only`
    CHECK (`bakery_destination_id` is null or `transaction_type` = 'OUT')
```

## 2. All `transaction_type` values

```sql
transaction_type ENUM('IN','OUT','TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT',
                       'OPNAME','PRODUCTION_IN','PRODUCTION_OUT','OPENING','REVERSAL') NOT NULL
```

(`database/schema.sql:289-290`) — 10 values total. `OUT` is the only one
permitted to carry a `bakery_destination_id`.

## 3. Why the CHECK doesn't reject IN / OUT / TRANSFER_OUT / TRANSFER_IN / ADJUSTMENT / OPNAME (and the rest)

The constraint is `bakery_destination_id IS NULL OR transaction_type = 'OUT'`
— a row satisfies it if **either** side is true:

- **`OUT`**: satisfies it via the right-hand side regardless of whether
  `bakery_destination_id` is set or NULL — both are explicitly required and
  tested (§4).
- **Every other type** (`IN`, `TRANSFER_OUT`, `TRANSFER_IN`, `ADJUSTMENT`,
  `OPNAME`, `PRODUCTION_IN`, `PRODUCTION_OUT`, `OPENING`, `REVERSAL`):
  satisfies it via the left-hand side **only when `bakery_destination_id`
  is NULL**. None of these types ever populate the column:
  - The three application services that create these rows
    (`FifoService`, `TransferService`, `StockOpnameService`,
    `StockAdjustmentService`, `ProductionService`, `VoidService`, the
    opening/historical importers) never pass `bakery_destination_id` into
    their INSERTs for any type except `OUT`.
  - `tests/master_data_v2_test.php` Section D goes further and proves this
    at the service layer directly: it POSTs a `TRANSFER_OUT` transaction
    **with `bakery_destination_id` in the request payload** and confirms
    the service still succeeds but silently never persists the value —
    `bakery_destination_id` is `NULL` on the stored row regardless of what
    the caller sent. So even a caller mistake can't reach the point of
    testing the DB constraint in normal operation; the service layer is a
    second, independent guard in front of it.
  - `TRANSFER_OUT` and `PRODUCTION_IN` both flow through
    `FifoService::postOut()` internally (same FIFO consumption code as a
    real `OUT`), which is exactly why the owner's phrasing — "a real OUT
    transaction" — matters: they represent an internal warehouse transfer
    and a production-input consumption, not a distribution to an external
    bakery, so they correctly must NOT carry a bakery destination even
    though they share FIFO machinery with `OUT`.

## 4. Tests proving the constraint's actual behavior (disposable DB)

Ran against a fresh disposable database (`inventory_taskc`, dropped after
this verification — never a database anyone else uses), built from the
**pre-V2 schema** (`git show 198fb31:database/schema.sql` — the shape
production is still on) and seeded with one historical row of **every**
`transaction_type` value before migrating, so the test proves both the
constraint's logic and its backward compatibility with real historical
shapes in one pass.

`scripts/v2_schema_postcheck.php` (expanded this phase from a single
`TRANSFER_OUT`-only probe to the full matrix) reported, post-migration:

```
PASS - OUT + bakery_destination_id SET is accepted
PASS - OUT + bakery_destination_id NULL is accepted
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=IN
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=TRANSFER_OUT
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=TRANSFER_IN
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=ADJUSTMENT
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=OPNAME
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=PRODUCTION_IN
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=PRODUCTION_OUT
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=OPENING
PASS - CHECK constraint rejects bakery_destination_id on transaction_type=REVERSAL
```

— i.e. exactly the four required cases plus full coverage of the
remaining seven types the owner didn't explicitly name:

| Required case | Result |
|---|---|
| Valid `OUT` + bakery_destination_id set → **passes** | PASS |
| `OUT` + `bakery_destination_id` NULL → **passes** | PASS |
| Non-`OUT` + bakery_destination_id set → **rejected** | PASS for all 9 non-OUT types (not just `TRANSFER_OUT`) |
| Existing historical rows remain valid | PASS — see §5 |

Each probe inserts into its own transaction that is always rolled back
(and the throwaway warehouse/role/user/bakery-destination fixture rows are
DELETEd in a `finally` block), so no probe data was ever kept in the
disposable database, and one probe's rejection never blocks the next
probe from running (verified: 12/12 in a standalone script before folding
the coverage into `v2_schema_postcheck.php`, then 11/11 CHECK-specific
assertions inside the full postcheck run below).

## 5. Migration + rollback dry run on a disposable DB (full cycle)

Database: `inventory_taskc`, dropped immediately after this verification.
Never run against production, staging, or any database anyone else uses.

**Setup** — built from the pre-V2 schema shape (`git show
198fb31:database/schema.sql`, the commit immediately before the V2 schema
changes were folded into `database/schema.sql`, i.e. the shape production
is still on today), then seeded with one row per `transaction_type`
(10 rows) to simulate real historical data:

```
Seeded 10 pre-existing historical transaction rows (one per transaction_type)
```

**Precheck** (`php scripts/v2_schema_precheck.php`):

```
Server version: 10.11.14-MariaDB-0ubuntu0.24.04.1
Row-count snapshot: {"items":0,"suppliers":0,"inventory_transactions":10,
                      "inventory_batches":0,"warehouses":1,"users":1}
PRECHECK PASSED — safe to run database/migrations/2026_09_18_v2_schema.sql
```

**Migration** (`mariadb inventory_taskc < database/migrations/2026_09_18_v2_schema.sql`):
ran with zero errors or warnings.

**Postcheck** (`php scripts/v2_schema_postcheck.php`):

```
TOTAL: 28  PASSED: 28  FAILED: 0
POSTCHECK PASSED.
```

Included in those 28: all 3 new tables exist, all 5 new/changed columns
exist, all 4 new foreign keys resolve, all 11 CHECK-constraint probes from
§4, and — critically — every pre-existing table's row count is **exactly
unchanged** from the precheck snapshot (`inventory_transactions` stayed at
10, `warehouses` at 1, `users` at 1). Directly confirmed with an explicit
per-type breakdown query before and after migration:

```
-- BEFORE and AFTER migration, identical:
IN 1 | OUT 1 | TRANSFER_OUT 1 | TRANSFER_IN 1 | ADJUSTMENT 1 |
OPNAME 1 | PRODUCTION_IN 1 | PRODUCTION_OUT 1 | OPENING 1 | REVERSAL 1
```

and every pre-existing row's `bakery_destination_id` is `NULL` after
migration (all 10 rows checked individually) — proving the CHECK
constraint's addition never invalidated a single pre-existing row, exactly
as the migration's own inline analysis predicted (the column is new and
nullable, so every row that existed before the column existed trivially
satisfies `bakery_destination_id IS NULL`).

**Rollback** (`mariadb inventory_taskc < database/migrations/2026_09_18_v2_schema_rollback.sql`):
ran with zero errors. Verified after:

- `categories`, `item_warehouse_stock_policy`, `bakery_destinations` — all
  three dropped (confirmed via `information_schema.TABLES` — zero rows
  returned).
- `inventory_transactions` row count: **10** (unchanged from before the
  migration ever ran).
- `warehouses`: **1**, `users`: **1** (unchanged).

The disposable database was dropped after this verification completed.

## 6. Server-version safety check

`scripts/v2_schema_precheck.php` already refuses to proceed on a server
that would silently ignore the CHECK constraint (MariaDB < 10.2.1 / MySQL
< 8.0.16 parse-but-ignore CHECK). Verified this session's server is
MariaDB 10.11.14 — well above that floor — and confirmed the constraint is
genuinely *enforced*, not just accepted syntax, via the 11 rejection
probes in §4 (a parse-and-ignore server would have shown `ACCEPTED` for
those too).

## 7. Conclusion

No compatibility risk was found at any step: the constraint's logic is
correct for all 10 transaction types, it never rejects a pre-existing
historical row, the forward migration and rollback both run cleanly and
symmetrically on a disposable DB seeded to simulate production's current
(pre-V2) shape, and the service layer independently prevents any caller
from ever attempting a disallowed value in the first place. **The CHECK
constraint stays in the migration** — no fallback to service-layer-only
enforcement is needed.

## 8. Evidence commands (reproducible)

```bash
# Extract the pre-V2 schema shape (the commit immediately before V2 changes
# were folded into database/schema.sql):
git show 198fb31:database/schema.sql > /tmp/pre_v2_schema.sql

# Disposable DB, fresh each time:
mariadb -u root -e "DROP DATABASE IF EXISTS inventory_taskc; CREATE DATABASE inventory_taskc;"
mariadb -u root inventory_taskc < /tmp/pre_v2_schema.sql

# Seed one row per transaction_type (see scratchpad seed script from this
# session, or write an equivalent INSERT loop over the 10 ENUM values).

DB_DATABASE=inventory_taskc php scripts/v2_schema_precheck.php
mariadb -u root inventory_taskc < database/migrations/2026_09_18_v2_schema.sql
DB_DATABASE=inventory_taskc php scripts/v2_schema_postcheck.php
mariadb -u root inventory_taskc < database/migrations/2026_09_18_v2_schema_rollback.sql

mariadb -u root -e "DROP DATABASE inventory_taskc;"
```
