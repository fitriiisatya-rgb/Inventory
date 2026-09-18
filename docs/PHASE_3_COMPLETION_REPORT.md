# Phase 3 — Implementation Completion Report

**Status: STOP. Nothing has been deployed. Nothing has touched production.**
This report covers Phase 3 (Implementation) only, per the owner's 19-point
required format. Phase 4 (Verification, already substantially covered here
since every sub-phase was tested as it landed) and Phase 5 (Deployment
Plan — plan only) follow this, both requiring separate owner sign-off
before any further action.

---

## 1. Commit hashes

Base (Phase 2 approval landed): `b2b1a22`
Current HEAD: `5124546`

| Commit | Sub-phase | Summary |
|---|---|---|
| `172343d` | 3a | Warehouse-isolation regression suite (pre-migration baseline) |
| `3b72ee9` | 3b | V2 schema migration + precheck/postcheck/rollback + schema-impact doc |
| `4d32619` | 3c | Category distinct-value report + idempotent backfill tooling |
| `c85ca7b` | 3d | `item_warehouse_stock_policy` backend + stock-status calc + SCM/Cibadak backfill |
| `87a7397` | 3e | Vendor/supplier + bakery-destination CRUD, `bakery_destination_id` wiring |
| `45b4d90` | 3f | `GET /reports/stock` — "Laporan Stok" |
| `f719587` | 3g | `GET /reports/transactions` (+detail) — "History Transaksi" |
| `5124546` | 3i+3j | V2 frontend — sidebar shell, shared components, new pages |
| `ed4c0f3`, `e62c667`, `3f404f3` | — | Interleaved handoff-doc updates (not implementation) |

Branch: `claude/funny-ramanujan-wmrlig`, pushed to `origin` after every
commit. Phase 3h (regression) produced no new commit — it was the
continuous re-run gate applied after every other sub-phase, formally
closed out with one final clean run (see Section 11).

---

## 2. Files changed

42 files changed, 5,321 insertions(+), 77 deletions(-) across Phase 3
(`git diff --stat b2b1a22 5124546`). By area:

**Schema/migration** (5 files): `database/schema.sql` (+96/-frozen
comment lines), `database/migrations/2026_09_18_v2_schema.sql` (new, 154
lines), `database/migrations/2026_09_18_v2_schema_rollback.sql` (new, 57
lines), `scripts/v2_schema_precheck.php` (new), `scripts/v2_schema_postcheck.php` (new).

**Backend services** (6 new, 1 changed): `services/StockPolicyService.php`,
`services/SupplierService.php`, `services/BakeryDestinationService.php`,
`services/StockReportService.php`, `services/TransactionHistoryService.php`
(all new), `services/FifoService.php` (+13/-2, `bakery_destination_id`
wiring in `postOut()`).

**Backend routes**: `public/index.php` (+270 lines) — new `GET/PUT
/stock-policy`, `POST/PUT /suppliers`, `GET/POST/PUT /bakery-destinations`,
`GET/POST/PUT /categories`, `GET /reports/stock`, `GET /reports/transactions`,
`GET /reports/transactions/{id}`.

**Data tooling** (3 new scripts): `scripts/report_category_distinct_values.php`,
`scripts/backfill_item_categories.php`, `scripts/backfill_stock_policy_scm_cibadak.php`.

**Frontend** (9 new, 10 changed): new `modal.js`, `data-table.js`,
`drawer.js`, `sidebar.js`, `stock-report.js`, `transaction-history.js`,
`master-categories.js`, `master-vendors.js`, `master-bakery-destinations.js`;
changed `index.html`, `app.js`, `dashboard.js`, `master.js`,
`api-client.js`, `ui.js`, `reports.js`, `transactions.js`, `app.css`.

**Tests** (6 new, 1 changed): `warehouse_isolation_regression_test.php`,
`stock_policy_test.php`, `master_data_v2_test.php`, `stock_report_test.php`,
`transaction_history_test.php` (new), `run_mysql_tests.sh` (changed to
wire all 5 new suites in).

