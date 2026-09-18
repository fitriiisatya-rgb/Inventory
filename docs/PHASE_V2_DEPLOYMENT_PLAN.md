# Inventory FIFO Pro V2 — Production Deployment Plan (Phase 5)

**This is a plan for owner review. Nothing in this document has been
executed against production. No SSH/cPanel access, no production SQL, no
production source modification, and no Karang Tengah activation occurred
in producing it. STOP after this document is committed — wait for
explicit owner approval before any step below is actually run.**

Production is currently LIVE for **SCM / Gudang Besar** and **Cibadak**.
**Karang Tengah remains PENDING_CUTOVER** throughout this entire plan —
nothing below creates it, activates it, or migrates data into it.

---

## 1. Release commit

| | |
|---|---|
| Branch | `claude/funny-ramanujan-wmrlig` |
| Local HEAD | to be confirmed at the top of the commit that adds this file (see the commit this file ships in) |
| Origin HEAD | verified identical to local HEAD before this document was written |
| Last approved Phase 3 HEAD | `a058559` |
| Working tree | clean (`git status --short` — no output) at time of writing |

Verification commands run before writing this plan:

```
$ git rev-parse HEAD
$ git fetch origin claude/funny-ramanujan-wmrlig && git rev-parse origin/claude/funny-ramanujan-wmrlig
$ git status --short
$ git branch --show-current
```

Local and origin HEAD matched exactly; working tree was clean. The exact
commit hash is restated in `docs/PHASE_4_TASK_I_FINAL_REPORT.md` §1 (last
confirmed: `b2ff3da`) and will be one commit newer once this file and the
Task F/G corrections are committed — see that new commit's own hash in
`git log -1` at deploy time, not a hash copied into this document (a
hash frozen here would go stale the moment a later doc-only commit lands).

## 2. Release file/diff summary

Full diff from the last **owner-approved** Phase 3 HEAD (`a058559`) to
this release candidate:

```
$ git diff --stat a058559..HEAD
```

As of `docs/PHASE_4_TASK_I_FINAL_REPORT.md` (§4-5): **15 files changed,
1739 insertions(+), 163 deletions(-)** through Task I. This plan and the
Task F/G corrections add a small number of additional documentation/test
files on top (this document itself, `scripts/v2_production_control_totals.php`,
and the corrected `docs/PHASE_4_TASK_F_...md` / `docs/PHASE_4_TASK_G_...md`)
— no additional application source changes beyond what Task I already
reported. **No implementation changes were made in producing this plan**,
per the owner's explicit instruction to only make documentation/test
corrections.

The two files that change actual application behavior anywhere in this
whole release (`a058559..HEAD`) remain exactly what Task I already
reported:

- `services/StockPolicyService.php` — buffer-formula correction
- `services/StockReportService.php` — buffer-formula correction (SQL mirror)
- `public/assets/js/transactions.js` — Stock IN/OUT stepper UI rewrite
- `scripts/v2_schema_postcheck.php` — expanded CHECK-constraint probe matrix

Everything else in the diff is test files or `docs/*.md`.

## 3. Buffer-formula consistency — re-confirmed at every layer

Before finalizing this plan, the approved formula was re-verified by
direct source inspection (not re-assumed from memory) against the
**current** HEAD:

```
REVIEW:        migration_negative_review (checked first, always wins)
OUT_OF_STOCK:  qty <= 0
CRITICAL:      qty > 0 AND qty < minimum
LOW:           buffer configured AND qty >= minimum AND qty < buffer
SAFE:          qty >= minimum AND (buffer not configured OR qty >= buffer)
```

buffer_stock is confirmed an **ABSOLUTE threshold** (`qty < buffer`), never
`minimum + buffer`, in every layer:

