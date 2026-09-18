# PRODUCTION CUTOVER CHECKLIST — SCM + CIBADAK Fast-Track

Prepared per the "FINAL FAST-TRACK GO_LIVE READINESS — SCM + CIBADAK"
instruction, Section 11. **Nothing on this page has been executed against
production.** Every command below is written to be copy-pasted by a human
operator, in order, with a real go/no-go decision at each checkpoint —
never run as one unattended script.

**For the granular, step-by-step version of this checklist** — every
action broken into Purpose/Command/Expected output/STOP condition/Verify/
Rollback, individually copy-paste tested end-to-end — use
`docs/PRODUCTION_CUTOVER_RUNBOOK.md` instead. This page remains the
higher-level conceptual overview; the runbook is what an operator should
actually follow command-by-command.

Prerequisite reading: `docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md` (the
dry run this checklist is the production twin of — same import services,
same input files, proven end-to-end in staging) and
`docs/PHASE_G17_BACKUP_RESTORE.md` (backup/restore mechanics referenced
below). Karang Tengah is explicitly out of scope — it stays
`PENDING_CUTOVER` and nothing here creates or touches it.

Fill in `<PROD_HOST>`, `<PROD_PORT>`, `<PROD_USER>`, `<PROD_PASSWORD>`,
`<PROD_DB>`, `<ADMIN_USER_ID>` before use. Treat the password as a secret —
never paste it into chat, a ticket, or a committed file.

---

## Go/no-go gate (all must be true before Command 1)

- [ ] Owner has reviewed and approved this checklist and the dry-run report.
- [ ] `docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md` shows `ALL REQUIRED
      VALIDATIONS MET: YES` and `go_live_ready: true`.
- [ ] A real admin account already exists in the target production
      database (Phase G21 user provisioning) — its `users.id` is
      `<ADMIN_USER_ID>` below. This script never creates a user.
- [ ] `migration/workspace/staging_export/*.csv` are the FINAL versions —
      regenerate with `python3 migration/scripts/export_scm_cibadak_staging.py`
      immediately before cutover if any source file changed since the dry run.
- [ ] A maintenance window is scheduled — Commands 3–7 write real data;
      nothing else should be writing to the same tables concurrently.

---

## 1. Production DB backup (pre-import)

```bash
migration/scripts/backup_db.sh pre-import <PROD_DB> -h<PROD_HOST> -P<PROD_PORT> -u<PROD_USER> -p<PROD_PASSWORD>
```
Confirm the resulting `storage/backups/<PROD_DB>_pre-import_<timestamp>.sql.gz`
exists and is non-empty before proceeding. This is the file Command 10
restores from if anything goes wrong.

## 2. Schema migration

