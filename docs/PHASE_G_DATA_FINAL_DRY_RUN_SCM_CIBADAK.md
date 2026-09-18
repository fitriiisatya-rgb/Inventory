# FINAL FAST-TRACK GO_LIVE READINESS — SCM + CIBADAK — Final Pre-Production Dry Run

Builds on the POLICY CORRECTION phase (migration-negative whitelist,
`docs/PHASE_G_DATA_POLICY_CORRECTION_MIGRATION_NEGATIVE.md`). This round
runs the actual import pipeline end-to-end against the **real** ~1,007-SKU
SCM+CIBADAK catalog and September reconstruction, in a dedicated **staging**
database — never production, never the `inventory_test` dev/test DB.

## 0. The most important caveat — read this first

Of the 1,007 SKUs actually needed for SCM+CIBADAK opening, only **33** carry
an owner-**approved** base unit (`review_status = APPROVED`,
`confidence = BUSINESS_CONFIRMED`). The other **974 (97%)** have only a
detector-guessed `global_base_unit_candidate` (861 LOW confidence, 104
MEDIUM, 9 with none at all — those 9 were excluded from this import
entirely, see Section 3). This dry run uses the candidate for those 974 so
the pipeline can be exercised end-to-end — every such item's `notes` column
is stamped `CANDIDATE_ONLY base unit -- NOT owner-approved`, never silently
presented as confirmed.

**`GO_LIVE_READY = true` below reflects technical/mechanical readiness of
the pipeline (schema, validation, FIFO, the migration-negative policy) —
it is not a claim that the underlying catalog data itself has owner
sign-off.** Production cutover of those 974 SKUs' base units still needs
the same real business confirmation this project has required at every
other step (matching the discipline already applied to the 33 approved +
5 migration-negative + 2 YUPI SKUs). This is a genuine, unresolved gap —
not something this round fixes, only surfaces clearly.

## 1. Three real bugs found and fixed by this dry run

Real-scale data (1,007 items, 1,130 opening lines, 1,264 historical rows)
surfaced three latent bugs that no prior synthetic (1–3 row) test had hit:

