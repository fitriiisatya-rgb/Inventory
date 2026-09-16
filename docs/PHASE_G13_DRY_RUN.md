# Phase G13 — Staging Dry Run Procedure

**Principle:** no approved production file is ever imported straight into a
production database. It goes into `inventory_staging` first, gets smoke
tested end to end, and only after that dry run and reconciliation (Phase
G14) both PASS does the same import get repeated against production
(Phase G18/G20 — not yet in scope).

## 1. Create a fresh staging database

```bash
migration/scripts/setup_staging_db.sh inventory_staging -h<host> -P<port> -u<user> -p<password>
```

This drops and recreates `inventory_staging` from `database/schema.sql` —
0 rows in every business table, only the seeded roles/permissions/units.

## 2. Provision the first SUPERADMIN

```bash
php migration/install.php <username> "<Full Name>"
```

(prompts for a password — see Phase G21 for the provisioning flow used for
every account after this first one).

## 3. Point the app at staging

Point `.env` (or the deployment's env config) at `inventory_staging` and
start the app (`php -S ... public/router.php` locally, or the real web
server in a real deployment).

## 4. Import approved files, in the G20 order

Only files that have gone through `migration/workspace/` and landed in
`approved/` (Phase G8) are imported here — never a raw file directly.
Through the normal API (`POST /import/upload` → `.../stage` → preview →
`.../commit`), in this order:

1. Warehouses
2. Divisions
3. Suppliers
4. Master Items
5. Opening Stock
6. Historical Transactions
7. Users (Phase G21 provisioning)

Check the data-quality report (Phase G9) after each stage; do not commit a
batch with unresolved ERROR rows.

## 5. Smoke test every module against staging

Manually (or via the same Playwright checks used for Phase D) confirm,
against the staging database's real imported data:

- Dashboard loads and its KPIs are non-zero and plausible
- Current Stock / Ledger for a handful of known SKUs matches the opening
  file's expected quantities and values
- Transaksi Masuk / Keluar post correctly against imported items
- Transfer, Production, Stock Opname all function against imported
  warehouses/items
- Book Closing preview loads without error
- Historical transaction reports show the imported historical rows, and
  confirm they do **not** appear in the Ledger (Phase G16) or move current
  balance

## 6. Run reconciliation

```
GET /reconciliation
```

Must return `go_live_ready: true`, including the new Phase G14 checks
(`opening_value_consistency`, `historical_inventory_effect_zero`,
`missing_unit_conversion`, `missing_cost`, `duplicate_legacy_transaction`,
`opening_vs_current_consistency`). Any ERROR here means: fix the
underlying data (re-stage a corrected file), not the reconciliation check.

## 7. Only then repeat against production

Once every check above is green on staging, the *same* approved files are
imported again — this time against a freshly created production database
(Phase G18, not yet built) — never by copying `inventory_staging` itself,
since staging may have accumulated test/reject artifacts during the dry
run that must never reach production (Phase G19's
`assert_database_clean.php` guards this).

## Status of this procedure

Tooling (`setup_staging_db.sh`) is built and verified in this session
(creates a fresh, 0-row schema on request). The full 7-step walkthrough
above has **not** been executed end-to-end yet, since no approved
production file exists — that happens in Phase G-DATA once real files are
provided and pass through `migration/workspace/`.
