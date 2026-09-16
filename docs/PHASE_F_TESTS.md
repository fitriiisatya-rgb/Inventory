# Phase F — Test Results

## Update: `database/schema.sql` has now been loaded and exercised against a real MariaDB server

The OS package mirror initially 404'd on `mariadb-server`'s dependency
chain and no Docker daemon was available, so the original plan was to test
only against SQLite. That gap has since been closed within this same
sandbox: `mariadb-install-db` + a manually-started `mariadbd` (10.11.14)
came up successfully, and **`database/schema.sql` — the actual production
MySQL DDL, unmodified except for one reserved-word column rename applied
to the file itself (`import_rows.row_number` → `row_no`, since
`ROW_NUMBER` is reserved in both MySQL 8 and MariaDB 10.11+) — loads
cleanly and was verified to enforce what it claims to**:

- All 30 tables created; seed data present only in `roles` (5),
  `permissions` (15), `role_permissions` (34), `units` (9),
  `system_settings` (4) — `items`, `suppliers`, `inventory_batches`,
  `inventory_transactions`, `users` etc. all confirmed at 0 rows (Section 2
  compliance, checked directly, not assumed).
- The generated-column trick backing `uq_iuc_one_open_version` actually
  blocks a second concurrently-open packaging version for the same
  item+unit — confirmed via a real `ERROR 1062 Duplicate entry` when
  attempting it.
- Foreign keys are actually enforced — confirmed via a real
  `ERROR 1452 Cannot add or update a child row` when inserting a batch
  against a non-existent warehouse.
- `tests/mysql_smoke_test.php` then ran the FIFO engine itself
  (`FifoService::postIn/postOut`, `Database::transaction`,
  `Database::lockFifoBatches` — the real code path, real `FOR UPDATE`
  clause, not the SQLite shim) against this live server: FIFO layering,
  the negative-stock rejection, and the transaction rollback all passed
  (4/4 — see the results table below).

This instance is throwaway and local to the sandbox (not reachable outside
it, and not part of the committed repo — `.env` is git-ignored). It
demonstrates the schema and service layer are sound against real
MySQL-family DB engine behavior; it does not substitute for load-testing
concurrent writers against the actual production server before go-live
(`docs/DEPLOYMENT.md` H.3).

## Why SQLite is still the *default* test runner

`tests/run.php` and `tests/import_test.php` stay SQLite-based
(`tests/sqlite_schema.sql`, `pdo_sqlite` — bundled with PHP, no server
needed) so they run in any environment without first standing up a
database server — useful for a quick local check or CI. `services/Database::lockFifoBatches()`
only drops the `FOR UPDATE` clause when the driver is `sqlite`; every other
line of business logic is identical to what just ran against real MariaDB
above.

Run them yourself:

```bash
php tests/run.php              # FIFO / unit conversion / idempotency / anomaly (SQLite)
php tests/import_test.php      # Master Barang import staging + commit (SQLite)
php tests/mysql_smoke_test.php # same FIFO engine against a real MySQL/MariaDB (.env required)
```

## Results — `tests/run.php` (Section 27 scenarios)

| # | Scenario | Result |
|---|---|---|
| 1 | Opening 100kg@Rp10.000, OUT 40kg → stock 60kg | **PASS** |
| 1 | OUT 40kg HPP = Rp400.000 | **PASS** |
| 2 | FIFO: IN 100@10.000 + IN 100@12.000, OUT 150 → HPP Rp1.600.000 (100×10.000 + 50×12.000) | **PASS** |
| 2 | Remaining after OUT 150 = 50kg | **PASS** |
| 2 | Remaining value = Rp600.000 (50×12.000) | **PASS** |
| 3 | 1 carton = 20kg, price Rp500.000/carton → unit_cost_base = Rp25.000/kg | **PASS** |
| 3 | IN 2 carton → base_qty = 40kg | **PASS** |
| 3 | IN 2 carton → stock value = Rp1.000.000 | **PASS** |
| 4 | Double-submit same `transaction_uuid` → exactly 1 transaction row | **PASS** |
| 4 | Second call reports `idempotent_replay` instead of posting again | **PASS** |
| 5 | OUT > available stock → `InsufficientStockException` (REJECT, no override) | **PASS** |
| 6 | Unit cost 100x historical reference → `PriceAnomalyException` | **PASS** |
| 6 | Same posting succeeds once `anomaly_approved_by` is attached (explicit override path) | **PASS** |

**13/13 assertions passed.**

## Results — `tests/import_test.php` (Phase E importer)

