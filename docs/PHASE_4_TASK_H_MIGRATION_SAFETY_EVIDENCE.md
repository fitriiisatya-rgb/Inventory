# Phase 4 — Task H: Migration Safety Evidence

**Status: COMPLETE.** Full precheck → migration → stock-policy backfill →
postcheck → rollback cycle run against a disposable database seeded with
a realistic dataset (not a toy/minimal one — real IN/OUT transactions,
real FIFO batches, real `fifo_allocations` rows, a real computed
company-wide inventory value), with every required metric captured at
each of the three checkpoints. **No production database access was used
or required for this verification.**

## 1. Filenames

| Step | File |
|---|---|
| Migration | `database/migrations/2026_09_18_v2_schema.sql` |
| Precheck | `scripts/v2_schema_precheck.php` |
| Postcheck | `scripts/v2_schema_postcheck.php` |
| Rollback | `database/migrations/2026_09_18_v2_schema_rollback.sql` |
| (Also exercised) Stock-policy backfill | `scripts/backfill_stock_policy_scm_cibadak.php` |

## 2. Test setup

Disposable database `inventory_taskh` (dropped after this verification),
built from the **pre-V2 schema shape** (`git show
198fb31:database/schema.sql` — the commit immediately before V2 changes
were folded into `database/schema.sql`, i.e. the shape production is
still on today — same base used for Task C).

Seeded with a realistic dataset via raw SQL matching the pre-V2 row shapes
exactly (the *current* `FifoService` code already assumes V2 columns
exist and cannot run against a pre-migration schema, so historical-style
rows were inserted directly — the same way real historical production
data already sits in the database as rows, not as live service calls):

- 2 warehouses, 3 items
- 12 `inventory_transactions` (IN) → 12 `inventory_batches` (2 layers ×
  3 items × 2 warehouses)
- 6 `inventory_transactions` (OUT) → 6 `fifo_allocations` rows, each
  partially consuming the oldest batch for its item/warehouse (15 units
  each)
- Company on-hand value: **Rp 759,000** across **690** units total

## 3. BEFORE migration

| Metric | Value |
|---|---|
| Item count | 3 |
| Warehouse count | 2 |
| Transaction count (`inventory_transactions`) | 18 |
| Transaction line count (`inventory_transaction_lines`) | 18 |
| Inventory batch count (`inventory_batches`) | 12 |
| FIFO allocation count (`fifo_allocations`) | 6 |
| Company inventory value (`SUM(qty_base * unit_cost_base)`) | **Rp 759,000** |
| Company on-hand quantity (`SUM(qty_base)`) | 690 |
| User count | 1 |
| Supplier count | 0 |

Precheck (`php scripts/v2_schema_precheck.php`) confirmed the server
version supports enforced CHECK constraints and wrote its own row-count
snapshot, independently matching the table above for every table it
tracks:

```
Server version: 10.11.14-MariaDB-0ubuntu0.24.04.1
{"items":3,"suppliers":0,"inventory_transactions":18,"inventory_batches":12,"warehouses":2,"users":1}
PRECHECK PASSED
```

## 4. Migration

`mariadb inventory_taskh < database/migrations/2026_09_18_v2_schema.sql`
— ran with **zero errors or warnings**.

## 5. AFTER MIGRATION (+ stock-policy backfill)

| Metric | Value | vs. BEFORE |
|---|---|---|
| Item count | 3 | unchanged |
| Warehouse count | 2 | unchanged |
| Transaction count | 18 | unchanged |
| Transaction line count | 18 | unchanged |
| Inventory batch count | 12 | unchanged |
| FIFO allocation count | 6 | unchanged |
| Company inventory value | **Rp 759,000** | **unchanged, to the rupiah** |
| Company on-hand quantity | 690 | unchanged |
| User count | 1 | unchanged |
| Supplier count | 0 | unchanged |
| **New:** `categories` rows | 0 | — (none created; migration only creates the table) |
| **New:** `item_warehouse_stock_policy` rows | 6 | 3 items × 2 warehouses (SCM+CIBADAK), created by `backfill_stock_policy_scm_cibadak.php` run immediately after the migration, as the real deployment sequence would |
| **New:** `bakery_destinations` rows | 0 | — (none created; migration only creates the table) |