**Docs**: `docs/PHASE_V2_SCHEMA_IMPACT.md` (new), `docs/SESSION_HANDOFF_V2_AUDIT.md`
(updated 3×, continuity artifact — not a project deliverable), this file.

---

## 3. Database migrations created

`database/migrations/2026_09_18_v2_schema.sql` — additive only. Creates:
`categories`, `item_warehouse_stock_policy`, `bakery_destinations` tables;
adds `items.category_id`, `suppliers.address`, `suppliers.email`,
`inventory_transactions.bakery_destination_id` columns; adds the
`chk_tx_bakery_destination_out_only` CHECK constraint; seeds 4 new
permission codes + their `role_permissions` rows for SUPERADMIN/ADMIN.
No existing column dropped, renamed, or narrowed. **Never run against
production** — applied and verified only against a local throwaway test
database (`inventory_test`) in this session.

The identical DDL is also folded directly into `database/schema.sql`
(the project's single dev/test source of truth, per its own header
comment and every prior phase's own convention) — this is NOT a second
migration, it's the same change expressed in the file that
`tests/run_mysql_tests.sh` and every fresh local install load from.

---

## 4. Rollback scripts

`database/migrations/2026_09_18_v2_schema_rollback.sql` — reverses the
migration in dependency order (permission grants → `suppliers` columns →
`bakery_destination_id` + its FK/CHECK/index → `bakery_destinations` →
`item_warehouse_stock_policy` → `items.category_id` → `categories`).
Verified in this session: applied against the migrated local test DB,
confirmed via `information_schema` queries that every added
table/column/constraint was gone with zero trace, then the migration was
re-applied cleanly on top. Every added column was nullable and every
added table independent of pre-existing data, so rollback never touches
a row of pre-existing business data.

---

## 5. Precheck scripts

`scripts/v2_schema_precheck.php` — refuses to proceed if: any of the 3
new tables already exist; any of the 4 new columns already exist; a
baseline table is missing (wrong database); the connected MariaDB/MySQL
version doesn't enforce CHECK constraints (MariaDB <10.2.1 / MySQL
<8.0.16). Snapshots row counts of every pre-existing table the migration
touches, for the postcheck to diff against. Exit 0 = safe to proceed,
exit 1 = stop.

---

## 6. Postcheck scripts

`scripts/v2_schema_postcheck.php` — verifies all 3 new tables + 4 new
columns + 4 new foreign keys exist; **empirically** (not just
structurally) verifies the `bakery_destination_id` CHECK constraint is
enforced by attempting a violating insert inside a rolled-back
transaction and asserting it's rejected; diffs every pre-existing table's
row count against the precheck snapshot and fails loudly on any
difference. Result in this session: **18/18 checks pass** (see Section 3
of `docs/PHASE_V2_SCHEMA_IMPACT.md` for the full run transcript,
including the constraint-enforcement proof: MariaDB error 4025 on a
violating insert).

---

## 7. Schema diff

```
+ categories (id, code, name, is_active, created_at, updated_at)
+ item_warehouse_stock_policy (id, item_id, warehouse_id, minimum_stock_base,
    buffer_stock_base NULL, is_active, notes, created_by, updated_by,
    created_at, updated_at)  UNIQUE(item_id, warehouse_id)
+ bakery_destinations (id, code, name, address, city_area, pic_name, phone,
    route_cluster, notes, is_active, created_at, updated_at)

~ items          + category_id INT UNSIGNED NULL (FK -> categories)
~ suppliers      + address VARCHAR(255) NULL
~ suppliers      + email VARCHAR(150) NULL
~ inventory_transactions
                 + bakery_destination_id INT UNSIGNED NULL (FK -> bakery_destinations)
                 + CHECK (bakery_destination_id IS NULL OR transaction_type = 'OUT')

+ permissions: MASTER_CATEGORY_MANAGE, MASTER_SUPPLIER_MANAGE,
    MASTER_BAKERY_DESTINATION_MANAGE, STOCK_POLICY_MANAGE
    (SUPERADMIN/ADMIN inherit automatically via the existing seed pattern)
```

