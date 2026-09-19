# Phase 5A Production Pre-flight Finding — Postcheck Zero-Persistence Fix

**Status: FIXED, verified, committed. Not deployed. Production untouched.**

## The finding

The Phase 5A production pre-flight review of `scripts/v2_schema_postcheck.php`
found the CHECK-constraint enforcement probe unacceptable for production
use: it **committed** a throwaway warehouse, user, role (if needed), and
bakery-destination row, then relied on `DELETE` statements in a `finally`
block to clean them up. If the PHP process were killed between the
`commit()` and the cleanup `DELETE`s (e.g. `SIGKILL`, OOM, a server
reboot mid-script), those probe rows would remain permanently in the
database — unacceptable for a script meant to run against production.
Additionally, committing-then-deleting still consumes `AUTO_INCREMENT`
values unnecessarily.

## The fix

Rewrote the probe (`scripts/v2_schema_postcheck.php`, section 4) to a
**zero-persistence** design: everything now runs inside ONE outer
transaction that is **unconditionally rolled back** at the very end —
never committed under any code path. Each individual insert attempt is
wrapped in `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` (one named savepoint,
reused across all 11 attempts) so a rejected insert never disturbs the
transaction state for the next probe. There is no cleanup `DELETE`
anywhere — there is nothing to clean up: an interrupted process at any
point simply drops the uncommitted transaction, exactly as if nothing
had ever run.

**Empirically verified before shipping** (per the owner's explicit "if
SAVEPOINT is unsafe on MariaDB, use another approach and document it"
instruction — this was tested, not assumed):
- `ROLLBACK TO SAVEPOINT` after a `CHECK` constraint violation does
  **not** poison the surrounding transaction on MariaDB (unlike
  Postgres) — the transaction remains fully usable for the next probe,
  confirmed by running a live `SELECT 1` immediately after each
  rollback-to-savepoint.
- Reusing one named savepoint across all 11 probe attempts works
  correctly (`SAVEPOINT` once, `ROLLBACK TO SAVEPOINT` repeatedly).
- The design was also changed to **reuse an existing warehouse/user**
  (`SELECT ... LIMIT 1`) instead of always inserting a throwaway one —
  true for any real production database, and confirmed on a disposable
  DB seeded with real fixtures: zero throwaway warehouse/user rows were
  created; the original two real fixtures remained the only rows in
  those tables after the full probe ran.
- `bakery_destinations` is a brand-new V2 table with zero rows on a
  freshly migrated production database — one throwaway row there (never
  committed) is the only unavoidable insert. This is documented in the
  script's own header comment, not hidden.

**One honest, documented limitation** (not something any zero-persistence
design can eliminate): InnoDB never reclaims an `AUTO_INCREMENT` value
once allocated, even on `ROLLBACK`. Empirically, on this MariaDB version,
only the single **accepted** probe attempt (`OUT` with a bakery
destination) advanced `inventory_transactions`' auto-increment counter by
one; the 9 **rejected** (CHECK-violated) attempts did not advance it
further. The one throwaway `bakery_destinations` row consumes exactly one
id. These are small, bounded, harmless gaps in an internal `BIGINT`/`INT`
primary key sequence — not a data leak, not a row that persists, and an
unavoidable consequence of proving server-side CHECK enforcement with a
real `INSERT` (the only way to prove enforcement rather than assume it).
**Row counts — the guarantee that actually matters — are verified exactly
unchanged**, both by the script's own check (against the precheck
snapshot) and independently by this fix's own verification (below).

## Also fixed in this pass (owner's Fixes 2-4)

- **Fix 2**: postcheck now verifies all 6 foreign keys the migration
  adds (`fk_items_category`, `fk_iwsp_item`, `fk_iwsp_warehouse`,
  `fk_iwsp_created_by`, `fk_iwsp_updated_by`, `fk_tx_bakery_destination`)
  — was previously missing the two `_by` FKs.
