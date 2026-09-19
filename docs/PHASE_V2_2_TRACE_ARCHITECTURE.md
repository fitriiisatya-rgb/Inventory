# Phase V2.2 — Trace Architecture

## Audit finding (done before any new code was written)

`audit_logs` already exists (Phase D13) with exactly the shape a generic
trace/audit event needs:

```
id, user_id, username_snapshot, action_code, entity_type, entity_id,
ip_address, before_data (JSON), after_data (JSON), reason, created_at
INDEX (entity_type, entity_id), INDEX (action_code), INDEX (created_at)
```

`AuditService::log()` is already called on every mutating master-data,
transaction, transfer, opname, production, closing, and import action —
34+ distinct action codes were in use before this phase (grepped from
`public/index.php` and `services/*.php`). This is reused as-is; **no new
audit table was created.**

Separately, the core transaction/FIFO schema already carries every
correlation a "chain trace" needs, as real foreign keys — found by reading
`database/schema.sql`, not assumed:

| Relationship | Column |
|---|---|
| Batch → its creating transaction line | `inventory_batches.source_transaction_line_id` |
| Transaction line → the batch it created | `inventory_transaction_lines.created_batch_id` |
| FIFO allocation → consuming line / consumed batch | `fifo_allocations.transaction_line_id` / `.batch_id` |
| Reversal ↔ original transaction | `inventory_transactions.reversal_of_id` (+ reverse lookup) |
| Transfer ↔ its OUT/IN transaction lines | `warehouse_transfer_lines.out_transaction_line_id` / `.in_transaction_line_id`, and `inventory_transactions.reference_no = 'TRANSFER-{id}'` |
| Production output ↔ its PRODUCTION_OUT line | `production_outputs.transaction_line_id` |
| Stock adjustment ↔ its ADJUSTMENT transaction | `stock_adjustments.transaction_id` |
| Stock policy who/when | `item_warehouse_stock_policy.created_by/updated_by/created_at/updated_at`, plus `audit_logs` (`entity_type='item_warehouse_stock_policy'`) for full before/after history |
| Historical import identification | `inventory_transactions.is_historical_import` / `.inventory_effect` |

## Decision: no new tables, no migration

Because every correlation above is already a real FK, `TraceService`
(`services/TraceService.php`) was built as a **read-only aggregation
service** over these existing tables plus `audit_logs` — not a new
`trace_event` table. This is deliberately the smaller, lower-risk design:
zero migration risk against production, and no duplicate/divergent source
of truth for data that's already correctly modeled.

**Known limitation:** `audit_logs` has no `warehouse_id` column, so the
Trace Center's Audit Events browser (`GET /trace/events`) can't filter by
warehouse directly — only by entity_type/action_code/actor/date. Warehouse
isolation IS enforced everywhere it actually matters: `GET /trace/entity`
(for `warehouse`/`stock_policy` types), `GET /trace/transaction/{id}`, and
`GET /trace/inventory` all re-derive the caller's warehouse scope from
their own account server-side (`inv_require_warehouse_scope`), never from
the request. If a future phase needs to filter the raw audit_logs feed by
warehouse, that would need an additive `warehouse_id` column with a
backfill strategy for existing rows — deliberately not attempted here to
avoid a migration for a browse-list convenience feature.

## API surface (all read-only, all gated on `AUDIT_LOG_VIEW`)

- `GET /trace/search?q=&type=&limit=` — whitelisted per-entity-type
  lookups (item, transaction, supplier, bakery_destination, category,
  warehouse, transfer, user), never a single generic cross-table query.
- `GET /trace/events?...` — paginated, filterable `audit_logs` browser.
- `GET /trace/entity?type=&id=` — current row + full audit timeline for
  item/warehouse/division/supplier/bakery_destination/category/stock_policy.
- `GET /trace/transaction/{id}` — header, lines, FIFO batches
  created/consumed, reversal link (both directions), transfer/production/
  adjustment cross-reference, audit timeline.
- `GET /trace/inventory?item_id=&warehouse_id=` — current stock + policy,
  paginated movement timeline (every transaction line touching this
  item+warehouse), FIFO batches.

## Never fabricates history

Every render path shows **"Data historis tidak merekam informasi ini."**
when a timeline is empty, rather than inventing a create event or actor
that was never actually logged. Verified in `tests/trace_test.php`
("warehouse entity trace with no audit history yet has an empty (not
fabricated) timeline") and in browser testing (a never-edited item's
Timeline tab shows exactly this message).

## Read-only guarantee

`tests/trace_test.php` section G snapshots `inventory_batches`,
`fifo_allocations`, transaction count, the stock policy row, and
`audit_logs` count before and after calling every `TraceService` method
several times, and asserts byte-identical state. Every route is `GET`
only; `TraceService` contains no `INSERT`/`UPDATE`/`DELETE` statement.

## Coverage in this phase vs. deferred

Built and tested this phase: Item, Warehouse, Division, Supplier, Bakery
Destination, Category, Stock Policy (all via `entityTrace`), Transaction
(IN/OUT/TRANSFER_OUT/TRANSFER_IN/ADJUSTMENT/PRODUCTION_IN/PRODUCTION_OUT/
REVERSAL — all post through `inventory_transactions`, so one trace method
covers all of them), FIFO Batch, FIFO Allocation, Inventory (item ×
warehouse), Historical Import (surfaced via the transaction's own
`is_historical_import` flag).

Deferred (see the deliverable report's coverage matrix and known
limitations): a dedicated Transfer-header trace view and Opname/Production
-header trace views distinct from their underlying transactions; User/Role
trace UI; warehouse-filterable Audit Events browsing.
