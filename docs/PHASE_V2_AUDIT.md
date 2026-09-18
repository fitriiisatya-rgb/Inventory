# PHASE 1 — AUDIT: Inventory FIFO Pro V2 (UI/UX + Feature Enhancement)

Scope: audit only, per instruction ("Mulai dengan Phase 1 — Audit saja.
Jangan coding terlebih dahulu"). **No code, no migration file, and no
schema change is written in this phase.**

## 0. Method note — one important caveat

This audit is built by reading the repository's current codebase
(`database/schema.sql`, `services/*.php`, `public/index.php`,
`public/assets/js/*.js`) and its own docs — not by connecting to a real
production database. This session still has no access to your actual
production server (confirmed in the prior cutover-runbook round). Your
message states SCM+Cibadak are now live; I take that as given and treat
the committed schema/backend as authoritative for "what production is
running," since `docs/DEPLOYMENT.md` requires production to run this
exact repo's code un-forked. If your live server has since diverged from
this branch in any way, that's a gap this audit can't see — worth a quick
`git log -1` check on the server before Phase 2 is finalized.

### 0.1 Correction — two commits landed on this branch after the first draft

After this audit's first draft, two commits appeared on
`origin/claude/funny-ramanujan-wmrlig` that I hadn't authored (pushed
directly as `fitriiisatya-rgb`): **`fix production reconciliation and
frontend routing`** and **`harden warehouse isolation and transfer
permissions`**. I rebased this doc on top of them and reviewed both in
full before finalizing. They change facts stated in the first draft —
corrected in place below, and called out here directly rather than
silently:

- **My original claim "STOCK-role warehouse scoping is checked server-side
  on every mutating route" was wrong for several by-id routes.** Before
  the hardening commit, `POST /transfers/{id}/receive`, `POST /transfers/{id}/cancel`,
  `GET /transfers/{id}`, `GET /stock-opname/{id}`, `POST /stock-opname/{id}/count`,
  `POST /stock-opname/{id}/finalize`, and `POST /stock-opname/{id}/post`
  only checked the general `WAREHOUSE_TRANSFER_MANAGE`/`STOCK_OPNAME_MANAGE`
  permission — never the *specific* transfer's or session's actual
  `warehouse_id` against the requester's own assignment. A SCM-scoped
  `STOCK` user who knew or guessed a Cibadak transfer/opname session id
  could have received, cancelled, viewed, counted, finalized, or posted
  it. `GET /transfers` and `GET /transfers/pending` also returned every
  warehouse's transfers to any authenticated user, and the dashboard's
  reconciliation view exposed the full company-wide reconciliation
  report regardless of role. **All of this is now fixed** by the
  hardening commit — every one of those routes now re-derives the
  transfer's/session's real warehouse from the database and calls
  `inv_require_warehouse_scope()` against it, `GET /transfers*` take an
  optional scope filter, and the dashboard now gates the reconciliation
  view behind a `RECONCILIATION_VIEW` permission check, falling back to a
  new warehouse-scoped `InventoryService::warehouseDashboardSummary()`
  for `STOCK` users. Section 3/4 below are corrected to reflect the
  current (fixed) state, not the state at first draft.
- **`GET /warehouses`, `GET /inventory/current`, `GET /inventory/current/{sku}`,
  `GET /inventory/batches`, `GET /inventory/value`, `GET /inventory/in-transit`,
  `GET /inventory/ledger`** now also require `INVENTORY_VIEW` and are
  warehouse-scoped for `STOCK` users (previously several of these had no
  permission check at all, only `inv_require_auth()`).
- **New `GET /transfer-destinations` endpoint** already ships the
  "Gudang Tujuan dari endpoint metadata khusus" behavior the V2 spec
  asks for, and `transfers.js` already locks "Gudang Asal" to the
  logged-in `STOCK` user's own warehouse. This is a real head start on
  V2's Transfer page (Section E) — Phase 2 should build on it, not
  re-design it from scratch.
