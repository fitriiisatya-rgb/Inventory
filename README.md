# Inventory FIFO Pro — MySQL Rebuild

Rebuilding the existing Google Sheets/Apps Script "Inventory FIFO Pro"
(`inventory.html`) onto a PHP + MySQL backend, keeping the existing flow,
UI and business rules but replacing the data layer and fixing the specific
integrity gaps the old client-only architecture could not enforce. Full
brief and constraints: **no production data is imported** — the new
database starts empty and stays empty until a deliberate, validated import
after go-live sign-off.

## Where things stand right now

| Phase | Deliverable | Status |
|---|---|---|
| A — System analysis | `docs/PHASE_A_ANALYSIS.md` | **Done** |
| B — MySQL architecture | `database/schema.sql`, `docs/ERD.md` | **Done** — loaded and verified against a real MariaDB 10.11 instance |
| C — Core backend | `services/FifoService.php`, `public/index.php` | **Done** — auth, master reads, Transaksi Masuk/Keluar (FIFO + unit conversion + negative-stock guard + price-anomaly guard + idempotency + period/warehouse lock) |
| C2 — Complete inventory backend | Transfer, Stock Opname, Stock Adjustment, Production, Book Closing, Period Lock, InventoryService, Ledger | **Done and tested** — see `docs/PHASE_C2_ENDPOINTS.md` for the full endpoint list and `docs/PHASE_F_TESTS.md` for 32 passing integration assertions |
| C3 — Security | `services/AuthService.php` | **Done** — server-side `password_hash`/`password_verify`, session cookie hardening, CSRF synchronizer token, login rate limiting, role+warehouse/division-scoped authorization middleware |
| D — Frontend adapter | `public/assets/js/api-client.js`, `docs/PHASE_D_MIGRATION_GUIDE.md` | **Not done** — the 11,810-line `inventory.html` has not been rewired. The migration guide is a concrete, line-referenced punch list, not a claim that it's finished. Deliberately deferred until the backend below was solid. |
| E — Import module | `services/Import*.php`, `templates/*.csv` | **All 6 importers done and tested**: Master Barang, Supplier, Division, Warehouse, Opening Stock (creates real batches), Historical Transaction (`is_historical_import=1, inventory_effect=0`, never affects stock) |
| E3 — Reconciliation | `services/ReconciliationService.php`, `GET /api/reconciliation` | **Done** — 7 checks (negative stock, zero/abnormal cost, orphan batch, duplicate SKU, unknown ref, unbalanced FIFO allocation), each PASS/WARNING/ERROR, rolled up into a single `go_live_ready` flag |
| F/F2 — Tests | `tests/*.php`, `tests/*.sh`, `docs/PHASE_F_TESTS.md` | **75 assertions passing**: 22 on SQLite (no server needed) + 53 on a real MariaDB instance (smoke 4, integration 32, importers 12, concurrency 5 independent runs) |

**Read `docs/PHASE_D_MIGRATION_GUIDE.md` before assuming the frontend talks
to MySQL today — it does not yet.** The legacy `inventory.html` still runs
exactly as before (Google Sheets sync included); per the deployment
requirement it also keeps running unmodified on the existing domain
regardless, since the two systems are meant to operate side by side.

## File tree

```
/api            (reserved — current routes live inline in public/index.php)
/config          config.php (env loader), .env.example
/database        schema.sql
/docs             PHASE_A_ANALYSIS.md, ERD.md, DEPLOYMENT.md,
                  LEGACY_DEPENDENCIES.md, PHASE_D_MIGRATION_GUIDE.md,
                  PHASE_C2_ENDPOINTS.md, PHASE_F_TESTS.md
/migration        install.php (creates the first SUPERADMIN user)
/public           index.php (API front controller), .htaccess,
                  assets/js/api-client.js
/services         Database, AuthService, UnitConversionService,
                  PriceAnomalyService, IdempotencyService, FifoService,
                  InventoryService, PeriodLockService, WarehouseLockService,
                  TransferService, StockOpnameService, StockAdjustmentService,
                  ProductionService, BookClosingService, ReconciliationService,
                  ImportMasterItemService, ImportSimpleMasterService,
                  ImportOpeningStockService, ImportHistoricalTransactionService,
                  AuditService, Exceptions
/templates        master_items.csv, suppliers.csv, divisions.csv,
                  warehouses.csv, opening_stock.csv, historical_transactions.csv
                  (headers + one dummy example row each — no real data)
/tests            run.php, import_test.php, sqlite_schema.sql (no server needed),
                  mysql_smoke_test.php, mysql_integration_test.php,
                  mysql_importer_test.php, concurrency_*.php/.sh,
                  run_mysql_tests.sh (orchestrates all real-MySQL suites)
```

`inventory.html` and `trace-stok-awal.js` at the repo root are the legacy
system, kept as the reference implementation per the brief — not deleted,
not modified beyond what they already were.

## Running the tests

```bash
php tests/run.php              # FIFO costing, unit conversion, idempotency, price anomaly (SQLite, no server needed)
php tests/import_test.php      # Master Barang import staging/validation/commit (SQLite)

# Real MySQL/MariaDB (requires a configured .env — see config/.env.example):
bash tests/run_mysql_tests.sh  # resets the schema fresh and runs every real-MySQL suite in order:
                                #   mysql_smoke_test.php, mysql_integration_test.php,
                                #   mysql_importer_test.php, concurrency_test.sh
```

All exit non-zero on any failure (suitable for CI). See `docs/PHASE_F_TESTS.md`
for the full breakdown of all 75 passing assertions, including the exact
Transfer/Opname/Production/Closing/Reconciliation scenarios from the brief
and the concurrency race (two real DB connections, stock=100, both attempt
OUT 70 — exactly one succeeds, final stock=30, never -40).

## Deploying

See `docs/DEPLOYMENT.md` — covers the new-domain/new-database separation
from the existing system, `.env` configuration, session/cookie/CORS
hardening, and the parallel-run → reconciliation → go-live checklist.

## What was deliberately not built yet (so it isn't mistaken for "done")

See `docs/PHASE_C2_ENDPOINTS.md`'s status table for the precise list. In short:

- **The frontend adapter (Phase D)** — by explicit instruction, this was
  deferred until the backend engine below it was complete and tested.
  `inventory.html` still runs entirely against `localStorage` + Google Apps
  Script today.
- **Multipart file upload** for the import endpoints — `stage()` takes a
  server-side file path today, not an uploaded file.
- **Generic void/reverse** for an arbitrary posted transaction — only
  `TransferService::cancel` demonstrates the restore-and-REVERSE pattern so far.
- **True point-in-time book closing** — closings read live batch state and
  require closing periods in order; see `BookClosingService`'s docblock
  and `docs/PHASE_F_TESTS.md`.
- **OCR/invoice-scan backend** — explicitly out of scope; see
  `docs/LEGACY_DEPENDENCIES.md`.