Nothing removed. Nothing renamed. `items.category` and
`items.minimum_stock` — the two fields the new columns could plausibly be
confused with — keep their exact original meaning and are read as
fallbacks, never repurposed. Full rationale for the CHECK constraint
(including why it's safe against real production data it has never seen)
is in `docs/PHASE_V2_SCHEMA_IMPACT.md`.

---

## 8. New/changed API endpoints

| Method & path | New/changed | Permission |
|---|---|---|
| `GET /categories` | new (write-path); read-path pre-existed unused since no writer existed | authenticated |
| `POST /categories` | new | `MASTER_CATEGORY_MANAGE` |
| `PUT /categories/{id}` | new | `MASTER_CATEGORY_MANAGE` |
| `POST /suppliers` | new | `MASTER_SUPPLIER_MANAGE` |
| `PUT /suppliers/{id}` | new | `MASTER_SUPPLIER_MANAGE` |
| `GET /bakery-destinations` | new | authenticated |
| `POST /bakery-destinations` | new | `MASTER_BAKERY_DESTINATION_MANAGE` |
| `PUT /bakery-destinations/{id}` | new | `MASTER_BAKERY_DESTINATION_MANAGE` |
| `GET /stock-policy` | new | `INVENTORY_VIEW` + warehouse scope |
| `PUT /stock-policy` | new | `STOCK_POLICY_MANAGE` + warehouse scope |
| `GET /reports/stock` | new | `INVENTORY_VIEW` + warehouse scope (STOCK forced to own) |
| `GET /reports/stock?format=csv` | new | same, raw CSV response |
| `GET /reports/transactions` | new | `INVENTORY_VIEW` + warehouse scope |
| `GET /reports/transactions/{id}` | new | `INVENTORY_VIEW` + scope re-derived from the transaction's own `warehouse_id` |
| `POST /transactions/out` | **changed** | accepts new optional `bakery_destination_id` field; fully backward-compatible, every existing field unchanged |
| `GET /items` | **unchanged, deliberately** | see Section 7.1 of `docs/PHASE_V2_TECHNICAL_DESIGN.md` for why |

Full endpoint contracts (request/response shapes) are documented inline
in each service's docblock and exercised by the corresponding test file.

---

## 9. New UI pages/components

**Shared components**: `modal.js` (confirm/prompt replacement),
`data-table.js` (server-side paginated/sortable/filterable + column
picker), `drawer.js` (slide-over detail panel, optional tabs),
`sidebar.js` (mobile toggle only — visibility logic reuses the
pre-existing `Auth.applyRoleVisibility()`).

**New pages**: Stok Barang (`stock-report.js`), History Transaksi
(`transaction-history.js`), Master Kategori/Vendor/Bakery Tujuan
(`master-categories.js`/`master-vendors.js`/`master-bakery-destinations.js`).

**Extended**: Dashboard (full KPI row, Need Attention, Recent Activity,
Quick Actions), Stock OUT form (Bakery Tujuan field).

**Navigation**: horizontal tab bar replaced with a dark-navy sidebar,
grouped per the spec's IA (Overview/Inventory/Transactions/Transfers/
Production/Stock Opname/Master Data/Audit & Control/Settings). A
breadcrumb was added. Every existing page (Transfer, Produksi, Stock
Opname, Import, Audit, Tutup Buku, the single-item Ledger view) is
unchanged functionally — only its position moved from a tab to a sidebar
link.

Verified with a real headless-browser Playwright session (not just
backend tests) — see Section 11.

---

## 10. Admin requirement completion matrix