| # | Scenario | Result |
|---|---|---|
| 1 | `templates/master_items.csv` (1 valid row) stages with 0 errors | **PASS** |
| 1 | Commit imports exactly 1 item | **PASS** |
| 1 | Item name matches source row | **PASS** |
| 1 | Base unit (GR) gets identity conversion 1.0 | **PASS** |
| 1 | Purchase unit (KARUNG) conversion = 25.000 | **PASS** |
| 1 | Middle unit (KG) conversion = 1.000 | **PASS** |
| 2 | A file with 1 valid + 1 error row (missing SKU) is staged with `error_rows=1` | **PASS** |
| 2 | `commit()` throws and refuses the whole batch while any row is ERROR | **PASS** |
| 2 | Zero items exist afterward — all-or-nothing, matching "IMPORT DITOLAK" (Section 16) | **PASS** |

**9/9 assertions passed.**

## Results — `tests/mysql_smoke_test.php` (real MariaDB 10.11.14, not SQLite)

| # | Scenario | Result |
|---|---|---|
| 1 | FIFO across two real-MySQL batches: HPP = Rp1.600.000 | **PASS** |
| 1 | Remaining stock after OUT = 50kg, value Rp600.000 | **PASS** |
| 2 | OUT far exceeding stock rejected via real `FOR UPDATE`-locked query | **PASS** |
| 2 | Stock unchanged afterward — proves `Database::transaction()`'s rollback actually reverted every write, not just the ones a mock would catch | **PASS** |

**4/4 assertions passed.**

## PHASE F2 — full backend engine (Transfer, Opname, Production, Closing, Reconciliation, Importers, Concurrency)

