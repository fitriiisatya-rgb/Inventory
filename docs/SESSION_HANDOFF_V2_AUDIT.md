# Session Handoff — Inventory FIFO Pro V2 (read this first, in any new session/account)

Written 2026-09-18 because the prior Claude Code session was running low on
context/tokens and the owner asked for continuity across an account switch.
This file is the durable record — it lives in git, so it survives regardless
of which Claude Code account or session opens this repo next. **Updated
same day, later, once Phase 2 was approved and Phase 3 (Implementation)
started — see Section 0 immediately below for the current state; the rest
of this file is the original Phase 1/2 handoff and is still accurate
background, just no longer the "current status."**

## 0. CURRENT STATUS (read this part first, it supersedes nothing below but is the freshest layer)

- **Phase 1 Audit**: approved.
- **Phase 2 Technical Design**: approved, with conditions — see
  `docs/PHASE_V2_TECHNICAL_DESIGN.md` for the design itself and the commit
  that added it (`b2b1a22`). The owner's approval conditions (verbatim
  intent) are captured in full in the Phase 3 kickoff commit message and
  restated in `docs/PHASE_V2_TECHNICAL_DESIGN.md` where they change the
  design — the short version: keep `GET /items` untouched and use
  `/reports/stock` instead; buffer_stock stays nullable with an explicit
  `buffer_configured` flag and a "Buffer belum dikonfigurasi" UI label
  (LOW status only applies once buffer is set); migration-negative rows
  must always show `REVIEW`/`MIGRATION_NEGATIVE_REVIEW`, never folded into
  SAFE/LOW/CRITICAL; evaluate (don't assume) whether a MariaDB CHECK
  constraint can restrict `bakery_destination_id` to OUT-type transactions
  before deciding between a DB constraint and service-layer-only
  enforcement; no full item editor — Master Barang in V2 exposes only
  list/search/detail/category/per-warehouse minimum+buffer, never
  base_unit/conversion history/locked_at/FIFO-sensitive fields.
- **Phase 3 Implementation**: IN PROGRESS — backend sub-phases 3a-3g are
  DONE (see Section "As of THIS update" below for the full list); 3h
  (regression re-run, effectively continuous already), 3i/3j (the actual
  V2 frontend — sidebar, dark-navy theme, data-table/drawer/modal
  components, new pages) and the final completion report are NOT started.
  Explicit owner-mandated order:
  (1) regression tests first, (2) migration files (never run against
  production), (3) category master, (4) min/buffer stock policy, (5)
  vendor/supplier, (6) bakery destination master, (7) `GET /reports/stock`,
  (8) transaction history, (9) V2 UI/UX, (10) performance, (11) security,
  (12) global UX polish. Full detailed requirements (sidebar IA, dashboard
  KPIs, per-page specs, the 19-point completion report format required at
  the end) are in the conversation that approved Phase 2 — if that context
  is gone, ask the owner to re-paste their Phase 3 kickoff message, or
  reconstruct intent from `docs/PHASE_V2_TECHNICAL_DESIGN.md` Section 14
  (Implementation phases) which already encodes the same ordering.
- **Task tracking**: this session's harness TaskCreate/TaskUpdate list has
  tasks #69–#79, one per Phase 3 sub-step (3a regression tests → 3j
  frontend pages → completion report). A new session's harness will show
  its own fresh task list (task IDs are session-scoped, not stored in
  git) — treat `docs/PHASE_V2_TECHNICAL_DESIGN.md` Section 14 as the
  authoritative phase breakdown, not the task IDs themselves.
- **Local dev DB gotcha discovered this phase**: the sandbox's
  `/var/lib/mysql` had its `mysql` system schema (grant tables) missing
  while the `inventory_test`/`inventory_staging_scm_cibadak` data
  directories were still present (unclear cause — possibly an unclean
  container restart between sessions). Fixed by running `mariadb-install-db
  --datadir=/var/lib/mysql --user=mysql
  --auth-root-authentication-method=normal` (safe — only creates the
  missing `mysql`/`performance_schema` system tables, does not touch
  existing user database directories), then `chown -R mysql:mysql
  /var/lib/mysql` and starting via `mysqld_safe`. If a new session hits
  the same "Can't open and lock privilege tables" error, this is the fix —
  do not delete/recreate the datadir.
