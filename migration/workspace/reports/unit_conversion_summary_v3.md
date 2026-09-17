# Unit Conversion Reconstruction — Summary Report v3 (Phase G-DATA 1B.1, normalization fix)

This report follows a direct review correction: v2's `CONVERSION_CONFLICT`
count included **false conflicts** because the detector compared raw
numeric values without first normalizing them to a common physical unit.
This round **fixes the detector engine itself**
(`reconstruct_unit_conversions_real.py`), not a post-processing patch,
then regenerates everything from raw source data + the 3 business
overrides. All 1,165 SKU confidence scores were recalculated from
scratch — nothing was carried over from v1/v2 just because it existed
there.

**Still no production posting.** No opening stock, no historical replay,
no FIFO batches, no cutover date. Staging/analysis only.

---

## 1. Total SKU

**1,165** — unchanged (same real catalog: GB 1,017 + CB 123 + KT 1,149 +
1 stock-only).

## 2. Identity conflicts remaining

**0 BLOCKED.** Per the owner's decision, Gudang Besar / SCM is now the
authoritative Global Master identity. All 5 SKUs previously BLOCKED
(111712, 111905, 111906, 111920, 800405) are resolved: GB's Name,
Category, Base Unit and Status win outright, and the differing
transit-warehouse name is kept as an alias for audit only. This does
**not** auto-approve their purchase conversion — only identity. `BLOCKED`
is now reserved for a SKU with no GB row to arbitrate between disagreeing
transit warehouses; no such case exists in this catalog.

## 3. Alias rows created

**5** — see the IDENTITY ALIASES sheet:

| SKU | Final Name (GB) | Alias (transit, audit only) |
|---|---|---|
| 111712 | Lilin Kriting Hijau @1x100 pack | KARANG_TENGAH: "Lilin Kriting @1x100 pack" |
| 111905 | Topper Ultah Hewan @12 set | KARANG_TENGAH: "Topper Ultah Baby Shark @12 set" |
| 111906 | Topper Ultah Baby Shark @12 set | KARANG_TENGAH: "ucapan HBD @1x12 Pack" |
| 111920 | Topper akrilik Happy Wedding | KARANG_TENGAH: "Topper akrilik HBD" |
| 800405 | Minyak Sania @12 x 1 liter | CIBADAK: "Minyak Camar @12 x 1 liter" |

(Note 111905/111906 look like a possible adjacent-code data-entry mix-up
in the Karang Tengah master — worth a human's attention beyond the
identity rule itself.)

## 4. CONVERSION_CONFLICT before this fix (v2, post-override)

**36**

## 5. CONVERSION_CONFLICT after real normalization (this run, raw detector, pre-override)

**48**, then **46** after re-applying the 3 unchanged business overrides
(999208/999209 drop out). This is **higher than the ~23 the review
forecast**, and that forecast's premise (13 false conflicts resolved,
23 untouched) undercounted a second effect the same bug was also
producing. Full accounting:

| | Count |
|---|---|
| v2 conflicts (post-override baseline) | 36 |
| **Resolved** (confirmed false conflicts, now AGREEMENT) | **13** |
| **Newly surfaced** (previously-hidden false agreements, now correctly CONFLICT) | **26** (24 new distinct SKU + re-flagging 999208/999209 which the override then re-resolves) |
| Unchanged (still conflicting, same as before) | 23 |
| **v3 conflicts (post-override)** | **46** |

**Root cause of the "newly surfaced" 24:** the v2 engine's bug was
symmetric, not one-directional. It compared raw numeric magnitudes with
no unit check at all, so it produced two kinds of errors:
- **False conflicts** (your 13 examples): same real quantity, different
  *convertible* units (KG vs GR, LTR vs ML) → raw numbers looked far
  apart → wrongly flagged CONFLICT. Fixed.