Only if the production schema is not already current (compare against
`database/schema.sql`; this checklist assumes the schema — including every
table/column this session added: `movement_reconciliation_reviews`,
`is_migration_negative_approved`, `stock_adjustments.migration_issue_reference`,
nullable `stock_opening_lines.item_id`/`warehouse_id`, etc. — is already
live in production from earlier phases' migration work):

```bash
mysql -h<PROD_HOST> -P<PROD_PORT> -u<PROD_USER> -p<PROD_PASSWORD> <PROD_DB> < database/schema.sql
```
`schema.sql` uses `CREATE TABLE` (not `CREATE TABLE IF NOT EXISTS`) — running
it against a database that already has these tables will error out loudly
rather than silently no-op. If it errors, that is the schema already being
current; do not force it through.

## 3. Master item import (Global Item Master, SCM+CIBADAK population)

Runs as part of Command 5 below (the cutover script does steps 1–2 and 4–6 in
one pass so master items exist before historical/opening reference them).
There is no separate standalone command — see Command 5.

## 4. Warehouse catalog import (SCM, CIBADAK)

Also runs inside Command 5 (Step 1 of the script). Karang Tengah is
deliberately never created by this fast-track.

## 5. Approved conversions promotion + Master item + Warehouse + Historical + Opening import

```bash
CUTOVER_CONFIRM=I-HAVE-OWNER-APPROVAL-FOR-PRODUCTION-CUTOVER \
CUTOVER_USER_ID=<ADMIN_USER_ID> \
DB_HOST=<PROD_HOST> DB_PORT=<PROD_PORT> DB_DATABASE=<PROD_DB> DB_USERNAME=<PROD_USER> DB_PASSWORD=<PROD_PASSWORD> \
php scripts/production_cutover_scm_cibadak.php
```

This single script — `scripts/production_cutover_scm_cibadak.php`, the
proven production twin of `scripts/staging_dry_run_scm_cibadak.php` (same
import services, same CSVs, only the safety guards differ) — runs, in order:
warehouse catalog → Global Item Master (approved purchase-unit conversions
for the 33 owner-confirmed SKUs are created inline as part of item creation,
so no separate "promotion" step is needed) → migration-negative whitelist
seed+approve → historical records → LIVE Opening 16 Sep. It refuses to run
without both `CUTOVER_CONFIRM` and a real `CUTOVER_USER_ID`, and refuses if
`items` is not already empty (one-time population only). It halts with a
non-zero exit and prints every ERROR row rather than committing a partial
batch, at every step.

## 6. Historical import

Runs inside Command 5 (Step 4). No separate command.

## 7. Opening import (LIVE Opening 16 Sep)

Runs inside Command 5 (Step 5). No separate command.

## 8. Reconciliation command (standalone re-check, after Command 5 exits 0)

```bash
DB_HOST=<PROD_HOST> DB_PORT=<PROD_PORT> DB_DATABASE=<PROD_DB> DB_USERNAME=<PROD_USER> DB_PASSWORD=<PROD_PASSWORD> \
php -r '
require "services/Database.php"; require "services/Exceptions.php";
require "services/MigrationNegativeStockService.php"; require "services/InventoryService.php";
require "services/OpeningReconciliationService.php";
use App\Services\{Database, OpeningReconciliationService};
$pdo = Database::connection();
$id = (int) $pdo->query("SELECT id FROM stock_openings ORDER BY id DESC LIMIT 1")->fetchColumn();
echo json_encode(OpeningReconciliationService::report($pdo, $id), JSON_PRETTY_PRINT), "\n";
'
```
Confirm: `checks.negative_qty = 0`, `migration_negative_count = 5`,
`go_live_ready = true`. **If this does not match, STOP — do not proceed to
Command 9. Go to Rollback (Command 10) and investigate offline.**

## 9. Post-import smoke test

```bash
DB_HOST=<PROD_HOST> DB_PORT=<PROD_PORT> DB_DATABASE=<PROD_DB> DB_USERNAME=<PROD_USER> DB_PASSWORD=<PROD_PASSWORD> \
php tests/staging_smoke_test.php
```
Note: `tests/staging_smoke_test.php` refuses to run unless `DB_DATABASE`
contains "staging" (its own safety guard, since it POSTS real transactions
— it is a smoke test, not read-only). For a production run, copy it to a
reviewed one-off script with that guard changed to check for your real
`<PROD_DB>` name instead, or — safer — run it once more against a staging
DB restored from the just-taken pre-import backup + this same cutover
script, as a final rehearsal immediately before Command 5, rather than
against production directly. This checklist intentionally does not lower
that guard for you.

## 10. Rollback

If Command 8 or 9 fails, or the owner does not sign off after reviewing the
result:

```bash
mysql -h<PROD_HOST> -P<PROD_PORT> -u<PROD_USER> -p<PROD_PASSWORD> -e "DROP DATABASE IF EXISTS <PROD_DB>_rollback_check;"  # sanity: never drop the real DB name here
mysql -h<PROD_HOST> -P<PROD_PORT> -u<PROD_USER> -p<PROD_PASSWORD> -e "CREATE DATABASE <PROD_DB>_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
migration/scripts/restore_db.sh storage/backups/<PROD_DB>_pre-import_<timestamp>.sql.gz <PROD_DB>_restored -h<PROD_HOST> -P<PROD_PORT> -u<PROD_USER> -p<PROD_PASSWORD>
```
Then, once the restored copy is verified correct: coordinate an actual
cutback (rename/swap `<PROD_DB>` for `<PROD_DB>_restored`, or restore
in-place into `<PROD_DB>` itself per `docs/PHASE_G17_BACKUP_RESTORE.md`) —
deliberately a manual, reviewed step, never scripted here, since it
determines which database the live application points at.

`migration/scripts/restore_db.sh` never runs `CREATE DATABASE`/`DROP
DATABASE` itself and asks for the target database name to be typed again
as a confirmation step — by design, so a rollback can never be triggered by
a single mis-typed command.

---

## After a successful cutover

- Take the post-import backup: `migration/scripts/backup_db.sh post-import <PROD_DB> ...`
- Confirm the 3 previously-committed migration-negative SKUs remain exactly
  as calculated (100304=-0.5 KG, 777419=-0.5 KG, 400201=-466.5 KG,
  555410=-250 PCS, 800401=-162 LTR) via `GET /migration-negative-review`.
- Notify the admin team: these 5 rows need Stock Opname/Adjustment to
  resolve, referencing `migration_issue_reference` on each.
- Karang Tengah stays `PENDING_CUTOVER` — schedule its own cutover once its
  IN/OUT 01–15 Sept file is available and a full company-wide
  reconciliation (all 3 warehouses) has been run and approved.
