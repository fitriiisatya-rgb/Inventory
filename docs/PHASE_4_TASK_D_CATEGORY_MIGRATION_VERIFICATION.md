# Phase 4 — Task D: Category Migration Verification

**Status: COMPLETE.** The category backfill tooling (`scripts/report_category_distinct_values.php`
+ `scripts/backfill_item_categories.php`, built in Phase 3c) was exercised
end-to-end against a disposable test database seeded with a deliberately
messy `items.category` column — real values, spelling variants of the same
logical category, blanks, and a value intentionally left out of the
mapping — to prove every property the owner asked for. No production data
was used; the disposable database (`inventory_taskd`) was dropped after
this verification.

## 1. Test dataset (deliberately messy, by design)

12 items seeded with these `items.category` raw values:

| SKU | `items.category` (raw, exact bytes) | Intent |
|---|---|---|
| TASKD-001 | `Bahan Kue` | canonical spelling |
| TASKD-002 | `Bahan Kue` | duplicate of canonical |
| TASKD-003 | `bahan kue` | spelling variant — case |
| TASKD-004 | `Bahan-Kue` | spelling variant — punctuation |
| TASKD-005 | ` Bahan Kue ` | spelling variant — leading/trailing whitespace |
| TASKD-006 | `Kemasan` | distinct category |
| TASKD-007 | `Kemasan` | duplicate of distinct category |
| TASKD-008 | `Topping` | distinct category |
| TASKD-009 | `NULL` | blank |
| TASKD-010 | `''` (empty string) | blank |
| TASKD-011 | `'  '` (whitespace-only) | blank |
| TASKD-012 | `Bahan Baku Import` | distinct value, deliberately **excluded** from the first mapping to test refusal |

## 2. Evidence for distinct old category values

`php scripts/report_category_distinct_values.php` (read-only, makes no
changes) against the seeded data:

```
Distinct raw category values: 8
Normalized groups: 5
Groups with more than one raw-value variant (near-duplicate candidates): 2
Blank/NULL category rows: 2

Near-duplicate candidates (review these merge decisions manually):
  group 1 [bahan kue]: Bahan Kue (n=3) |  Bahan Kue  (n=1) | Bahan-Kue (n=1)
  group 2 [__BLANK__]:  (n=2) | (NULL) (n=1)
```

Full CSV output (`group_id, raw_value, normalized_key, item_count,
is_blank, group_has_multiple_raw_values`) is generated to
`storage/reports/category_distinct_values_<timestamp>.csv` — this is the
artifact the owner reviews to produce an approved mapping. The report
correctly flagged the `Bahan Kue` spelling-variant group as a near-duplicate
candidate **without auto-merging it** — merge decisions stay a human
approval step, never inferred by the tool.

**Two findings worth flagging explicitly** — both are inherent to
MySQL/MariaDB collation behavior, not bugs introduced by the Phase 3c
tooling, but both affect what the distinct-values report can show a human
reviewer, so they matter for how much trust to place in that report's
completeness:

**(a) Case-insensitive collation silently pre-merges case variants at the
SQL level, before the report or PHP ever sees them as separate rows.**
High confidence — reproduced directly: `items.category` is
`varchar(100)` under `utf8mb4_unicode_ci` (confirmed via `SHOW FULL
COLUMNS FROM items`, and this is the production collation, not an
artifact of the disposable test DB — a throwaway table created explicitly
with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` reproduces it
identically). A direct probe:

```sql
CREATE TABLE t (v VARCHAR(50)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INSERT INTO t VALUES ('Bahan Kue'), ('bahan kue'), ('BAHAN KUE');
SELECT v, COUNT(*) FROM t GROUP BY v;
-- returns ONE row: v='Bahan Kue', COUNT(*)=3
```

In this session's test data, `TASKD-003` was stored with `category =
'bahan kue'` (all lowercase) — a genuine byte-distinct value from
`TASKD-001`/`TASKD-002`'s `'Bahan Kue'`. The distinct-values report's
`SELECT category, COUNT(*) FROM items GROUP BY category` already merges
it into the same group *at the database level* — it never appears to the
PHP script, or the human reviewer, as a separate raw value the way the
punctuation/whitespace variants do (those are visible as separate CSV rows
because they are byte-distinct AND collation-distinct). Practically, this
means: (1) the reviewer cannot see or explicitly approve the case-variant
merge — it already happened before the report ran; (2) the mapping file
still worked correctly for `TASKD-003` with only `Bahan Kue` as an
explicit `raw_value` row, because the backfill script's own `UPDATE items
... WHERE category = :raw` uses the same case-insensitive collation, so
one mapping row transparently covers every case variant of that string.
The net effect on this test was **not incorrect** — `bahan kue` correctly
ended up in the `BAHAN-KUE` category — but it happened via collation
behavior, outside the tool's explicit "never auto-merge, human decides"
design intent for near-duplicate grouping. Worth documenting so a future
reviewer of the real production CSV understands the "distinct raw values"
count already excludes pure-case variants of the same string.

**(b) Whitespace-only variants are also pre-merged by the same
comparison semantics** (medium confidence): `''` (empty string,
TASKD-010) and `'  '` (2 spaces, TASKD-011) are stored as different byte
lengths (`LENGTH()` returns 0 and 2) but `GROUP BY category` merges them
into one bucket before the PHP script runs. This one is inconsequential in
practice — both rows are independently caught by `is_blank`
(`TRIM(category) = ''`) regardless of whether SQL merged them first, so
the eventual mapping outcome for blanks is unaffected either way.

Neither finding changes Task D's conclusion (no item lost, no silent
incorrect assignment, original text always preserved) but both mean the
"N distinct raw category values" figure the report prints is a count of
collation-equivalence classes, not exact byte-distinct strings — worth
stating precisely rather than treating that number as exact.

## 3. Normalized mappings, blanks, spelling variants, duplicate mappings

Approved mapping CSV used (`raw_value,category_code,category_name`):

```csv
Bahan Kue,BAHAN-KUE,Bahan Kue
 Bahan Kue ,BAHAN-KUE,Bahan Kue
Bahan-Kue,BAHAN-KUE,Bahan Kue
Kemasan,KEMASAN,Kemasan
Topping,TOPPING,Topping
(NULL),UNCATEGORIZED,Belum Dikategorikan
Bahan Baku Import,BAHAN-BAKU-IMPORT,Bahan Baku Import
```

This demonstrates every required case in one mapping:
- **Spelling variants → duplicate mapping to the same category**: three
  explicit raw-string rows (`Bahan Kue`, ` Bahan Kue `, `Bahan-Kue`) all map
  to the single `BAHAN-KUE` category code — proving the tool supports an
  explicit many-to-one mapping, made by a human, not inferred. A fourth
  variant, the all-lowercase `bahan kue` (TASKD-003), was **not** given its
  own row in this mapping, yet still resolved to `BAHAN-KUE` — because of
  the case-insensitive-collation behavior documented as finding (a) in §2,
  not because the tool merged it. One mapping row transparently covers
  every case variant of that exact string under `utf8mb4_unicode_ci`.
- **Blanks**: the single `(NULL)` mapping row covers all three blank
  variants (`NULL`, `''`, `'  '`) at once, because
  `backfill_item_categories.php`'s own precheck logic collapses any row
  where `category IS NULL OR TRIM(category) = ''` into the same `(NULL)`
  bucket before matching against the mapping file (line 85) — consistent
  with how the distinct-values report groups them too.

## 4. Item counts before/after, no item lost

| Stage | `items` row count |
|---|---|
| Before backfill | 12 |
| After partial backfill (`--allow-partial`) | 12 |
| After full backfill (mapping extended) | 12 |

Confirmed via direct `SELECT COUNT(*) FROM items` at every stage — the
backfill script only ever runs `UPDATE items SET category_id = ...`, never
`DELETE` or `INSERT` on `items`, so row count preservation is guaranteed
by construction; the count check is a belt-and-suspenders confirmation.

## 5. No category assignment silently changed incorrectly

**Refusal on incomplete mapping** (`Bahan Baku Import` intentionally
omitted, no `--allow-partial`):

```
REFUSING TO RUN — the following items.category values are not covered by the mapping file:
 - Bahan Baku Import (n=1)
```

Exit code 1. Zero database changes made (confirmed: `categories` table had
0 rows, all `items.category_id` still NULL, immediately after this refusal).

**`--dry-run` makes zero changes**, confirmed by running the same mapping
with `--allow-partial --dry-run` (reports the same summary numbers it
would produce for real) then checking the DB directly:
`categories` table = 0 rows, `items.category_id` = NULL for all 12 rows —
proving `--dry-run` truly previews without writing.

**`--allow-partial` leaves the uncovered item alone, never guesses**: after
the real (non-dry-run) partial run, `TASKD-012` (`Bahan Baku Import`) kept
`category_id = NULL` while its `category` text (`Bahan Baku Import`) was
untouched — 11 of 12 items got a `category_id`, exactly matching the
mapping's coverage.

**Original free text (`items.category`) is never modified**, verified with
a direct byte-for-byte comparison of every row's `category` column before
and after both backfill runs — every value, including the deliberately
preserved leading/trailing spaces on TASKD-005 (` Bahan Kue `), is
identical:

```
sku          category (raw text, unchanged)      category_id (assigned)
TASKD-001    Bahan Kue                            1 (BAHAN-KUE)
TASKD-002    Bahan Kue                            1 (BAHAN-KUE)
TASKD-003    bahan kue                            1 (BAHAN-KUE)
TASKD-004    Bahan-Kue                            1 (BAHAN-KUE)
TASKD-005     Bahan Kue                           1 (BAHAN-KUE)
TASKD-006    Kemasan                              2 (KEMASAN)
TASKD-007    Kemasan                              2 (KEMASAN)
TASKD-008    Topping                              3 (TOPPING)
TASKD-009    NULL                                 4 (UNCATEGORIZED)
TASKD-010    (empty)                              4 (UNCATEGORIZED)
TASKD-011    (whitespace)                         4 (UNCATEGORIZED)
TASKD-012    Bahan Baku Import                    NULL (uncovered, as intended)
```

**Idempotency**: re-running the exact same partial mapping a second time
produced `Categories created: 0`, `Categories reused: 4` (same 4 category
rows, same ids/codes/names — verified by direct `SELECT * FROM categories`
before and after, byte-identical) and re-assigned the same 11 items to the
same category_ids — a true no-op on data, not just "ran without error."

**Extending coverage only touches the newly-covered rows**: adding the
`Bahan Baku Import` mapping row and re-running produced `Categories
created: 1` (only the new category), `Categories reused: 4` (the original
4 untouched), and `Item rows with category_id set/refreshed: 12` — the
previously-assigned 11 items were re-affirmed to their existing category
(same ids, not reassigned to something else), and only `TASKD-012` newly
received a `category_id`. Final state: 12/12 items categorized, 0
remaining NULL, no item's `category_id` value changed from what it had
been assigned to in the earlier partial run.

## 6. Migration preserves original text until successful validation

By construction, `backfill_item_categories.php` (line 185: `"items.category
(the original free text) is never written by this script — only
category_id."`) never issues an `UPDATE items SET category = ...` —
`items.category` remains the single source of historical provenance
indefinitely, regardless of how many times the backfill runs, in what
order, or whether it's a partial or full pass. `category_id` is purely
additive metadata layered on top; there is no code path in this tool that
can lose or alter the original text.

## 7. Test commands (reproducible)

```bash
mariadb -u root -e "DROP DATABASE IF EXISTS inventory_taskd; CREATE DATABASE inventory_taskd;"
mariadb -u root inventory_taskd < database/schema.sql
# seed items with a units row + the 12-row test dataset from §1

DB_DATABASE=inventory_taskd php scripts/report_category_distinct_values.php
DB_DATABASE=inventory_taskd php scripts/backfill_item_categories.php mapping_partial.csv --dry-run          # refuses (missing value)
DB_DATABASE=inventory_taskd php scripts/backfill_item_categories.php mapping_partial.csv --allow-partial --dry-run  # previews only
DB_DATABASE=inventory_taskd php scripts/backfill_item_categories.php mapping_partial.csv --allow-partial     # real partial run
DB_DATABASE=inventory_taskd php scripts/backfill_item_categories.php mapping_partial.csv --allow-partial     # idempotency re-run
DB_DATABASE=inventory_taskd php scripts/backfill_item_categories.php mapping_full.csv                        # extend to full coverage

mariadb -u root -e "DROP DATABASE inventory_taskd;"
```
