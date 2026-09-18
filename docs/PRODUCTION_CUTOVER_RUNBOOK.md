# PRODUCTION CUTOVER RUNBOOK — SCM + CIBADAK

Step-by-step, copy-paste execution runbook for the owner-approved SCM +
CIBADAK production cutover. Built on `docs/PRODUCTION_CUTOVER_CHECKLIST.md`
and `scripts/production_cutover_scm_cibadak.php`, both already proven
against a real staging database populated from the real ~1,007-SKU
catalog (`docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md`).

**This session has no access to your production server.** Every command
below is written for YOU to run, directly, on that server. Nothing in this
document was executed by the assistant — it only prepared this runbook and
the scripts it references.

## How to use this document

- Go through STEP 0 → STEP 17 **in order**. Do not skip ahead.
- Every step has 6 parts: **Purpose**, **Command**, **Expected output**,
  **STOP condition**, **Verify**, **Rollback**.
- Wherever a step's "STOP condition" is met: stop, do not improvise a fix,
  go to that step's Rollback (or the Emergency Rollback at the end), and
  only resume once the underlying cause is understood and corrected.
- Commands never embed a password. `mysql` commands use bare `-p` (it
  prompts interactively — nothing appears on screen or in shell history).
  PHP scripts read `DB_PASSWORD` from your server's own `.env` file, which
  you edit directly on the server — never paste it here or into chat.
- Placeholders to fill in once, at the top of your terminal session:

```bash
export PROD_HOST="<your production DB host>"
export PROD_PORT="<your production DB port, usually 3306>"
export PROD_USER="<your production DB username>"
export PROD_DB="<your production database name>"
export REPO_DIR="<absolute path to this repo on the production server>"
```
Every command below uses these variables — set them once, per terminal
session, and they carry through. They are never secrets themselves.

---

## STEP 0 — Pre-flight production environment check

**Purpose:** confirm you are about to operate on the correct machine,
directory, code version, and database — before anything else runs.

**Command:**
```bash
echo "Server: $(hostname -f 2>/dev/null || hostname)"
echo "Directory: $(pwd)"
cd "$REPO_DIR"
echo "Repo dir: $(pwd)"
git branch --show-current
git log -1 --format="%H %ci %s"
php -v | head -1
php -m | grep -i pdo_mysql
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p -e "SELECT 1;" "$PROD_DB"
grep -E '^(APP_ENV|DB_HOST|DB_PORT|DB_DATABASE|APP_URL)=' .env
ls migration/workspace/staging_export/*.csv 2>/dev/null && wc -l migration/workspace/staging_export/*.csv
```

**Expected output:**
- `hostname` / `pwd` match the server and directory you intend.
- Branch is `claude/funny-ramanujan-wmrlig`.
- Commit hash is exactly `198fb3155b68e330e7b5625beb25054476399a12` — **the
  approved commit this runbook was generated from**. If your `git log -1`
  shows a different hash, STOP (see below) — do not pull ahead of this
  commit without re-running the dry run against the newer code first.
- PHP 8.1+ (this codebase uses `readonly` properties / `match` / ctor
  promotion — targets 8.1+, tested against 8.4).
- `pdo_mysql` is listed.
- `SELECT 1;` returns `1` (real DB connectivity, real credentials, prompted
  interactively — not printed).
- `.env` shows `APP_ENV=production`, and `DB_DATABASE` equal to `$PROD_DB`.
- The 4 `staging_export/*.csv` files exist with row counts: `historical.csv`
  = 1265 (1264 rows + header), `master_items.csv` = 1008, `opening_16sep.csv`
  = 1131, `warehouses.csv` = 3.

**If the CSVs are missing:** these are derived from real business data and
are deliberately never committed to git (`.gitignore`). Regenerate them
**on this server**, from your own original source files (the same 5
`stok_awal_september_*.xlsx` / `In Out * 01-15 Sept 2026.xlsx` files you
originally supplied — copy them to
`migration/workspace/raw/final_opening_round1_2026-09-17/` on this server
if they aren't already there) plus the already-committed
`migration/workspace/normalized/unit_conversion_candidates_real_v6.json`:
```bash
python3 migration/scripts/export_scm_cibadak_staging.py
```
Then re-check the row counts above before continuing.

**What output means STOP:**
- Wrong server/directory.
- Commit hash differs from `198fb315...`.
- `.env` shows anything other than your real intended `DB_DATABASE`, or
  `APP_ENV` is not `production`.
- `SELECT 1;` fails (bad credentials, network, or firewall — fix that
  first, outside this runbook).
- CSV row counts don't match after regenerating (source files differ from
  what this runbook was built against — do not proceed; re-run the export
  and inspect the diff, or re-run the full dry run against the new data
  before continuing here).

**Verification command:** the block above *is* the verification — nothing
further needed before STEP 1.

**Rollback:** none needed — nothing has been written yet.

---

## STEP 1 — Backup existing production database/state

**Purpose:** a verified, restorable snapshot must exist before anything
destructive happens. This is the file every later rollback in this runbook
restores from.

**Command:**
```bash
cd "$REPO_DIR"
migration/scripts/backup_db.sh pre-import "$PROD_DB" -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p
```
(`-p` with nothing after it — you'll be prompted for the password
interactively by `mysqldump`.)

**Expected output:** a line naming the created file, of the form
`storage/backups/<PROD_DB>_pre-import_<UTC timestamp>.sql.gz`.

**What output means STOP:** any error from `mysqldump` (access denied,
connection refused, disk full). Do not proceed without a backup — go fix
the underlying issue (credentials, disk space) and re-run this step.