- **As of THIS update (third Phase 3 update, after Phase 3g landed)**:
  commits `172343d`(3a) `3b72ee9`(3b) `4d32619`(3c) `c85ca7b`(3d)
  `87a7397`(3e) `45b4d90`(3f) `f719587`(3g) are pushed. **Backend is now
  functionally complete for all 6 mandatory admin requirements.** Next
  unstarted step is **Phase 3h: full regression re-run** (already
  continuously re-run after every sub-phase, so this is mostly a formality
  — see the running total below), then **3i/3j: the actual V2 frontend**
  (sidebar/dark-navy UI, data-table/drawer/modal components, new pages) —
  **zero frontend work has been done yet**, everything so far is backend
  only. Task #76 (3h) in this session's tracker is next.

  Concretely done so far, in order:
  - **3a**: `tests/warehouse_isolation_regression_test.php` (28
    assertions) — SCM/Cibadak inventory/ledger/batches isolation, SKU
    lookup scoping, opname/transfer cross-warehouse blocks.
  - **3b**: `database/migrations/2026_09_18_v2_schema.sql` +
    `_rollback.sql` + `scripts/v2_schema_{precheck,postcheck}.php` +
    `docs/PHASE_V2_SCHEMA_IMPACT.md`. The same DDL is ALSO folded directly
    into `database/schema.sql` (matching this project's established
    convention) — a fresh `mysql < database/schema.sql` load already has
    every V2 table/column. The `bakery_destination_id` CHECK constraint
    was verified empirically enforced (MariaDB error 4025 on a violating
    insert).
  - **3c**: `scripts/report_category_distinct_values.php` (read-only) +
    `scripts/backfill_item_categories.php` (idempotent, mapping-file-
    driven). Proven against synthetic data only — real production
    category values are not accessible from this session; the owner must
    run the report themselves and return an approved mapping.
  - **3d**: `services/StockPolicyService.php` (`resolve()`/`upsert()`/
    `stockStatus()` — REVIEW always wins, then
    OUT_OF_STOCK/CRITICAL/LOW-only-if-buffer-configured/SAFE), `GET`/`PUT
    /stock-policy`, `scripts/backfill_stock_policy_scm_cibadak.php`
    (SCM+Cibadak only, verified zero rows land on Karang Tengah). Added
    permission codes `MASTER_CATEGORY_MANAGE`, `MASTER_SUPPLIER_MANAGE`,
    `MASTER_BAKERY_DESTINATION_MANAGE`, `STOCK_POLICY_MANAGE`.
  - **3e**: `services/SupplierService.php` + `services/BakeryDestinationService.php`
    (create/update, soft-delete only via `is_active`, duplicate-code
    rejection), `POST`/`PUT /suppliers`, `GET`/`POST`/`PUT /bakery-destinations`,
    `GET`/`POST`/`PUT /categories`. Wired `bakery_destination_id` into
    `FifoService::postOut()` — only ever persisted for `transaction_type
    === 'OUT'`, service-layer guard on top of the DB CHECK.
  - **3f**: `services/StockReportService.php` — `GET /reports/stock`, the
    full "Laporan Stok". One aggregate query (never per-item), SQL status
    CASE deliberately mirrors `StockPolicyService::stockStatus()` (cross-
    checked by test), `?format=csv` export. `GET /items` is untouched.
  - **3g**: `services/TransactionHistoryService.php` — `GET
    /reports/transactions` (+`/{id}` detail with FIFO allocations + gated
    audit trail) — "History Transaksi". All 32 test assertions passed on
    the very first run (no bugs found writing this one).
  - **Bug pattern caught FOUR times across 3d/3e/3f — memorize this**:
    PDO's native MySQL prepares reject a named placeholder used twice in
    the same statement (e.g. `VALUES (..., :by, :by)`, or `WHERE x LIKE
    :q OR y LIKE :q`) with `SQLSTATE[HY093]: Invalid parameter number` —
    always use two distinct placeholder names even when binding the same
    value twice, or matching the same search term against two columns.
  - Two other real bugs worth knowing about if you touch these files:
    `StockReportService`'s `COUNT(*)` subquery originally selected only
    `i.id` but its `HAVING` clause referenced computed aliases
    (`qty_base`/`status`) that only exist in the full `$select` list — the
    count subquery must always select the same full column list as the
    main query when `HAVING` is used. And the company-wide (no
    `warehouse_id`) mode of the same query referenced
    `item_warehouse_stock_policy` columns from a table it deliberately
    doesn't join in that mode — the `$select` string itself must branch on
    `$warehouseId !== null`, not just the joins.
  - Full regression suite (existing 138 + all new V2 suites) is
    **275/275 passing** as of `f719587`. Every phase reset the DB fresh
    from `database/schema.sql` and re-ran the WHOLE suite, not just the
    new file, each time — this has been a continuous regression gate, not
    a one-time end check.
  - Local test DB note: the sandbox's MariaDB had to be restarted this
    session too (see the gotcha above) — if you're a new session hitting
    connection refused on `127.0.0.1:3306`, check whether `mysqld_safe` is
    even running (`mysqladmin ping`) before assuming the gotcha above
    applies; it may simply not be started yet in a fresh container.