- **A real reconciliation bug from earlier in this engagement was fixed**:
  `OpeningReconciliationService`'s `opening_control_total_match` and
  `current_stock_equals_opening` checks compared against
  `inventory_batches.qty_base` (the *current remaining* quantity, which
  legitimately drops as stock sells) instead of `original_qty_base` (the
  quantity *as of opening*) — meaning those checks would have started
  failing the moment any real sale happened after go-live. Now compares
  against `original_qty_base` and the item's live net balance separately.
- **A gap in my own earlier POLICY CORRECTION work was fixed**: I added
  the migration-negative-approved exclusion to `OpeningReconciliationService`
  this session, but never to the separate `ReconciliationService`
  (`GET /reconciliation`) — so its `negative_stock` check would have kept
  flagging the 5 owner-approved migration-negative SKUs as `ERROR`
  forever. Now excludes them the same way, and adds a `migration_negative_review`
  `WARNING`-level check alongside it.
- **The stale `"MySQL Backend — Dummy Data Mode"` copy flagged in the
  first draft's open questions is already fixed** — `index.html` now
  reads "MySQL Backend — Production" / "Environment Production." Removed
  from Section 7's open questions below.

None of this changes the *shape* of the V2 gap analysis (Sections 4-6
below still hold) — it corrects specific factual claims about what
already exists, and it's good news: real security gaps that predated
this V2 request have already been closed, and part of the Transfer page
groundwork is already done.

---

## 1. Existing files/modules related to this request

**Backend (PHP), all under `App\Services`, wired in `public/index.php`:**

