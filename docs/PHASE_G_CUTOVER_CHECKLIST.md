# Phase G24 — Cutover Checklist

The exact checklist from the Phase G spec. **If one item fails, NO GO
LIVE** — this is not a majority-pass gate.

| # | Item | Status as of this batch | Where it's verified |
|---|---|---|---|
| 1 | Security PASS | ✅ Confirmed (Phase G0, this batch) | `tests/mysql_security_test.php` — 11/11 assertions |
| 2 | Backend PASS | ✅ Confirmed (Phase G0, this batch) | `tests/run_mysql_tests.sh` full suite |
| 3 | Frontend PASS | ✅ Confirmed (Phase D, prior batch) | Playwright smoke tests, Phase D final report |
| 4 | Production DB clean | ⏳ Tooling ready, not yet run against a real production DB | `scripts/assert_database_clean.php` (Phase G19) |
| 5 | Master import PASS | ⏳ Not yet run — no production file provided yet | `scripts/generate_import_quality_report.php MASTER_ITEM <id>` (Phase G9) + reconciliation |
| 6 | Opening import PASS | ⏳ Not yet run — no production file provided yet | Same tooling + Phase G15 control-total check + Phase G14 `opening_value_consistency`/`opening_vs_current_consistency` |
| 7 | Historical import PASS | ⏳ Not yet run — no production file provided yet | Phase G14 `historical_inventory_effect_zero`/`duplicate_legacy_transaction` |
| 8 | Reconciliation PASS | ⏳ Tooling ready (13 checks total, up from 7), not yet run against real production data | `GET /reconciliation` → `go_live_ready: true` |
| 9 | Backup created | ⏳ Tooling ready, not yet run against a real production DB | `migration/scripts/backup_db.sh` (Phase G17) |
| 10 | Users created | ⏳ Tooling ready, no real accounts provisioned yet | `scripts/provision_user.php` (Phase G21) |
| 11 | HTTPS PASS | ⬜ Not started — deployment/infra concern, out of this batch's scope | — |
| 12 | Domain PASS | ⬜ Not started — deployment/infra concern, out of this batch's scope | — |
| 13 | Mobile PASS | ✅ Confirmed (Phase D, prior batch) | 375px viewport, no horizontal scroll, all 10 tabs |
| 14 | FIFO test PASS | ✅ Confirmed (Phase G0, this batch) | `tests/mysql_smoke_test.php`, `tests/mysql_integration_test.php` |
| 15 | Concurrency PASS | ✅ Confirmed (Phase G0, this batch) | `tests/concurrency_test.sh 5` — 5/5 runs |
| 16 | No Google Sheets dependency | ✅ Confirmed (Phase D, prior batch) | Grep audit of `public/assets/` — zero hits |
| 17 | No production localStorage | ✅ Confirmed (Phase D, prior batch) | Only `sessionStorage['inv_active_tab']` (cosmetic) |
| 18 | `GO_LIVE_READY=true` | ⏳ True on the current dummy/test dataset; must be re-confirmed on real production data | `GET /reconciliation` |

## What "PASS" means for the ⏳ items

Every ⏳ item above has its tooling built and verified against sandbox/test
data in this session — the mechanism works. What hasn't happened yet is
running it against **real production data**, because none has been
provided (explicitly out of scope for this batch — see the "JANGAN"
list this batch was scoped under). Marking these ✅ before that happens
would be exactly the kind of false-positive this checklist exists to
prevent.

## Order of operations once real data arrives (Phase G-DATA)

1. Files land in `migration/workspace/raw/` → normalized → validated →
   `migration/workspace/approved/` (Phase G8 flow).
2. Dry run against `inventory_staging` (Phase G13) — full import order
   (Phase G20, not yet built): Warehouses → Divisions → Suppliers →
   Master Items → Opening Stock → Historical Transactions → Users.
3. Reconciliation PASS on staging.
4. `assert_database_clean.php` PASS on a **fresh** production database
   (never copied from staging, which may carry dry-run artifacts).
5. Backup (schema + pre-import) on production.
6. Same approved files imported into production, same order.
7. Reconciliation PASS on production. Backup (post-import).
8. Every row in this table is ✅. Only then: cutover date is chosen
   (Phase G12 — a human decision, never automated) and recorded in
   `CUTOVER_DATE`.

## Explicitly not this batch's job

Items 11/12 (HTTPS, Domain) are infrastructure/deployment concerns —
picking a hosting provider, DNS, TLS certificates — which depend on
decisions (domain name, hosting environment) that haven't been made and
weren't asked for in this batch. Flagged here so they aren't silently
forgotten, not because there's tooling to show for them yet.