**Verify:**
```bash
BACKUP_FILE=$(ls -t storage/backups/${PROD_DB}_pre-import_*.sql.gz | head -1)
echo "Backup file: $BACKUP_FILE"
ls -lh "$BACKUP_FILE"
gzip -t "$BACKUP_FILE" && echo "Archive integrity OK"
zcat "$BACKUP_FILE" | head -20
```
Expect: file exists, non-trivial size (not a handful of bytes),
`gzip -t` reports no error, and the head of the dump shows real
`CREATE TABLE` / `INSERT INTO` statements for your existing schema/data.

**Record this filename** — you will need it for every Rollback section
below and for the Emergency Rollback at the end. Write it down now:

```
BACKUP_FILE = storage/backups/____________________________.sql.gz
```

**Rollback:** N/A — this step only reads, it creates the safety net for
every step after it.

---

## STEP 2 — Maintenance mode (if supported)

**Purpose:** prevent staff/testers from using the new system's UI while
data is being written underneath them.

**Honest note:** this codebase has **no built-in maintenance-mode toggle**
— it was never part of any earlier phase's scope. Per
`docs/DEPLOYMENT.md`, the new system already runs on its own
domain/subdomain, side-by-side with your existing system, with no shared
traffic — so this step is a courtesy to your own staff/testers using the
new system's UI during the import window, not a requirement for
correctness (the import itself is transactional and does not corrupt data
if a read happens concurrently).

**Command (pick whichever applies to your setup, or skip):**
```bash
# Option A: your hosting panel (aaPanel etc.) has a per-site maintenance
# toggle — use that instead of anything here.

# Option B: no panel toggle — temporarily block new logins at the
# application level by revoking login for non-SUPERADMIN roles is NOT
# built either. Simplest zero-code option: tell your staff not to use the
# new system's UI for the duration of STEPs 3-15, since only you (running
# this runbook) need DB access during that window.
```

**Expected output:** N/A (informational step).

**STOP condition:** none — this step cannot fail; it's advisory.

**Verify:** if you used your panel's toggle, confirm the site shows a
maintenance page in a browser.

**Rollback:** turn the panel toggle back off (STEP 16 does this
explicitly).

---

## STEP 3 — Pull/checkout the exact approved production commit

**Purpose:** ensure the code actually running matches what was tested —
never an arbitrary newer commit.

**Command:**
```bash
cd "$REPO_DIR"
git fetch origin claude/funny-ramanujan-wmrlig
git checkout 198fb3155b68e330e7b5625beb25054476399a12
```

**Expected output:** `HEAD is now at 198fb315 FINAL FAST-TRACK GO_LIVE READINESS: SCM+CIBADAK pre-production dry run`