| # | Requirement (owner's mandatory list) | Status | Evidence |
|---|---|---|---|
| 1 | Laporan stok shows ALL items incl. zero-stock | ✅ Done | `stock_report_test.php` §A; screenshot sent to owner |
| 2 | Filter per category | ✅ Done | `stock_report_test.php` §B |
| 3 | Search SKU/nama barang | ✅ Done | `stock_report_test.php` §C |
| 4 | Minimum stock / buffer | ✅ Done | `stock_policy_test.php`, `item_warehouse_stock_policy` |
| 5 | Status aman/tidak aman | ✅ Done | `stockStatus()` 5-state calc, SQL/PHP cross-checked (`stock_report_test.php` §F) |
| 6 | History transaksi IN/OUT | ✅ Done | `transaction_history_test.php` |
| 7 | History shows source/destination area | ✅ Done (warehouse-level; see Section 17 for the one narrowing) | `transaction_history_test.php` §A |
| 8 | History shows qty/unit | ✅ Done | `transaction_history_test.php` §A |
| 9 | Master Vendor/Supplier | ✅ Done | `SupplierService`, `master-vendors.js`, `master_data_v2_test.php` |
| 10 | Master Bakery Tujuan | ✅ Done | `BakeryDestinationService`, `master-bakery-destinations.js` |

All 10 mandatory requirements are implemented and tested. Additional
explicit owner conditions also verified: `GET /items` untouched (Section
7.1 design decision, never revisited); `buffer_configured` flag +
"Buffer belum dikonfigurasi" label present in both API and UI; migration-
negative rows always show `MIGRATION_NEGATIVE_REVIEW`, verified never
folding into SAFE/LOW/CRITICAL (`stock_report_test.php` §G); no full item
editor built (only `PUT /items/{id}/category` exists — base_unit,
conversion history, `locked_at`, FIFO-sensitive fields are not exposed
anywhere in V2); Karang Tengah untouched by any of it (verified in the
stock-policy backfill script, which hard-refuses any warehouse code other
than `SCM`/`CIBADAK`).

---

## 11. Regression test result

Full suite (`bash tests/run_mysql_tests.sh`, fresh `database/schema.sql`
load before each file): **275/275 assertions pass**, exit code 0.

| Suite | Assertions | Result |
|---|---|---|
| `mysql_smoke_test.php` | 4 | PASS |
| `mysql_integration_test.php` | 35 | PASS |
| `mysql_importer_test.php` | 17 | PASS |
| `mysql_void_test.php` | 15 | PASS |
| `mysql_security_test.php` | 11 | PASS |
| `opening_g_data_2_test.php` | 20 | PASS |
| `migration_negative_stock_test.php` | 31 | PASS |
| `warehouse_isolation_regression_test.php` (new) | 28 | PASS |
| `stock_policy_test.php` (new) | 33 | PASS |
| `master_data_v2_test.php` (new) | 18 | PASS |
| `stock_report_test.php` (new) | 26 | PASS |
| `transaction_history_test.php` (new) | 32 | PASS |
| `concurrency_test.sh` | 5 | PASS |

This was not a one-time end check — the entire suite was re-run from
scratch after every single sub-phase (3a through 3j), each time against
a freshly reset database, so a regression introduced by sub-phase N would
have been caught before sub-phase N+1 started. Every PHP file touched
this phase also passes `php -l` with zero syntax errors (swept across
every file in `services/`, `scripts/`, `public/` at the end of Phase 3h).

---

## 12. Warehouse security test result

`warehouse_isolation_regression_test.php`, 28/28 pass, covering exactly
the owner's mandatory list:
- SCM cannot read Cibadak inventory / Cibadak cannot read SCM — PASS
- SCM inventory value contains only SCM / Cibadak only Cibadak — PASS
  (`scope_warehouse_id` matches, sibling warehouse never appears in
  `value_per_warehouse`)
- SKU lookup without `warehouse_id` stays scoped to the STOCK user's own
  warehouse (never falls back to all-warehouses) — PASS
- Ledger/batches reports follow the same rule — PASS
- Opname cross-warehouse count/finalize/post all rejected FORBIDDEN — PASS
- Transfer create only from own warehouse, receive only by destination,
  cancel only by source, list/detail only when the user's warehouse is
  involved — PASS

