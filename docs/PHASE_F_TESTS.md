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

## Not yet covered by an automated test (documented gap, not silently skipped)

- Warehouse transfer atomicity (OUT+IN as one unit) — `FifoService::postOut`
  and `postIn` are individually tested; the transfer composition itself is
  not yet a built endpoint (Phase D/C follow-up list).
- Row-locking under genuine *concurrent* connections (two processes racing
  to consume the same FIFO batch at once) — `tests/mysql_smoke_test.php`
  proves the `FOR UPDATE` SQL is valid and a single transaction's rollback
  works on real MariaDB, but does not spin up two concurrent connections to
  prove the lock actually blocks a second writer. Worth a dedicated
  concurrency test before go-live.
- Stock opname posting, production/racik costing, book closing — schema
  and design exist (`database/schema.sql`, `docs/ERD.md`); service classes
  for these are not yet written.
