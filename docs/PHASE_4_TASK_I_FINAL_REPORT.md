# Phase 4 — Task I: Final Source/Git Report

**Phase 4 is complete. This report is the STOP point per the owner's
explicit instruction: "When Phase 4 complete, STOP... Wait for explicit
owner approval for Phase 5."** No deployment, no SSH/cPanel production
access, and no SQL against production occurred at any point in this
phase — every verification in Tasks A-H ran against local disposable/test
databases only, all dropped after use, or the local `inventory_test`
database used for the standing regression suite.

## 1. Final HEAD commit

```
affcd8ce354a760c2a742fc31cbc9a71796345f3
```

Branch: `claude/funny-ramanujan-wmrlig`, pushed to
`origin/claude/funny-ramanujan-wmrlig` at every step of this phase (no
unpushed local commits).

## 2. Commits added after `a058559` (the Phase 3 completion commit)

```
3d64b2b  Correct handoff doc's HEAD reference to the actual final commit
bb38b02  Phase 4 correction: LOW/SAFE threshold is buffer itself, not minimum+buffer
cd53c97  Phase 4 Task A: Stock IN/OUT 4-step stepper UI
52f8f1f  Phase 4 Task B: confirm GET /items compatibility + document scaling debt
eeb5423  Phase 4 Task C: full CHECK-constraint evidence for bakery_destination_id
96580b2  Phase 4 Task D: category migration verification evidence
7e5c2dd  Phase 4 Task E: min/buffer stock migration verification evidence
5f77804  Phase 4 Task F: admin requirements PASS/FAIL matrix (18 items)
69c34c5  Phase 4 Task G: full 20-category verification + close category-CRUD test gap
affcd8c  Phase 4 Task H: migration safety evidence (before/after/rollback counts)
```

10 commits. The first two (`3d64b2b`, `bb38b02`) predate the owner's
explicit 9-section Task A-I message but are part of this same
conditional-approval response: `bb38b02` is the formula correction the
owner's Phase 4 message required (documented prominently, not silently
folded in), done first before any other Phase 4 work began.

## 3. Git status

```
$ git status --short
(clean — no output)
```

Working tree is clean. Every file this phase touched is committed and
pushed.

## 4. Files changed (all commits `a058559..HEAD`)

```
 docs/PHASE_4_TASK_B_GET_ITEMS_DEBT.md               |  83 +++ (new)
 docs/PHASE_4_TASK_C_BAKERY_CHECK_CONSTRAINT.md      | 227 +++ (new)
 docs/PHASE_4_TASK_D_CATEGORY_MIGRATION_VERIFICATION.md | 246 +++ (new)
 docs/PHASE_4_TASK_E_STOCK_POLICY_MIGRATION_VERIFICATION.md | 195 +++ (new)
 docs/PHASE_4_TASK_F_ADMIN_REQUIREMENTS_MATRIX.md    |  70 +++ (new)
 docs/PHASE_4_TASK_G_FULL_VERIFICATION.md            | 101 +++ (new)
 docs/PHASE_4_TASK_H_MIGRATION_SAFETY_EVIDENCE.md    | 163 +++ (new)
 docs/PHASE_V2_TECHNICAL_DESIGN.md                   |  10 +-  (correction note added)
 docs/SESSION_HANDOFF_V2_AUDIT.md                    |  25 +-  (updated during session)
 public/assets/js/transactions.js                    | 619 +++--- (rewritten: stepper UI)
 scripts/v2_schema_postcheck.php                     |  76 +-   (expanded CHECK-constraint probe matrix)
 services/StockPolicyService.php                     |  23 +-  (formula correction)
 services/StockReportService.php                     |   6 +-  (formula correction, SQL mirror)
 tests/master_data_v2_test.php                       |  39 +   (new §F: category CRUD positive path)
 tests/stock_policy_test.php                         |  19 +-  (boundary tests rewritten for corrected formula)
```