Extended this phase to the two new report endpoints (`stock_report_test.php`
§K, `transaction_history_test.php` §F): STOCK forced to own warehouse
with `warehouse_id` omitted, rejected FORBIDDEN when requesting another
warehouse explicitly, detail endpoint re-derives the real warehouse from
the DB rather than trusting the request — all PASS.

---

## 13. FIFO test result

No FIFO logic was changed this phase except the additive
`bakery_destination_id` bind in `FifoService::postOut()`'s transaction
insert (metadata only, never touches batch selection or cost math). The
existing FIFO test suite (`mysql_integration_test.php`,
`mysql_smoke_test.php`, `concurrency_test.sh`, and the FIFO-specific
assertions inside `opening_g_data_2_test.php`/`migration_negative_stock_test.php`)
was re-run unchanged after every sub-phase and passes identically to the
pre-Phase-3 baseline — IN/OUT postings, layer consumption order, transfer
value preservation, cancel-restore, receive-recreate-cost-layer, and the
2-connection race test (`OUT 70 vs OUT 70 on stock=100`) all still pass.
`stock_report_test.php` additionally cross-checks that
`StockReportService`'s SQL-computed values (`qty_base`, `average_cost`)
match what `FifoService`/`InventoryService` actually posted, byte-for-byte
on the test data used.

---

## 14. Reconciliation test result

`ReconciliationService`/`OpeningReconciliationService` were not modified
this phase. Their existing tests (`opening_g_data_2_test.php`,
`migration_negative_stock_test.php` §G/G2) were re-run unchanged and pass
identically. Company inventory totals remain consistent: `stock_report_test.php`
verifies the company-wide (no `warehouse_id`) rollup correctly sums a
multi-warehouse item's quantity (30+20=50) while the single-warehouse
view correctly isolates just its own 30 — proving the new report never
double-counts or under-counts against the existing reconciliation math.
Historical audit-only transactions (`inventory_effect=0`) are untouched
by any V2 change; `TransactionHistoryService` explicitly filters to
`status = 'POSTED'` and surfaces `is_historical` on every row without
altering how historical rows are stored or counted.

---

## 15. Migration-negative test result

`migration_negative_stock_test.php`, 31/31 pass, unchanged from the
pre-Phase-3 baseline — the whitelist mechanism, the FIFO OUT block, and
`OpeningReconciliationService`'s GO_LIVE_READY split were not touched
this phase. Extended coverage added this phase in `stock_report_test.php`
§G: a migration-negative item's SQL-computed status in the new stock
report is `MIGRATION_NEGATIVE_REVIEW` (never `OUT_OF_STOCK`, despite its
quantity being negative, which the naive branch order would otherwise
produce), and filtering `?status=MIGRATION_NEGATIVE_REVIEW` correctly
returns it. Both the PHP (`StockPolicyService::stockStatus()`) and SQL
(the `CASE` expression in `StockReportService`) implementations check
`migration_negative_review` **first**, before any quantity comparison —
verified identical for every generated test row in `stock_report_test.php`
§F's cross-check.

---

## 16. Performance considerations

- `GET /reports/stock` and `GET /reports/transactions` are each a single
  aggregate query (plus one count query, plus a summary query for the
  stock report) — never a per-item/per-transaction loop. The
  migration-negative whitelist (bounded ~8 rows in production today) is
  resolved once per request and injected as a plain `IN` list, not a
  per-row subquery.
- Both endpoints paginate server-side (default 50, max 200 rows per
  page) and every `DataTable` instance on the frontend only ever fetches
  one page at a time — no client-side filtering of a full dataset.