- **Fix 3**: postcheck now verifies all 4 V2 permission codes exist,
  are granted to `SUPERADMIN` and `ADMIN`, and are explicitly **not**
  granted to `STOCK`/`DIVISION`/`VIEWER` — matching the migration's own
  design exactly (confirmed by reading
  `database/migrations/2026_09_18_v2_schema.sql`'s permission-seed
  section before writing the checks, not guessed): "STOCK/DIVISION/VIEWER
  get none of these four; their reads of the new endpoints reuse
  INVENTORY_VIEW."
- **Fix 4**: precheck's baseline-table check now also requires `roles`,
  `permissions`, and `role_permissions` to exist (previously only
  checked `items`/`warehouses`/`suppliers`/`inventory_transactions`/
  `users`) — the migration's permission-seed step writes to all three,
  and precheck must not assume they exist merely because a normal
  production database usually has them.

## Verification (Fix 5) — disposable DB, realistic dataset, independent proof

Database `inventory_fixverify` (dropped after verification), built from
the pre-V2 schema shape, seeded with 2 real warehouses (`SCM`,
`CIBADAK`), 1 real user, and one pre-existing `inventory_transactions`
row per `transaction_type` (10 rows) — mirroring real historical
production data, same approach as Tasks C/H.

1. **PHP syntax checks**: `php -l` clean on both
   `scripts/v2_schema_postcheck.php` and `scripts/v2_schema_precheck.php`.
2. **Migration against disposable DB**: `database/migrations/2026_09_18_v2_schema.sql`
   — ran with zero errors.
3. **Revised postcheck**: `php scripts/v2_schema_postcheck.php` →
   **54/54 PASS, 0 FAIL** (up from 28 — the increase is entirely the new
   FK/permission checks: 2 new FK checks + 20 new permission checks = 22
   more than before).
4. **Independently proved zero probe rows remain** — not just trusting
   the script's own output, ran direct `SELECT COUNT(*)` queries after
   the postcheck completed: `bakery_destinations` = 0 rows total (not
   just 0 matching the probe prefix — the table is genuinely empty), 0
   probe-prefixed rows in `inventory_transactions`/`warehouses`/`users`/
   `roles`. The original 10 pre-existing transaction rows (one per type)
   were confirmed unchanged. The 2 real warehouse fixtures and 1 real
   user fixture were confirmed as the *only* rows in those tables — no
   throwaway `POSTCHECK-PROBE-WH`/`postcheck-probe-user` rows were ever
   created, because the reuse-existing-fixture path was exercised.
5. **Proved no cleanup DELETE is necessary**: zero `DELETE` statements
   were run after the postcheck in this verification — the database was
   already clean, confirmed by step 4.
6. **Rollback dry run**: `database/migrations/2026_09_18_v2_schema_rollback.sql`
   — ran with zero errors. Row counts after rollback matched the
   pre-migration baseline exactly (`warehouses`=2, `users`=1,
   `inventory_transactions`=10, `roles`=5, `permissions`=20,
   `role_permissions`=49); all 3 V2 tables confirmed dropped; all 4 V2
   permission codes confirmed removed.
7. **Full regression suite**: `bash tests/run_mysql_tests.sh` →
   **285/285 PASS, 0 FAIL, 0 SKIP** (unchanged from before this fix —
   expected, since the regression suite doesn't invoke these two scripts
   directly, but confirms nothing else broke).

## Files changed

- `scripts/v2_schema_postcheck.php` — zero-persistence CHECK-constraint
  probe (SAVEPOINT-based, no commit, no cleanup DELETE), FK check
  expanded to all 6, new permission-seed verification section (24
  checks: 4 existence + 4×(SUPERADMIN+ADMIN granted, STOCK+DIVISION+VIEWER
  not granted) = 4 + 8 + 12 = 24)
- `scripts/v2_schema_precheck.php` — baseline-table check hardened to
  include `roles`, `permissions`, `role_permissions`

No production database was used at any point. Production remains
untouched.
