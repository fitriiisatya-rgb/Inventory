# Phase V2.2B — Trace Coverage Completion

Follow-up to `docs/PHASE_V2_2_TRACE_ARCHITECTURE.md`. That phase shipped
Item/Warehouse/Division/Supplier/Bakery Destination/Category/Stock Policy/
Transaction/Inventory trace, but left Transfer/Opname/Production/Opening as
**PARTIAL** (traceable only indirectly, via their underlying transaction)
and Import/User/Role/Permission as **dead ends** (`NO`). This phase closes
all seven.

## What was built

Same discipline as V2.2: read-only aggregation over **existing** tables,
no new tables, no migration. Each new `TraceService` method (`transferTrace`,
`opnameTrace`, `productionTrace`, `openingTrace`, `importTrace`, `userTrace`,
`roleTrace`) only `SELECT`s, proven in `tests/trace_test.php` section G
(state snapshot before/after every new method, byte-identical).

| Entity | Method | Route | Extra access control |
|---|---|---|---|
| Transfer | `transferTrace()` | `GET /trace/transfer/{id}` | STOCK must own the from- **or** to-warehouse |
| Stock Opname | `opnameTrace()` | `GET /trace/opname/{id}` | STOCK must own the session's warehouse |
| Production | `productionTrace()` | `GET /trace/production/{id}` | STOCK must own the production's warehouse |
| Opening Stock | `openingTrace()` | `GET /trace/opening/{id}` | STOCK sees only their own warehouse's lines (filtered, not blocked) |
| Import Batch | `importTrace()` | `GET /trace/import/{id}` | AUDIT_LOG_VIEW only (not warehouse-scoped — import batches aren't warehouse entities) |
| User | `userTrace()` | `GET /trace/user/{id}` | **SUPERADMIN/ADMIN only** — stricter than AUDIT_LOG_VIEW, per spec |
| Role/Permission | `roleTrace()` | `GET /trace/role/{id}` | **SUPERADMIN/ADMIN only** |

All seven are also reachable through Trace Center's global search (new
`opname`/`production`/`opening`/`import`/`role` types; `transfer`/`user`
already existed but were dead-end clicks before this phase — now routed to
real drawers). "Lihat Jejak" buttons were also wired directly into the
Transfer list, the active Stock Opname session banner, the Production
success panel, and every Import section's post-commit success panel.

## Trace chains delivered (matches spec sections 1–4)

- **Transfer**: header (from/to warehouse, created/received/cancelled by+when)
  → each line's FIFO allocations on the source side (batches consumed) →
  the destination batch created on receive → cross-referenced
  TRANSFER_OUT/TRANSFER_IN transaction headers (by `reference_no`).
- **Opname**: session header (counted/finalized/posted/cancelled by+when) →
  each line's system/physical qty + variance → the resulting
  `stock_adjustments` row and its ADJUSTMENT transaction, when variance was
  posted.
- **Production**: header → each raw-material input's FIFO allocations
  (source batches + actual cost) → the finished-good output and the batch
  it created.
- **Opening Stock**: the staging record (`stock_openings`/
  `stock_opening_lines` — cutoff date, source, verification status, who
  staged/committed it) shown **separately** from the live FIFO baseline it
  produced (`inventory_batches` + the OPENING-type `inventory_transactions`
  row) — deliberately two columns, never merged, so "what was reported" and
  "what FIFO reads today" are never confused. Never recomputes or touches
  opening economics — read-only, same as everything else here.

## Import trace (spec section 5)

`import_batches`/`import_rows` already existed (Phase 13-17) with real
per-row status/messages/`created_entity_id`. `importTrace()` surfaces this
directly — no fabrication needed, the source data was already complete.
Opening Stock's own import has a richer, dedicated trace via
`openingTrace()` instead (it uses `stock_openings`, not `import_batches` —
see the architecture note in `services/ImportOpeningStockService.php`).

## User trace (spec section 6) — honest gap, not a false YES

Audited before writing code: **no code path in this application ever wrote
an `audit_logs` row for account creation, activation/deactivation, role
change, or warehouse reassignment before this phase.** Accounts are
provisioned exclusively by a CLI script (`scripts/provision_user.php`) —
there is no user-management API/UI at all in Inventory Pro. That script now
logs `USER_PROVISION` (`entity_type='users'`) going forward, so User Trace
has real events for every account created from this phase on. Older
accounts' creation history is genuinely unrecoverable (it was never
recorded anywhere) and `userTrace()` says so explicitly via
`historical_note`/`historical_data_limited`, never a fabricated timeline.

Real, always-available evidence show up regardless of that gap:

- Current role/division/warehouse assignment, active/inactive, forced
  password-change flag — read straight from `users`.
- Full login history (success/failure/IP/timestamp) from `login_attempts`,
  which predates this phase and is unaffected by the audit-logging gap.
- `password_hash` and every credential/session-shaped field are stripped
  before the response ever leaves `TraceService` (`stripSecrets()`, same
  helper the original V2.2 entity trace already used) — verified in
  `tests/trace_test.php` ("user trace NEVER exposes password_hash").

## Role/Permission trace (spec section 7)

`role_permissions` is exclusively schema-seeded (`database/schema.sql`) —
there is no code path anywhere in the application that changes a role's
permission set at runtime. So "current permissions" (shown, real, always
available) already **is** the complete history — there is no drift, no
untracked change, nothing that could be silently lost. `roleTrace()` states
this plainly via `historical_note` rather than implying a change log exists
where none is possible.

## Coverage matrix (spec section 9)

| Entity | Before this phase | After this phase |
|---|---|---|
| Transfer | PARTIAL | **YES** — dedicated chain, both directions, bidirectional nav |
| Opname | PARTIAL | **YES** — dedicated chain incl. resulting adjustment |
| Production | PARTIAL | **YES** — dedicated chain, consumption + output |
| Opening | PARTIAL | **YES** — staging vs. live, explicitly separated |
| Import | NO | **YES** — full row-level status/messages/created-entity |
| User | NO | **YES for current state, login history, and every event from this phase forward.** Pre-phase account-lifecycle history (create/activate/deactivate/role-change) is genuinely unrecoverable — no code path ever logged it. Not claimed as full historical YES; the gap is disclosed in the response itself (`historical_note`), not just in this document. |
| Role/Permission | NO | **YES** — current permission set is always-complete (schema-seeded, no runtime mutation path exists, so no history could be lost) |

No item is marked a false full YES where the underlying data genuinely
doesn't exist — the User row above is the one case with a real historical
gap, and it is called out both here and in the API response itself.

## Tests

`tests/trace_test.php` grew from 44 to **111** checks, entirely additive
(no existing section A–H was weakened): new sections I (Transfer), J
(Opname), K (Production), L (Opening), M (Import), N (User), O (Role), P
(search coverage for the 5 newly-searchable types), extended G (read-only
snapshot now also covers all 7 new methods) and H (HTTP warehouse isolation
for Transfer/Opname/Production/Opening + AUDIT_LOG_VIEW-vs-SUPERADMIN/ADMIN
distinction for User/Role, using a 3rd throwaway warehouse to prove a
genuinely out-of-scope STOCK user is rejected, not just an in-scope one
accepted).

Full regression (`tests/run_mysql_tests.sh`, 15 suites + concurrency) was
re-run after every source change in this phase: **450/450 passed, 0
failed**, including the untouched V2.1/V2.2/reconciliation/FIFO/security
suites — nothing in this phase touched their behavior.
