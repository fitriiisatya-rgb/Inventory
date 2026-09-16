# API Endpoint Inventory (post PHASE C2/C3/E2/E3)

Status legend: **LIVE** = implemented, wired in `public/index.php`, exercised
by a passing test. **SERVICE ONLY** = the business logic exists and is
tested, but no HTTP route calls it yet. **NOT BUILT** = neither exists.

| Method | Path | Status | Backing service |
|---|---|---|---|
| POST | /auth/login | LIVE | AuthService (rate-limited, issues CSRF token) |
| POST | /auth/logout | LIVE | AuthService |
| GET | /auth/me | LIVE | AuthService |
| GET | /items | LIVE | (inline query) |
| GET | /warehouses | LIVE | (inline query) |
| GET | /suppliers | LIVE | (inline query) |
| GET | /divisions | LIVE | (inline query) |
| GET | /inventory/current | LIVE | InventoryService::currentStock |
| GET | /inventory/current/{sku} | LIVE | InventoryService::currentStock / currentStockAllWarehouses |
| GET | /inventory/batches | LIVE | InventoryService::batches |
| GET | /inventory/value | LIVE | InventoryService::companyTotalValue |
| GET | /inventory/in-transit | LIVE | InventoryService::inTransitValue |
| GET | /inventory/ledger | LIVE | InventoryService::ledger |
| POST | /transactions/in | LIVE | FifoService::postIn |
| POST | /transactions/out | LIVE | FifoService::postOut |
| POST | /transfers | LIVE | TransferService::create |
| POST | /transfers/{id}/receive | LIVE | TransferService::receive |
| POST | /transfers/{id}/cancel | LIVE | TransferService::cancel |
| GET | /transfers | LIVE | TransferService::listAll |
| GET | /transfers/pending | LIVE | TransferService::listPending |
| GET | /transfers/{id} | LIVE | TransferService::get |
| POST | /stock-opname | LIVE | StockOpnameService::start |
| GET | /stock-opname/{id} | LIVE | StockOpnameService::get |
| POST | /stock-opname/{id}/count | LIVE | StockOpnameService::count |
| POST | /stock-opname/{id}/finalize | LIVE | StockOpnameService::finalize |
| POST | /stock-opname/{id}/post | LIVE | StockOpnameService::post |
| POST | /stock-adjustments | LIVE | StockAdjustmentService::post |
| POST | /production | LIVE | ProductionService::create |
| GET | /production/{id} | LIVE | ProductionService::get |
| POST | /book-closing/preview | LIVE | BookClosingService::preview |
| POST | /book-closing/close | LIVE | BookClosingService::close |
| GET | /book-closing | LIVE | BookClosingService::listAll |
| GET | /reconciliation | LIVE | ReconciliationService::run |
| POST | /import/master-item/stage | LIVE (path-based, no multipart upload — see note) | ImportMasterItemService::stage |
| POST | /import/master-item/{id}/commit | LIVE | ImportMasterItemService::commit |
| POST | /import/{supplier\|division\|warehouse}/stage | LIVE | ImportSimpleMasterService::stage |
| POST | /import/{supplier\|division\|warehouse}/{id}/commit | LIVE | ImportSimpleMasterService::commit |
| POST | /import/opening-stock/stage | LIVE | ImportOpeningStockService::stage |
| POST | /import/opening-stock/{id}/commit | LIVE | ImportOpeningStockService::commit |
| POST | /import/historical/stage | LIVE | ImportHistoricalTransactionService::stage |
| POST | /import/historical/{id}/commit | LIVE | ImportHistoricalTransactionService::commit |
| GET | /import/{id} (preview a staged batch's rows before commit) | **NOT BUILT** | — |
| GET | /reports/stock, /reports/movement (Section 24's original sketch) | **NOT BUILT** — superseded by /inventory/current, /inventory/ledger, /reconciliation, which cover the same ground | — |
| Void/reverse a posted transaction | **SERVICE ONLY (partial)** — `inventory_transactions.status` supports VOID/REVERSED and TransferService::cancel demonstrates the pattern (restore batches, mark REVERSED); a generic `POST /transactions/{id}/void` endpoint for arbitrary IN/OUT transactions is **NOT BUILT** | — |

## Important caveat: file upload

None of the `/import/*/stage` routes implement actual multipart file upload
handling in this pass — they accept a `file_path` that must already exist
on the server's disk. A real upload endpoint (e.g. `multipart/form-data` →
save to a controlled directory → call `stage()` with that path) is
straightforward to add on top of this, but was not built here; say so
explicitly rather than implying drag-and-drop upload works today.

## Server-side enforcement summary (so this isn't just a route list)

- **Auth**: `password_hash()`/`password_verify()`, PHP session with
  `Secure`+`HttpOnly`+`SameSite=Strict` cookie, CSRF synchronizer token
  required on every mutating request, login rate-limited (5 failures /
  15 min per username, backed by a real `login_attempts` table — verified
  live via curl: 4×401 then 429 once the threshold was hit).
- **Authorization**: every mutating route calls `inv_require_permission()`
  (role→permission via `role_permissions`), plus `inv_require_warehouse_scope()`
  / `inv_require_division_scope()` for STOCK/DIVISION-role users bound to one
  warehouse/division (`users.warehouse_id`/`division_id`).
- **Period lock**: `PeriodLockService::assertNotLocked()` runs inside
  `FifoService::postIn`/`postOut` (and therefore inside every Transfer/
  Production/Adjustment call that composes them) — impossible to bypass by
  calling a "higher" service.
- **Warehouse lock**: `WarehouseLockService::assertNotLocked()`, same
  placement — an active Stock Opname session blocks movement in that one
  warehouse only.
- **Idempotency**: every posting service takes a `*_uuid` and checks
  `IdempotencyService`/`findByUuid` first; StockOpnameService's per-line
  adjustments derive a deterministic key (`session_uuid:item_id`) so
  re-posting an already-POSTED session is a safe no-op, not a duplicate.
