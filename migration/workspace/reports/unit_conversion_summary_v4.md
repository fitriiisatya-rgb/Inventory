# Unit Conversion Reconstruction — Summary Report v4 (Phase G-DATA 1B.2)

Semantic grouping round. This does **not** repeat Phase 1B.1 — the
physical-unit normalization fix (KG↔GR, LTR↔ML) is unchanged from the v3
baseline (commit `4755ea0`). This round adds a separate classification
layer on top of that baseline: it decides what each remaining conflict's
evidence actually **means** (a real competing conversion factor? a
content descriptor? a business-vocabulary difference like SET vs PCS?)
and consolidates the 46 raw conflicts into a small number of business
decisions instead of 46 individual questions.

**Still no production posting.** Staging/analysis only.

---

## 1. Starting conflicts

**46** (from v3, physical-normalization-only baseline).

## 2. Parser-resolved conflicts

**4** — resolved purely by fixing the name parser itself (composite
quantity parsing + known-unit validation), independent of grouping:

| SKU | Item | Old (broken) parse | New parse | Outcome |
|---|---|---|---|---|
| 100310 | Coklat bubuk bensdrop 8kg@3pcs | unit="PCS", qty=3 (compared directly against legacy 24 Kg — false conflict) | composite: 8 KG × 3 = 24 KG | Matches legacy exactly → AGREEMENT |
| 140808 | Kacang cincang @1kg x 10 | unit="KG", qty=1 (compared directly against legacy 10 Kg — false conflict) | composite: 1 KG × 10 = 10 KG | Matches legacy exactly → AGREEMENT |
| 110416 | SAUS TIRAM @ 1 LITER | qty=1, unit=LTR treated as a competing candidate factor against legacy's 1000 Gr | recognized as a **trivial restatement of the base unit** (already LTR, qty=1) — carries no conversion information | Excluded from candidate comparison → no conflict |
| 140710 | GONDENFIL CHOCO CRUCH @1 KG | qty=1, unit=KG treated as competing against legacy's 12 Kg (Carton) | recognized as **trivial base-unit restatement** — legacy's 12 KG/Ctn is a separate, non-competing purchase layer | Excluded from candidate comparison → no conflict |

## 3. Product-name false conflicts removed

**4** — `Kertas Roti` SKUs 333201–333204. Their trailing words
("Spesial", "Ori Kecil", "Ori Besar", "Talas") were being parsed as
units because the old parser accepted *any* word after a number. They
are now validated against a known-unit allowlist, fail it, and are
correctly classified `PRODUCT_VARIANT_TEXT` (product variant labels, not
units) — excluded from evidence entirely, no conflict raised.

## 4. Content-descriptor cases

**7** — the name value describes packaging **content**, not a
base-unit conversion, so it is never compared against legacy/base as a
competing factor:

- **CONTENT_WEIGHT_PER_INVENTORY_UNIT (5)**: 110301 (GARAM @250gr, base
  PCS), 140805/140806 (Bun Falling @1Kg, base PCS), 200132 (CMC @43 GR,
  base PCS), 400104 (DARK SWEET CHEERY @425gr, base PCS) — each states
  the net weight of one inventory unit, not a packaging multiplier.
- **CONTENT_QTY_PER_INVENTORY_UNIT (2)**: 100503 (Mix K600 @100Pcs, base
  PACK — "1 PACK contains 100 PCS" is a content note; base stays PACK)
  and 777211 (Kresek Jumbo @50Pcs, base KG — "≈50 pcs per KG" is
  operational info, not a KG→PCS conversion).

## 5. Composite-package agreements

