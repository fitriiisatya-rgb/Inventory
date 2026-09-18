# API Contract (FROZEN — PHASE D0.3)

This is the frozen contract the frontend (Phase D) is built against. **Do
not change response shape or error codes during Phase D without updating
this document first** — the frontend is instructed to branch on
`error.code`, never on `error.message` text, specifically so this contract
can stay stable while wording improves.

Machine-readable version: [`openapi.yaml`](./openapi.yaml) (covers the same
endpoints; this file carries the narrative detail — permissions, business
rules, worked examples — the OpenAPI spec doesn't).

## Response envelope

Every response is JSON with this exact top-level shape:

**Success**
```json
{ "success": true, "data": { ... }, "message": "human-readable status" }
```

**Error**
```json
{ "success": false, "error": { "code": "INSUFFICIENT_STOCK", "message": "human-readable detail" } }
```

`data` is `null` for actions with nothing to return (e.g. logout). `error.code`
is always one of the codes below — new codes may be added over time, but an
existing code's *meaning* never changes without a version bump to this file.

## Standard error codes

| Code | HTTP | Meaning |
|---|---|---|
| `UNAUTHENTICATED` | 401 | No valid session. |
| `FORBIDDEN` | 403 | Authenticated, but missing permission or outside role scope (wrong warehouse/division). |
| `CSRF_INVALID` | 403 | Missing/incorrect `X-CSRF-Token` header on a mutating request. |
| `VALIDATION_ERROR` | 422 | Malformed or incomplete request body (missing required field, bad type, business-rule violation not covered by a more specific code below). |
| `NOT_FOUND` | 404 | Referenced entity (by id or natural key) does not exist. |
| `DUPLICATE_REQUEST` | 409 | Two concurrent requests raced with the same idempotency key; a normal retried request with a *previously-completed* key instead gets a `success:true` idempotent response, not this. |
| `PERIOD_LOCKED` | 423 | `transaction_date` (or the original transaction being voided) falls inside a LOCKED book-closing period. |
| `NEGATIVE_STOCK` | 422 | A stock adjustment/correction would take stock below zero and no override was granted. |
| `PRICE_ANOMALY` | 422 | Unit cost is outside the configured reference-price band (Section 9) and wasn't explicitly approved. |
| `COST_REQUIRED` | 422 | A stock-increasing adjustment has no reliable cost and none was supplied — never defaulted to a fake value. |
| `UNIT_CONVERSION_NOT_APPROVED` | 422 | PHASE G-DATA 2: a transaction was posted in a unit with no active, approved `item_unit_conversions` row for that item as of the transaction date. Post in the item's base unit (`GET /items/{id}/units` lists only approved units) or get the conversion approved first — never guessed. Opening stock is unaffected: it always posts directly in the item's Global Base Unit and never needs a purchase-unit conversion to succeed. |
| `NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT` | 422 | POLICY CORRECTION: this item+warehouse is on the owner-approved migration-negative whitelist (`GET /migration-negative-review`) and current stock is already `<= 0`. OUT, TRANSFER_OUT, and PRODUCTION_IN (raw-material consumption) are all blocked — FIFO available quantity is clamped to zero, no further negative FIFO batch is created. IN, Stock Opname, and Stock Adjustment remain allowed; resolve via one of those. |
| `OPNAME_ACTIVE` | 423 | The target warehouse has an active Stock Opname session (OPEN/FINALIZED) blocking movement. |
| `TRANSFER_ALREADY_RECEIVED` | 409 | A **new** (non-retried) attempt to receive a transfer that's already RECEIVED. |
| `TRANSFER_ALREADY_CANCELLED` | 409 | A **new** (non-retried) attempt to cancel a transfer that's already CANCELLED. |
| `INSUFFICIENT_STOCK` | 422 | A plain OUT/TRANSFER_OUT/PRODUCTION_IN transaction would take stock below zero and no override was granted. |
| `IMPORT_VALIDATION_FAILED` | 422 | An import batch/commit has ERROR rows and was rejected (all-or-nothing). |
| `RATE_LIMITED` | 429 | Too many failed login attempts (5 / 15 min per username). Not in the brief's minimal list but required for login — documented here rather than silently added. |
| `PASSWORD_CHANGE_REQUIRED` | 403 | The authenticated account has `must_change_password=1` (Phase G21 provisioning) and is calling any endpoint other than `POST /auth/change-password`. |
| `INTERNAL_ERROR` | 500 | Unhandled server error. Logged server-side; never leaks a stack trace to the client. |

`INSUFFICIENT_STOCK` vs `NEGATIVE_STOCK`: both mean "would go below zero
without an override," but come from different call sites — a plain
Transaksi Keluar/Transfer/Production uses `INSUFFICIENT_STOCK`; a Stock
Adjustment/Opname correction uses `NEGATIVE_STOCK`. The frontend should
treat them identically (show the shortfall, offer the override flow if the
user's role allows it) unless it specifically needs to distinguish the two
contexts.

## Auth & session

All endpoints except `POST /auth/login` require a valid session cookie
(`inv_session`, `Secure`+`HttpOnly`+`SameSite=Strict`). All mutating
requests (non-GET) from an authenticated session require header
`X-CSRF-Token: <token>`, where `<token>` comes from the `csrf_token` field
returned by `login` or `GET /auth/me`.

| Method | Path | Auth | Request | Response `data` |
|---|---|---|---|---|
| POST | `/auth/login` | none | `{username, password}` | `{username, role, must_change_password, csrf_token}` |
| POST | `/auth/logout` | session | — | `null` |
| GET | `/auth/me` | none (returns `null` if not logged in) | — | `{id, username, role_code, division_id, warehouse_id, must_change_password, csrf_token}` or `null` |
| POST | `/auth/change-password` | session (the ONE endpoint allowed while `must_change_password=1`) | `{current_password, new_password}` | `null` |

Errors: `UNAUTHENTICATED` (invalid credentials, code 401), `RATE_LIMITED`.

### Forced password change (Phase G21)

An account created by `scripts/provision_user.php` starts with
`must_change_password=1` and a temporary generated password. While that
flag is set, **every** endpoint except `POST /auth/change-password` (and
`GET /auth/me` / `POST /auth/logout`, which never required auth to begin
with) returns `403 PASSWORD_CHANGE_REQUIRED` — enforced once, centrally,
in `inv_require_auth()`, so no individual route needs its own check. The
frontend should treat `must_change_password: true` on login/`/auth/me` as
an immediate redirect to a change-password screen, not wait for a blocked
call to discover it.

## Master data (read-only for all roles with `INVENTORY_VIEW` or higher)

| Method | Path | Permission | Response `data` |
|---|---|---|---|
| GET | `/items` | any authenticated user | array of item rows |
| GET | `/warehouses` | any authenticated user | array of warehouse rows |
| GET | `/suppliers` | any authenticated user | array of supplier rows |
| GET | `/divisions` | any authenticated user | array of division rows |
| GET | `/items/{id}/units` | any authenticated user | array of `{id, code, name, conversion_to_base, is_purchase_default}` — this item's currently-open unit conversions (base unit included), for populating a transaction form's unit dropdown. Added in Phase D5/D6. |

## Inventory (InventoryService — single source of truth, Section 7)

| Method | Path | Query params | Response `data` |
|---|---|---|---|
| GET | `/inventory/current` | `item_id, warehouse_id` | `{qty_base, value}` |
| GET | `/inventory/current/{sku}` | `warehouse_id` (optional) | with `warehouse_id`: `{qty_base, value}`; without: `{by_warehouse: [...], total: {...}}` |
| GET | `/inventory/batches` | `item_id, warehouse_id` | array of live batch rows (FIFO order) |
| GET | `/inventory/value` | — | `{on_hand_value, in_transit_value, total_value}` (company-wide) |
| GET | `/inventory/in-transit` | — | `{in_transit_value}` |
| GET | `/inventory/ledger` | `item_id, warehouse_id` | array of `{date, reference, transaction_type, transaction_id, is_historical, in_qty, out_qty, balance_qty, historical_running_balance, unit_cost_base, value, notes}`, chronological. POLICY CORRECTION: now includes historical-import rows (`is_historical_import=1`) inline (`is_historical=true`), each accumulating its own `historical_running_balance` — `balance_qty` (the live/FIFO balance) is updated only by real postings and never moves on a historical row. There is currently no separate warehouse-wide or daily-movement-report endpoint — only this per-item-per-warehouse ledger. |

Errors: `NOT_FOUND` (`/inventory/current/{sku}` with unknown SKU).

## Transactions

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/transactions/in` | `TRANSACTION_IN_CREATE` (+ warehouse scope) | `{transaction_uuid, item_id, warehouse_id, input_qty, input_unit_id, unit_price_input, transaction_date, supplier_id?, reference_no?, anomaly_approved_by?}` | `{transaction_id, line_id, batch_id, base_qty, unit_cost_base}` |
| POST | `/transactions/out` | `TRANSACTION_OUT_CREATE` (+ warehouse/division scope); `+STOCK_ALLOW_NEGATIVE` if `allow_negative_stock` | `{transaction_uuid, item_id, warehouse_id, input_qty, input_unit_id, transaction_date, division_id?, allow_negative_stock?, negative_stock_reason?}` | `{transaction_id, line_id, base_qty, unit_cost_base, total_cost}` |
| POST | `/transactions/{id}/void` | `TRANSACTION_VOID` (+ `TRANSACTION_VOID_LOCKED_PERIOD` if `superadmin_override:true`) | `{request_uuid, reason, superadmin_override?}` | `{original_transaction_id, reversal_transaction_id, reversed_base_qty}` |

Errors: `VALIDATION_ERROR`, `PERIOD_LOCKED`, `OPNAME_ACTIVE`,
`PRICE_ANOMALY` (IN only), `INSUFFICIENT_STOCK` (OUT only), `NOT_FOUND`
(void: unknown transaction id), `FORBIDDEN` (void: voiding is only
supported for IN/OUT/ADJUSTMENT types — anything else responds
`VALIDATION_ERROR` naming the unsupported type).

Idempotency: `transaction_uuid`/`request_uuid` must be a client-generated
UUID v4. Resubmitting the same value after a network failure returns
`success:true` with the original result — never a duplicate.

## Transfers (Section 1)

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/transfers` | `WAREHOUSE_TRANSFER_MANAGE` (+ source-warehouse scope) | `{transfer_uuid, from_warehouse_id, to_warehouse_id, ship_date, lines: [{item_id, input_qty, input_unit_id}], allow_negative_stock?}` | `{transfer_id}` |
| POST | `/transfers/{id}/receive` | `WAREHOUSE_TRANSFER_MANAGE` | `{request_uuid?, receive_date?}` | `{transfer_id, lines_received}` |
| POST | `/transfers/{id}/cancel` | `WAREHOUSE_TRANSFER_MANAGE` | `{request_uuid?, reason}` | `{transfer_id}` |
| GET | `/transfers` | any authenticated user | — | array of all transfers |
| GET | `/transfers/pending` | any authenticated user | — | array of PENDING transfers |
| GET | `/transfers/{id}` | any authenticated user | — | transfer header + lines |

Errors: `VALIDATION_ERROR`, `PERIOD_LOCKED`, `INSUFFICIENT_STOCK` (create),
`NOT_FOUND`, `TRANSFER_ALREADY_RECEIVED`, `TRANSFER_ALREADY_CANCELLED`.
`receive`/`cancel` are idempotent only when `request_uuid` matches the
value used on the call that actually changed the transfer's state — pass
the SAME `request_uuid` on a retry, a fresh one otherwise.

## Stock Opname (Section 2)

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/stock-opname` | `STOCK_OPNAME_MANAGE` (+ warehouse scope) | `{warehouse_id, item_ids?}` | `{session_id}` |
| GET | `/stock-opname` | any authenticated user | query: `warehouse_id?, status?` | array of session rows (no lines) — used to discover an already-active session for a warehouse before starting a new one. Added in Phase D9. |
| GET | `/stock-opname/{id}` | any authenticated user | — | session + lines |
| POST | `/stock-opname/{id}/count` | `STOCK_OPNAME_MANAGE` | `{counts: [{item_id, counted_qty_base}]}` | session + lines |
| POST | `/stock-opname/{id}/finalize` | `STOCK_OPNAME_MANAGE` | — | session + lines (with `variance_qty_base`, `cost_required`) |
| POST | `/stock-opname/{id}/post` | `STOCK_OPNAME_MANAGE` | `{cost_overrides?: {item_id: cost}}` | `{session_id, status, adjustments: [...]}` |

Errors: `VALIDATION_ERROR` (wrong session state, uncounted lines),
`NOT_FOUND`, `COST_REQUIRED` (post, IN variance with no reliable cost and
no override supplied). While a session is OPEN/FINALIZED, every
transaction endpoint against that warehouse returns `OPNAME_ACTIVE`.

## Stock Adjustments (Section 3)

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/stock-adjustments` | `STOCK_ADJUSTMENT_CREATE` (+ warehouse scope) | `{transaction_uuid, item_id, warehouse_id, qty_base_delta, adjustment_type, reason, reference_no?, override_cost_base?, transaction_date?, migration_issue_reference?}` | `{adjustment_id, transaction_id, before_qty_base, after_qty_base, unit_cost_base}` |

`adjustment_type` ∈ `OPNAME, CORRECTION, DAMAGE, EXPIRED, LOSS, OTHER,
NEGATIVE_OVERRIDE`. `reason` is always required — there is no silent
adjustment path. Errors: `VALIDATION_ERROR`, `PERIOD_LOCKED`,
`OPNAME_ACTIVE`, `COST_REQUIRED` (positive delta, no cost available),
`NEGATIVE_STOCK` (negative delta exceeding available stock).

## Production / Racik (Section 4)

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/production` | `PRODUCTION_MANAGE` (+ warehouse/division scope) | `{production_uuid, warehouse_id, production_date, inputs: [{item_id, input_qty, input_unit_id}], output: {item_id, output_qty, output_unit_id?}, division_id?}` | `{production_id, total_input_cost, inputs: [...], output_qty_base, output_unit_cost_base}` |
| GET | `/production/{id}` | any authenticated user | — | header + inputs + outputs |

Single output only (matches the existing system — Phase A analysis).
Errors: `VALIDATION_ERROR`, `PERIOD_LOCKED`, `OPNAME_ACTIVE`,
`INSUFFICIENT_STOCK` (raw material), `NOT_FOUND`.

## Book Closing (Section 5 / D0.4)

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/book-closing/preview` | `BOOK_CLOSING_MANAGE` | `{period_start, period_end}` | `{period_start, period_end, next_closeable_period_start, ending_inventory_value, in_transit_value, total_company_value, purchase_total, usage_total, shrinkage_total, blockers: [...], can_close}` |
| POST | `/book-closing/close` | `BOOK_CLOSING_MANAGE` | `{period_start, period_end}` | same shape as preview, plus `{book_closing_id, closed_by, closed_at}` |
| GET | `/book-closing` | any authenticated user | — | array of all closings |
| GET | `/book-closing/next-closeable` | any authenticated user | — | `{next_closeable_period_start}` |

The frontend must call `/book-closing/next-closeable` (or read
`next_closeable_period_start` off `preview`) and only ever offer **that**
period to close — never let the user type an arbitrary `period_start`.
`close()` itself also enforces this server-side regardless of what the UI
sends. Errors: `VALIDATION_ERROR` (blocked by pending transfers, active
opname, out-of-order period, or later-dated data already posted).

## Reconciliation (Section E3)

| Method | Path | Permission | Response `data` |
|---|---|---|---|
| GET | `/reconciliation` | `RECONCILIATION_VIEW` | `{total_sku, sku_with_stock, qty_per_warehouse, value_per_warehouse, company_inventory_value, in_transit_value, company_total_value, checks: {check_name: {status, count, rows}}, go_live_ready}` |

`go_live_ready` is `false` if **any** check's `status` is `ERROR` — no
partial-pass reading. Current `checks` keys: `negative_stock`,
`zero_cost_batch`, `abnormal_cost_batch`, `orphan_batch`, `duplicate_sku`,
`unknown_sku_or_warehouse`, `unbalanced_fifo_allocation` (Phase E3), plus
the Phase G14 pre-cutover checks: `opening_value_consistency`,
`historical_inventory_effect_zero`, `missing_unit_conversion`,
`missing_cost`, `duplicate_legacy_transaction`,
`opening_vs_current_consistency`.

## System Health (Phase G25 — post-go-live monitoring)

| Method | Path | Permission | Response `data` |
|---|---|---|---|
| GET | `/system/health` | role ADMIN or SUPERADMIN only (not a permission code — checked directly) | `{db_connection: {ok, error}, last_successful_transaction, failed_requests_today, price_anomaly_count, pending_transfer_count, active_opname_count, negative_stock_count, zero_cost_batch_count, checked_at}` |

`failed_requests_today` is always `null` — this codebase has no
request-level access log to count against; see
`docs/PHASE_G25_HEALTH_ENDPOINT.md` for the reasoning and what it would
take to add.

## Import module (Section 13-17 / E2)

All `/import/*/stage` endpoints currently take `file_path` (a path already
on the server's disk) rather than a multipart upload — see
`docs/PHASE_C2_ENDPOINTS.md` for this documented gap (Phase D12 will close it).

| Method | Path | Permission | Request | Response `data` |
|---|---|---|---|---|
| POST | `/import/master-item/stage` | `IMPORT_MANAGE` | `{file_path, file_name?}` | `{import_batch_id}` |
| POST | `/import/master-item/{id}/commit` | `IMPORT_MANAGE` | — | `{imported, skipped}` |
| POST | `/import/{supplier\|division\|warehouse}/stage` | `IMPORT_MANAGE` | `{file_path, file_name?}` | `{import_batch_id}` |
| POST | `/import/{supplier\|division\|warehouse}/{id}/commit` | `IMPORT_MANAGE` | — | `{imported, skipped}` |
| POST | `/import/opening-stock/stage` | `IMPORT_MANAGE` | `{file_path, file_name?}` | `{stock_opening_id}` — PHASE G-DATA 2: accepts `final_opening_stock_template.xlsx` directly (sniffed by `file_name` extension) as well as the original CSV format |
| POST | `/import/opening-stock/{id}/commit` | `IMPORT_MANAGE` | — | `{imported}` — creates REAL batches, `inventory_effect=1` |
| GET | `/import/opening-stock/{id}/reconciliation` | any authenticated | — | PHASE G-DATA 2 Section 12: the GO_LIVE_READY checklist (`negative_qty` — unknown/unapproved negative rows only, `missing_cost`, `unknown_sku`, `unknown_warehouse`, `base_unit_mismatch`, `duplicate_opening`, `error_rows`, `opening_control_total_match`, `current_stock_equals_opening`, `historical_inventory_effect_zero`, `go_live_ready`), plus `migration_negative_count`/`migration_negative_rows` (POLICY CORRECTION: owner-approved migration-negative rows — ALLOW_WITH_WARNING, never block `go_live_ready`) — read-only, never mutates |
| GET | `/movement-reconciliation-reviews` | any authenticated | — | PHASE G-DATA 2 Section 11: the historical movement-vs-final-stock evidence rows — informational only, kept separate from unit-conversion and opening questions, never alters final opening |
| GET | `/migration-negative-review` | any authenticated | — | POLICY CORRECTION: the owner-approved migration-negative whitelist (5 SKU+warehouse rows), each with live current balance, `status` (`MIGRATION_NEGATIVE_REVIEW`/`RESOLVED`), `needs_stock_opname`, `migration_issue_reference`, and the `historical_*` Opening+IN/OUT evidence to drill back into. Same flags (`migration_negative_review`, `needs_stock_opname`, `migration_issue_reference`) are also returned inline on every `GET .../stock` response (`InventoryService::currentStock`/`currentStockAllWarehouses`) so the dashboard, stock list, and SKU detail screens need no separate lookup |
| POST | `/import/historical/stage` | `IMPORT_MANAGE` | `{file_path, file_name?}` | `{import_batch_id}` |
| POST | `/import/historical/{id}/commit` | `IMPORT_MANAGE` | — | `{imported}` — `is_historical_import=1, inventory_effect=0`, never touches stock |

Errors: `IMPORT_VALIDATION_FAILED` (any ERROR row in the batch — rejected
all-or-nothing), `NOT_FOUND` (unknown batch id), `VALIDATION_ERROR`
(unknown `{type}` segment).

## Role → permission quick reference

| Role | Scope |
|---|---|
| `VIEWER` | GET-only (`INVENTORY_VIEW`, `AUDIT_LOG_VIEW`, `RECONCILIATION_VIEW`) — every mutating route responds `FORBIDDEN` regardless of what the UI shows. |
| `STOCK` | Transaction/Transfer/Opname/Adjustment create, scoped to `users.warehouse_id` if set (NULL = all warehouses). A request naming a different `warehouse_id` gets `FORBIDDEN`. |
| `DIVISION` | OUT transactions + Production, scoped to `users.division_id` if set. |
| `ADMIN` | All operational permissions except `USER_MANAGE`, `SYSTEM_SETTINGS_MANAGE`, `TRANSACTION_VOID_LOCKED_PERIOD`. |
| `SUPERADMIN` | Every permission, including voiding a transaction inside an already-LOCKED period. |

Server-side enforcement is authoritative — hiding a menu item client-side
is a UX nicety only; every one of the above is re-checked on the server
regardless of what the browser sends (Section D1: "server tetap harus
reject jika dipanggil manual").