---

**Branch**: `claude/funny-ramanujan-wmrlig`
**HEAD at time of writing (original Phase 1/2 handoff, now superseded by Section 0 above for current status)**: `935394a` (local and `origin/claude/funny-ramanujan-wmrlig` are in sync — verify with `git fetch origin claude/funny-ramanujan-wmrlig && git log --oneline -5` before doing anything else, since the owner pushes to this branch directly outside of Claude Code turns too).

Commit chain (newest first) as of this writing:
```
935394a Correct Phase 1 V2 audit against two hardening commits already on GitHub
4baef1f Phase 1 audit for Inventory FIFO Pro V2 (UI/UX + feature enhancement)
30c5368 harden warehouse isolation and transfer permissions          <- owner's own commit
3c5e457 fix production reconciliation and frontend routing            <- owner's own commit
510215a Add detailed copy-paste production cutover runbook for SCM+CIBADAK
```

## 1. Production status (do not violate these constraints)

- **SCM and CIBADAK warehouses**: owner has stated the system is already
  production / live for these two. This session has **no access to any real
  production database or server** — never fabricate a "cutover succeeded"
  claim; everything tested here was against a local throwaway MariaDB
  (`inventory_test`) started manually inside the sandbox.
- **Karang Tengah warehouse**: must stay `PENDING_CUTOVER`. Never create it,
  never import its opening/IN/OUT data, never make it live, until the owner
  explicitly says so and provides its own IN/OUT 01–15 Sept 2026 file (not
  yet supplied as of this writing).
- **5 whitelisted migration-negative balances** — must NEVER be zeroed,
  hidden, or provisionally adjusted away. They carry forward into LIVE
  Opening exactly as calculated, tagged `MIGRATION_NEGATIVE_REVIEW` +
  `NEEDS_STOCK_OPNAME`:
  - SCM: SKU `100304` = -0.5 KG
  - SCM: SKU `777419` = -0.5 KG
  - CIBADAK: SKU `400201` = -466.5 KG
  - CIBADAK: SKU `555410` = -250 PCS
  - CIBADAK: SKU `800401` = -162 LTR
  - Enforcement lives in `services/MigrationNegativeStockService.php`,
    `services/FifoService.php::postOut()` (blocks OUT for these via
    `NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT`), and is surfaced through
    `InventoryService::currentStock()`/`currentStockAllWarehouses()`.
- **Never**: reset the DB, re-import opening, delete historical transactions,
  change FIFO to average costing, bypass warehouse permission checks,
  hard-delete referenced master data, `DROP`/`TRUNCATE` production tables,
  or deploy anything without explicit owner approval.