**What output means STOP:** `git checkout` fails (uncommitted local
changes in the way — `git status` first, stash or discard deliberately,
never force through blindly), or the commit doesn't exist on this clone
(fetch didn't reach the right remote/branch).

**Verify:**
```bash
git log -1 --format="%H"   # must print exactly 198fb3155b68e330e7b5625beb25054476399a12
git status                 # should show a detached HEAD, clean tree
```

**Rollback:** `git checkout <the branch/commit you were on before>` — no
data has been touched yet.

---

## STEP 4 — Install/update dependencies

**Purpose:** none required. Documented for completeness.

**Command:** none. This codebase has **zero Composer dependencies by
design** (`docs/DEPLOYMENT.md` H.2: "so a plain aaPanel PHP install works
without a Packagist network dependency"). If your `.env` isn't created
yet, create it now from the template and fill in your real production
values directly on the server (never through this session):
```bash
[ -f .env ] || cp config/.env.example .env
# edit .env now with your real production DB_HOST/PORT/DATABASE/USERNAME/PASSWORD, APP_ENV=production, APP_URL=https://your-real-domain
```

**Expected output:** `.env` exists and is correctly filled in (re-check
with the STEP 0 `grep` command).

**STOP condition:** `.env` missing required keys — every command from
STEP 5 onward depends on it.

**Verify:** `php -r 'require "config/config.php"; var_dump($config["db"]["database"] !== "");'` → `bool(true)`.

**Rollback:** N/A.

---

## STEP 5 — Database clean / pre-flight checks

**Purpose:** confirm the target database has no leftover dummy/test data
before real data goes in.

**Command:**
```bash
cd "$REPO_DIR"
php scripts/assert_database_clean.php
```

**Expected output:** every line `PASS`, ending in `DATABASE CLEAN = PASS`.

**What output means STOP:** any `FAIL` line, or the script errors because
`items`/`warehouses`/etc. don't exist yet (schema not applied — that's
expected if this is a fresh database; in that case skip ahead to STEP 6
first, then come back and re-run this check before STEP 7).

**Verify:** the exit code — `echo $?` should be `0`.

**Rollback:** N/A (read-only check). If it fails because of unexpected
dummy data, investigate and clean that data through the normal audited
path (never a raw `DELETE`) before proceeding — do not run this cutover
against a database whose state you don't understand.

---

## STEP 6 — Apply schema/migrations

**Purpose:** bring the production schema to the exact structure this
cutover expects.

**Command:**
```bash
cd "$REPO_DIR"
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" < database/schema.sql
```

**Expected output:** no output on success (or a stream of nothing/no
errors — `mysql` is silent on a clean `CREATE TABLE` run).

**What output means STOP:** any `ERROR 1050 (42S01): Table '...' already
exists` — this means the schema (or part of it) is already applied. Do
**not** drop and recreate tables to force it through. Instead:
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "SHOW TABLES;"
```
and manually compare against `database/schema.sql`'s table list — if
everything this session added is already present (`movement_reconciliation_reviews`,
`is_migration_negative_approved` on it, `stock_adjustments.migration_issue_reference`,
nullable `stock_opening_lines.item_id`/`warehouse_id`), the schema is
already current and you can proceed to STEP 7. If something is missing,
apply only the missing pieces by hand from `database/schema.sql`, reading
each statement before running it.

**Verify:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "SHOW TABLES;" | wc -l
# expect 28 (27 tables + header line)
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "DESCRIBE stock_opening_lines;" | grep -E "item_id|warehouse_id"
# expect both show YES under the Null column
php scripts/assert_database_clean.php   # re-run STEP 5 — must still PASS
```

**Rollback:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p -e "DROP DATABASE IF EXISTS ${PROD_DB}; CREATE DATABASE ${PROD_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
migration/scripts/restore_db.sh "$BACKUP_FILE" "$PROD_DB" -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p
```
(only if `$PROD_DB` had real pre-existing data worth restoring — if this
is a fresh, empty database as `docs/DEPLOYMENT.md` specifies, a rollback
here is just `DROP DATABASE` + re-`CREATE DATABASE` empty, no restore
needed.)

---

## STEP 7 — Create/verify the real administrator account

**Purpose:** a real, securely-provisioned admin account to attribute this
cutover to — never a hardcoded or shared password.

**Command:**
```bash
cd "$REPO_DIR"
php scripts/provision_user.php <your_admin_username> "<Full Name>" SUPERADMIN
```
This prints a one-time generated temporary password to your terminal —
**nowhere else** (not logged, not stored in plaintext, only its bcrypt
hash is saved). Relay it to the real account holder through a channel you
already trust, then let your terminal scrollback fall away naturally —
don't paste it anywhere, including back into this session.

**Expected output:** something like
`Created user '<username>' (id=1, role=SUPERADMIN). Temporary password: <one-time string> — must be changed on first login.`

**What output means STOP:** `Refusing to run: username '...' already
exists` — if you already provisioned this admin earlier, skip this step
and just look up their id (Verify command below).

**Verify:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "SELECT id, username, role_id FROM users WHERE username = '<your_admin_username>';"
```
Record the `id` — this is your `CUTOVER_USER_ID` for STEP 8 onward.
```
CUTOVER_USER_ID = ____
```
Log in once via the real application UI/`/api/auth/login` and confirm you
are forced through `POST /auth/change-password` before anything else
works (`must_change_password=1` is enforced server-side, not just a
frontend nag).

**Rollback:** `DELETE FROM users WHERE id = <id>;` — safe, nothing else
references this user yet at this point in the runbook.

---

## STEP 8 — Import Global Item Master

**Purpose:** populate the item catalog (base units + the 29 owner-approved
alternate/purchase-unit conversions, created inline).

**Command:**
```bash
cd "$REPO_DIR"
export CUTOVER_USER_ID=<id from STEP 7>
export DB_DATABASE="$PROD_DB"
php -r '
require "services/Database.php"; require "services/Exceptions.php";
require "services/AuditService.php"; require "services/UnitConversionService.php";
require "services/UnitNormalizationService.php"; require "services/PriceAnomalyService.php";
require "services/ImportMasterItemService.php";
use App\Services\{Database, ImportMasterItemService};
$pdo = Database::connection();
$userId = (int) getenv("CUTOVER_USER_ID");
$id = ImportMasterItemService::stage($pdo, "migration/workspace/staging_export/master_items.csv", "master_items.csv", $userId);
$errors = $pdo->query("SELECT COUNT(*) FROM import_rows WHERE import_batch_id={$id} AND row_status=\"ERROR\"")->fetchColumn();
if ($errors > 0) { fwrite(STDERR, "{$errors} ERROR rows -- commit refused, inspect import_rows WHERE import_batch_id={$id}\n"); exit(1); }
$result = Database::transaction(fn (PDO $tx) => ImportMasterItemService::commit($tx, $id, $userId));
echo json_encode($result), "\n";
'
```

**Expected output:** `{"imported":1007,"skipped":0}`

**What output means STOP:** any `ERROR rows` message — do **not** re-run
with a modified CSV to force it through. Inspect:
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "SELECT row_no, messages FROM import_rows WHERE row_status='ERROR' ORDER BY row_no LIMIT 20;"
```
and fix the source data / re-run `export_scm_cibadak_staging.py`, or STOP
and escalate if the error is unexpected (this exact CSV imported cleanly
in staging with 0 errors — a different result here means the production
schema or `units` seed differs from staging, which needs to be understood
before continuing).

**Verify:**
```bash
php scripts/cutover_verify_master_import.php
```
Expect `total_items: 1007`, `duplicate_sku: 0`,
`invalid_or_missing_base_unit: 0`, `items_missing_identity_conversion: 0`,
`approved_alternate_purchase_conversions_created: 29`, and the script's
own `OK — proceed to STEP 9.` line.

**Rollback:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
DELETE FROM item_unit_conversions;
DELETE FROM items;
DELETE FROM import_rows WHERE import_batch_id IN (SELECT id FROM import_batches WHERE import_type='MASTER_ITEM');
DELETE FROM import_batches WHERE import_type='MASTER_ITEM';"
```
(Safe at this point — nothing downstream references these items yet.)

---

## STEP 9 — Import warehouse catalog (SCM, CIBADAK)

**Purpose:** create exactly 2 warehouses. Karang Tengah is deliberately
never created — see the note in Section 6.1 of
`docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md` on how this achieves the
"KT rejected" requirement without inventing a new status enum.

**Command:**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"
php -r '
require "services/Database.php"; require "services/Exceptions.php";
require "services/AuditService.php"; require "services/ImportSimpleMasterService.php";
use App\Services\{Database, ImportSimpleMasterService};
$pdo = Database::connection();
$userId = (int) getenv("CUTOVER_USER_ID");
$id = ImportSimpleMasterService::stage($pdo, "WAREHOUSE", "migration/workspace/staging_export/warehouses.csv", "warehouses.csv", $userId);
echo json_encode(Database::transaction(fn (PDO $tx) => ImportSimpleMasterService::commit($tx, "WAREHOUSE", $id, $userId))), "\n";
'
```

**Expected output:** `{"imported":2,"skipped":0}`

**STOP condition:** anything other than `imported: 2`.

**Verify:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "SELECT code, name, warehouse_type, is_active FROM warehouses;"
```
Expect exactly 2 rows: `SCM`, `CIBADAK`. **`KARANG_TENGAH` must NOT
appear.** If it does, STOP — something ran ahead of this runbook; do not
proceed to opening import until you understand why.

**Rollback:** `DELETE FROM warehouses WHERE code IN ('SCM','CIBADAK');`
(safe here — nothing references them yet).

---

## STEP 10 — Promote approved alternate-unit conversions (verification only)

**Purpose:** confirm only APPROVED/BUSINESS_CONFIRMED conversions exist —
never LOW/MEDIUM candidates.

**Important:** there is **no separate promotion action to run here.** The
29 approved purchase-unit conversions were already created as part of
STEP 8 — `master_items.csv` only ever populates the `purchase_unit`/
`purchase_conversion` columns for the 29 SKUs with
`approved_purchase_unit`/`approved_purchase_conversion` set in the Global
Master (i.e. `review_status = APPROVED`, `confidence = BUSINESS_CONFIRMED`)
— every other row leaves those columns blank, so
`ImportMasterItemService::createItem()` never created a conversion for a
LOW/MEDIUM candidate in the first place. This step is a verification that
that held true, not a new write.

**Command:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
SELECT COUNT(*) AS approved_conversions
FROM item_unit_conversions c JOIN items i ON i.id = c.item_id
WHERE c.unit_id <> i.base_unit_id AND c.is_purchase_default = 1 AND c.valid_to IS NULL;"
```

**Expected output:** `approved_conversions = 29`.

**STOP condition:** any number other than 29 — means either a candidate
conversion leaked in (data problem in the CSV) or an approved one is
missing (import problem). Do not proceed to STEP 11 until this is exactly
29.

**Verify:** same command as above — this step IS its own verification.

**Rollback:** N/A (nothing written this step).

---

## STEP 11 — Import historical records (Opening 1 Sep + IN/OUT 1–15 Sep)

**Purpose:** load the historical evidence trail, `inventory_effect = 0` —
visible for audit, never affecting current stock or FIFO.

**Command:**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"
php -r '
require "services/Database.php"; require "services/Exceptions.php";
require "services/AuditService.php"; require "services/UnitConversionService.php";
require "services/ImportHistoricalTransactionService.php";
use App\Services\{Database, ImportHistoricalTransactionService};
$pdo = Database::connection();
$userId = (int) getenv("CUTOVER_USER_ID");
$id = ImportHistoricalTransactionService::stage($pdo, "migration/workspace/staging_export/historical.csv", "historical.csv", $userId);
$errors = $pdo->query("SELECT COUNT(*) FROM import_rows WHERE import_batch_id={$id} AND row_status=\"ERROR\"")->fetchColumn();
if ($errors > 0) { fwrite(STDERR, "{$errors} ERROR rows -- commit refused\n"); exit(1); }
echo json_encode(Database::transaction(fn (PDO $tx) => ImportHistoricalTransactionService::commit($tx, $id, $userId))), "\n";
'
```

**Expected output:** `{"imported":1264}`

**What output means STOP:** any `ERROR rows` message. Inspect
`import_rows` the same way as STEP 8. This exact CSV imported cleanly (0
errors) in staging — a different result here means STEP 8/9 produced
different item/warehouse ids or names than expected; do not proceed.

**Verify (proves historical rows never touch live stock, per Section 3 of
the POLICY CORRECTION phase):**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
SELECT COUNT(*) AS historical_rows, SUM(is_historical_import=1 AND inventory_effect=0) AS correctly_flagged
FROM inventory_transactions WHERE is_historical_import = 1;
SELECT COUNT(*) AS batches_from_historical FROM inventory_batches
WHERE source_transaction_line_id IN (SELECT l.id FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id=l.transaction_id WHERE t.is_historical_import=1);"
```
Expect `historical_rows = 1264 = correctly_flagged`, and
`batches_from_historical = 0` (historical import never creates a batch).

**Rollback:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
DELETE FROM inventory_transaction_lines WHERE transaction_id IN (SELECT id FROM inventory_transactions WHERE is_historical_import=1);
DELETE FROM inventory_transactions WHERE is_historical_import=1;
DELETE FROM import_rows WHERE import_batch_id IN (SELECT id FROM import_batches WHERE import_type='HISTORICAL_TRANSACTION');
DELETE FROM import_batches WHERE import_type='HISTORICAL_TRANSACTION';"
```
(Safe — historical rows never created batches, so nothing else references
them.)

---

## STEP 11.5 — Migration-negative whitelist (seed + approve)

**Purpose:** required before STEP 12. Without this, `OpeningValidationService`
correctly treats the 5 known migration-negative rows as ordinary,
non-whitelisted negative opening quantities and **rejects the entire STEP
12 batch with 5 ERROR rows** — this is not a hypothetical: it is exactly
what happens if this step is skipped. Reuses the same two scripts already
verified end-to-end in the staging dry run — no new logic, just run
against the production connection.

**Command:**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"
php scripts/seed_movement_reconciliation_review.php
php scripts/approve_migration_negative_whitelist.php
```

**Expected output:**
```
Seeded/updated 8 movement_reconciliation_reviews rows.
Approved 5 migration-negative whitelist row(s): 100304/SCM=-0.5, 777419/SCM=-0.5, 400201/CIBADAK=-466.5, 555410/CIBADAK=-250, 800401/CIBADAK=-162
```

**What output means STOP:** `approve_migration_negative_whitelist.php`
refuses (exit 1, `REFUSING TO APPROVE`) if any of the 5 rows' recorded
`historical_calculated_ending` doesn't match the owner's exact stated
figures — it re-verifies this itself before approving anything. Do not
proceed to STEP 12 if this happens; the historical data in this database
disagrees with what was approved, which needs to be understood first.

**Verify:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
SELECT sku, warehouse_code, historical_calculated_ending, is_migration_negative_approved
FROM movement_reconciliation_reviews ORDER BY sku;"
```
Expect 8 rows total, exactly 5 of them (100304, 777419, 400201, 555410,
800401) with `is_migration_negative_approved = 1`.

**Rollback:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "DELETE FROM movement_reconciliation_reviews;"
```
(Safe — nothing else references this table yet at this point.)

---

## STEP 12 — Import LIVE Opening 16 Sep (SCM + CIBADAK)

**Purpose:** the authoritative baseline. **This is the step that actually
creates real, current stock/FIFO batches** — the most consequential single
action in this runbook.

**Command (staging first, then commit — two separate, reviewable
sub-steps, not one blind action):**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"

# 12a. STAGE only -- read-only against inventory_batches, safe to inspect before committing.
php -r '
require "services/Database.php"; require "services/Exceptions.php";
require "services/AuditService.php"; require "services/UnitConversionService.php";
require "services/UnitNormalizationService.php"; require "services/PriceAnomalyService.php";
require "services/IdempotencyService.php"; require "services/MigrationNegativeStockService.php"; require "services/InventoryService.php";
require "services/FifoService.php"; require "services/PeriodLockService.php"; require "services/WarehouseLockService.php";
require "services/CostNormalizationService.php"; require "services/OpeningValidationService.php";
require "services/ImportOpeningStockService.php";
use App\Services\{Database, ImportOpeningStockService};
$pdo = Database::connection();
$userId = (int) getenv("CUTOVER_USER_ID");
$id = ImportOpeningStockService::stage($pdo, "migration/workspace/staging_export/opening_16sep.csv", "opening_16sep.csv", $userId);
echo "STAGED opening batch id={$id}\n";
$errors = $pdo->query("SELECT COUNT(*) FROM stock_opening_lines WHERE stock_opening_id={$id} AND row_status=\"ERROR\"")->fetchColumn();
echo "ERROR rows: {$errors}\n";
'
```

**Expected output (12a):** `STAGED opening batch id=<N>` then
`ERROR rows: 0`. **Write down `<N>` — you need it for 12b.**

**STOP condition (12a):** `ERROR rows` is not 0. Inspect via
`SELECT id, item_id, warehouse_id, qty_base, row_messages FROM
stock_opening_lines WHERE stock_opening_id=<N> AND row_status='ERROR';`
Do not proceed to 12b.

```bash
# 12b. COMMIT the staged batch (id from 12a) -- this is the write that
# creates real inventory_batches rows. Run ONLY after 12a showed 0 errors.
export OPENING_ID=<N from 12a>
php -r '
require "services/Database.php"; require "services/Exceptions.php";
require "services/AuditService.php"; require "services/UnitConversionService.php";
require "services/UnitNormalizationService.php"; require "services/PriceAnomalyService.php";
require "services/IdempotencyService.php"; require "services/MigrationNegativeStockService.php"; require "services/InventoryService.php";
require "services/FifoService.php"; require "services/PeriodLockService.php"; require "services/WarehouseLockService.php";
require "services/CostNormalizationService.php"; require "services/OpeningValidationService.php";
require "services/ImportOpeningStockService.php";
use App\Services\{Database, ImportOpeningStockService};
use App\Services\ImportValidationException;
$pdo = Database::connection();
$userId = (int) getenv("CUTOVER_USER_ID");
$id = (int) getenv("OPENING_ID");
try {
    $result = Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, $id, $userId));
    echo json_encode($result), "\n";
} catch (ImportValidationException $e) {
    fwrite(STDERR, "COMMIT REFUSED: " . implode("; ", $e->errors) . "\n");
    exit(1);
}
'
```

**Expected output (12b):**
`{"imported":560,"control_total_value":2638047167.9209}`

**What output means STOP (12b):** `COMMIT REFUSED: ...` — this means the
control-total check inside `ImportOpeningStockService::commit()` itself
detected a mismatch and **already rolled back** (it runs inside
`Database::transaction()`) — nothing partial was left committed. Do not
retry blindly; understand why the staged total and the actually-created
batches would disagree (this should not happen if 12a showed 0 errors on
this exact CSV) before proceeding.

**Verify (this is the single most important check in the whole runbook —
confirms the 5 migration-negative rows landed exactly right and nothing
was zeroed or invented):**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
SELECT i.sku, w.code AS warehouse, b.qty_base, b.is_negative_layer, b.unit_cost_base
FROM inventory_batches b JOIN items i ON i.id=b.item_id JOIN warehouses w ON w.id=b.warehouse_id
WHERE i.sku IN ('100304','777419','400201','555410','800401')
ORDER BY i.sku, w.code;"
```
Expect exactly these 5 rows, `is_negative_layer=1` on every one:
```
100304  SCM       -0.5     1   <some cost>
400201  CIBADAK   -466.5   1   <some cost>
555410  CIBADAK   -250     1   <some cost>
777419  SCM       -0.5     1   <some cost>
800401  CIBADAK   -162     1   <some cost>
```
If any value differs from `-0.5`/`-466.5`/`-250`/`-0.5`/`-162` — **STOP.**
Do not proceed to STEP 13. This is the exact owner-approved policy from
the POLICY CORRECTION phase; any deviation means something upstream (CSV,
master data, or code) has drifted from what was tested.

**Rollback:**
```bash
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "$PROD_DB" -e "
DELETE FROM inventory_transaction_lines WHERE transaction_id IN (SELECT id FROM inventory_transactions WHERE reference_no LIKE 'OPENING-%');
DELETE FROM inventory_batches WHERE source_transaction_line_id IN (SELECT id FROM inventory_transaction_lines WHERE transaction_id IN (SELECT id FROM inventory_transactions WHERE reference_no LIKE 'OPENING-%'));
DELETE FROM inventory_transactions WHERE reference_no LIKE 'OPENING-%';
DELETE FROM stock_opening_lines WHERE stock_opening_id = ${OPENING_ID};
DELETE FROM stock_openings WHERE id = ${OPENING_ID};"
```
If this has already been followed by STEP 13+ actions (real transactions
posted on top), do **not** attempt this targeted rollback — go straight
to Emergency Rollback (restore from the STEP 1 backup) instead, since
later transactions may have consumed these batches.

---

## STEP 13 — Run opening reconciliation

**Purpose:** the GO_LIVE_READY gate, on the actual committed production
data.

**Command:**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"
php scripts/cutover_report.php
```

**Expected output:** ends with
`OK — SCM_GO_LIVE_READY=true CIBADAK_GO_LIVE_READY=true KARANG_TENGAH_STATUS=PENDING_CUTOVER`,
and within the JSON above it: `"go_live_ready": true`,
`"migration_negative_count": 5`, every `checks` value `0` except the two
`"PASS"` string checks, and `"KARANG_TENGAH warehouse exists in this DB: no"`.

**What output means STOP:** the script itself exits non-zero and prints
`STOP — review the output above before proceeding.` if `go_live_ready` is
not `true` or Karang Tengah exists. Read the `checks` block to see which
count is non-zero — this mirrors exactly what
`docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md` Section 3 already proved
at 0 in staging, so a non-zero here means production data actually
differs from what was tested.

**Verify:** the command above IS the verification. Save its full output —
you'll compare it again in STEP 15.

**Rollback:** read-only step, nothing to roll back. If it STOPs, go back
to STEP 12's rollback or Emergency Rollback.

---

## STEP 14 — Production smoke tests (minimal, reversible)

**Purpose:** prove the live system actually works end-to-end, using the
smallest possible real transactions — each one indexed here so it can be
individually voided/reversed afterward. **Do not use
`tests/staging_smoke_test.php` directly against production** — it refuses
to run unless `DB_DATABASE` contains "staging" (by design), and it creates
a throwaway admin user and a synthetic test item not meant for production.

**Precondition:** the admin account from STEP 7 must already have gone
through `POST /auth/change-password` (STEP 7's own Verify instruction told
you to do this via the real UI) — `must_change_password` is enforced
server-side on **every** endpoint, not just a frontend nag: if it's still
`true`, every command below will fail with `PASSWORD_CHANGE_REQUIRED`
instead of the expected result. If you skipped that, do it now:
```bash
curl -s -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -X POST https://<your-real-domain>/api/auth/change-password \
  -H 'Content-Type: application/json' \
  -d '{"current_password":"<the STEP 7 temporary password>","new_password":"<a new real password>"}'
```

First, log in for real and get a CSRF token (replace with your real admin
credentials — never typed into this session):
```bash
JAR=$(mktemp)
curl -s -c "$JAR" -b "$JAR" -X POST https://<your-real-domain>/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"<your_admin_username>","password":"<the password you set in STEP 7>"}'
```
Confirm the response shows `"must_change_password":false` — if `true`, do
the change-password call above before anything else. Copy the
`csrf_token` from the response into `$CSRF` for every command below:
```bash
export CSRF="<paste csrf_token here>"
export API="https://<your-real-domain>/api"
```

Pick one real, low-value SCM SKU and one real CIBADAK SKU you recognize
from your own catalog for tests 14.7–14.10 (any SKU with a small positive
opening balance works — check `GET $API/inventory/current/<sku>` first).
Use quantity `1` of the item's base unit throughout.

| # | Test | Command | Expected | Cleanup |
|---|---|---|---|---|
| 14.1 | Admin login | (done above) | `csrf_token` present | N/A |
| 14.2 | SCM stock list | `curl -s -b "$JAR" "$API/inventory/current?item_id=<id>&warehouse_id=<scm_id>"` | 200, real qty | N/A (read-only) |
| 14.3 | CIBADAK stock list | same with `warehouse_id=<cibadak_id>` | 200, real qty | N/A |
| 14.4 | Historical Opening 1 Sep visible | `curl -s -b "$JAR" "$API/inventory/ledger?item_id=<id>&warehouse_id=<wh_id>"` | a line with `transaction_type:"ADJUSTMENT"`, `is_historical:true` | N/A (read-only) |
| 14.5 | Historical IN/OUT 1-15 Sep visible | same response | lines with `transaction_type` `IN`/`OUT`, `is_historical:true` | N/A |
| 14.6 | Historical does NOT alter live stock | compare `balance_qty` on the historical lines vs. the live `OPENING` line's `balance_qty` | historical lines' `balance_qty` equals whatever it was immediately before them chronologically — never jumps on a historical row (see `docs/PHASE_G_DATA_POLICY_CORRECTION_MIGRATION_NEGATIVE.md` Section 3) | N/A |
| 14.7 | SCM IN (qty 1) | `curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -X POST "$API/transactions/in" -d '{"transaction_uuid":"smoke-scm-in-1","item_id":<id>,"warehouse_id":<scm_id>,"input_qty":1,"input_unit_id":<base_unit_id>,"unit_price_input":<real unit cost>,"transaction_date":"<today>"}'` | 200, `transaction_id` returned — **record it** | Void: `curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -X POST "$API/transactions/<id>/void" -d '{"request_uuid":"smoke-void-1","reason":"post-cutover smoke test cleanup"}'` |
| 14.8 | SCM OUT (qty 1) | same shape, `/transactions/out`, no `unit_price_input` | 200, `transaction_id` returned | Void the same way |
| 14.9 | CIBADAK IN (qty 1) | same as 14.7 with `warehouse_id=<cibadak_id>` | 200 | Void |
| 14.10 | CIBADAK OUT (qty 1) | same as 14.8 with `warehouse_id=<cibadak_id>` | 200 (unless the chosen SKU is one of the 5 migration-negative rows — see 14.14) | Void |
| 14.11 | SCM → CIBADAK transfer (qty 1) | `curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -X POST "$API/transfers" -d '{"transfer_uuid":"smoke-transfer-1","from_warehouse_id":<scm_id>,"to_warehouse_id":<cibadak_id>,"ship_date":"<today>","lines":[{"item_id":<id>,"input_qty":1,"input_unit_id":<base_unit_id>}]}'` | 200, `transfer_id` returned | Cancel (if not yet received): `POST $API/transfers/<id>/cancel` with `{"request_uuid":"smoke-cancel-1"}` |
| 14.12 | Receive that transfer | `curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -X POST "$API/transfers/<id>/receive" -d '{"request_uuid":"smoke-receive-1"}'` | 200 | If already received, cleanup is a matching -1 Stock Adjustment on the CIBADAK side instead of cancel |
| 14.13 | FIFO consumption sanity | compare 14.8's `unit_cost_base` in the response against the OLDEST batch's cost for that SKU/warehouse (`GET /inventory/batches?item_id=<id>&warehouse_id=<scm_id>`, ordered by `received_date`) | matches the oldest layer's cost, not a blended/newest one | N/A (read-only comparison) |
| 14.14 | Migration-negative OUT rejection | `curl -s -b "$JAR" -H "X-CSRF-Token: $CSRF" -X POST "$API/transactions/out" -d '{"transaction_uuid":"smoke-migneg-out","item_id":<id for 100304>,"warehouse_id":<scm_id>,"input_qty":0.1,"input_unit_id":<kg_unit_id>,"transaction_date":"<today>"}'` | **422**, `error.code = "NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT"` | N/A — request was rejected, nothing to clean up |
| 14.15 | Stock Opname (minimal) | Start a session scoped to ONE item only (`item_ids:[<id>]`), count it at its current system value (no variance), finalize, post | 200 at every step, `variance_qty_base = 0`, posts with no adjustment created | None needed — zero variance creates no adjustment |
| 14.16 | Stock Adjustment (reversible) | `POST $API/stock-adjustments` with `qty_base_delta: 1, adjustment_type: "CORRECTION", reason: "post-cutover smoke test"` on a healthy positive SKU | 200, `adjustment_id` returned | Reverse with a second adjustment `qty_base_delta: -1`, same `reason` suffixed `"(reversal)"` |
| 14.17 | Dashboard totals | `curl -s -b "$JAR" "$API/inventory/value"` | 200, `contains_unresolved_migration_negative_stock: true` | N/A |
| 14.18 | Karang Tengah blocked | `curl -s -b "$JAR" "$API/warehouses"` | response contains only `SCM`/`CIBADAK`, no `KARANG_TENGAH` | N/A |
| 14.19 | Auth/role/CSRF sanity | `curl -s "$API/warehouses"` (no cookie jar) → expect 401 `UNAUTHENTICATED`; `curl -s -b "$JAR" -X POST "$API/stock-adjustments" -d '{}'` (no `X-CSRF-Token` header) → expect 403 `CSRF_INVALID` | both as stated | N/A |

**STOP condition (any row):** a response that doesn't match "Expected", or
a 5xx error. Do not continue to the next row — go straight to that row's
own reversal if it partially succeeded, then to Emergency Rollback if the
failure is unexplained.

**Verify (after cleanup):**
```bash
php scripts/cutover_report.php
```
Confirm the counts (`live_opening_row_count`, `fifo_opening_batch_count`,
`migration_negative_count`) are unchanged from STEP 13's run, and the 5
migration-negative rows still read exactly `-0.5`/`-0.5`/`-466.5`/`-250`/`-162`
(the smoke test's IN/OUT pair on a healthy SKU nets to zero net stock
change once voided; it does NOT touch the migration-negative SKUs except
the deliberate-rejection test 14.14, which never posts anything).

**Rollback:** each row's own Cleanup column. If several rows are voided
and the state still looks wrong, go to Emergency Rollback.

---

## STEP 15 — Final production reconciliation and control totals

**Purpose:** confirm the post-smoke-test state still matches what was
proven in staging, within rounding tolerance.

**Command:**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"
php scripts/cutover_report.php
```

**Expected output:** same shape as STEP 13. Compare specifically against
the **staging** figures from `docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md`
Section 4 — they should match closely (SCM ≈ Rp2,330,669,085.78, CIBADAK ≈
Rp307,378,082.14, company ≈ Rp2,638,047,167.92) modulo whatever your real
production unit costs/quantities in the CSVs actually were, and modulo the
net-zero effect of STEP 14's voided smoke transactions.

**What output means STOP:** a large, unexplained divergence from the
staging figures — investigate before declaring success.

**Verify:** the report script's own `OK`/`STOP` line, plus a manual read
of the JSON.

**Rollback:** none at this point (read-only) — if the numbers are wrong,
this is a signal to go back and check STEP 12/14, or Emergency Rollback.

---

## STEP 16 — Exit maintenance mode

**Purpose:** reverse STEP 2.

**Command:** turn your panel's maintenance toggle back off (or simply
announce to staff that the system is live, if you skipped STEP 2's
toggle).

**Expected output:** site loads normally for staff again.

**STOP condition:** none.

**Verify:** load the real application URL in a browser, confirm normal
(non-maintenance) behavior.

**Rollback:** re-enable maintenance mode if you need to pause again.

---

## STEP 17 — Final health check

**Purpose:** the closing report.

**Command:**
```bash
cd "$REPO_DIR"
export DB_DATABASE="$PROD_DB"
curl -s "https://<your-real-domain>/api/system/health"
php scripts/cutover_report.php
```

**Expected output:** `GET /system/health` returns 200 healthy, and
`cutover_report.php` still shows `go_live_ready: true`,
`migration_negative_count: 5`, Karang Tengah absent.

**Report (fill in from STEP 13/15/17's actual output):**
```
SCM_LIVE = true
CIBADAK_LIVE = true
KARANG_TENGAH_PENDING = true
PRODUCTION_CUTOVER_STATUS = SUCCESS

SCM live opening value = Rp____________
CIBADAK live opening value = Rp____________
Company LIVE inventory value = Rp____________
Imported master SKU count = 1007
Historical transaction count = 1264
Live opening row count = 1130
FIFO opening batch count = 560
migration_negative_count = 5
Post-cutover smoke result = ___/19 PASS
Backup identifier/path = <$BACKUP_FILE from STEP 1>
Rollback point = pre-import backup taken in STEP 1, before any write in this runbook
```

**STOP condition:** health check unhealthy, or any figure above doesn't
match what STEP 13/15 already showed.

**Verify:** this step's own commands are the verification.

**Rollback:** Emergency Rollback below.

---

## EMERGENCY ROLLBACK

Use this if any STOP condition above is hit and you cannot cleanly
reverse it with that step's own Rollback section, or at any point you
decide to abandon the cutover.

```bash
cd "$REPO_DIR"

# 1. Confirm the backup file from STEP 1 (never guess this path):
echo "$BACKUP_FILE"
ls -lh "$BACKUP_FILE"

# 2. Take a snapshot of the current (broken) state first, for post-mortem —
#    never discard evidence of what went wrong:
migration/scripts/backup_db.sh post-import "${PROD_DB}_failed_cutover" -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p

# 3. Restore into a FRESH database (never directly into the live name, so
#    you can verify before swapping):
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p -e "CREATE DATABASE ${PROD_DB}_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
migration/scripts/restore_db.sh "$BACKUP_FILE" "${PROD_DB}_restored" -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p
# (this script asks you to type the target database name again as a
# confirmation step before it does anything)

# 4. Verify the restored copy looks right:
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p "${PROD_DB}_restored" -e "SHOW TABLES;"
php -r 'putenv("DB_DATABASE=____restored"); require "services/Database.php"; require "config/config.php";' # adapt as needed to point a one-off check at the restored copy

# 5. ONLY once verified: swap the restored copy in for the real name. This
#    is the one genuinely destructive line in this whole runbook — read it
#    twice before running it:
mysql -h"$PROD_HOST" -P"$PROD_PORT" -u"$PROD_USER" -p -e "
RENAME TABLE ${PROD_DB}.items TO ${PROD_DB}_broken.items;" 2>/dev/null || true
# MySQL has no native RENAME DATABASE -- the safe, standard approach is:
#   a) stop the application (maintenance mode, STEP 2, back on)
#   b) rename PROD_DB -> PROD_DB_broken_<timestamp> (keep it, do not drop)
#   c) rename PROD_DB_restored -> PROD_DB
#   d) point .env DB_DATABASE at PROD_DB (already correct if you renamed to the same name)
#   e) exit maintenance mode
# Do each of these as its own reviewed command, on your own server, at the
# moment you actually need this — not pre-scripted here, since the exact
# mechanics depend on whatever privileges your DB user actually has
# (RENAME DATABASE support varies; a full mysqldump export/import of
# PROD_DB_restored into PROD_DB after dropping PROD_DB's tables is the
# universally-supported fallback).

echo "STOP after this. Report the exact failure that triggered this rollback before attempting the cutover again."
```

Never attempt to "improvise" a data correction on top of a failed cutover.
If something went wrong, the backup from STEP 1 is the source of truth —
restore it, understand the failure fully (using the `_failed_cutover`
snapshot from step 2 above as evidence), fix the underlying cause, and
only then re-attempt from STEP 3 (re-checkout the approved commit) once
you're confident.