- **False agreements** (the newly surfaced 24): same *raw number*,
  genuinely different, non-convertible units (e.g. name says "@12 SET",
  legacy says isi_dasar=12 **PCS** — a coincidence of both being "12",
  not real evidence of a shared conversion factor) → wrongly clustered
  as MEDIUM-confidence agreement. The fix requires the *normalized unit*
  to match, not just the magnitude, so these no longer silently pass —
  they now correctly need a human decision (is a "SET" here really
  interchangeable with "PCS" for this product line, or not?).

This is reported exactly as found, per this project's standing rule not
to force a number to match an expectation set before the full data was
re-derived.

## 6. False conflicts resolved

**13 / 13 — all confirmed.** Every SKU you listed now shows AGREEMENT:

| SKU | Name evidence (raw → normalized) | Legacy evidence (raw → normalized) | Verdict |
|---|---|---|---|
| 100309 | 12.5 KG → 12500 GR | 12500 GR → 12500 GR | AGREEMENT |
| 110414 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 110415 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 140508 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 150108 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 150110 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 150121 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 300215 | 1 LTR → 1000 ML | 1000 ML → 1000 ML | AGREEMENT |
| 300216 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 400602 | 250 GR → 250 GR | 0.25 KG → 250 GR | AGREEMENT |
| 600107 | 50 KG → 50000 GR | 50000 GR → 50000 GR | AGREEMENT |
| 700113 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |
| 900225 | 1 KG → 1000 GR | 1000 GR → 1000 GR | AGREEMENT |

All moved from LOW/CONVERSION_CONFLICT to **MEDIUM** (name + legacy now
agree — exactly 2 independent sources), consistent with item 4's
recalculation rule.

## 7. Exact resolved SKU list

`100309, 110414, 110415, 140508, 150108, 150110, 150121, 300215, 300216, 400602, 600107, 700113, 900225`

## 8. Confidence distribution (fully recalculated, all 1,165 SKU)

| Confidence | v2 | v3 |
|---|---|---|
| HIGH | 0 | 0 |
| MEDIUM | 133 | 125 |
| LOW | 1,005 | 1,016 |
| NONE | 24 | 21 |
| BUSINESS_CONFIRMED | 3 | 3 |

MEDIUM went down slightly net (133→125) even though 13 SKUs newly joined
it, because the same normalization fix also *removed* some previously
counted MEDIUM rows that were false agreements under the old raw-number
comparison (the "newly surfaced 24" conflicts) — a few of those had been
sitting at MEDIUM in v2, not just NONE/LOW. NONE dropped slightly
(24→21) as a few SKUs gained a genuine cross-source signal after unit
normalization.

## 9. Purchase conversion candidate count

**1,144 / 1,165** — unchanged from v2 (this count comes from legacy/name
evidence presence, not from the clustering fix; the fix changes *whether
two candidates agree*, not whether a candidate exists at all).

## 10. Dimension-conflict count

**40** of the 46 remaining `CONVERSION_CONFLICT` rows involve evidence
values that, even after normalization, sit in genuinely different units
(e.g. SET vs PCS, KG vs PCS, SHEET vs PCS) — a real cross-dimension
disagreement, not a magnitude dispute within the same unit. The other 6
are same-unit numeric disagreements (e.g. legacy says one factor, name
heuristic says a different one, both already in KG or GR).

## 11. Remaining human-review conversion rows

**1,162** (everything except the 3 business-confirmed SKUs — `Approved`
is still blank on all of them). Priority order:

1. **0 BLOCKED** — nothing is hard-blocked any more; the 5 former
   identity conflicts are informational-only now (see IDENTITY ALIASES).
2. **46 CONVERSION_CONFLICT rows** — 40 are genuine cross-dimension
   disagreements (SET vs PCS, KG vs PCS, SHEET vs PCS — is the "12" or
   "500" the same real count in both systems, or not?); the other 6 are
   same-unit numeric disputes.
3. **7 Global Master candidates** (33515, 333516, 120104, 150110, 150114,
   150115, 150118), of which **1** (33515) is tagged `NEEDS_CATEGORY` —
   no trustworthy category exists for it in any source, so none was
   invented.