All of the following ran against the **same real MariaDB 10.11 instance**
used above, via `tests/run_mysql_tests.sh` (resets the schema fresh before
each file, since several tests LOCK an accounting period and that must not
leak into the next file's fixture dates).

| Suite | Assertions | Result |
|---|---|---|
| `tests/mysql_smoke_test.php` | 4 | **4/4 PASS** |
| `tests/mysql_integration_test.php` (Transfer, Opname, Production, Book Closing, period lock, reconciliation) | 32 | **32/32 PASS** |
| `tests/mysql_importer_test.php` (Supplier, Division, Warehouse, Opening Stock, Historical Transaction) | 12 | **12/12 PASS** |
| `tests/concurrency_test.sh` (5 independent runs, two real OS processes/connections each) | 5 runs | **5/5 PASS** |

### Transfer — A 100@Rp10.000, ship 40 to B, receive

| Assertion | Result |
|---|---|
| A = 60kg after shipping 40 | PASS |
| B = 0kg while PENDING (not yet received) | PASS |
| In-transit value = Rp400.000 | PASS |
| Company total value (on-hand + in-transit) unchanged during transit | PASS |
| Second `receive()` call on an already-RECEIVED transfer is idempotent (no double effect) | PASS |
| A stays 60kg, B = 40kg after receive | PASS |
| In-transit value = 0 after receive | PASS |
| B's batch preserves the original Rp10.000/kg cost layer (not re-averaged) | PASS |
| Company total value unchanged after the full ship→receive round-trip | PASS |
| Duplicate `transfer_uuid` on `create()` is idempotent | PASS |
| `cancel()` (while PENDING) restores the source warehouse's stock exactly | PASS |
| A cancelled transfer cannot be received | PASS |

### Stock Opname — system 100, physical count 95

| Assertion | Result |
|---|---|
| Movement is blocked on the warehouse while the opname session is OPEN/FINALIZED | PASS |
| Ending stock = 95kg after `post()` | PASS |
| A real `stock_adjustments` row is created with `qty_base_delta = -5` | PASS |
| Adjustment type = OPNAME | PASS |
| An audit log entry exists for the adjustment | PASS |
| Warehouse is unblocked once the session is POSTED | PASS |
| Posting an already-POSTED session again creates no second adjustment (idempotent) | PASS |

### Production — raw 100kg@Rp10.000, consume 40kg, output 20 units

| Assertion | Result |
|---|---|
| Production input cost = Rp400.000 | PASS |
| Output unit cost = Rp20.000/unit (400.000 / 20) | PASS |
| Raw material remaining = 60kg | PASS |
| Output batch = 20 units, value Rp400.000 | PASS |
| Total inventory value conserved through the conversion (Rp600.000 raw + Rp400.000 output = pre-production total) | PASS |
| Duplicate `production_uuid` is idempotent (one production only) | PASS |

### Book Closing — period lock, September ending = October opening

| Assertion | Result |
|---|---|
| Closing succeeds when there are no blockers | PASS |
| `ending_inventory_value` from `close()` matches the live company value at close time | PASS |
| "Ending" and "opening" are the same number, because nothing is moved on close (structural guarantee, not a separate computation) | PASS |
| A transaction dated inside the now-LOCKED period is rejected `PERIOD_LOCKED` | PASS |
| Re-closing an already-LOCKED period is idempotent | PASS |

### Reconciliation — sample output (dummy data)

```
total_sku=5, sku_with_stock=5, company_inventory_value=3.340.000, go_live_ready=true
  - negative_stock: PASS (0)
  - zero_cost_batch: PASS (0)
  - abnormal_cost_batch: PASS (0)
  - orphan_batch: PASS (0)
  - duplicate_sku: PASS (0)
  - unknown_sku_or_warehouse: PASS (0)
  - unbalanced_fifo_allocation: PASS (0)
```

### Importers — Supplier, Division, Warehouse, Opening Stock, Historical Transaction

| Assertion | Result |
|---|---|
| Supplier/Division/Warehouse valid row commits | PASS (each) |
| Supplier row with missing code is rejected at commit (all-or-nothing) | PASS |
| Opening Stock creates a REAL batch (125kg @ Rp12 = Rp1.500) | PASS |
| Opening Stock line shows up in the ledger as an `OPENING`-type entry | PASS |
| Historical Transaction import does NOT change stock qty | PASS |
| Historical row is flagged `is_historical_import=1, inventory_effect=0` | PASS |
| No `inventory_batches` row is created for the historical line | PASS |

### Concurrency — the one that matters most

Two independent **OS processes**, each with its **own PDO connection**,
both attempting `OUT 70` against the same 100kg stock at (as close to)
the same instant as `bash`'s `&`/`wait` can arrange:

```
PASS - run 1: final_stock=30 successes=1 rejected=1
PASS - run 2: final_stock=30 successes=1 rejected=1
PASS - run 3: final_stock=30 successes=1 rejected=1
PASS - run 4: final_stock=30 successes=1 rejected=1
PASS - run 5: final_stock=30 successes=1 rejected=1
```

Exactly one process succeeds, one is rejected `STOCK_INSUFFICIENT`, and the
final stock is 30 — never -40 — in all 5 runs. This is real evidence that
`Database::lockFifoBatches()`'s `SELECT ... FOR UPDATE` genuinely
serializes concurrent writers at the database level, not just in
single-process testing.

### Real bugs this pass of testing actually caught (fixed, not hidden)

Worth stating plainly, since these would not have been caught without
exercising the real HTTP layer and real MySQL:

1. **Duplicate named PDO placeholders** (`:sku` used twice in one query,
   etc.) silently work under SQLite/emulated prepares but throw
   `SQLSTATE[HY093]: Invalid parameter number` under MySQL native
   prepares — found in three separate places (test fixtures and
   `migration/install.php`'s own INSERT) and fixed.
2. **DECIMAL columns come back as PHP strings from MySQL**, which
   `declare(strict_types=1)` rejects at the first strictly-typed
   `float`/`int` parameter downstream (`PriceAnomalyService::recordPrice`).
   Fixed with a normalization step at the top of `FifoService::postIn`/
   `postOut` so every caller (Transfer, Production, direct API) is
   protected in one place.
3. **`transaction_uuid CHAR(36)` was too narrow** once
   Transfer/Production/Opname started deriving composite per-line
   idempotency keys (`{uuid}:OUT:{item_id}`) — widened to `VARCHAR(100)`.
4. **An incomplete/malformed request body crashed into a generic 500**
   instead of a clean `VALIDATION_FAILED`, both inside the service
   (accessing `$p['transaction_uuid']` before checking it exists) and in
   the router itself (`inv_require_warehouse_scope()` reading
   `$input['warehouse_id']` before checking it exists). Both fixed with an
   explicit required-field check that runs first.
5. **`migration/install.php`'s password prompt** called `stty -echo`
   unconditionally, which fails outside a real TTY (e.g. scripted/CI
   installs) — now falls back to a plain read rather than aborting.

## Not yet covered by an automated test (documented gap, not silently skipped)

- **CSRF and login-rate-limit** were verified manually via `curl` against a
  running `php -S` instance (documented in `docs/PHASE_C2_ENDPOINTS.md`)
  but are not yet captured as a repeatable script in `/tests` — worth
  adding an HTTP-level test file before go-live.
- **Void/reverse of an arbitrary posted transaction** — `TransferService::cancel`
  demonstrates the restore-and-mark-REVERSED pattern for transfers
  specifically; a generic `POST /transactions/{id}/void` for any IN/OUT
  transaction is not built.
- **Multipart file upload** for the import endpoints — `stage()` currently
  takes a server-side `file_path`, not an uploaded file; see
  `docs/PHASE_C2_ENDPOINTS.md`.
- **True point-in-time book closing** — `BookClosingService` snapshots
  *live* batch state at close time and refuses to close if any later-dated
  transaction already exists anywhere in the system (see the class
  docblock). This is a real, documented simplification: periods must be
  closed in chronological order before the next period's data is entered.
  A full point-in-time reconstruction (replaying the ledger up to a cutoff
  regardless of what's been posted since) is future work.
- **Multi-connection contention beyond the 2-writer case** proven by
  `tests/concurrency_test.sh` — higher-concurrency load testing (e.g. 10+
  simultaneous writers) has not been run.