- New indexes: `idx_items_category`, `idx_items_name` (search),
  `idx_tx_bakery_destination`. Existing `idx_tx_date`/`idx_tx_type_status`/
  `idx_tx_warehouse` already cover most of the transaction report's
  filters; a composite `(warehouse_id, transaction_date)` index was
  deliberately **not** added speculatively — `docs/PHASE_V2_TECHNICAL_DESIGN.md`
  Section 11 flags it as an optional follow-up only if `EXPLAIN` against
  real production row counts shows it's needed.
- Search inputs in `data-table.js` are debounced (300ms) before firing a
  request; select-type filters fire immediately (no typing to debounce).
- `GET /items` was deliberately left unpaginated and untouched (Section
  7.1 design decision) — it remains a real, known, **pre-existing**
  performance concern at 1,000+ SKUs for the dropdown/master-cache use
  case specifically, not solved by this phase and not claimed to be. See
  Section 17.

---

## 17. Known issues / technical debt

- **`GET /items` remains unpaginated.** This was a known-and-accepted
  scope boundary from Phase 2 (not a regression) — `Master.loadAll()`
  needs the full list for dropdowns, and changing that shape would break
  every existing form. At 1,000+ SKUs this is a real, present cost on
  every page load. If it becomes a measured problem, the fix is a
  dedicated lightweight `/items/lookup` endpoint returning only
  `{id, sku, name}`, not retrofitting pagination onto the existing route.
- **Transaction history's "source/destination area" is warehouse-level,
  not transfer-pair-level.** For a `TRANSFER_OUT`/`TRANSFER_IN` row, the
  history table shows that row's own single `warehouse_id` (source for
  the OUT row, destination for the IN row) rather than cross-referencing
  `warehouse_transfers` to show both source AND destination on the same
  row. This satisfies requirement #7 literally (source/destination area
  is visible, across the two paired rows) but is less convenient than a
  dedicated transfer view — which already exists (the Transfer page's own
  Outgoing/Incoming/In Transit/History tabs) and was left as the
  authoritative place for that detail rather than duplicating it here.
- **No full item editor.** Deliberate per the owner's explicit condition
  — `PUT /items/{id}/category` is the only item-mutation endpoint. Base
  unit, conversion history, `locked_at`, and any other FIFO-sensitive
  field remain unreachable from the UI, as required.
- **Stock IN/OUT forms were not rebuilt into the mockup's 4-step
  stepper.** The existing single-page forms were extended (vendor
  already existed on IN; Bakery Tujuan added to OUT) rather than
  restructured into a stepper UI. This is the one mockup-fidelity gap in
  this phase — a cosmetic/UX restructuring of an already-functional,
  already-tested form, deliberately deprioritized against the functional
  requirements (schema, security, the 6 mandatory admin features) within
  this pass. The CSS for a `.stepper` component was added
  (`app.css`, unused so far) in case a future pass builds it.
- **Transfer/Stock Opname/Production pages were not visually restyled**
  beyond moving from a tab to a sidebar link — they still use the
  original card-based layout, not the dark-navy mockup's specific table/
  panel treatment for those flows. Functionally unchanged and untested-
  for-regression-because-untouched, which is itself lower risk than a
  rewrite would have been.
- **No real production category or business data was available to this
  session.** `scripts/report_category_distinct_values.php` and
  `scripts/backfill_item_categories.php` are built and proven against
  synthetic data only — the owner must run the report against the real
  database and supply an approved mapping before the backfill script is
  ever run for real.
- **Recurring bug pattern worth flagging for future work on this
  codebase**: PDO's native MySQL prepared statements reject a named
  placeholder reused twice in one statement (`SQLSTATE[HY093]`). This was
  hit four separate times in this phase (`StockPolicyService::upsert()`,
  `backfill_stock_policy_scm_cibadak.php`, and twice in
  `StockReportService`) before being caught by the accompanying tests
  each time. It's a real gotcha for this specific PDO driver
  configuration, not obvious from reading the SQL string alone.

---

## 18. Exact proposed production deployment procedure (NOT executed)

This mirrors the structure of the already-proven
`docs/PRODUCTION_CUTOVER_RUNBOOK.md` from the earlier SCM/Cibadak
cutover. **None of this has been run against production. This session
has no production access and will not attempt any of it.**

