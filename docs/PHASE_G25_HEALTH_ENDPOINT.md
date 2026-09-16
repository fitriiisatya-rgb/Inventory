# Phase G25 — System Health Endpoint

`GET /system/health` — restricted to `ADMIN`/`SUPERADMIN` (checked by role
directly, not a permission code, matching the spec's "Role:
ADMIN/SUPERADMIN"). Read-only; every field is a live query against the
same tables the rest of the app reads.

## Response fields

| Field | Source | Notes |
|---|---|---|
| `db_connection.ok` / `.error` | `SELECT 1` against the live PDO connection | If this endpoint responds at all the connection is almost certainly fine, but the explicit check also catches the (rare) case of a connection that was live when the request started but died mid-request. |
| `last_successful_transaction` | latest `POSTED` row in `inventory_transactions` | `{id, transaction_type, transaction_date, created_at}` or `null` if none exist. |
| `failed_requests_today` | **not tracked** | See below. |
| `price_anomaly_count` | count of `inventory_transaction_lines.is_price_anomaly = 1` | Every one of these was already reviewed and approved at post time (a `PRICE_ANOMALY` error blocks the post otherwise) — this is a "how many overrides has anyone used, ever" signal, not a current alert. |
| `pending_transfer_count` | `warehouse_transfers.status = 'PENDING'` | Same figure `InventoryService::inTransitValue()` sums against. |
| `active_opname_count` | `stock_opname_sessions.status IN ('OPEN','FINALIZED')` | A non-zero count here means at least one warehouse is currently movement-locked. |
| `negative_stock_count` | same query as Reconciliation's `negative_stock` check | Should always be 0 — any hit here is worth investigating immediately, same severity as a reconciliation ERROR. |
| `zero_cost_batch_count` | same query as Reconciliation's `zero_cost_batch` check | A batch with positive quantity and zero cost understates inventory value. |
| `checked_at` | server timestamp at query time | For a monitoring caller to detect a stale/cached response. |

## `failed_requests_today` — known gap, not fabricated

This codebase has no request-level access log or table — `public/index.php`
doesn't record a row per request, only domain events go through
`AuditService`. Rather than invent a number from an unrelated proxy metric
(that would be worse than no number — a false signal), this field is
always `null`. Adding real support would mean either:

- a lightweight `request_log` table written on every 4xx/5xx response in
  `public/index.php`'s error handler, or
- parsing the web server's own access log (Apache/php-built-in-server) out
  of band.

Neither is built in this batch — flagged here as explicit follow-up work
for whoever owns post-go-live monitoring, not silently left unmentioned.

## Verified in this session

`GET /system/health` was tested via curl against the sandbox database:
returns all fields correctly for a `SUPERADMIN` session (`db_connection.ok:
true`, all counts `0` on the then-empty test database), and returns `403
FORBIDDEN` for a `STOCK`-role session.
