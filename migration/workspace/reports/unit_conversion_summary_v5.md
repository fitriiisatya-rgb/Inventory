# Unit Conversion Reconstruction — Summary Report v5 (Phase G-DATA 1B.3)

Applies the owner/admin's answers to `admin_decision_groups.xlsx`
(Phase G-DATA 1B.2), received as a screenshot with numbered answers
covering all 6 decision groups and all 4 unique cases. Layered on top of
the v4 baseline (unchanged) as a separate, auditable override — same
pattern as Phase 1B.1's business-confirmed overrides.

**Still no production posting.** Staging/analysis only.

---

## All 31 SKU answered — 0 CONVERSION_CONFLICT remaining

Every SKU that needed an admin answer (27 across 6 groups + 4 unique
cases) was covered by the owner's response. `CONVERSION_CONFLICT` and
`CROSS_COUNT_UNIT` are both now **0** across the full 1,165-SKU catalog.

## Group answers applied

| Group | Owner's answer | Resolution | SKU count |
|---|---|---|---|
| TOPPER_SET_VS_PCS | "A ya, 1 set = 1 pcs. 1 pack isi 12 pcs" | SET ≡ PCS (identity). Purchase unit Pack = 12 Pcs (matches legacy exactly). | 17 |
| PACK_VS_PCS | "Iya isi pcs dalam 1 pak" | Use each SKU's name-derived PCS count as its Pack conversion: 111507→10, 111508→10, 333404→50. 100503 stays base=PACK with "100 Pcs" recorded as content only (Pack is already the base, no further purchase layer). | 4 |
| SHEET_VS_PCS | "baking powder 1 pack 500 sheet" | SHEET ≡ PCS (identity). Pack = 500 (matches legacy's 500 exactly). | 1 |
| ROLL_VS_PCS | "Strech film Betul 1 roll = 1 pcs. 1 ctn = 6 roll" | ROLL ≡ PCS (identity). Ctn = 6. | 1 |
| SET_VS_PCS | "Mika chiffon 1 set = 1 pcs. Per pack 250 pcs" | SET ≡ PCS (identity). Pack = 250 (matches legacy's 250 exactly). | 1 |
| SAME_UNIT_LEGACY_DEFAULT_VS_NAME_COUNT | Per-SKU (see below) | Legacy's trivial default (1) is superseded by a real count. | 3 |

`SAME_UNIT_LEGACY_DEFAULT_VS_NAME_COUNT`, per SKU:
- **333502** Sticker Bolu Amor Oval: "sekarang 1 lembarnya @50 pcs" — an
  **updated** authoritative count (50), superseding both the old
  name-parsed value (43) and legacy's default (1). 1 Lembar = 50 Pcs.
- **555911** Dus General: "1 pack 250 pcs" — confirms the name-derived
  value exactly. 1 Pack = 250 Pcs.
- **666201** Papper Cup Kecil: "1 pack 25 pcs" — confirms the
  name-derived value exactly. 1 Pack = 25 Pcs.

## Unique-case answers applied

| SKU | Item | Owner's answer | Resolution |
|---|---|---|---|
| 100201 | CHEFMATE 3 IN 1 @500GRX20 | "Chefmate 1 ctn 10 kg kemasan yang 500 gr" | Confirms the **corrected parser's** composite value (500gr × 20 = 10kg) over legacy's 20kg. 1 Ctn = 10 Kg. |
| 140541 | CREAMFILL CLASSIC BANANA | "1 ctn isi 10 kg" | New authoritative value, overriding both legacy's trivial 1kg and the price-ratio-derived ~136× candidate. 1 Ctn = 10 Kg. |
| 400111 | Delifruit Dark Cherry 50 @2,7 Kg | "1 pcs = 2,7 kg" | Confirms the name evidence is correct; legacy's "2.7 Pcs" was a mislabeled unit. Recorded as content: 1 piece nets 2.7 KG. Base unit stays KG. |
| 900501 | Wilton Blackforest @1Liter | "1 pcs = 1 kg" | Confirms base unit KG (matches legacy exactly, trivial factor 1); the name's "Liter" mention is informal, not used. |

## Updated distribution

| Metric | v4 | v5 |
|---|---|---|
| Confidence HIGH | 0 | 0 |
| Confidence MEDIUM | 110 | 110 |
| Confidence LOW | 1,020 | 1,000 |
| Confidence NONE | 21 | 21 |
| Confidence BUSINESS_CONFIRMED | 3 | 34 |
| Review status APPROVED | 3 | 34 |
| CONVERSION_CONFLICT | 6 | **0** |
| CROSS_COUNT_UNIT | 24 | **0** |

(31 rows moved from LOW/CROSS_COUNT_UNIT/CONVERSION_CONFLICT into
BUSINESS_CONFIRMED/APPROVED; the 999208/999209/140539 trio from Phase
1B.1 is untouched — 34 = 31 + 3.)

## Everything still open (not covered by this round, by design)

- **8 MOVEMENT_RECONCILIATION_REVIEW rows** — unchanged, kept in their
  own section throughout. Opening + IN−OUT discrepancy is a different
  question from unit conversion and was never mixed in.
- **7 Global Master candidates** — identity can proceed; 1 of 7 (33515)
  still has no trustworthy category (`NEEDS_CATEGORY`, never invented).
  Not blocking, per Phase 1B.2 item 11.
- **900240 / 900251 duplicate-source SKUs** — informational only, no
  admin question needed (differ only in spelling/capitalization).
- **1,000 LOW-confidence rows** — single-source legacy evidence only,
  never challenged this round; still the largest bucket needing eventual
  review before any conversion is treated as final, though none of them
  carry an active conflict.

## Regression

**82/82 PASS, 0 failures** (`tests/run_mysql_tests.sh`). No backend/PHP
code touched this round (Python analysis tooling only).

## Confirmation

**NO production opening, NO historical replay, NO production FIFO, NO
cutover.** This round only applied recorded admin answers as a
staging/analysis override layer.

## Files produced

- `migration/scripts/admin_decisions_v5.json` — the owner's answers,
  recorded verbatim (`source_answer`) plus the derived resolution per
  SKU/group, for audit.
- `migration/scripts/apply_admin_decisions_v5.py` — applies them onto
  the v4 baseline (read-only input).
- `migration/scripts/build_conversion_review_v5.py` — report builder.
- `migration/workspace/normalized/unit_conversion_candidates_real_v5.json`
- `migration/workspace/normalized/unit_conversion_review_v5.xlsx` — 6
  sheets: INSTRUCTIONS, SUMMARY, ADMIN CONFIRMED (31 rows, includes the
  owner's original answer text per SKU), CONVERSION REVIEW (1,165),
  CONVERSION_CONFLICT rows (0), MOVEMENT REVIEW (8).
- `migration/workspace/normalized/warehouse_unit_comparison_v5.csv`
- `migration/workspace/reports/unit_conversion_summary_v5.md` — this file.

---

## STOP

**NO opening import. NO historical replay. NO production FIFO. NO
cutover.** Unit conversion reconstruction for this catalog is now fully
resolved (0 open conflicts). Remaining work before any cutover: the 8
movement-reconciliation rows, the 1 missing category (33515), and a
final human read-through of the ~1,000 LOW-confidence rows are still
recommended, but none of them is a blocking conflict.