**2** (100310, 140808 — see item 2's table). Both are also counted under
"parser-resolved" since the fix that produced the correct composite
value is what allowed them to match legacy.

## 6. Semantic-unit groups

**6 groups**, all "is unit X the same countable thing as unit Y for this
product line" questions — genuinely ambiguous business vocabulary, never
auto-resolved:

| Group | SKU count | Pattern |
|---|---|---|
| TOPPER_SET_VS_PCS | 17 | Base=PCS, name says "@12 SET", legacy says "12 Pcs" |
| PACK_VS_PCS | 4 | Base=PCS or PACK, name gives a PACK count, legacy shows PCS (often trivial factor 1) |
| SAME_UNIT_LEGACY_DEFAULT_VS_NAME_COUNT | 3 | Same unit (PCS) on both sides, but legacy shows the trivial default factor 1 while the name implies a real count |
| SHEET_VS_PCS | 1 | 333211: name "500 SHEET" vs legacy "500 Pcs" (same number, different label) |
| ROLL_VS_PCS | 1 | 444803: name "6 Roll" vs legacy's trivial "1 Pcs" |
| SET_VS_PCS | 1 | 666105: name "250 Pcs" vs legacy "250 Set" (same number, different label, non-Topper product) |

## 7. Number of admin decision groups

**6** (table above).

## 8. Number of unique SKU questions

**4** — could not be grouped, each has its own specific numeric or
dimensional disagreement:

| SKU | Item | Issue |
|---|---|---|
| 100201 | CHEFMATE 3 IN 1 @500GRX20 | Parser now correctly computes 500 GR × 20 = 10 KG, but legacy says the carton holds 20 KG — a genuine, specific 2× disagreement, not a recurring pattern. |
| 140541 | CREAMFILL CLASSIC BANANA | Pre-existing price-ratio-vs-legacy conflict (~136× ratio) — explicitly not covered by the business's 999208/999209/140539 confirmations; still open. |
| 400111 | Delifruit Dark Cherry 50 @2,7 Kg | Legacy `Isi Dasar` = 2.7 **Pcs** — a fractional PCS count is physically implausible and looks like a legacy data-entry artifact (same number 2.7 copy-pasted with the wrong unit), not real evidence. |
| 900501 | Wilton Blackforest @1Liter | Base unit is KG, name says "1 Liter" — both trivial (qty=1) but in a different *dimension* (weight vs. volume). No numeric conflict (both reduce to "no packaging factor"), but the base-unit dimension itself is ambiguous and worth a human's confirmation. |

## 9. Total SKUs requiring admin answer

**31** = 27 (across the 6 groups) + 4 (unique cases).

## 10. Movement-review count (kept separate)

**8** — unchanged from prior rounds, kept in its own MOVEMENT REVIEW
sheet, never mixed with conversion questions, per instruction: opening +
IN−OUT discrepancy is a different question from unit conversion.
SKUs: 100304, 150116, 400201, 555410, 666408, 777212, 777419, 800401.

## 11. Regression result

**82/82 PASS, 0 failures** (`tests/run_mysql_tests.sh`). No backend/PHP
code touched this round (Python analysis tooling only).

## 12. Confirmation: no production posting

Confirmed. This round only re-derived name evidence and grouped
existing v3 records; nothing was imported, replayed, posted as FIFO, or
scheduled as a cutover.

---

## Accounting check

15 (auto-resolved) + 27 (grouped) + 4 (unique) = **46** — matches the
starting count exactly, per instruction 15 ("don't optimize for a
smaller number — if 46 remain after correct analysis, report 46; if
they reduce, show exact reason for every reduction"). Every reduction
above has its exact SKU-level reason shown.

Auto-resolved breakdown (15): 5 CONTENT_WEIGHT_PER_INVENTORY_UNIT + 4
PRODUCT_VARIANT_TEXT + 2 NAME_TRIVIAL_BASE_UNIT + 2
COMPOSITE_PACKAGE_AGREEMENT + 2 CONTENT_QTY_PER_INVENTORY_UNIT.

## Priority of evidence (item 10 of the spec) — how it was applied

Product-name evidence was never allowed to override real transaction
behavior or the current Gudang Besar master. Where a SKU's own SCM/
Cibadak transaction data or GB master already established the base
unit, the name's own mention of a *different* unit was reclassified as
a content descriptor (weight- or count-per-unit) rather than treated as
a competing base-unit claim — it never won against the operational
evidence. The 4 unique cases and 6 groups above are exactly the
residual disagreements that real transaction data does **not**
resolve on its own (transaction data confirms the *operating* unit —
e.g. PCS — but not the packaging *count* a name like "@12 set" implies),
so they legitimately need a human decision.

## Global Master candidates & duplicates (unchanged from v3, no new admin question)

- 7 Global Master candidates (33515, 333516, 120104, 150110, 150114,
  150115, 150118) — identity can proceed; category available for 6 of 7
  from source data, 33515 alone has no trustworthy category and is
  tagged `NEEDS_CATEGORY` (left `NULL`, never invented). Per instruction
  11, this does not block conversion cleanup and is not raised as a
  separate admin question unless a category becomes operationally
  required before import.
- 900240 / 900251 duplicate-source SKUs — differ only in spelling/
  capitalization (confirmed in the prior round); per instruction 12,
  no admin question is needed. Gudang Besar's identity is the canonical
  one; the duplicate note is retained for audit only.

## Files produced

- `migration/scripts/semantic_grouping_v4.py` — the semantic layer
  (parser fix + classification + grouping), reads v3 baseline read-only.
- `migration/scripts/build_conversion_review_v4.py`,
  `migration/scripts/build_admin_decision_groups.py` — report builders.
- `migration/workspace/normalized/unit_conversion_candidates_real_v4.json`
- `migration/workspace/normalized/unit_conversion_review_v4.xlsx` — 7
  sheets: INSTRUCTIONS, SUMMARY, CONVERSION REVIEW (1,165 rows),
  CONVERSION_CONFLICT rows (6, includes the 3 grouped
  same-unit-legacy-default SKUs plus the 4 unique — see
  admin_decision_groups.xlsx for the authoritative split), GROUPED (24 =
  the CROSS_COUNT_UNIT rows), CONTENT DESCRIPTORS (10), MOVEMENT REVIEW (8).
- `migration/workspace/normalized/warehouse_unit_comparison_v4.csv`
- `migration/workspace/normalized/admin_decision_groups.xlsx` — 5
  sheets: INSTRUCTIONS, SUMMARY, DECISION GROUPS (6), AFFECTED SKUS (27),
  UNIQUE CASES (4). **This is the file for the owner/admin to answer.**
- `migration/workspace/reports/unit_conversion_summary_v4.md` — this file.

---

## STOP

**NO opening import. NO historical replay. NO production FIFO. NO
cutover.**

The next action is the owner/admin answering
`admin_decision_groups.xlsx` (6 grouped business questions + 4 unique
SKU questions). Once answered, a follow-up phase applies those
decisions the same way the 999208/999209/140539 business confirmations
were applied in Phase 1B.1 — as an explicit, auditable override layer,
never silently.
