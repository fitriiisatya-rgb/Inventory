# Phase 4 — Task E: Minimum/Buffer Stock Migration Verification

**Status: COMPLETE.** The SCM/Cibadak stock-policy backfill script
(`scripts/backfill_stock_policy_scm_cibadak.php`, Phase 3d) was exercised
end-to-end against a disposable test database (`inventory_taske`, dropped
after this verification) seeded with SCM, CIBADAK, **and** KARANG_TENGAH
warehouses, items with varying `items.minimum_stock`, and one
migration-negative-whitelisted item with actual negative stock — to prove
every property the owner asked for. The corrected status formula (fixed
earlier this phase, see `docs/PHASE_V2_TECHNICAL_DESIGN.md` Section 10
correction note) is also re-confirmed here against the real regression
suite. No production database was used.

## 1. SCM and Cibadak receive the initial per-item minimum from `items.minimum_stock`

Seeded items with varying global minimums, then ran
`scripts/backfill_stock_policy_scm_cibadak.php`:

| SKU | `items.minimum_stock` (global) | `item_warehouse_stock_policy.minimum_stock_base` — SCM | — CIBADAK |
|---|---|---|---|
| TASKE-001 | 10 | 10.000000 | 10.000000 |
| TASKE-002 | 25.5 | 25.500000 | 25.500000 |
| TASKE-003 | 0 | 0.000000 | 0.000000 |
| TASKE-004 | 100 | 100.000000 | 100.000000 |
| TASKE-NEG | 20 | 20.000000 | 20.000000 |

Every value matches exactly, for both warehouses independently, including
the zero-minimum and fractional-minimum edge cases — confirming both
requirements ("SCM receives initial per-item minimum from existing global
minimum_stock; Cibadak same") in one run.

## 2. `buffer_stock` remains NULL unless explicitly configured

All 10 rows created by the backfill (5 items × 2 warehouses) had
`buffer_stock_base = NULL` — the script never invents a buffer value
(`scripts/backfill_stock_policy_scm_cibadak.php:90`: `VALUES (:i, :w, :min,
NULL, ...)` — `NULL` is a literal in the INSERT, not a variable, so there
is no code path that could populate it from anywhere).

Confirmed the resulting API-facing shape is also correct: querying the
same data through `StockReportService::list()` (the exact code
`GET /reports/stock` uses) for an item whose policy row still has
`buffer_stock_base = NULL` returns `buffer_configured: false` and
`buffer_stock: null` — never a guessed number, never `false` misread as
`0`.

## 3. No Karang Tengah policy rows created

The disposable DB was seeded with all three warehouses (SCM, CIBADAK,
**and** KARANG_TENGAH) specifically so the backfill's exclusion could be
tested against a real Karang Tengah row in the same database, not just its
absence:

```
Target warehouses: CIBADAK (id=2), SCM (id=1)
```

— Karang Tengah never appears in the script's own target-warehouse query
(`WHERE code IN ('SCM', 'CIBADAK')`), and directly querying afterward:

```sql
SELECT COUNT(*) FROM item_warehouse_stock_policy p
JOIN warehouses w ON w.id = p.warehouse_id WHERE w.code = 'KARANG_TENGAH';
-- 0
```

Zero rows. The script also has an explicit second guard (line 51-56) that
refuses to run at all if any warehouse in its own query result is not
literally `SCM` or `CIBADAK` — belt-and-suspenders against a future schema
change accidentally widening that `WHERE IN (...)`.

## 4. Migration-negative items remain REVIEW

Seeded `TASKE-NEG`: whitelisted via `movement_reconciliation_reviews`
(`is_migration_negative_approved = 1`) with an actual `qty_base = -5` in
SCM (posted as an `OPENING` transaction with an explicit negative-layer
batch, mirroring how real historical negative-stock migration rows are
represented). Ran the stock-policy backfill (which gives this item a
normal `minimum_stock_base = 20` / `buffer = NULL` row, same as any other
item), then queried it through `StockReportService::list()`:

```
PASS - TASKE-NEG status is MIGRATION_NEGATIVE_REVIEW (not OUT_OF_STOCK, despite qty=-5)
PASS - TASKE-NEG migration_negative_review flag is true
PASS - TASKE-NEG minimum_stock reflects the backfilled policy row (20)
```

Confirms REVIEW is orthogonal to the stock-policy backfill: having a
normal minimum/buffer policy row does not fold a migration-negative item
into `OUT_OF_STOCK`, `CRITICAL`, `LOW`, or `SAFE` — REVIEW always wins,
exactly as `StockPolicyService::stockStatus()` and
`StockReportService`'s SQL CASE expression both implement (and as already
covered generally by `tests/stock_report_test.php` Section G, which this
result is consistent with).