1. **`ImportOpeningStockService::commit()` crashed on any zero-quantity
   row.** `OpeningValidationService` flags qty=0 as WARNING ("row will not
   create a FIFO batch"), but commit() called `FifoService::postIn()`
   unconditionally, which requires `input_qty > 0` and threw
   `ValidationException`, aborting the entire batch (570 of 1,130 rows in
   the real SCM+CIBADAK data are zero-qty). **Fixed**: commit() now skips
   qty=0 lines (no batch, no `created_batch_id`), matching the validator's
   documented intent. Regression: `tests/opening_g_data_2_test.php` Test J.
2. **`stock_opening_lines.item_id`/`warehouse_id` were `NOT NULL`**, but
   `OpeningValidationService::validateRow()` intentionally returns
   `item_id: null` for an unknown SKU — and `OpeningReconciliationService`
   already counts `item_id IS NULL` as `unknown_sku`. The schema
   contradicted the service's own designed behavior, crashing `stage()`
   with an uncaught FK/NOT-NULL violation the first time a real unknown-SKU
   row was staged. **Fixed**: both columns are now nullable — `commit()`
   only ever processes VALID/WARNING rows, both always fully resolved, so
   this can never let a NULL-item/warehouse row reach `FifoService`.
3. **`ImportMasterItemService::createItem()` stamped every new conversion's
   `valid_from` as `now()`.** Since historical/opening data is dated in the
   past (1–16 Sept 2026) relative to any realistic import time,
   `FifoService::postIn()` correctly refused to post — the item's own
   identity conversion wasn't "active yet" as of those dates. This is not
   a dry-run artifact — it would hit any real cutover where master data is
   imported on the go-live day but opening/historical data is backdated.
   **Fixed**: identity/purchase/middle conversions created at item-creation
   time are now backdated to `2000-01-01` — a tautology (1 base unit = 1
   base unit) is true for all time, and packaging facts for an existing,
   already-operating business predate the day its catalog happens to be
   imported.
4. (Bonus, found via the HTTP-level smoke test, Section 7) **The
   migration-negative FIFO guard checked `$available`** (the FIFO-consumable,
   positive-only sum from `Database::lockFifoBatches()`) **instead of the
   item's true net balance.** The moment ANY positive batch existed
   alongside the negative layer (e.g. a partial IN), `$available` read
   positive even though the item was still net-negative overall, wrongly
   letting an OUT through. **Fixed**: the guard now sums *every* batch
   (`SELECT SUM(qty_base) FROM inventory_batches ...`, not just the
   positive ones) to get the true net balance. Regression: new Test D3 in
   `tests/migration_negative_stock_test.php`.

Also: `UnitNormalizationService` was missing an Indonesian alias
(`Lembar` → `SHEET`) that blocked the whole master-item batch on one row —
added, plus the same PAIL/JAR/SET/METER/BATANG aliases already used
Python-side, for parity.

## 2. Pipeline (Sections 4/6/7)

`migration/scripts/export_scm_cibadak_staging.py` re-runs
`reconcile_opening_partial.py` in-process (via `runpy`, no logic
duplicated) and reshapes its already-verified `scm_result`/`cb_result`
into 4 importer-ready CSVs (`migration/workspace/staging_export/`, never
committed — see `.gitignore`). `scripts/staging_dry_run_scm_cibadak.php`
loads them into a dedicated staging DB (refuses to run unless the DB name
contains "staging", and unless `items` is already empty) using the real
import services — no raw INSERT bypass anywhere:

Warehouses (SCM, CIBADAK only) → Global Item Master (1,007 items; approved
purchase-unit conversions for the 33 confirmed SKUs created inline, no
separate promotion step needed) → migration-negative whitelist seed+approve
→ Historical (Opening 1 Sep + IN/OUT 1–15 Sep, `inventory_effect=0`, 1,264
rows) → LIVE Opening 16 Sep = Closing 15 Sep (1,130 rows staged, 560 real
FIFO batches created — 570 were qty=0, correctly skipped).

One command runs the whole thing: `bash scripts/run_staging_dry_run.sh`
(resets the staging DB, runs the import, then the smoke test below).

## 3. Required validations (Section 5) — all met

```
unknown_sku: 0            (required 0)  PASS
unknown_warehouse: 0      (required 0)  PASS
unit_mismatch: 0          (required 0)  PASS
duplicate_item: 0                       PASS
duplicate_opening: 0      (required 0)  PASS
missing_required_cost: 0  (required 0)  PASS
ordinary_negative_opening: 0 (required 0) PASS
migration_negative_count: 5  (required 5) PASS
historical_inventory_effect_nonzero: 0 (required 0) PASS
```
`ALL REQUIRED VALIDATIONS MET: YES`. (9 SKUs with no usable base unit at
all — neither approved nor candidate — were excluded from the master
import entirely rather than guessed; they contributed 0 to every count
above since they never got as far as SCM/CIBADAK opening or historical
rows either.)

## 4. Opening control totals (Section 6)

| | SCM | CIBADAK |
|---|---|---|
| Item balance rows | 473 | 87 |
| Positive rows | 471 | 84 |
| Zero rows | 0 | 0 |
| Negative migration rows (whitelisted) | 2 | 3 |
| Inventory value | Rp2,330,669,085.78 | Rp307,378,082.14 |

**Company LIVE total (SCM+CIBADAK only): Rp2,638,047,167.92**
(`on_hand_value = in_transit_value` since nothing is mid-transfer at
opening; `contains_unresolved_migration_negative_stock = true`, honestly
included, never hidden).

**KARANG_TENGAH = PENDING_CUTOVER / EXCLUDED FROM LIVE TOTAL** — not
created in this staging DB at all (see Section 6).

## 5. The 5 migration-negative rows — exact, as staged

| SKU | Warehouse | Balance | Value |
|---|---|---|---|
| 100304 | SCM | -0.5 KG | -Rp19,011.28 |
| 777419 | SCM | -0.5 KG | -Rp24,000.00 |
| 400201 | CIBADAK | -466.5 KG | -Rp3,732,000.00 |
| 555410 | CIBADAK | -250 PCS | -Rp443,750.00 |
| 800401 | CIBADAK | -162 LTR | -Rp1,494,000.00 |

All 5 verified via `GET /migration-negative-review`: `status =
MIGRATION_NEGATIVE_REVIEW`, `needs_stock_opname = true`, each with its
`historical_opening`/`historical_in`/`historical_out`/
`historical_calculated_ending` drill-down evidence attached and a
`migration_issue_reference` (`MIGRATION-<sku>-<warehouse>`) ready for the
resolving Stock Opname/Adjustment to cite.

## 6. FIFO verification + critical smoke test (Sections 7–8)

`tests/staging_smoke_test.php` — real HTTP requests (`php -S` + curl, same
harness as `tests/mysql_security_test.php`) against the populated staging
DB. **27/27 PASS**:

login → item search (555410, both warehouses) → SCM IN → SCM OUT → CIBADAK
IN on a migration-negative SKU (allowed) → CIBADAK OUT on the same,
still-negative SKU (**blocked**, `NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT`,
this is what caught bug #4 above) → transfer SCM→CIBADAK + receive → FIFO
consumption (two IN layers, OUT correctly weighted-average from the
oldest first) → Stock Opname resolves 800401/CIBADAK (physical count 50
LTR) → OUT on 800401 now succeeds → Stock Adjustment directly resolves
100304/SCM (+3.5 KG, `migration_issue_reference` recorded) → historical
rows visible in the SKU ledger alongside the live opening (Section 3 of
the POLICY CORRECTION phase) → migration-negative review list (5→3 still
open after the two resolutions above) → dashboard/company totals
(`contains_unresolved_migration_negative_stock` correctly still true) →
**Karang Tengah rejected**: warehouse catalog contains only SCM+CIBADAK,
and staging an opening row against `KARANG_TENGAH` fails validation with
`unknown warehouse_code` (see Section 6.1 — this is the honest mechanism,
not a dedicated status enum).

### 6.1 Karang Tengah rejection — how it actually works

This project's `warehouses` table has no `PENDING_CUTOVER` status enum —
only `is_active`, which nothing currently enforces at transaction time.
This fast-track achieves the rejection the instruction asks for simply by
**never creating the Karang Tengah warehouse row** in this scope: any
attempt to reference it fails as "unknown warehouse" at the normal
validation layer. Functionally equivalent for this purpose, but stated
plainly rather than implied — a real `PENDING_CUTOVER` warehouse-status
business rule was not built this round.

## 7. Full regression (Section 9)

Auth/CSRF/role-scoping/idempotency/concurrency/period-locking/reversal-void
are covered in depth by the pre-existing suite, re-run in full after every
change above — no gaps introduced:

| Suite | Result |
|---|---|
| `tests/run.php` (offline SQLite) | 13/13 |
| `tests/mysql_smoke_test.php` | 4/4 |
| `tests/mysql_integration_test.php` | 35/35 |
| `tests/mysql_importer_test.php` | 17/17 (+5 new ledger-visibility checks) |
| `tests/mysql_void_test.php` | 15/15 |
| `tests/mysql_security_test.php` | 11/11 |
| `tests/opening_g_data_2_test.php` | 20/20 (+5 new zero-qty checks) |
| `tests/migration_negative_stock_test.php` | 31/31 (+2 new partial-IN regression checks) |
| `tests/concurrency_test.sh` (5 runs) | 5/5 |
| `tests/staging_smoke_test.php` (real data) | 27/27 |

**178/178 total.** `scripts/assert_database_clean.php` re-run against a
freshly-rebuilt schema: `DATABASE CLEAN = PASS`.

## 8. GO_LIVE_READY (Section 10)

```
SCM_GO_LIVE_READY = true
CIBADAK_GO_LIVE_READY = true
KARANG_TENGAH_STATUS = PENDING_CUTOVER
PARTIAL_WAREHOUSE_GO_LIVE_READY = true
```
No blocker beyond the 5 approved migration-negative exceptions and
Karang Tengah being out of scope — **subject to the Section 0 caveat
above**: this is mechanical/pipeline readiness, not a claim that the 974
candidate-only base units carry real owner sign-off.

## 9. Production cutover package (Section 11 — prepared, NOT executed)

`docs/PRODUCTION_CUTOVER_CHECKLIST.md` — 10 numbered steps (backup, schema
migration, master/warehouse/historical/opening import via
`scripts/production_cutover_scm_cibadak.php`, standalone reconciliation
re-check, post-import smoke test, rollback via
`migration/scripts/restore_db.sh`). `production_cutover_scm_cibadak.php`
is the production twin of the staging script proven in this round — same
import services, same CSVs, refuses to run without an explicit
`CUTOVER_CONFIRM` value and a real, already-provisioned `CUTOVER_USER_ID`.
**Neither has been run against production.**

## 10. STOP

Per the instruction: reporting only. **No production cutover was
executed.** Waiting for owner review of Section 0's data-governance
caveat and explicit approval before Command 1 of
`docs/PRODUCTION_CUTOVER_CHECKLIST.md` is ever run.