- **Production cutover runbook** (`docs/PRODUCTION_CUTOVER_RUNBOOK.md` +
  `docs/PRODUCTION_CUTOVER_CHECKLIST.md`) was fully prepared and every
  command was actually executed end-to-end against a local test DB to
  verify it works — but as far as this session can observe, the owner has
  **not yet confirmed executing it against real production** (though two
  commits landed on GitHub mid-session, `3c5e457` and `30c5368`, authored
  directly by the owner as `fitriiisatya-rgb`, suggesting real production
  work is happening in parallel outside Claude Code sessions). Check with
  the owner directly for current real-world status before assuming either way.

## 2. Current active task: Inventory FIFO Pro V2 (UI/UX + feature enhancement)

The owner sent a long Indonesian specification (twice, identical) for a
dark-navy sidebar-based enterprise ERP/WMS redesign plus 6 mandatory backend
features. It ended with an explicit instruction that must be honored by
whichever session continues this:

> **"Mulai dengan Phase 1 — Audit saja. Jangan coding terlebih dahulu sampai
> audit dan technical design disetujui."**
> (Start with Phase 1 — Audit only. Do not code until audit and technical
> design are approved.)

The full spec used 3 mockup screenshots (dark navy Dashboard, Stok Barang
with detail drawer, Stock Opname) with production-matching figures (1,007
SKU, ~Rp 2,33M, Rp 2.330.669.085,78 SCM / Rp 307.378.082,14 Cibadak). **These
image files and the original prompt markdown only existed in the previous
session's ephemeral upload paths (`/root/.claude/uploads/...` and a scratch
images directory) — they were never copied into this repo, and are almost
certainly NOT accessible in a new session/account.** If the owner needs the
V2 spec re-referenced in detail beyond the summary below, ask them to
re-paste it or re-upload the mockups — do not assume they're recoverable.

### 2.1 Six mandatory requirements (summarized from the spec)

1. **Full "Laporan Stok"** — ALL items incl. zero-stock, with
   category/unit/qty/value/min/buffer/status/last-movement columns; search,
   filter, sort, pagination, column-picker, export, row-click drawer;
   warehouse-scoped for STOCK role. **Currently missing** — no endpoint
   lists "all items × current stock" for one warehouse today.
2. **Category + search filters, server-side** (not client-side) for 1000+
   rows.
3. **Minimum/buffer stock per-item-per-warehouse** with
   SAFE/LOW/CRITICAL/OUT_OF_STOCK (or AMAN/TIDAK AMAN) status. Migration-negative
   rows must stay a separate REVIEW state, never folded into "normal".
   **Currently**: `items.minimum_stock` is ONE global value, written but
   never read back for any status calculation. No per-warehouse policy table
   exists.
4. **Full IN/OUT transaction history** with detail drawer (vendor for IN /
   bakery destination for OUT / FIFO allocation / audit). **Currently
   missing** — only a per-item `ledger()` lookup exists
   (`services/InventoryService.php::ledger()`), no list-all-transactions
   endpoint.
5. **Master Vendor** — audit existing `suppliers` table first (it exists),
   don't duplicate; add `address`, `PIC`, `phone`, `email`, `notes`,
   `is_active` if missing.
6. **Master Bakery Tujuan** — new `bakery_destinations` table for OUT
   distribution destinations. **Must NOT be conflated with**:
   - `warehouse_id` (internal stock location — different concept)
   - `division_id` (existing field — internal production cost-center,
     different concept)

Plus: full sidebar IA (Overview/Inventory/Transactions/Transfers/Stock
Opname/Reports/Master Data, menus hidden not disabled per permission);
per-page specs for Dashboard/Stock IN (4-step stepper)/Stock OUT (4-step
stepper w/ FIFO preview)/Transfer/Stock Opname; global UX rules (**no
`prompt()`/`confirm()`** — see 2.3 below for where these currently violate
this; loading skeletons; empty/error states; confirmation modals; Rupiah/qty
formatting; tooltips; keyboard-friendly; prevent double-submit; idempotency
preserved; sticky headers; server-side pagination; responsive tablet;
accessibility); security requirement restating STOCK warehouse scoping must
be backend-enforced, never trust frontend `warehouse_id`; explicit
**"JANGAN eksekusi migration ke production"** — migrations/rollback/precheck/postcheck
must be produced as **files only**, never run; suggested API routes;
performance requirements (server-side pagination, indexed filters, no
N+1, search debounce); acceptance criteria per feature; required automated
tests (STOCK cross-warehouse isolation, report scoping, min/buffer calc,
vendor/bakery filters, bakery destination persistence, transfer/opname
scope, migration-negative behavior unchanged, FIFO unchanged, company
reconciliation unchanged, plus run the existing regression suite).