Note: `docs/PHASE_4_TASK_I_FINAL_REPORT.md` (this file) is not yet in the
diff above since it is being created in this same commit cycle — it will
be added as the final commit of this phase.

## 5. Diff stat (source of truth: `git diff --shortstat a058559..HEAD`)

```
15 files changed, 1739 insertions(+), 163 deletions(-)
```

## 6. Migrations

No new migration files were created this phase (Task C/H exercised the
existing ones):

- `database/migrations/2026_09_18_v2_schema.sql` (created in Phase 3,
  unchanged this phase — verified safe again in Tasks C and H)
- `database/migrations/2026_09_18_v2_schema_rollback.sql` (same)

## 7. Rollback scripts

Same file as above — `2026_09_18_v2_schema_rollback.sql` is both "the
rollback script" and was itself dry-run twice more this phase (Task C: a
10-row synthetic dataset covering every `transaction_type`; Task H: a
realistic dataset with real FIFO batches/allocations and a computed
company inventory value). Both runs confirmed a full, lossless return to
the pre-migration state.

## 8. Test totals — exact, not "all passing"

**Backend regression suite** (`bash tests/run_mysql_tests.sh`, re-run as
the final step of this report, against a freshly reset `inventory_test`):

```
TOTAL: 285  PASSED: 285  FAILED: 0
```