1. **Backup**: `scripts/backup_db.sh` (existing tool) against production,
   labeled `pre-v2-migration`.
2. **Maintenance mode**: enable per the existing deployment convention
   (see `docs/DEPLOYMENT.md`).
3. **Precheck**: `php scripts/v2_schema_precheck.php` against production —
   STOP if it reports anything other than PRECHECK PASSED.
4. **Schema migration**: `mysql <prod-db> < database/migrations/2026_09_18_v2_schema.sql`.
5. **Postcheck**: `php scripts/v2_schema_postcheck.php` against
   production — STOP if any of the 18 checks fail, especially the
   row-count-unchanged checks and the CHECK-constraint-enforcement probe.
6. **Category backfill (owner-gated, two steps, can happen any time
   after step 5, does not need to block go-live)**:
   a. `php scripts/report_category_distinct_values.php` against
      production — read-only, safe to run anytime even before this
      point.
   b. Owner reviews the CSV, returns an approved mapping file.
   c. `php scripts/backfill_item_categories.php <approved_mapping.csv>`
      against production (dry-run first with `--dry-run`).
7. **Stock-policy backfill**:
   `php scripts/backfill_stock_policy_scm_cibadak.php --dry-run` first,
   review output, then without `--dry-run`. Confirms zero Karang Tengah
   rows both times.
8. **Deploy source**: standard deployment of this branch's `public/`,
   `services/`, `scripts/` to the production web root, per
   `docs/DEPLOYMENT.md`'s existing procedure.
9. **Smoke test**: manually exercise (or extend
   `tests/staging_smoke_test.php`-style HTTP smoke coverage for) at least:
   login, `GET /reports/stock` for SCM and for Cibadak as a STOCK user,
   `GET /reports/transactions`, creating a vendor, creating a bakery
   destination, posting one real OUT with a bakery destination selected,
   confirming it appears correctly in the new history view.
10. **Reconciliation**: run the existing `GET /reconciliation` check —
    confirm no change from its pre-deployment state (this phase touches
    no reconciliation logic).
11. **Exit maintenance mode.**
12. **Final health check**: existing `GET /health` endpoint.

**Explicit STOP conditions carried over from every prior cutover
document in this project**: if any precheck/postcheck/smoke-test step
fails, STOP immediately, do not improvise a fix on production, roll back
per Section 19, and report the exact failure.

---

## 19. Exact rollback procedure (NOT executed)

1. **Application rollback**: redeploy the previous source revision
   (before this branch's merge) to the production web root.
2. **Schema rollback** (only if the new tables/columns already have real
   data in them that must be discarded — confirm with the owner first,
   since this DROPs the new tables/columns):
   `mysql <prod-db> < database/migrations/2026_09_18_v2_schema_rollback.sql`.
   Verified in this session to remove every trace of the migration with
   zero effect on any pre-existing table (Section 4). If no real V2 data
   has been entered yet (e.g. rollback happens immediately after a failed
   smoke test in step 9 above, before any real vendor/bakery-destination/
   category was created), this is safe to run without data-loss risk to
   anything that mattered before the migration.
3. **If schema rollback is NOT desired** (new tables have real data worth
   keeping even though the application is being rolled back): skip step 2
   entirely — the additive schema causes no harm to the older application
   code, which simply never queries the new tables/columns.
4. **Restore from backup**: only as a last resort if steps 1-3 don't
   resolve the issue — restore the `pre-v2-migration` backup taken in
   deployment step 1, using the existing `scripts/restore_db.sh`.
5. **Exit maintenance mode**, confirm the pre-V2 application is serving
   correctly, report the exact failure that triggered the rollback.

---

**End of Phase 3 report. STOP — awaiting owner review before Phase 4
(formal verification write-up, already substantially covered above) and
Phase 5 (deployment plan sign-off). No deployment, no production
migration, no further action without explicit owner approval.**