Explicit 5-phase gated structure: **Phase 1 Audit → Phase 2 Technical
Design → Phase 3 Implementation → Phase 4 Verification → Phase 5 Deployment
Plan (plan-only, never deploy without explicit approval)** — each phase
gated on owner approval before the next begins.

### 2.2 Phase 1 — Audit: COMPLETE (see `docs/PHASE_V2_AUDIT.md`)

Already written, corrected, committed, and pushed
(`4baef1f` then corrected in `935394a`). Read that file directly for full
detail — key findings:

- **Schema gaps**: no `categories` table (`items.category` is free-text
  VARCHAR(100)); no `bakery_destinations` table; no per-warehouse stock
  policy table (`items.minimum_stock` is one global value).
- **Missing endpoints**: no "all items × current stock for one warehouse"
  report list; no transaction-history LIST endpoint (only per-item ledger).
- **Frontend is the largest gap**: no sidebar/drawer/dark-navy theme, no
  global search/column-picker, **no master-data CRUD UI exists at all**
  (master data is import-CSV-only today), 2 `prompt()`/`confirm()`
  violations (both in `public/assets/js/reports.js`, lines 98 and 109 — see
  2.3).
- **7 risks identified**, most important: `GET /items` is unpaginated
  today, which is a **real present-day performance issue at 1000+ SKUs**,
  not hypothetical; `division_id`/`bakery_destination_id` confusion risk;
  frontend rewrite touches every existing JS module's `render()` entry
  point.
