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
| B — MySQL architecture | `database/schema.sql`, `docs/ERD.md` | **Done** — loaded and verified against a real MariaDB 10.11 instance (`docs/PHASE_F_TESTS.md`) |
| C — PHP backend | `services/*.php`, `public/index.php` | **Core slice done** — auth, items/warehouses/suppliers/divisions read, Transaksi Masuk/Keluar (full FIFO + unit conversion + negative-stock guard + price-anomaly guard + idempotency). Transfers/Opname/Production/Book-closing/Reports are designed in the schema but have no endpoint yet — see the follow-up list below. |
| D — Frontend adapter | `public/assets/js/api-client.js`, `docs/PHASE_D_MIGRATION_GUIDE.md` | **Not done** — the 11,810-line `inventory.html` has not been rewired. The migration guide is a concrete, line-referenced punch list, not a claim that it's finished. |
| E — Import module | `services/ImportMasterItemService.php`, `templates/*.csv` | **Master Barang importer done and tested** (staging → validate → commit, all-or-nothing on ERROR). Supplier/Division/Warehouse/Opening/Historical importers follow the same pattern but are not yet written. |
| F — Tests | `tests/run.php`, `tests/import_test.php`, `docs/PHASE_F_TESTS.md` | **Done for what exists** — 22/22 assertions pass. Run against SQLite because no MySQL server is reachable in this sandbox (see `docs/PHASE_F_TESTS.md` for why, and re-run against real MySQL before go-live). |

**Read `docs/PHASE_D_MIGRATION_GUIDE.md` before assuming the frontend talks
to MySQL today — it does not yet.** The legacy `inventory.html` still runs
exactly as before (Google Sheets sync included) until that work is done;
per the deployment requirement, it also keeps running unmodified on the
existing domain regardless, since the two systems are meant to operate
side by side during development.

## File tree

```
/api            (reserved — current routes live inline in public/index.php;
                  split into per-resource files here as the surface grows)
/config          config.php (env loader), .env.example
/database        schema.sql
/docs             PHASE_A_ANALYSIS.md, ERD.md, DEPLOYMENT.md,
                  LEGACY_DEPENDENCIES.md, PHASE_D_MIGRATION_GUIDE.md,
                  PHASE_F_TESTS.md
/migration        install.php (creates the first SUPERADMIN user)
/public           index.php (API front controller), .htaccess,
                  assets/js/api-client.js
/services         Database, AuthService, UnitConversionService,
                  PriceAnomalyService, IdempotencyService, FifoService,
                  ImportMasterItemService, AuditService, Exceptions
/templates        master_items.csv, suppliers.csv, divisions.csv,
                  warehouses.csv, opening_stock.csv, historical_transactions.csv
                  (headers + one dummy example row each — no real data)
/tests            run.php, import_test.php, sqlite_schema.sql
```

`inventory.html` and `trace-stok-awal.js` at the repo root are the legacy
system, kept as the reference implementation per the brief — not deleted,
not modified beyond what they already were.

## Running the tests

```bash
php tests/run.php              # FIFO costing, unit conversion, idempotency, price anomaly, negative-stock block (SQLite)
php tests/import_test.php      # Master Barang import staging/validation/commit (SQLite)
php tests/mysql_smoke_test.php # same FIFO engine against a real MySQL/MariaDB — requires a configured .env
```

All three exit non-zero on any failure (suitable for CI). The first two
need no server; `schema.sql` itself has also been loaded and exercised
against a real MariaDB 10.11 instance — see `docs/PHASE_F_TESTS.md` for
what that proved (generated-column uniqueness, FK enforcement, real
`FOR UPDATE` locking, real transaction rollback).

## Deploying

See `docs/DEPLOYMENT.md` — covers the new-domain/new-database separation
from the existing system, `.env` configuration, session/cookie/CORS
hardening, and the parallel-run → reconciliation → go-live checklist.

## What was deliberately not built yet (so it isn't mistaken for "done")

- Warehouse Transfer, Stock Opname, Production/Racik, Book Closing, and
  Reports API endpoints — the schema and FIFO primitives they'd compose
  (`FifoService::postIn/postOut`) are ready; the endpoints themselves are
  the next slice of Phase C.
- Supplier/Division/Warehouse/Opening-Stock/Historical-Transaction
  importers — same staging pattern as `ImportMasterItemService`, not yet
  duplicated for the other five templates.
- The frontend adapter (Phase D) — `inventory.html` still runs entirely
  against `localStorage` + Google Apps Script today.
- OCR/invoice-scan backend — explicitly out of scope; see
  `docs/LEGACY_DEPENDENCIES.md`.