4. **2 duplicate-source SKUs** (900240, 900251) — unchanged from v2.
5. **8 MOVEMENT_RECONCILIATION_REVIEW** rows — unchanged.
6. **1,016 LOW-confidence rows** — single-source legacy evidence only,
   lowest priority.

## 12. 20 highest-risk remaining rows

Ranked by CONVERSION_CONFLICT first (none are BLOCKED any more):

| # | SKU | Item | Confidence | Issue |
|---|---|---|---|---|
| 1 | 100201 | CHEFMATE 3 IN 1 @500GRX20 | LOW | CONVERSION_CONFLICT (pre-existing name-parser edge case: "500GRX20" is parsed as unit "GRX", a known limitation, out of this fix's scope — see note below) |
| 2 | 100310 | Coklat bubuk bensdrop 8kg@3pcs | LOW | CONVERSION_CONFLICT |
| 3 | 100503 | Mix K600 @100Pcs | LOW | CONVERSION_CONFLICT |
| 4 | 110301 | GARAM @250gr | LOW | CONVERSION_CONFLICT |
| 5 | 110416 | SAUS TIRAM @1 LITER | LOW | CONVERSION_CONFLICT |
| 6 | 111507 | Balon foil angka 0-9 @10 pack | LOW | CONVERSION_CONFLICT |
| 7 | 111508 | Balon foil huruf A-Z @10 pack | LOW | CONVERSION_CONFLICT |
| 8 | 111904 | Topper Ultah Boboboi @12 set | LOW | CONVERSION_CONFLICT (SET vs PCS — newly surfaced) |
| 9 | 111905 | Topper Ultah Hewan @12 set | LOW | CONVERSION_CONFLICT + identity alias |
| 10 | 111906 | Topper Ultah Baby Shark @12 set | LOW | CONVERSION_CONFLICT + identity alias |
| 11 | 111907 | Topper Ultah Hello Kitty @12 set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 12 | 111908 | Topper Ultah Tayoo @12 set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 13 | 111909 | Topper Ultah Dinosaurus @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 14 | 111910 | Topper Ultah Doraemon @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 15 | 111911 | Topper Ultah Cars @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 16 | 111912 | Topper Ultah Upin Ipin @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 17 | 111913 | Topper Ultah Astronot @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 18 | 111914 | Topper Ultah Batman @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 19 | 111915 | Topper Ultah Ultraman @12set | LOW | CONVERSION_CONFLICT (newly surfaced) |
| 20 | 111916 | Topper Ultah Thomas @12 set | LOW | CONVERSION_CONFLICT (newly surfaced) |

**Pattern worth flagging as a group:** 17 of the 46 remaining conflicts
are "Topper Ultah ... @12 set" family items where name evidence says
"12 SET" and legacy says isi_dasar "12 PCS" — same number, different
label. A single human decision ("is SET the same countable unit as PCS
for this product line?") would likely resolve most of this family at
once, but per your instruction not to hardcode exceptions, the engine
correctly leaves each as its own row rather than assuming the answer.

**Pre-existing, out-of-scope note on 100201:** its name "@500GRX20" is
parsed by the name-heuristic regex as unit "GRX" (a different bug class —
ambiguous notation mixing a weight and a multiplier — not a physical-unit
normalization issue). Flagging for awareness; not fixed here since it's
outside the KG/GR/LTR/ML normalization scope this round targeted.

## 13. 20 strongest normalized candidates

Clean MEDIUM tier (name + legacy agree post-normalization, no conflict):
**125 SKUs total**, up from the original detector's un-normalized
MEDIUM tier by the 13 newly-agreeing SKUs (net of a few that moved out
to CONFLICT). Top 20:

| # | SKU | Item | Candidate purchase unit × factor (normalized) |
|---|---|---|---|
| 1 | 100101 | Black Coockie Crumb @5 Kg | Ctn × 5.0 |
| 2 | 100309 | MEISES PLAZA BIRU @12.5kg | Ctn × 12,500 g (=12.5 kg) — newly resolved |
| 3 | 100312 | COKLAT BUBUK DIAMOND @5 KG | ctn × 5.0 |
| 4 | 100421 | Mercolade filling pasta @20kg | ctn × 20.0 |
| 5 | 100424 | MERCOLADE BISCOA SPREAD@5KG | pail × 5.0 |
| 6 | 100425 | NUCOMAL SILVERQUEEN @5KG | pail × 5.0 |
| 7 | 100426 | NUCOMAL TINE CRUNCHY @5KG | pail × 5.0 |
| 8 | 100427 | MEISES WINTER @12.5 KG | ctn × 12.5 |
| 9 | 110414 | MASAKO RASA AYAM @1KG | Kg × 1,000 g — newly resolved |
| 10 | 110415 | CHICKEN SEASONING POWDER KNORR @1 KG | Kg × 1,000 g — newly resolved |
| 11 | 110417 | KECAP MANIS @700GR | Pcs × 700.0 |
| 12 | 110418 | DELMONTE SAUS SPAGETI @250 GR | Pcs × 250.0 |
| 13 | 111927 | TOPPER AKRILIK @100 PCS | Pack × 100.0 |
| 14 | 120101 | Susu Bubuk F-04 @25 Kg | Bag × 25.0 |
| 15 | 120107 | Susu Bubuk F-04 @10 Kg | Bag × 10.0 |
| 16 | 120113 | SUSU BUBUK F-06 @25 KG | Bag × 25.0 |
| 17 | 120116 | SUSU BUBUK FITAMIL @25 KG | Bag × 25.0 |
| 18 | 130102 | BE FAST COTTON CAKE @10KG/BAG | Bag × 10.0 |
| 19 | 130106 | Maizena CHEILJEDANG @25Kg | Bag × 25.0 |
| 20 | 130109 | MAIZENA MIWON DAESANG @25KG | Bag × 25.0 |

## 14. Regression test result

**82/82 PASS, 0 failures** (`tests/run_mysql_tests.sh`) — smoke (4),
integration (35), importer (12), void (15), security (11), concurrency
(5 runs). No backend/PHP code was touched this round (Python analysis
tooling only), so this reconfirms no regression.

---

## Files regenerated (from raw source data, not patched)

- `migration/scripts/reconstruct_unit_conversions_real.py` — detector
  itself fixed: physical-unit normalization (KG↔GR, LTR↔ML) applied
  before evidence clustering; GB-authoritative identity resolution;
  category/status candidate extraction with `NEEDS_CATEGORY` instead of
  invention.
- `migration/scripts/apply_business_confirmed_overrides.py` — now takes
  a version-suffix argument; re-applied unchanged (999208, 999209,
  140539 overrides untouched).
- `migration/workspace/normalized/unit_conversion_candidates_real.json`
  — regenerated detector-only output.
- `migration/workspace/normalized/unit_conversion_candidates_real_v3.json`
  — detector output + overrides.
- `migration/workspace/normalized/unit_conversion_review_v3.xlsx` — 13
  sheets: INSTRUCTIONS, SUMMARY, BUSINESS CONFIRMED, CONVERSION REVIEW,
  CONFLICTS, **NORMALIZATION TRACE** (new, 1,317 rows — raw vs normalized
  vs verdict for every evidence item), PRICE RATIO CHECK, NAME
  HEURISTICS, LEGACY CONVERSIONS, BLOCKED SKU (now 0 rows),
  **IDENTITY ALIASES** (new, 5 rows), TRANSACTION EVIDENCE, WAREHOUSE
  UNIT COMPARISON.
- `migration/workspace/normalized/warehouse_unit_comparison_v3.csv` —
  1,002 rows.
- `migration/workspace/reports/unit_conversion_summary_v3.md` — this file.

## Confirmation

**NO production opening, NO historical replay, NO production FIFO, NO
cutover** — staging/analysis only, exactly as before.

---

**STOP** (unchanged): the remaining 46 conflicts (40 of them genuine
cross-dimension disagreements needing a human "is X the same as Y here"
decision), the 7 Global Master candidates (1 needing a category), the 2
duplicate-source SKUs, and the 8 movement-reconciliation rows all still
need review before any opening import or cutover proceeds.