## 5. `buffer_configured` correctly exposed when NULL

Beyond the NULL case in §2, also verified the positive case in the same
run: manually set `buffer_stock_base = 15` on `TASKE-001`'s SCM policy row
(simulating a value a human configured via `PUT /stock-policy` after the
backfill), then confirmed:

```
PASS - TASKE-001 buffer_configured is true (buffer was explicitly set to 15)
PASS - TASKE-001 buffer_stock is 15
```

`buffer_configured` correctly flips to `true` only when a real numeric
buffer exists, and stays `false` with `buffer_stock: null` for every item
the backfill left untouched — both directions of this flag verified in one
test run, not just the NULL case.

## 6. Idempotency and never-overwrite (belt-and-suspenders on top of Phase 3d)

Re-running the backfill against the already-populated disposable DB:

```
Rows created: 0
Rows skipped (already had a policy row — never overwritten): 10
```

— and directly confirmed the manually-set `TASKE-001` row (minimum
overwritten to 999, buffer set to 15, simulating both a backfilled value a
human later corrected and a buffer a human configured) was **completely
untouched** by the re-run: `minimum_stock_base` stayed 999,
`buffer_stock_base` stayed 15. The backfill only ever inserts a row when
none exists for that `(item_id, warehouse_id)` pair; it never issues an
`UPDATE` against an existing policy row under any circumstance.

## 7. Status calculation formula — re-confirmed against the real regression suite

The corrected formula (fixed earlier this phase — see the standalone
commit `bb38b02` — buffer is an **absolute threshold**, `qty < buffer`,
not `qty < minimum + buffer`) is exercised by
`tests/stock_policy_test.php` Section G, re-run this session for this
report:

```
== G: stockStatus() — the 5-state calculation, evaluated in order ==
   (Phase 4 correction: buffer is an ABSOLUTE threshold -- qty < buffer --
   not a margin added on top of minimum. minimum=10, buffer=20 throughout.)
PASS - migration_negative_review always wins -> REVIEW, even with qty>minimum and qty>buffer
PASS - qty <= 0 -> OUT_OF_STOCK
PASS - negative qty -> OUT_OF_STOCK
PASS - 0 < qty < minimum -> CRITICAL
PASS - qty >= minimum, buffer NOT configured -> SAFE (LOW never fires without buffer)
PASS - qty >= minimum, buffer configured, qty < buffer -> LOW
PASS - qty >= buffer -> SAFE
PASS - boundary: qty exactly = buffer -> SAFE (not LOW; condition is strictly qty < buffer)
PASS - boundary: qty exactly = minimum, buffer configured and above minimum -> LOW, not CRITICAL
PASS - boundary: qty exactly = minimum, buffer NOT configured -> SAFE, not CRITICAL
PASS - CRITICAL still applies even when buffer is configured, if qty < minimum

TOTAL: 34  PASSED: 34  FAILED: 0
```

This exact ordering and every boundary case matches the owner's Section E
specification verbatim:

```
OUT_OF_STOCK: qty <= 0
CRITICAL:     qty > 0 AND qty < minimum
LOW:          buffer configured AND qty >= minimum AND qty < buffer
SAFE:         qty >= minimum AND (buffer not configured OR qty >= buffer)
REVIEW:       migration-negative controlled item (always wins, checked first)
```

`tests/stock_report_test.php` Section F additionally cross-checks that
`StockReportService`'s SQL `CASE` expression produces the identical status
as the PHP `StockPolicyService::stockStatus()` for every row in its
dataset — the two implementations cannot silently drift apart.

## 8. Test commands (reproducible)

```bash
mariadb -u root -e "DROP DATABASE IF EXISTS inventory_taske; CREATE DATABASE inventory_taske;"
mariadb -u root inventory_taske < database/schema.sql
# seed: SCM, CIBADAK, KARANG_TENGAH warehouses; items with varying
# minimum_stock; one migration-negative-whitelisted item with qty=-5 in SCM
# (movement_reconciliation_reviews + an OPENING transaction/negative batch)

DB_DATABASE=inventory_taske php scripts/backfill_stock_policy_scm_cibadak.php --dry-run
DB_DATABASE=inventory_taske php scripts/backfill_stock_policy_scm_cibadak.php
# verify: minimum_stock_base per item/warehouse, buffer NULL, 0 rows for
# KARANG_TENGAH, then query StockReportService::list() for the
# migration-negative item's status and buffer_configured flags

# idempotency: manually alter one row's minimum/buffer, re-run, confirm untouched
DB_DATABASE=inventory_taske php scripts/backfill_stock_policy_scm_cibadak.php

mariadb -u root -e "DROP DATABASE inventory_taske;"

# status formula regression (against the main test DB):
DB_DATABASE=inventory_test php tests/stock_policy_test.php
```