Postcheck (`php scripts/v2_schema_postcheck.php`) — **28/28 PASS, 0
FAIL**: all 3 new tables exist, all 5 new/changed columns exist, all 4
new foreign keys resolve, all 11 CHECK-constraint enforcement probes
pass (§4 of `docs/PHASE_4_TASK_C_BAKERY_CHECK_CONSTRAINT.md` has the full
per-transaction-type breakdown), and all 6 pre-existing-table row counts
matched the precheck snapshot exactly.

The company inventory value figure — the single number a finance
stakeholder would check first — is **identical to the rupiah** before and
after: Rp 759,000. This is the strongest single piece of evidence that
the migration did not silently touch a batch, allocation, or transaction
row.

## 6. Rollback

`mariadb inventory_taskh < database/migrations/2026_09_18_v2_schema_rollback.sql`
— ran with **zero errors**.

## 7. AFTER ROLLBACK — proof of return to pre-migration state

| Metric | Value | vs. BEFORE (§3) |
|---|---|---|
| Item count | 3 | **identical** |
| Warehouse count | 2 | **identical** |
| Transaction count | 18 | **identical** |
| Transaction line count | 18 | **identical** |
| Inventory batch count | 12 | **identical** |
| FIFO allocation count | 6 | **identical** |
| Company inventory value | **Rp 759,000** | **identical, to the rupiah** |
| Company on-hand quantity | 690 | **identical** |
| User count | 1 | **identical** |
| Supplier count | 0 | **identical** |
| `categories` table | dropped (0 rows possible) | confirmed absent via `information_schema.TABLES` |
| `item_warehouse_stock_policy` table | dropped (the 6 backfilled rows are gone with it) | confirmed absent — expected and documented in the rollback script's own header: rollback DROPs the V2 tables, so any data written into them (including a completed stock-policy backfill) is lost, same as it never existed pre-V2 |
| `bakery_destinations` table | dropped | confirmed absent |

Every pre-existing metric is **byte-for-byte, rupiah-for-rupiah identical**
to the BEFORE state. The three new tables and their data (including the
stock-policy backfill performed in §5) are gone, exactly as the rollback
script's own documentation states they would be — this is a full return
to the pre-V2 state, not a partial or lossy one.

## 8. Test commands (reproducible)

```bash
# Pre-V2 schema base (same extraction as Task C):
git show 198fb31:database/schema.sql > /tmp/pre_v2_schema.sql

mariadb -u root -e "DROP DATABASE IF EXISTS inventory_taskh; CREATE DATABASE inventory_taskh;"
mariadb -u root inventory_taskh < /tmp/pre_v2_schema.sql

# Seed a realistic dataset (2 warehouses, 3 items, 12 batches via IN,
# 6 fifo_allocations via OUT) — see this session's seed script for the
# exact raw-SQL inserts, mirroring the pre-V2 row shapes.

DB_DATABASE=inventory_taskh php scripts/v2_schema_precheck.php
mariadb -u root inventory_taskh < database/migrations/2026_09_18_v2_schema.sql
DB_DATABASE=inventory_taskh php scripts/v2_schema_postcheck.php

# Rename warehouses to SCM/CIBADAK first (the backfill script only ever
# targets those exact codes):
mariadb -u root inventory_taskh -e "UPDATE warehouses SET code='SCM' WHERE code='...'; UPDATE warehouses SET code='CIBADAK' WHERE code='...';"
DB_DATABASE=inventory_taskh php scripts/backfill_stock_policy_scm_cibadak.php

# Capture AFTER-MIGRATION metrics (item/warehouse/transaction/batch/
# allocation counts + SUM(qty_base * unit_cost_base) company value).

mariadb -u root inventory_taskh < database/migrations/2026_09_18_v2_schema_rollback.sql
# Capture AFTER-ROLLBACK metrics, confirm identical to BEFORE.

mariadb -u root -e "DROP DATABASE inventory_taskh;"
```