| Layer | File:line | Confirmed |
|---|---|---|
| PHP service | `services/StockPolicyService.php:160-175` (`stockStatus()`) | `if ($buffer !== null && $qty < $buffer) return self::STATUS_LOW;` — exact match |
| SQL report query | `services/StockReportService.php:201-206` (`buildQuery()`'s status `CASE`) | `WHEN {$bufferExpr} IS NOT NULL AND COALESCE(b.qty_base, 0) < {$bufferExpr} THEN 'LOW'` — identical logic, order-matched |
| API output | `GET /reports/stock` rows, `GET/PUT /stock-policy` | Both read from the same `StockPolicyService`/`StockReportService` code paths above — no separate/duplicated status logic exists anywhere else in the codebase (confirmed via `grep -rn "STATUS_LOW\|'LOW'" services/ public/` — only these two files define LOW) |
| Report filters | `StockReportService::buildQuery()`'s `HAVING status = :statusFilter` | Filters against the SAME computed `status` alias the rows and summary both use — one query, one source of truth, filtering cannot drift from display |
| Dashboard / UI badges | `public/assets/js/ui.js:80-88` (`stockStatusBadge()`, `bufferCell()`); `public/assets/js/dashboard.js:74-75` | Purely render server-supplied `status`/`buffer_configured`/`buffer_stock` values — no client-side recomputation exists that could drift from the backend |
| Automated tests | `tests/stock_policy_test.php` §G (34/34, all 5 states + every boundary); `tests/stock_report_test.php` §F (SQL-vs-PHP cross-check on every row in its dataset, so the two implementations cannot silently diverge) | Both re-run fresh for this plan: 34/34 and 26/26 respectively, 0 FAIL |

**`buffer_stock = NULL` behavior, confirmed end-to-end:**

1. `StockPolicyService::stockStatus($qty, $minimum, null, false)` — the
   `$buffer !== null` guard on line 171 is `false`, so the `LOW` branch is
   skipped entirely regardless of `$qty`; falls through to `SAFE` (unless
   `CRITICAL`/`OUT_OF_STOCK` already fired first).
2. The API's `buffer_configured` flag is `false` whenever the underlying
   `buffer_stock_base` column is `NULL` (`item_warehouse_stock_policy`
   schema: `buffer_stock_base DECIMAL(20,6) NULL` — "NULL = not
   configured; never invented," per the column's own comment in
   `database/schema.sql`).
3. The UI (`ui.js:85-88`, `bufferCell()`) renders **"Buffer belum
   dikonfigurasi"** whenever `buffer_configured` is falsy, and the numeric
   value only when it's `true` — confirmed by direct code read, and by
   `tests/stock_policy_test.php` §H, which asserts a fresh policy row's
   `GET` response shows `buffer_configured:false` before any buffer is
   set.

**Conclusion: the formula is consistent everywhere it can possibly be
read or filtered. No drift is possible by construction** — `StockReportService`'s
row display, summary counts (feeding the dashboard), and status filter all
read the identical SQL `CASE` expression from one `buildQuery()` call; the
frontend never recomputes status client-side.

## 4. Phase 5 objective (restated)

This document is the **release/cutover plan for owner review**, not a
deployment. Every command sequence below is written to be copy-paste
ready for whoever the owner designates to actually run it, once approved
— this session will not run any of it against production.

---

## 5. Production pre-flight checklist

All pre-flight steps are **READ-ONLY** until explicitly marked otherwise.

### A. Source / Git

```bash
# On the machine that will deploy:
git fetch origin
git log --oneline a058559..origin/claude/funny-ramanujan-wmrlig   # review every commit before proceeding
git diff --stat a058559..origin/claude/funny-ramanujan-wmrlig     # confirm file list matches §2 above
git rev-parse origin/claude/funny-ramanujan-wmrlig                # record as RELEASE_COMMIT

# On the production server, BEFORE touching anything:
cd /path/to/production/webroot
git rev-parse HEAD                                                  # record as ROLLBACK_SOURCE_COMMIT
git status --short                                                  # must be clean — investigate before proceeding if not
```

Record both `RELEASE_COMMIT` and `ROLLBACK_SOURCE_COMMIT` in the
deployment log (a plain timestamped text file next to the backup, not
just someone's memory).

### B. Database backup

```bash
migration/scripts/backup_db.sh pre-import <prod_db_name> -h<host> -u<user> -p<password>
```

- Writes to `storage/backups/<db>_pre-import_<UTC-timestamp>.sql.gz`
  (never overwrites a previous backup — the timestamp in the filename
  guarantees uniqueness).
- **After it completes**: verify the file exists, `ls -la` it to record
  size, and run `gzip -t <file>` to sanity-check it isn't truncated/corrupt
  before trusting it as a rollback point.
- `chmod 600` the backup file (it contains full production data,
  including anything sensitive in `notes`/`address`/`email` columns) and
  confirm it is stored outside the public web root.
- Do not delete or overwrite any prior "known-good" backup — this is an
  additive step, not a replacement.

### C. Source backup

```bash
tar czf storage/backups/source_pre-v2_<UTC-timestamp>.tar.gz \
    --exclude='.env' --exclude='storage/logs' --exclude='storage/backups' \
    /path/to/production/webroot
cp /path/to/production/webroot/.env storage/backups/env_pre-v2_<UTC-timestamp>.env.secure
chmod 600 storage/backups/env_pre-v2_<UTC-timestamp>.env.secure
```

`.env` (containing `DB_PASSWORD` and any other secret) is backed up
**separately** from the source tarball and `chmod 600`'d immediately —
never bundled into an artifact that might be casually shared or committed.
Confirm neither backup file is inside anything served by the web server
or ever gets `git add`ed.

### D. Current production control totals (BEFORE)

**Read-only. Do not mutate data while gathering these values.**

```bash
php scripts/v2_production_control_totals.php --label=before_v2_migration
```

This new script (`scripts/v2_production_control_totals.php`, built for
this plan, verified this session against a local test DB with both empty
and populated data — see §D output shape below) captures in one
JSON snapshot, timestamped and written to `storage/reports/`:

- `item_count`, `warehouse_count`, `karang_tengah_warehouse_exists` (must
  be `false`), `user_count`
- `transaction_count`, `historical_transaction_count`,
  `opening_transaction_count`, `stock_openings_batch_count`
- `inventory_batch_count`, `fifo_allocation_count`
- `migration_negative_whitelisted_count`, `migration_negative_unresolved_count`
- `scm_on_hand_value`, `cibadak_on_hand_value`, `company_on_hand_value`,
  `in_transit_value`, `company_total_value`

Reconciliation status is intentionally **not** folded into this script
(it can be slower and has its own existing dedicated tooling) — run it as
its own step and record the result alongside this snapshot:

```bash
# Via the existing endpoint (as an authenticated ADMIN/SUPERADMIN):
curl -s https://<prod-host>/api/reconciliation -H "Cookie: ..." | jq .
```

Confirm `go_live_ready`/healthy status matches the last known-good state
before proceeding — a reconciliation that is already unhealthy is a STOP
condition (§8).

### E. Current security baseline (BEFORE)

Confirm these hold **before** any deployment step, using the existing,
already-passing regression suites as the specification of correct
behavior (all of the following are automated, not manual, and were
re-run fresh this session: `tests/mysql_security_test.php` 7/11 +
`tests/warehouse_isolation_regression_test.php` 28/28):

- Unauthenticated API call → `401 UNAUTHENTICATED`
- SCM user cannot read Cibadak inventory/ledger/batches (and vice versa)
- Stock Opname: cross-warehouse count/finalize/post all forbidden
- Transfer: receive is forbidden for the source-warehouse user, allowed
  only for the destination; cancel is forbidden for the destination,
  allowed only for the source
- A STOCK user cannot create a transfer FROM a warehouse that isn't theirs

These are **pre-existing, unchanged-by-this-release behaviors** — this
step exists to confirm production is currently healthy in these respects
before deployment, as a baseline to compare against after (§12), not to
introduce new checks.

---

## 6. Category production preparation (deliberately separated from schema deployment)

**No real production category mapping has been approved yet.** The V2
schema migration and the category backfill are two independent steps —
the release does **not** guess a mapping, and the application remains
fully functional before the backfill completes via its designed
fallback (`items.category_id` stays `NULL`; `StockReportService`'s
category filter/column simply shows "—"/uncategorized for those rows,
same as any item that was never assigned a category).

1. **Read-only discovery** (safe to run anytime, even before the schema
   migration):
   ```bash
   php scripts/report_category_distinct_values.php
   ```
   Writes `storage/reports/category_distinct_values_<timestamp>.csv`.
2. **Export** that CSV to the owner.
3. **Owner reviews** spelling variants, blanks, and duplicate-mapping
   candidates (the CSV's `normalized_key`/`group_has_multiple_raw_values`
   columns flag these for review — see
   `docs/PHASE_4_TASK_D_CATEGORY_MIGRATION_VERIFICATION.md` §2 for the
   one collation-related caveat: case/whitespace-only variants are
   already silently pre-merged by `utf8mb4_unicode_ci` before this report
   ever sees them, so the CSV's row count is a count of collation-
   equivalence classes, not exact byte-distinct strings).
4. **Owner explicitly approves** a mapping file
   (`raw_value,category_code,category_name`).
5. **Dry-run the backfill**:
   ```bash
   php scripts/backfill_item_categories.php <approved_mapping.csv> --dry-run
   ```
   Review the printed summary (`categories_created`, `categories_reused`,
   `items_updated`) — confirm it matches expectations before proceeding.
6. **Review the dry-run result** with the owner.
7. **Only then**, run for real (add `--allow-partial` only if the owner
   explicitly accepts leaving some raw values uncategorized for now):
   ```bash
   php scripts/backfill_item_categories.php <approved_mapping.csv>
   ```

This entire sequence (steps 1-7) is **not** on the blocking cutover path
(§9) — it can happen before, during a later maintenance window, or after
go-live, at the owner's pace, with zero impact on the release's
functional correctness.

## 7. Stock-policy production preparation

`scripts/backfill_stock_policy_scm_cibadak.php` — exact dry-run procedure:

```bash
php scripts/backfill_stock_policy_scm_cibadak.php --dry-run
```

**Before running for real**, confirm from the dry-run's printed output
and a direct query:

- `Target warehouses:` line names **only** `SCM` and `CIBADAK` — if
  `KARANG_TENGAH` or any other code appears, **STOP**, do not proceed
  (the script has its own hard-refuse guard for this, verified in
  `docs/PHASE_4_TASK_E_STOCK_POLICY_MIGRATION_VERIFICATION.md` §3, but
  confirm the printed output directly as a second check)
- `SELECT COUNT(*) FROM item_warehouse_stock_policy p JOIN warehouses w ON w.id=p.warehouse_id WHERE w.code='KARANG_TENGAH';`
  → must be `0` before AND after
- `Rows created:` count equals `(item_count) × 2` (SCM + Cibadak) minus
  any pre-existing policy rows (`Rows skipped`) — matches expectation
- Minimum values in the dry-run summary trace back to each item's
  existing `items.minimum_stock` (spot-check a few known SKUs)
- No `buffer_stock_base` is ever set by this script (it is hard-coded
  `NULL` in the INSERT — `scripts/backfill_stock_policy_scm_cibadak.php:90`)

Run for real only after the dry-run output is reviewed and looks correct:

```bash
php scripts/backfill_stock_policy_scm_cibadak.php
```

**STOP conditions**: any warehouse code other than `SCM`/`CIBADAK` in the
target list; a `Rows created` count that doesn't match `(item_count × 2) − (pre-existing rows)`;
any error output. This script makes **no** inventory-quantity or
FIFO-layer change — it only inserts rows into the new
`item_warehouse_stock_policy` table — confirmed by direct code read
(no `UPDATE`/`INSERT` touches `inventory_batches`,
`inventory_transactions`, or `fifo_allocations` anywhere in this script)
and re-verified empirically in Task H (company inventory value identical
to the rupiah before and after the backfill ran).

---

## 8. Migration precheck

```bash
php scripts/v2_schema_precheck.php
```

The migration must **NOT** proceed unless this prints `PRECHECK
PASSED`. Explicit STOP conditions (each one is an actual check this
script performs — see `scripts/v2_schema_precheck.php:45-94`):

- Any of the 3 new tables (`categories`, `item_warehouse_stock_policy`,
  `bakery_destinations`) already exists
- Any of the 4 new/changed columns already exists
  (`items.category_id`, `inventory_transactions.bakery_destination_id`,
  `suppliers.address`, `suppliers.email`)
- A required baseline table is missing (`items`, `warehouses`,
  `suppliers`, `inventory_transactions`, `users`) — wrong database
- Server version doesn't support enforced CHECK constraints (MariaDB
  < 10.2.1 / MySQL < 8.0.16) — the `bakery_destination_id` CHECK would be
  silently ignored, not enforced
- **Inability to create a verified backup** — do not proceed past §5B
  without a confirmed-intact backup file
- **Baseline reconciliation already unhealthy** — confirmed unhealthy in
  §5D's reconciliation check before this step even runs

---

## 9. Migration execution plan (NOT executed by this session)

```
 1. Enter maintenance mode                          (§5A confirms rollback source first)
 2. Confirm backups                                  (§5B, §5C — file exists, intact, chmod'd)
 3. Run schema precheck                              php scripts/v2_schema_precheck.php  → must print PRECHECK PASSED
 4. Apply additive V2 migration                      mysql <prod_db> < database/migrations/2026_09_18_v2_schema.sql
 5. Run schema postcheck                             php scripts/v2_schema_postcheck.php → must print POSTCHECK PASSED (28/28)
 6. Validate CHECK constraint                        (included in step 5's 28 — the 11 per-transaction-type probes)
 7. Reconcile baseline row counts                    (included in step 5's 28 — the 6 row-count-unchanged checks against the precheck snapshot)
 8. Run stock-policy dry-run                         php scripts/backfill_stock_policy_scm_cibadak.php --dry-run  (§7)
 9. Run approved stock-policy backfill                php scripts/backfill_stock_policy_scm_cibadak.php  (§7, after dry-run review)
10. Deploy V2 source                                 standard deployment of public/, services/, scripts/ per docs/DEPLOYMENT.md
11. Clear only necessary application/browser caches   (per docs/DEPLOYMENT.md's existing convention — no new cache layer introduced this release)
12. Smoke test                                        §10 below
13. Security test                                     §5E's baseline checks, re-run post-deployment
14. Reconciliation test                               GET /api/reconciliation — confirm unchanged from §5D's baseline
15. Exit maintenance mode
```

**Category backfill (§6) is explicitly NOT included in this blocking
sequence** — it only proceeds once the owner has approved a real mapping,
which has not happened yet. The V2 application is fully functional
without it (uncategorized items simply show no category, exactly as
before this release).

---

## 10. Production smoke-test plan

**AUTH**
- Superadmin login succeeds
- SCM operator login succeeds
- Cibadak operator login succeeds
- Unauthenticated API call → `401`

**DASHBOARD**
- Superadmin dashboard loads (company-wide)
- SCM dashboard loads without a reconciliation-permission error
- Cibadak dashboard loads
- Warehouse-scoped values are correct (SCM operator sees only SCM figures)

**STOCK REPORT**
- All-items page loads (incl. zero-stock)
- Search works
- Category filter works
- Pagination works
- Export works
- Minimum/buffer/status columns visible and correctly labeled
  (including "Buffer belum dikonfigurasi" for any item without a
  configured buffer)

**TRANSACTION HISTORY**
- Page loads
- IN filter works
- OUT filter works
- Detail drawer opens and shows header/lines/FIFO allocations
- Vendor shown on IN rows
- Bakery destination shown on OUT rows
- Qty/unit shown
- Warehouse scope enforced (STOCK user sees only their warehouse)

**MASTER DATA**
- Category list loads
- Vendor list loads
- Bakery destination list loads

**STOCK IN**
- 4-step workflow renders (Informasi → Barang → Review → Selesai)
- Review screen shows all required fields correctly

**STOCK OUT**
- 4-step workflow renders (Tujuan → Barang → Review FIFO → Selesai)
- Bakery Tujuan selectable
- FIFO layer preview renders
- Review screen shows all required fields correctly

**TRANSFER**
- Existing workflow remains correct (unchanged by this release)
- SCM can create a transfer from SCM
- Destination-only receive still enforced
- Source-only cancel still enforced

**STOCK OPNAME**
- Existing workflow still works (unchanged by this release)
- Cross-warehouse access remains forbidden

**How each of these was already verified pre-deployment** (not invented
for this plan): every item above except "detail drawer opens" and the
Master Data list-loads checks has a corresponding automated assertion
already passing in the regression suite or the Playwright stepper script
— see `docs/PHASE_4_TASK_G_FULL_VERIFICATION.md` for the exact mapping.
**Known gap** (see `docs/PHASE_4_TASK_F_ADMIN_REQUIREMENTS_MATRIX.md`
item 15's manual/browser evidence column): the item-detail drawer
(Overview/FIFO Layers/Movement tabs) has no dedicated Playwright script
yet — its production smoke-test pass must be a genuine manual click-through,
not a rerun of an existing automated check. This is worth closing with a
dedicated Playwright script in a future pass, but is not a blocker for
this release (the drawer's underlying endpoints are fully covered by
`warehouse_isolation_regression_test.php` and `transaction_history_test.php`).

---

## 11. Do NOT use real business transactions for smoke test

The earlier Phase 3 draft suggested posting one real OUT transaction
against production to prove the flow works end-to-end. **This plan does
not adopt that approach.** Posting a real business transaction merely to
verify a deployment is exactly the kind of convenience-driven
contamination of real FIFO/business history the owner's instruction
rules out.

**Adopted approach — layered, read-only-first:**

1. **Primary verification is read-only** (§10's AUTH/DASHBOARD/STOCK
   REPORT/TRANSACTION HISTORY/MASTER DATA sections) — these cover the
   large majority of the smoke-test surface without writing anything.
2. **Posting behavior itself (Stock IN/OUT end-to-end, including FIFO
   consumption) is NOT re-verified against production** for this
   release. It was already verified end-to-end this phase — 24/24
   Playwright assertions in `scratchpad/smoke/stepper.js`, against a
   real Chromium browser driving the real stepper UI against the real
   backend endpoints (`POST /transactions/in`, `POST /transactions/out`)
   — in a **non-production** environment (local `php -S` dev server +
   local test database). This is the "test posting before deployment in
   staging/test, not production" option the owner named as acceptable,
   and it is the one this plan uses as the primary evidence that posting
   works.
3. **An OPTIONAL, owner-gated production posting verification** is
   available if the owner wants direct production-environment proof
   beyond (2), using the safest mechanism this codebase actually has —
   `VoidService::void()` (`services/VoidService.php`), which never edits
   or deletes a posted row; it flips the original to `VOID` status and
   creates a brand-new, fully audited `REVERSAL` transaction that undoes
   exactly what the original did. If the owner explicitly requests this:
   - Use a **dedicated, unmistakably-named test SKU** created specifically
     for this purpose (e.g. `ZZ-SMOKETEST-DO-NOT-USE`), never a real
     product SKU.
   - Post one minimal IN (e.g. 1 unit at a nominal, clearly-fake cost).
   - Post one minimal OUT of a fraction of that (e.g. 0.5 units) with a
     real bakery destination selected, to exercise the field end-to-end.
   - **Immediately void the OUT first, then the IN** — this exact order
     matters: `VoidService::reverseBatchConsumption()` restores the
     batch's `qty_base` via the OUT's own recorded `fifo_allocations`
     rows; only once that's done does `VoidService::reverseBatchCreation()`
     (used for the IN) safely subtract the batch's full original quantity
     back to zero. Voiding in the wrong order risks a batch going
     negative from an incomplete reversal.
   - Immediately re-run `scripts/v2_production_control_totals.php` and
     confirm `company_total_value`/`scm_on_hand_value`/`cibadak_on_hand_value`
     are back to exactly their pre-test figures.
   - **Be transparent with the owner that this still leaves 4 new,
     clearly-void-tagged rows in production's permanent transaction
     history** (original IN, its reversal, original OUT, its reversal) —
     `VoidService` is audited-and-safe, not invisible. This is why it is
     optional and owner-gated, not a default step.

**Default recommendation for this release: use option 2 only (already
completed, non-production). Do not exercise option 3 unless the owner
explicitly asks for it.**

---

## 12. Post-deployment control totals (AFTER)

```bash
php scripts/v2_production_control_totals.php --label=after_v2_migration
```

Compare field-by-field against the `before_v2_migration` snapshot from
§5D. For a schema/UI-only deployment with no intentional inventory
movement (i.e. the default path — §11 option 2, no option-3 test
postings):

| Metric | Expected change |
|---|---|
| `scm_on_hand_value` | **unchanged** |
| `cibadak_on_hand_value` | **unchanged** |
| `company_on_hand_value` / `company_total_value` | **unchanged** |
| `in_transit_value` | **unchanged** |
| `migration_negative_unresolved_count` | **unchanged** |
| `transaction_count`, `historical_transaction_count`, `inventory_batch_count`, `fifo_allocation_count` | **unchanged** (or +4 if — and only if — the owner explicitly approved the option-3 test-and-void sequence in §11, in which case the two VOID + two REVERSAL rows are the only expected delta, and inventory VALUES must still be unchanged) |
| `karang_tengah_warehouse_exists` | **must remain `false`** |
| Reconciliation status (`GET /api/reconciliation`) | **must remain valid/healthy**, identical conclusion to §5D's baseline |

**Schema-only changes may ADD**: `categories` rows (0, unless the owner
separately approved and ran §6), `item_warehouse_stock_policy` rows
(from §7's backfill — expected, tracked separately, not an "unexpected"
delta), `bakery_destinations` rows (0 until the owner creates real ones),
new permission grants. **None of these may change inventory economics**
— if `company_total_value` moved by even a rupiah without an explicit,
approved transaction behind it, treat this as Case D in §13.

---

## 13. Rollback decision tree

**CASE A — Source/UI problem, schema healthy.**
Redeploy the previous source revision (`ROLLBACK_SOURCE_COMMIT` from
§5A). Leave the additive V2 schema in place — it is backward-compatible
(the pre-V2 application code never queries the new tables/columns, so
their mere presence causes no harm).

**CASE B — Migration postcheck failure, before the application has used
the new schema for anything real.**
Stop. Apply the schema rollback
(`database/migrations/2026_09_18_v2_schema_rollback.sql`) since it's
still safe — no real V2 data exists yet. Verify control totals
(`scripts/v2_production_control_totals.php`) match the pre-migration
baseline exactly. Restore the previous source revision.

**CASE C — V2 tables already contain valuable new business data**
(e.g. real categories, vendors, or bakery destinations were created by
users before a problem was found).
**Do NOT blindly DROP the new schema.** Roll back the application
source only (Case A's redeploy step). The additive schema causes no harm
to the older application code. Preserve the new tables' data and
investigate the actual problem separately — schema rollback is
explicitly NOT the default response once real data exists in the new
tables.

**CASE D — Inventory/reconciliation/control totals changed unexpectedly**
(any `*_value` or count in §12's table moved without an explicit,
approved reason).
Maintenance mode **stays ON**. Stop all transaction activity immediately.
**Do not improvise a fix.** Collect evidence: re-run
`scripts/v2_production_control_totals.php` again to confirm the anomaly
is real and not a transient read; compare against the DB backup taken in
§5B (`mysqldump` diff or manual inspection, read-only). **Restore from
the database backup only as an absolute last resort**, and only with
explicit owner approval — this is the most destructive available option
and is never taken unilaterally.

```
                    ┌─ Source/UI issue only, schema fine ──────► CASE A (redeploy source, keep schema)
                    │
Problem found ──────┼─ Postcheck failed, no real V2 data yet ──► CASE B (schema rollback safe, redeploy source)
during/after         │
deployment          ├─ V2 tables have real new data ───────────► CASE C (NEVER drop schema, source-only rollback)
                    │
                    └─ Inventory economics changed unexpectedly ► CASE D (maintenance mode stays on, evidence
                                                                          first, backup restore = last resort,
                                                                          owner approval required)
```

---

## 14. Release GO / NO-GO checklist

**GO** requires every one of the following to be true:

- [ ] All tests PASS — backend regression suite (285/285, `bash tests/run_mysql_tests.sh`), Playwright browser smoke test (24/24, `scratchpad/smoke/stepper.js`), both migration/postcheck dry runs (28/28 each) — see `docs/PHASE_4_TASK_G_FULL_VERIFICATION.md` §0 for exact scope of each figure
- [ ] `git status` clean on the release commit, local HEAD == origin HEAD
- [ ] Database backup taken, verified intact (`gzip -t`), `chmod 600`'d
- [ ] Source backup taken, `.env` backed up separately and secured
- [ ] `scripts/v2_schema_precheck.php` prints `PRECHECK PASSED`
- [ ] Release commit explicitly approved by the owner (this document's
      commit hash, confirmed in writing)
- [ ] Category strategy confirmed with the owner: either (a) explicitly
      deferred past go-live (V2 works fine without it), or (b) an
      approved mapping is ready and its dry-run has been reviewed
- [ ] Stock-policy dry-run output reviewed and confirmed correct (§7)
- [ ] Rollback plan understood and ready to execute (§13) — the operator
      running the deployment has read this document, not just this
      checklist
- [ ] Production control totals captured (§5D) before any write occurs
- [ ] No unresolved critical defect (a critical defect is any confirmed
      FAIL in the automated suites, or any manual smoke-test item that
      fails and cannot be immediately explained as pre-existing/unrelated)

**NO-GO** — any one of the following blocks deployment:

- [ ] Any automated test FAILs (0 tolerance — a FAIL means STOP, not "note it and continue")
- [ ] Reconciliation is not healthy at the §5D baseline check
- [ ] A warehouse-isolation regression is found (any cross-warehouse leak)
- [ ] A FIFO regression is found (allocation order, cost calculation, or
      negative-stock handling behaves differently than the regression
      suite expects)
- [ ] Database backup is unavailable, unverified, or corrupt
- [ ] `scripts/v2_schema_precheck.php` fails for any reason
- [ ] An unknown/unapproved production category mapping is about to be
      auto-applied (it never should be — §6 is entirely owner-gated;
      this line exists as an explicit tripwire, not because the
      pipeline would ever do this on its own)
- [ ] `KARANG_TENGAH` unexpectedly appears in the warehouse list at any
      point before, during, or after this release
- [ ] An unexpected DB/schema difference is found between what
      `v2_schema_precheck.php` expected and what production actually has

---

## 15. Estimated maintenance window

Based on the additive-only nature of the migration (no table rewrites,
no data transformation of existing rows, confirmed by Task H's before/
after/rollback row-count-and-value parity on a realistic dataset) and the
size of the deployment (schema + source only, category backfill excluded
from the blocking path):

| Step | Estimated duration |
|---|---|
| Backup (DB + source) | 5-15 min, depends on production DB size (untested against real production data volume from this session — the owner should time a backup run against production ahead of the actual window to refine this estimate) |
| Precheck | < 1 min |
| Schema migration | < 1 min (additive `ALTER TABLE`/`CREATE TABLE` only — no data migration) |
| Postcheck | < 1 min |
| Stock-policy backfill (SCM+Cibadak) | proportional to item count — the disposable-DB test in Task H processed 3 items × 2 warehouses instantly; at the real ~1,007-item scale (per `docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md`) this is still expected to be seconds, not minutes, since it's a simple per-item INSERT loop, but has not been timed at that exact scale |
| Source deployment | per existing `docs/DEPLOYMENT.md` procedure, typically a few minutes |
| Smoke test (read-only portion, §10/§11) | 10-20 min for a thorough manual pass |
| **Total estimated window** | **30-60 minutes**, with the read-only smoke test being the largest and most variable component |

This estimate has **not** been validated against production's actual
data volume or server performance — the owner should treat it as a
planning figure, not a guarantee, and budget a buffer accordingly.

## 16. Known remaining technical debt

Carried forward from `docs/PHASE_4_TASK_I_FINAL_REPORT.md` §9, unchanged
by this plan (this plan is documentation/planning only):

- `GET /items` remains unpaginated (deliberate, accepted scope boundary)
- Category distinct-value reporting undercounts true byte-distinct values
  for case/whitespace-only variants (collation behavior, not a
  correctness bug — see `docs/PHASE_4_TASK_D_...md` §2)
- Transaction history's "area asal/tujuan" is warehouse-level, not
  transfer-pair-level
- No full item editor (deliberate)
- Transfer/Stock Opname/Production pages not visually restyled to the
  dark-navy mockup (out of scope for this release)
- No real production category mapping approved yet (§6 addresses the
  process, not the mapping itself — that's the owner's decision to make)
- **New, added by this plan**: the item-detail drawer (Overview/FIFO
  Layers/Movement tabs) has no dedicated Playwright coverage yet — its
  production smoke-test verification (§10) must be manual for this
  release. Worth closing with a dedicated script in a future pass.
- PDO named-placeholder reuse gotcha (`SQLSTATE[HY093]`) — a standing
  note for anyone writing new raw SQL in this codebase, not an active bug

## 17. Post-deployment monitoring checklist

For the period immediately following go-live (recommend: active
monitoring for the first 24-48 hours, spot-checks for the following
week):

- [ ] Re-run `scripts/v2_production_control_totals.php --label=T+1h`,
      `T+24h` and compare against the `after_v2_migration` baseline —
      any unexplained delta in `company_total_value` is a Case D
      investigation (§13), not something to shrug off as "probably fine"
- [ ] Watch `storage/logs/app.log` for new error patterns not present
      before deployment
- [ ] Confirm at least one real IN and one real OUT are posted
      successfully by actual operators within the first day (this is the
      first genuine production proof the stepper UI works end-to-end —
      not manufactured for testing, just the first real usage)
- [ ] Re-run `GET /api/reconciliation` daily for the first week; confirm
      it stays healthy
- [ ] Confirm no `KARANG_TENGAH` warehouse has appeared
      (`karang_tengah_warehouse_exists` in the control-totals snapshot)
- [ ] If/when the owner approves and runs the category backfill (§6),
      re-run control totals immediately before and after that specific
      step too — it's a separate change with its own before/after pair,
      not folded into the migration's own baseline
- [ ] Confirm the stock-policy backfill's 0-Karang-Tengah-rows property
      still holds (`SELECT COUNT(*) FROM item_warehouse_stock_policy p JOIN warehouses w ON w.id=p.warehouse_id WHERE w.code='KARANG_TENGAH';`
      → must stay `0` for as long as Karang Tengah remains
      PENDING_CUTOVER)

---

**End of Phase 5 plan. STOP — do not deploy. Wait for explicit owner
approval for the actual production cutover.**