- **5 candidate migrations** (list-only, no SQL written yet, per the "don't
  code yet" instruction): `item_warehouse_stock_policy` table,
  `bakery_destinations` table, `inventory_transactions.bakery_destination_id`
  nullable column, `suppliers.address` + `suppliers.email` columns,
  category-normalization (shape TBD).
- **Section 0.1** (added in the correction commit) documents that the
  audit's first draft WRONGLY claimed STOCK-role warehouse scoping was
  already checked server-side on every mutating route — this was false for
  several transfer/opname routes before the owner's own `30c5368` commit
  fixed it directly. The corrected audit now credits that fix accurately
  instead of leaving the stale claim in place.

### 2.3 Two `prompt()`/`confirm()` violations to fix in Phase 3

`public/assets/js/reports.js`:
- Line 98: `const reason = prompt(...)` — asks for a void reason.
- Line 109: `const override = confirm(...)` — asks to proceed as superadmin
  override when a period is locked.

Both must be replaced with proper modals per the V2 global UX rule, while
preserving the exact same server calls (`InvApi.voidTransaction(...)` with
`request_uuid`, `reason`, optional `superadmin_override: true`).

### 2.4 Open questions blocking Phase 2 (owner has NOT yet answered these)

From `docs/PHASE_V2_AUDIT.md` Section 7 — **ask the owner these before
starting Phase 2 Technical Design**:

1. Category normalization approach: keep free-text + filter, or build a
   full `categories` table + backfill real production category values
   (which this session cannot see)?
2. Minimum/buffer migration approach: backfill the existing global
   `minimum_stock` value into every warehouse row, or start blank and let
   the owner set them per-warehouse manually?
3. Frontend approach confirmation: stay strictly dependency-free vanilla JS
   (the project's long-standing deliberate constraint — no npm/Composer),
   or allow a lightweight bundler specifically for the V2 rewrite given its
   mockup complexity (sidebar, drawers, column-picker)?
4. Should writing regression tests for the warehouse-isolation fixes
   (the ones in the owner's `30c5368` commit) be prioritized as an early
   Phase 2/3 item, since no dedicated automated test currently covers them?

### 2.5 What NOT to do until the owner answers

- Do not write Phase 2 Technical Design until at minimum question 3
  (frontend approach) is answered — it changes the shape of everything
  else in the design.
- Do not write migration SQL files yet (Phase 2 deliverable, not Phase 1).
- Do not touch production. Never run a migration against anything but a
  local/test DB, ever, without the owner explicitly saying "execute this
  against production."

## 3. Key architectural facts a new session needs (condensed)

- PHP 8.1+/8.4 + MySQL/MariaDB, vanilla JS frontend, **no framework, no
  npm/Composer dependencies** by deliberate design
  (`docs/DEPLOYMENT.md`). Single-file front controller `public/index.php`.
- Frozen API error-shape contract (`docs/API_CONTRACT.md`): success
  `{"success":true,"data":{...},"message":"..."}`, error
  `{"success":false,"error":{"code":"...","message":"..."}}` — frontend
  branches on `error.code`, never `error.message`.
- FIFO batch costing: `inventory_batches` (`qty_base` decays with sales,
  `original_qty_base` stays fixed at opening — these are DIFFERENT and
  conflating them was a real bug fixed this session, see git history around
  `services/OpeningReconciliationService.php`).
  `Database::lockFifoBatches()` only sums `qty_base > 0` (FIFO-consumable)
  — different from an item's TRUE NET balance (sum of ALL batches incl.
  negative layers). Conflating these was also a real bug (fixed in
  `FifoService::postOut()`'s migration-negative guard).
- `inv_require_warehouse_scope($user, $warehouseId)` in `public/index.php`
  must always be called with the RESOURCE's actual warehouse_id re-derived
  from the DB, never trusted from the request body/query. Several routes
  violated this before the owner's own `30c5368` hardening commit.
- Migration-negative whitelist: `movement_reconciliation_reviews` table +
  `services/MigrationNegativeStockService.php`. Status is always computed
  LIVE from current net balance ≤ 0, never stored as a flag.
- CSRF token via `X-CSRF-Token` header on mutating requests.
  `must_change_password` enforced server-side on every endpoint, not just
  a frontend nag.
- Every real HTTP route wraps mutations in
  `Database::transaction(fn (PDO $tx) => ...)`.
- `services/InventoryService.php` is "single source of truth" for every
  stock figure — no other service should compute current stock with its
  own SQL. It already has `warehouseDashboardSummary()` (added by the
  owner's `30c5368` commit) used by the hardened `GET /inventory/value`.
- Layered-override pattern used throughout migration tooling: never edit a
  detector/generator script's output in place; produce a new versioned
  artifact + a small JSON overrides file + a dedicated apply script with a
  pre-write safety check.
- Global Base Unit resolution vs. alternate/purchase-unit conversion
  approval are DISTINCT concepts (owner correction, important not to
  re-conflate): of the 1,007 SCM+CIBADAK opening SKUs, all 1,007 have a
  resolved Global Base Unit (0 unresolved conflicts); only 29 have an
  approved alternate/purchase-unit (e.g. CTN) conversion — this is NOT a
  go-live blocker, missing CTN conversion only means CTN-unit transactions
  get `UNIT_CONVERSION_NOT_APPROVED` while base-unit transactions work fine.

## 4. Git workflow reminders for the new session

- Branch `claude/funny-ramanujan-wmrlig`. The owner pushes to this branch
  directly outside of Claude Code turns — **always `git fetch` and check
  `git log` for divergence before assuming local state matches remote**;
  rebase (not merge) if the owner has pushed commits with no file overlap,
  to keep linear history.
- This session has no production DB/server access — `.env` only ever
  points to a local throwaway test instance. Never claim a production
  action succeeded without the owner directly confirming it.
- Commit message attribution footer is whatever the CURRENT session's
  system reminder specifies (it changes per session — don't copy this
  file's own footer blindly, use your own session's instructed footer).

## 5. Immediate next step for whoever picks this up

Ask the owner the 4 open questions in Section 2.4 above (or re-confirm
them if already answered outside this file's knowledge), then start Phase
2 — Technical Design: schema (5 candidate migrations with exact SQL now),
API routes, permission matrix, UI page/component structure, status
definitions, migration+rollback plan. Do not write implementation code
until Phase 2 is presented and the owner approves it, per their explicit
instruction repeated twice.