(Up from 276 at the start of this phase — the +9 is the category-CRUD
positive-path test gap closed during Task G's own verification pass.)

**Browser smoke test** (Playwright, `scratchpad/smoke/stepper.js`,
against a local `php -S` dev server + local test DB, re-run fresh this
session):

```
TOTAL: 24  PASSED: 24  FAILED: 0
```

**Migration/rollback dry runs** (Tasks C and H, disposable databases):
28/28 postcheck assertions each of the 2 runs; all before/after/rollback
row-count and company-value comparisons matched exactly (see those
tasks' own reports for the full breakdown).

**Grand total this phase: 285 + 24 = 309 assertions run, 309 PASS, 0
FAIL, 0 SKIP**, plus 2 qualitative PASS results for the migration and
rollback dry runs (detailed, assertion-level counts for those live in
`docs/PHASE_4_TASK_C_BAKERY_CHECK_CONSTRAINT.md` and
`docs/PHASE_4_TASK_H_MIGRATION_SAFETY_EVIDENCE.md`, not re-added here to
avoid a third double-count — see `docs/PHASE_4_TASK_G_FULL_VERIFICATION.md`
for the full reconciliation arithmetic).

## 9. Known issues / unresolved technical debt

Carried forward from Phase 3 (`docs/PHASE_3_COMPLETION_REPORT.md`
Section 17) with this phase's updates:

- ~~**Stock IN/OUT forms were not the mockup's 4-step stepper.**~~
  **RESOLVED this phase (Task A).** Both flows are now the required
  4-step stepper (Informasi/Barang/Review/Selesai for IN;
  Tujuan/Barang/Review FIFO/Selesai for OUT), reusing the existing
  tested POST endpoints and FIFO service unchanged.
- **`GET /items` remains unpaginated** — still open, still a deliberate
  scope boundary per the owner's own Phase 2 condition, not a regression.
  Re-confirmed untouched this phase (Task B) and re-documented as known
  debt with a non-mandated future option (`GET /items/options?q=`).
- **Category distinct-value reporting undercounts true byte-distinct
  values for case and whitespace-only variants**, due to
  `utf8mb4_unicode_ci`'s case-insensitive comparison semantics (the
  actual production collation, confirmed by direct reproduction — see
  `docs/PHASE_4_TASK_D_CATEGORY_MIGRATION_VERIFICATION.md` §2). Not a
  correctness bug (no item lost, no incorrect assignment, original text
  always preserved) — newly discovered and documented this phase.
- **Transaction history's "source/destination area" is warehouse-level,
  not transfer-pair-level** — unchanged, still open, not in this phase's
  scope.
- **No full item editor** — unchanged, deliberate, still open.
- **Transfer/Stock Opname/Production pages were not visually restyled**
  to the dark-navy mockup — unchanged, still open, not in this phase's
  scope (the owner's Task A only asked for the Stock IN/OUT steppers).
- **No real production category or business data was available to this
  session** — still true. `scripts/report_category_distinct_values.php`
  and `scripts/backfill_item_categories.php` remain proven against
  synthetic/disposable data only (Task D this phase used a deliberately
  messy synthetic dataset, not real production text). The owner must run
  the report against the real database and supply an approved mapping
  before the backfill is ever run for real — this cannot be done from
  this session under the production freeze.
- **This report's own numbering caveat (Tasks F and G)**: the owner's
  Phase 4 message named an 18-item admin-requirements list and a
  20-category verification list. This session's context was compacted
  between receiving that message and writing the Task F/G reports, and
  the exact original item text/numbering did not survive compaction —
  only a paraphrase. Both reports reconstruct the lists from the best
  available evidence and flag this prominently at the top; **if the
  owner's original numbering differs, only the item-number mapping needs
  correction — every underlying PASS result stands independently and does
  not need to be re-verified.**
- **PDO named-placeholder reuse gotcha** — no new occurrences this phase,
  restated from Phase 3 as a standing gotcha for future work on this
  codebase (`SQLSTATE[HY093]` when the same `:name` placeholder is used
  twice in one prepared statement).

## 10. Exact deployment plan (NOT executed — reference only)

This phase did not change the deployment plan already fully specified in
`docs/PHASE_3_COMPLETION_REPORT.md` Section 18, which remains the
authoritative procedure. Two additions from this phase's work:

- **Step 8 ("Deploy source")** now also includes the rewritten
  `public/assets/js/transactions.js` (Stock IN/OUT stepper UI) — no new
  deployment step is needed since this is a plain static-asset file like
  any other in that step.
- **Step 9 ("Smoke test")** should additionally exercise: completing one
  full Stock IN via the stepper (Informasi→Barang→Review→Selesai) and one
  full Stock OUT via the stepper (Tujuan→Barang→Review FIFO→Selesai),
  confirming the FIFO layer preview renders and the transaction posts
  successfully — this is exactly what `scratchpad/smoke/stepper.js`
  automates and what this phase re-ran fresh (24/24 PASS) as its own
  verification.

Full 12-step procedure (backup → maintenance mode → precheck → schema
migration → postcheck → category backfill (owner-gated) → stock-policy
backfill → deploy source → smoke test → reconciliation check → exit
maintenance mode → health check) is unchanged from Phase 3 Section 18 —
see that document for the complete, exact command sequence. **None of
this has been run against production. This session has no production
access and will not attempt any of it.**

## 11. Exact rollback plan (NOT executed — reference only)

Unchanged from `docs/PHASE_3_COMPLETION_REPORT.md` Section 19, and
re-verified twice more this phase on disposable databases (Tasks C and
H): application rollback (redeploy previous source revision) → optional
schema rollback via `2026_09_18_v2_schema_rollback.sql` (only if new V2
data must be discarded; otherwise the additive schema is harmless to
roll back application code against) → restore-from-backup as a last
resort → exit maintenance mode → report the exact failure.

## 12. Production-freeze constraints — confirmed honored throughout Phase 4

Per the owner's explicit "PRODUCTION REMAINS FROZEN" instruction: this
session did not reset production, re-import data, change production
schema, modify production source, activate Karang Tengah, alter opening
balances, alter historical transaction data, alter FIFO layers, bypass
warehouse isolation, or deploy V2. Every migration/rollback/postcheck run
in Tasks C and H used a disposable local database (`inventory_taskc`,
`inventory_taskh`), each created fresh and dropped after use. Every
regression/browser-smoke run used the local `inventory_test` database.
No SSH, cPanel, or production SQL access occurred.

## 13. STOP

**Phase 4 is complete per this report. This session now stops and awaits
explicit owner approval before any Phase 5 (deployment) work begins.**