| Area | File(s) |
|---|---|
| Inventory read/report | `services/InventoryService.php` (single source of truth for stock/value/ledger — see Section 3) |
| FIFO posting | `services/FifoService.php` |
| Item/unit master | `services/ImportMasterItemService.php`, `services/UnitConversionService.php`, `services/UnitNormalizationService.php` |
| Supplier/Division/Warehouse master | `services/ImportSimpleMasterService.php` (one class, 3 import types) |
| Transactions IN/OUT | `FifoService::postIn/postOut`, routes `POST /transactions/in`, `POST /transactions/out` |
| Transfers | `services/TransferService.php`, routes `POST/GET /transfers*` |
| Stock Opname | `services/StockOpnameService.php`, `services/StockAdjustmentService.php` |
| Migration-negative policy | `services/MigrationNegativeStockService.php` (this session's own work) |
| Reconciliation | `services/OpeningReconciliationService.php`, `services/ReconciliationService.php` |
| Auth/permissions | `services/AuthService.php`, `inv_require_permission()`/`inv_require_warehouse_scope()` in `public/index.php` |
| Audit trail | `services/AuditService.php`, route `GET /audit-logs` |
| Front controller | `public/index.php` (single file, ~700 routes as a big match array — no framework, no router class beyond `public/router.php`) |

**Frontend (vanilla JS, no build step, no framework):**

| File | Role | Lines |
|---|---|---|
| `public/index.html` | Single static shell: login screen, change-password screen, one `<header>`, a **horizontal tab bar** (not a sidebar), 10 empty `<div class="tab-content">` mount points | 107 |
| `public/assets/js/app.js` | Tab activation, session resume, login/change-password forms | 154 |
| `public/assets/js/api-client.js` | Fetch wrapper, CSRF header, idempotency UUIDs | 182 |
| `public/assets/js/master.js` | Loads items/warehouses/suppliers/divisions into memory; **no CRUD UI at all** — master data is only ever created via the Import tab's CSV flow | 37 |
| `public/assets/js/dashboard.js` | Minimal KPI cards | 80 |
| `public/assets/js/reports.js` | "Laporan" tab — **single-item, single-warehouse ledger lookup only** (two `<select>`s + a table), not a report of all items | 129 |
| `public/assets/js/transactions.js` | IN/OUT forms | 183 |
| `public/assets/js/transfers.js` | Transfer create/list/receive/cancel | 204 |
| `public/assets/js/stock-opname.js` | Opname workflow | 206 |
| `public/assets/js/adjustments.js`, `production.js`, `closing.js`, `imports.js`, `audit.js`, `ui.js` | One module each, functional, unstyled beyond `app.css` | 96–159 each |

Total frontend: **2,098 lines** across all JS — a small, functional-only
shell built for the original PHP+MySQL migration project (Phase D), never
styled or IA'd beyond "one tab per feature." It is the mockup's opposite
in every structural sense: horizontal tabs vs. sidebar, one flat page per
tab vs. list+detail-drawer, no dark-navy theme (plain `app.css`, not
audited line-by-line in this pass but confirmed via `index.html`'s
absence of the mockup's visual language). The header copy has already
been corrected to "MySQL Backend — Production" (see Section 0.1).

**Relevant docs:** `docs/API_CONTRACT.md` (frozen error-shape contract —
must stay compatible), `docs/DEPLOYMENT.md`, this session's
`docs/PHASE_G_DATA_POLICY_CORRECTION_MIGRATION_NEGATIVE.md` and
`docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md` (the migration-negative
policy this V2 work must not regress).

---

## 2. Existing relevant schema

```
items(id, sku, barcode, name, category VARCHAR(100) free-text, brand,
      base_unit_id, minimum_stock DECIMAL  -- ONE global value, not per-warehouse
      default_supplier_id, notes, status, locked_at, ...)
suppliers(id, code, name, contact_name, phone, notes, is_active, ...)
      -- no address, no email columns
divisions(id, code, name, is_active, ...)
      -- INTERNAL cost-center/production destination, already used on OUT
      -- (transactions.js "Divisi Tujuan") -- see Section 4's warning about
      -- not conflating this with the new "Bakery Tujuan" concept
warehouses(id, code, name, warehouse_type, is_active, ...)
units(id, code, name)
item_unit_conversions(item_id, unit_id, conversion_to_base, valid_from, valid_to, ...)
inventory_batches(id, item_id, warehouse_id, qty_base, unit_cost_base,
                   received_date, is_negative_layer, ...)
inventory_transactions(id, transaction_uuid, transaction_type, transaction_date,
                        warehouse_id, supplier_id, division_id, reference_no,
                        status, is_historical_import, inventory_effect, ...)
inventory_transaction_lines(..., item_id, input_qty, input_unit_id, base_qty,
                             unit_cost_base, warehouse_id, ...)
fifo_allocations(transaction_line_id, batch_id, qty_allocated, unit_cost_base, ...)
warehouse_transfers / warehouse_transfer_lines
stock_opname_sessions / stock_opname_lines
stock_adjustments(..., migration_issue_reference)  -- added this session
movement_reconciliation_reviews(..., is_migration_negative_approved)  -- added this session
roles / permissions / role_permissions / users(role_id, warehouse_id, division_id)
audit_logs
```

**No `categories` table** — `items.category` is a free-text VARCHAR.
**No `bakery_destinations` table.** **No per-warehouse stock policy
table** — `minimum_stock` lives once on `items`, globally, not per
warehouse (directly contradicts the V2 requirement that SCM's minimum can
differ from Cibadak's for the same SKU).

---

## 3. Existing features — what actually works today

**Backend, solid and already covers most of the hard domain logic:**
- FIFO IN/OUT with full batch/allocation audit trail, idempotent posting, price-anomaly detection, period locking, warehouse locking during opname.
- Warehouse-scoped `STOCK` role: `inv_require_warehouse_scope()` is checked server-side on every mutating route (`/transactions/in|out`, `/transfers`, `/stock-adjustments`, `/stock-opname`) — **never trusts the frontend's `warehouse_id`**, consistent with the new instruction's "Jangan mempercayai warehouse_id dari frontend."
- Transfer state machine: source creates/cancels, destination receives — already exactly matches the V2 spec's required behavior (`services/TransferService.php`, tested in `tests/mysql_integration_test.php`).
- Stock Opname: count → finalize → post, corrections always go through `StockAdjustmentService` (never a direct batch UPDATE) — matches "Jangan mengubah integrity mechanism."
- Migration-negative policy (this session): `MIGRATION_NEGATIVE_REVIEW`/`NEEDS_STOCK_OPNAME` flags, OUT/TRANSFER_OUT/PRODUCTION_IN blocked while balance ≤ 0, surfaced on `InventoryService::currentStock()` and a dedicated `GET /migration-negative-review`.
- `InventoryService::ledger()` already returns a merged live+historical chronological feed (`is_historical`, `historical_running_balance`) — built this session, directly reusable for the V2 "History Transaksi" detail drawer.
- Company/warehouse value totals, control-total reconciliation, `GO_LIVE_READY` gating.

**Backend, read/report surface — the actual gap for V2:**
- `GET /items` returns **every** item, unfiltered, unpaginated, no search/category/stock-status params — the opposite of what the V2 "Laporan Stok" needs.
- **No endpoint lists "all items × current stock" for one warehouse.** `InventoryService` only exposes per-item lookups (`currentStock($itemId, $warehouseId)`, `currentStockAllWarehouses($itemId)`) — there is no `currentStockForWarehouse($warehouseId)` equivalent. The frontend today never needs one because `reports.js` only ever looks at one item at a time.
- **No transaction-history *list* endpoint at all.** `InventoryService::ledger()` is scoped to one item+warehouse; there's no `GET /transactions` with date/type/search/vendor filters as the V2 spec requests — today the only way to see "everything that happened" is `GET /audit-logs` (a generic audit trail, not a transaction-shaped view) or the per-item ledger.
- No minimum/buffer-vs-status computation exists anywhere (`items.minimum_stock` is stored but nothing reads it to compute SAFE/LOW/CRITICAL — confirmed via `Grep` for `minimum_stock` usage: only written at import time, never read back for a status calculation).

**Frontend — the largest gap:**
- No sidebar, no drawer, no dark-navy theme, no global search, no per-column pagination controls, no "pilih kolom", no CSV/Excel export beyond the one ad-hoc ledger export in `reports.js`.
- No master-data CRUD UI whatsoever (items/suppliers/warehouses/divisions are import-only today).
- Two real `prompt()`/`confirm()` uses in production code today (`reports.js`'s void-reason prompt and period-lock-override confirm) — **directly violates** the new "Jangan gunakan browser `prompt()`" requirement; this predates V2 and must be fixed as part of it, not newly introduced.
- No stepper-based IN/OUT flow (current forms are single-screen).
- No Vendor/Bakery/Category master pages (none of those concepts exist client-side).

---

## 4. Gap analysis (against each mandatory point)

| # | Requirement | Status | Gap |
|---|---|---|---|
| 1 | Laporan Stok lengkap semua item | **Missing** | No backend endpoint, no frontend page. `reports.js` is a single-item lookup, not a report. |
| 2 | Filter kategori + search | **Missing** | No `categories` table; `items.category` free-text has no distinct-values endpoint; no server-side search param on `GET /items`. |
| 3 | Minimum/Buffer + status | **Partially missing** | `minimum_stock` exists but is global-per-item, not per-warehouse, and nothing computes SAFE/LOW/CRITICAL from it. No `buffer_stock` concept at all. Migration-negative already correctly stays a separate `REVIEW` state (built this session) — V2 must keep that distinction, not fold it into the new status enum. |
| 4 | History Transaksi IN/OUT lengkap + drawer | **Partially missing** | The domain data (vendor via `supplier_id`, warehouse, qty, user, FIFO allocations) all exists and is already queryable per-item via `ledger()`; what's missing is a *list* endpoint across items/dates and the UI entirely. No "Bakery Tujuan" concept exists yet on OUT (only `division_id`, a different, already-used field — see below). |
| 5 | Master Vendor | **Extend, don't duplicate** | `suppliers` table already fits the "Vendor" concept per the instruction's own guidance ("jika tabel suppliers sudah memenuhi kebutuhan, jangan membuat tabel vendor duplikat"). Missing columns only: `address`, `email` (has `code`, `name`, `contact_name`≈PIC, `phone`, `notes`, `is_active` already). No CRUD UI exists. |
| 6 | Master Bakery Tujuan | **New** | No table, no UI, no transaction-line field. **Important distinction already visible in the code**: `division_id` on `inventory_transactions` is an existing, different concept — internal cost-center for production consumption (`transactions.js` labels it "Divisi Tujuan", used by `ProductionService`). A new `bakery_destination_id` must be added alongside it, never replacing it, and only meaningful for `transaction_type = 'OUT'` (external distribution), while `division_id` stays meaningful for internal consumption. Building this without keeping the two separate would be exactly the mistake the instruction warns against ("jangan menyamakan bakery tujuan dengan warehouse tanpa analisa") — applies equally to divisions. |

**IA / sidebar / dark-navy theme:** 100% new frontend work — nothing in
the current 2,098-line JS shell resembles the target IA. This is by far
the largest single piece of V2.

**Security requirements met as of the current HEAD** (corrected per
Section 0.1 — the first draft overstated this before reviewing the
hardening commit): STOCK-role warehouse scoping is now checked
server-side on every mutating route AND on every by-id read/mutate route,
re-derived from the actual resource's warehouse rather than trusted from
the request; `GET /transfers*` and the dashboard's company-wide view are
now scoped/gated too. Server-side permission checks
(`inv_require_permission`) remain independent of frontend menu visibility
(the frontend *does* already hide tabs it shouldn't show —
`data-require-permission`/`data-require-role` attributes on the tab
buttons, `Auth.applyRoleVisibility()` — so the V2 "sembunyikan menu,
jangan hanya disabled" requirement is **already satisfied** by the
existing pattern and should be carried forward, not reinvented). Phase 2
should still write the explicit regression tests the V2 spec asks for
(STOCK SCM cannot read Cibadak, etc.) — the fix is live, but Section 1
found no dedicated automated test for it yet; confirming that is a Phase
2/3 action item, not assumed done.

---

## 5. Risks

1. **`GET /items` at 1,000+ SKUs with no pagination** is already a
   real, present-day performance risk independent of V2 — every page
   load fetches the full item list into `Master.items()`. V2's own
   "Performance" section explicitly asks for server-side pagination; this
   is confirmation the concern is justified, not hypothetical.
2. **Conflating `division_id` and a new `bakery_destination_id`** (both
   optional "destination" fields on the same OUT transaction) risks
   confusing operators and reports if the UI/API design doesn't keep them
   visually and semantically distinct from day one.
3. **`items.category` is free text** — introducing a normalized
   `categories` table means either (a) a one-time backfill mapping
   existing free-text values to new category rows (needs owner review of
   the distinct values actually in production — this audit did not see
   production data and can't enumerate them), or (b) keeping `category`
   as free text and only adding a *filter* that operates on distinct
   existing strings, deferring full normalization. This is a scope
   decision for Phase 2, not something to guess at now.
4. **Per-warehouse minimum/buffer is a genuinely new stock policy**,
   not just a UI change — every existing item's *current* single global
   `minimum_stock` needs an explicit decision: migrate it forward as the
   same value for both SCM and Cibadak (safe, reversible starting point),
   or require the owner to set real per-warehouse values before V2 ships
   the status column (safer data-quality-wise, slower to ship). Needs an
   owner decision before Phase 2 locks the migration shape.
5. **Frontend rewrite blast radius**: index.html's tab-based mount
   points are read directly by `app.js`'s `activateTab()`; moving to a
   sidebar/routed IA touches every existing `*.js` module's `render()`
   entry point, not just new pages. This is a large, cross-cutting
   change that benefits from being done as one coherent Phase 3 pass
   with its own regression pass, not File-by-file drift.
6. **Existing `prompt()`/`confirm()` usage** (Section 3) must be
   replaced with proper confirmation modals as part of this work per the
   new global UX rules — small in code size, but touches a currently-
   working void/period-lock-override flow that has real test coverage
   (`tests/mysql_void_test.php`); the replacement needs to preserve
   exactly the same server calls, just a different UI trigger.
7. **No frontend framework, by design** (`docs/DEPLOYMENT.md`'s "no
   Packagist dependency" principle extends to the frontend — everything
   today is hand-written vanilla JS with zero npm dependencies). A
   sidebar/drawer/global-search IA of the mockup's sophistication is
   substantial to hand-build without a component framework. This isn't
   a blocker, but it's worth an explicit owner confirmation that "no
   framework" is still the intended constraint for V2, since the mockup's
   visual complexity is a step up from anything currently in this
   codebase.

---

## 6. Migrations likely needed (list only — no SQL yet, per instruction)

1. `item_warehouse_stock_policy` (new table) — per-item-per-warehouse `minimum_stock_base`, `buffer_stock_base`, exactly as the instruction's own recommended shape.
2. `bakery_destinations` (new table) — code, name, address, area, phone, pic_name, is_active.
3. `inventory_transactions.bakery_destination_id` (new nullable column + FK) — OUT-only, alongside the existing `division_id`, never replacing it.
4. `suppliers.address`, `suppliers.email` (new nullable columns) — extending, not duplicating.
5. Category normalization — **shape TBD pending Phase 2 decision** (Section 5, risk 3): either a new `categories` table + `items.category_id` FK (with a backfill plan for existing free-text values), or a lighter "distinct existing category strings as a filter" approach that defers full normalization.

Every one of these is additive (`ALTER TABLE ... ADD COLUMN`, new
`CREATE TABLE`) — none require dropping or rewriting an existing column,
consistent with "migration harus menjaga data existing 100%." Full
`migrations/*.sql` + rollback + precheck/postcheck, as requested, are
Phase 2/3 deliverables once the shape above is confirmed.

---

## 7. Open questions for the owner before Phase 2 locks the design

1. **Category normalization** (Risk 3): keep `items.category` as free
   text with a distinct-values filter, or fully normalize into a new
   `categories` table with a backfill? (Real production category values
   would need to be reviewed either way — this audit has no visibility
   into them.)
2. **Minimum/buffer migration** (Risk 4): backfill every item's existing
   global `minimum_stock` as the starting per-warehouse value for both
   SCM and Cibadak, or start every item's new per-warehouse policy blank
   until an admin sets it explicitly?
3. **Frontend approach confirmation** (Risk 7): continue as
   dependency-free vanilla JS (larger hand-written effort, keeps the
   "no Packagist/npm dependency" principle intact), or is a lightweight
   bundled approach (still no backend framework change) acceptable for
   the frontend specifically, given the mockup's complexity?
4. **New in this revision**: now that the warehouse-isolation hardening
   (Section 0.1) has landed directly on GitHub outside of a reviewed
   Claude Code turn, should Phase 2 include writing the automated
   regression tests for it (STOCK SCM ↛ Cibadak reads, transfer/opname
   by-id scope, etc. — the V2 spec's own "TESTING WAJIB" list already
   asks for these) as an explicit, prioritized early item, so this class
   of gap has test coverage going forward rather than relying on manual
   review each time?

---

## STOP — Phase 1 complete

No code, schema, or migration file has been written. Per your
instruction, waiting for Phase 1 sign-off (and answers to Section 7's
open questions) before starting Phase 2 — Technical Design.
