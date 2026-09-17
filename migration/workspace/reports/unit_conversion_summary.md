# Unit Conversion Reconstruction — Summary Report (Phase G-DATA 1B, REAL DATA)

This run replaces the prior sample-only report. It was executed against the
full real catalog extracted from `claude_inventory_real_data_handoff.zip`
(`migration/workspace/raw/handoff_2026-09-17/...`), using **only**
`input_real/` files as active production candidates and `review_outputs/`
files as supporting evidence, per `README_HANDOFF.md`. `reference_old_versions/`
was not used. `dbinventory.xlsx` was used strictly as **legacy evidence**,
matched by fuzzy product name (its SKU coding scheme is unrelated to the
current numeric codes) — never as authoritative production data.

**No production posting occurred.** No opening stock, no historical
transaction replay, no FIFO batches, and no cutover date were created or
touched. This phase is staging/analysis only, exactly as instructed.

---

## 1. Total Global SKU analyzed

**1,165** unique SKU codes — the union of Gudang Besar master (1,017),
Cibadak master (123), Karang Tengah master (1,149 after de-duplicating two
internally-duplicated rows), plus 1 stock-only code with no master row yet
(33515). This is the full real catalog, not the 18-SKU sample from the
prior round.

## 2. Base Unit resolved / unresolved

- **Resolved: 1,165 / 1,165** — every SKU has a determinable base unit
  (`Satuan Dasar`), and the check for cross-warehouse base-unit
  disagreement found **zero** mismatches (base unit is a shared master
  field and is consistent company-wide in this dataset).
- Unresolved: **0**

## 3. Purchase conversion resolved / unresolved (candidate, not approved)

- **Candidate found: 1,144 / 1,165** (98.2%) — mostly from legacy
  `dbinventory.xlsx` evidence (`Kemasan Beli` / `Isi Dasar`), supplemented
  by 374 name-heuristic parses and 4 price-ratio detections.
- **No candidate at all: 21** — no legacy match, no name pattern, no price
  ratio evidence. These need a human to supply purchase-unit data from
  another source (e.g. supplier records) if a conversion is needed at all.
- **Nothing here is auto-approved** — every `Approved` cell in the
  workbook is blank (confirmed: 0 rows with `Approved = YES`).

## 4. Middle conversion resolved / unresolved

- **Resolved (independently derived candidate): 0 / 1,165** — per the
  explicit "do not force a Middle Unit" instruction, this run never
  invents a middle-packaging layer without direct evidence for it.
- **Legacy middle-unit evidence available for reference: 1,144** SKUs
  (`legacy_middle_unit` / `legacy_middle_conversion`, from
  `dbinventory.xlsx`'s `Satuan Kemasan` / `Isi Kemasan`) — visible in the
  CONVERSION REVIEW sheet for a human to decide whether to promote to an
  approved middle unit, but never auto-populated as a "candidate".

## 5. Confidence distribution

| Confidence | Count |
|---|---|
| HIGH | 0 |
| MEDIUM | 133 |
| LOW | 1,008 |
| NONE | 24 |

**Why HIGH is 0, not inflated:** an earlier draft of this run counted
"transaction data exists and its unit matches the master unit" as
independent evidence, which is true for nearly every plain single-unit
SKU and produced an implausible HIGH=126. That was a scoring bug, not a
real finding, and has been removed. Confidence here is now based purely on
clustering **real candidate packaging-factor values** (name-heuristic
quantity, price-ratio factor, legacy `Isi Dasar`) that mutually agree
within 5% tolerance:
- **HIGH** would require 3 independent sources agreeing on the same factor
  (e.g. the user's own worked example: legacy + name + price ratio + a
  consistent transaction pattern all saying "15") — no SKU in this real
  catalog currently has 3 mutually-agreeing sources; the closest cases
  (999208/999209, see §7) have price-ratio evidence that actively
  **conflicts** with legacy evidence, not agrees with it.
- **MEDIUM (133)** = exactly 2 sources agree — almost all are
  name-heuristic + legacy agreeing on the same purchase quantity (e.g.
  "Black Coockie Crumb @5 Kg" ↔ legacy `Isi Dasar = 5.0`).
- **LOW (1,008)** = exactly 1 source, overwhelmingly legacy evidence alone
  with no independent corroboration yet.
- **NONE (24)** = no usable evidence at all.

Transaction data (In/Out SCM + Cibadak) is still surfaced per-SKU in its
own TRANSACTION EVIDENCE columns/sheet for human judgment — it is
deliberately no longer folded into the numeric confidence score.

## 6. Supported by legacy evidence

**1,144 / 1,165** — fuzzy name-matched against `dbinventory.xlsx`
(`Master Barang`, exact normalized-name match first, then
`rapidfuzz.token_sort_ratio` ≥ 90 fallback). Always tagged
`LEGACY_CONVERSION_CANDIDATE`, never auto-approved.

## 7. Supported by transaction evidence

**1,007** SKUs have In/Out SCM and/or Cibadak transaction rows attached as
evidence (1,007 from SCM, 123 from Cibadak, some overlapping). Confirmed:
Cibadak's 123 opening SKUs match 123/123 against its In/Out file, and its
transaction units/prices match its own master file — strong operational
confirmation of Cibadak's base unit, exactly as the handoff claimed.

Two SCM SKUs flagged by the prior `scm_reconciliation` review as
theoretical-negative (small, non-production-affecting) were re-confirmed
here and tagged `MOVEMENT_RECONCILIATION_REVIEW` (never auto-corrected):
**100304** (Meises Hagel @1x12.5 Kg, ending ≈ -0.5 Kg) and **777419**
(PLASTIK PE 50x85 CM, ending ≈ -0.5 Kg). The Cibadak reconciliation file
independently surfaced 3 more small negatives not named in the handoff
text (400201, 555410, 800401) — included in the same flag set for
completeness. **8 SKU total** carry a `MOVEMENT_RECONCILIATION_REVIEW` flag.

## 8. Supported by price-ratio evidence

**4** SKUs total — 3 genuine packaging-ratio candidates, 1 suspected
unit-label mismatch:

| SKU | Item | Ratio | Comparison | Verdict |
|---|---|---|---|---|
| 999208 | PREMIX MENTEGA BASIC | 15.00× | Opening Karang Tengah Rp580.930/PCS vs Transaction Gudang Besar Rp38.729/KG | `PRICE_RATIO_SUPPORTS_CONVERSION` — but **conflicts** with legacy evidence (legacy says factor=1, Kg→Kg, no conversion) → `CONVERSION_CONFLICT` |
| 999209 | PREMIX MENTEGA ROTI | 15.00× | Opening Karang Tengah Rp537.848/PCS vs Transaction Gudang Besar Rp35.857/KG | Same pattern — `CONVERSION_CONFLICT` with legacy |
| 140541 | CREAMFILL CLASSIC BANANA | 135.75× | Master GB Rp53.280/KG vs Opening Karang Tengah Rp392,50/GR | Not close to the expected 1000× (KG↔GR) — flagged, `CONVERSION_CONFLICT` with legacy (factor=1) |
| 140539 | MUTIARA PUTIH 8 MM | 1000.00× | Opening GB Rp305.000/KG vs Opening Karang Tengah Rp305/KG (**same** unit label) | `UNIT_LABEL_MISMATCH` — likely a mislabeled per-GR price, not a real conversion; not auto-corrected |

The genuine anomaly for 999208/999209/140539 lives in **Master-vs-Opening
and Opening-vs-Transaction** price comparisons, not Master-vs-Master
(company-wide reference prices are largely identical by design, so that
comparison alone is uninteresting).

## 9. Supported by name heuristic

**374 / 1,165** item names matched an `@<qty><unit>` pattern. Of these,
**204** matched the ambiguous `@<count>x<size><unit>` form and are flagged
`PACKAGE_STRUCTURE_UNCLEAR` — the engine reports the total implied
quantity but never guesses whether "12" is a carton count, a pack count,
or something else.

## 10. Cross-warehouse unit mismatch count

**0.** No SKU shows a genuinely different base unit across Gudang
Besar / Cibadak / Karang Tengah master files (`BASE_UNIT_REVIEW_REQUIRED`
issue count = 0). The "large unit at Gudang Besar, small unit at transit"
pattern the handoff describes does **not** show up as a base-unit
difference — the base unit (`Satuan Dasar`) is a shared, company-wide
field by design. Instead, that pattern shows up at the **packaging/purchase
layer**: `warehouse_unit_comparison.csv` now reports **465** SKUs (of the
1,002 present in 2+ warehouses) with a legacy or derived purchase-conversion
factor > 1, tagged `SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CANDIDATE` — this is
where the real "Gudang Besar buys in KARTON/CTN/PAIL, transit handles KG/PCS"
evidence lives.

## 11. Identity-blocked count

**5** SKUs, all newly found against the real full catalog (the 2 SKUs named
in the handoff, 140541 and 140509, are **confirmed resolved** — neither
appears here, matching the handoff's updated facts):

| SKU | Conflicting names across warehouses |
|---|---|
| 111712 | GB "Lilin Kriting Hijau @1x100 pack" vs KT "Lilin Kriting @1x100 pack" |
| 111905 | GB "Topper Ultah Hewan @12 set" vs KT "Topper Ultah Baby Shark @12 set" |
| 111906 | GB "Topper Ultah Baby Shark @12 set" vs KT "ucapan HBD @1x12 Pack" |
| 111920 | GB "Topper akrilik Happy Wedding" vs KT "Topper akrilik HBD" |
| 800405 | GB/KT "Minyak Sania @12 x 1 liter" vs CB "Minyak Camar @12 x 1 liter" |

All 5 are forced to `review_status = BLOCKED`; no conversion work was
attempted on any of them. Note the 111905/111906 pair in particular looks
like a possible adjacent-code mix-up in the source master and is worth a
human's attention.

## 12. Duplicate-source count

**2** — Karang Tengah internal duplicate rows for the same SKU code,
exactly as the handoff named:
- **900240** "PEWARNA CROSS SKY BLUE @60ML" (2 rows, minor spacing variant)
- **900251** "PEWARNA CROSS EGG YELLOW @60ML" (2 rows, minor capitalization variant)

Both tagged `DUPLICATE_SOURCE_ROW` with an explicit correction note: keep
one global identity, do not create a duplicate item.

## 13. Final unit dictionary (as observed in real data)

Units actually present in `Satuan Dasar` across the real master files:
**GR, JAR, KG, LTR, METER, ML, PACK, PAIL, PCS, ROLL, SET, SHEET.**

`BOX`, `KARTON`, `KARUNG`, and `LUSIN` from the candidate dictionary in the
handoff were **not observed** as a base unit in this real catalog (they may
still appear as purchase/packaging units in legacy or name-derived
evidence — e.g. `Ctn`/`pail` in the LEGACY CONVERSIONS sheet — but not as
a `Satuan Dasar` value). No unit was mapped package→base without an
explicit factor; unrecognized raw unit strings are preserved verbatim
(uppercased) for review rather than guessed.

## 14. 20 highest-risk conversion rows

Ranked by: BLOCKED (identity conflict) first, then genuine
`CONVERSION_CONFLICT`, then `MOVEMENT_RECONCILIATION_REVIEW` /
`UNIT_LABEL_MISMATCH` / `DUPLICATE_SOURCE_ROW`:

| # | SKU | Item | Status | Issue |
|---|---|---|---|---|
| 1 | 111712 | Lilin Kriting Hijau @1x100 pack | BLOCKED | IDENTITY_CONFLICT |
| 2 | 800405 | Minyak Sania @12 x 1 liter | BLOCKED | IDENTITY_CONFLICT |
| 3 | 111905 | Topper Ultah Hewan @12 set | BLOCKED | IDENTITY_CONFLICT |
| 4 | 111906 | Topper Ultah Baby Shark @12 set | BLOCKED | IDENTITY_CONFLICT |
| 5 | 111920 | Topper akrilik Happy Wedding | BLOCKED | IDENTITY_CONFLICT |
| 6 | 100201 | CHEFMATE 3 IN 1 @500GRX20 | PENDING/LOW | CONVERSION_CONFLICT |
| 7 | 100309 | MEISES PLAZA BIRU @12.5kg | PENDING/LOW | CONVERSION_CONFLICT |
| 8 | 100310 | Coklat bubuk bensdrop 8kg@3pcs | PENDING/LOW | CONVERSION_CONFLICT |
| 9 | 100503 | Mix K600 @100Pcs | PENDING/LOW | CONVERSION_CONFLICT |
| 10 | 110301 | GARAM @250gr | PENDING/LOW | CONVERSION_CONFLICT |
| 11 | 110414 | MASAKO RASA AYAM @1KG | PENDING/LOW | CONVERSION_CONFLICT |
| 12 | 110415 | CHICKEN SEASONING POWDER KNORR @1 KG | PENDING/LOW | CONVERSION_CONFLICT |
| 13 | 110416 | SAUS TIRAM @1 LITER | PENDING/LOW | CONVERSION_CONFLICT |
| 14 | 111507 | Balon foil angka 0-9 @10 pack | PENDING/LOW | CONVERSION_CONFLICT |
| 15 | 111508 | Balon foil huruf A-Z @10 pack | PENDING/LOW | CONVERSION_CONFLICT |
| 16 | 140508 | COKLAT CACA BESAR @1KG | PENDING/LOW | CONVERSION_CONFLICT |
| 17 | 140541 | CREAMFILL CLASSIC BANANA | PENDING/LOW | CONVERSION_CONFLICT (price-ratio vs legacy) |
| 18 | 140710 | GONDENFIL CHOCO CRUCH @1 KG | PENDING/LOW | CONVERSION_CONFLICT |
| 19 | 140808 | Kacang cincang @1kg x 10 | PENDING/LOW | CONVERSION_CONFLICT |
| 20 | 150108 | BAWANG PUTIH @1 KG | PENDING/LOW | CONVERSION_CONFLICT |

(Most CONVERSION_CONFLICT rows here are name-heuristic quantity = 1
conflicting with a legacy `Isi Dasar` ≠ 1, or vice versa — i.e. legacy and
today's operational naming disagree about whether the item has a
packaging factor at all. Full list of 38 conflict rows is in the
CONFLICTS sheet.)

## 15. 20 strongest conversion candidates

All from the clean MEDIUM tier (2 independent, non-conflicting sources —
name heuristic and legacy agreeing on the same factor):

| # | SKU | Item | Candidate purchase unit × factor |
|---|---|---|---|
| 1 | 100101 | Black Coockie Crumb @5 Kg | Ctn × 5.0 |
| 2 | 100312 | COKLAT BUBUK DIAMOND @5 KG | ctn × 5.0 |
| 3 | 100421 | Mercolade filling pasta @20kg | ctn × 20.0 |
| 4 | 100424 | MERCOLADE BISCOA SPREAD@5KG | pail × 5.0 |
| 5 | 100425 | NUCOMAL SILVERQUEEN @5KG | pail × 5.0 |
| 6 | 100426 | NUCOMAL TINE CRUNCHY @5KG | pail × 5.0 |
| 7 | 100427 | MEISES WINTER @12.5 KG | ctn × 12.5 |
| 8 | 110417 | KECAP MANIS @700GR | Pcs × 700.0 |
| 9 | 110418 | DELMONTE SAUS SPAGETI @250 GR | Pcs × 250.0 |
| 10 | 111904 | Topper Ultah Boboboi @12 set | Pack × 12.0 |
| 11 | 111907 | Topper Ultah Hello Kitty @12 set | Pack × 12.0 |
| 12 | 111908 | Topper Ultah Tayoo @12 set | Pack × 12.0 |
| 13 | 111909 | Topper Ultah Dinosaurus @12set | Pack × 12.0 |
| 14 | 111910 | Topper Ultah Doraemon @12set | Pack × 12.0 |
| 15 | 111911 | Topper Ultah Cars @12set | Pack × 12.0 |
| 16 | 111912 | Topper Ultah Upin Ipin @12set | Pack × 12.0 |
| 17 | 111913 | Topper Ultah Astronot @12set | Pack × 12.0 |
| 18 | 111914 | Topper Ultah Batman @12set | Pack × 12.0 |
| 19 | 111915 | Topper Ultah Ultraman @12set | Pack × 12.0 |
| 20 | 111916 | Topper Ultah Thomas @12 set | Pack × 12.0 |

133 SKUs total sit at this clean-MEDIUM tier — the full list is in
CONVERSION REVIEW, filterable by `Confidence = MEDIUM` and no
`CONVERSION_CONFLICT` in `Issue Code`.

## 16. Rows requiring human decision

**All 1,165 rows** — `Approved` is blank on every row (confirmed 0 rows
with `Approved = YES`). Priority order for a human reviewer:

1. **Resolve the 5 identity conflicts first** (§11) — nothing else can be
   decided for these SKUs until their true identity is confirmed.
2. **Judge the 4 price-ratio cases** (§8) — especially 999208/999209/140541
   where price-ratio evidence actively disagrees with legacy data (does a
   real ~15× or ~136× packaging factor exist, or is legacy's "no
   conversion" correct?), and 140539 (is Rp305 really a mislabeled
   per-GR price?).
3. **Confirm the 7 Global Master candidates**: 33515 (STICKER DUBAI CHEWY
   COOKIES), 333516 (STICKER DUBAI CHEWY COOKIES BESAR — a very likely
   sibling of 33515, worth reviewing together), 120104, 150110, 150114,
   150115, 150118 — all currently Karang-Tengah-only with live stock;
   supply a category for each (never invented by this engine).
2b. **Confirm the 2 duplicate-source SKUs** (900240, 900251): pick the
   canonical name spelling; no new item needed.
4. **Review the 38 CONVERSION_CONFLICT rows** (§14, CONFLICTS sheet) where
   name-heuristic and legacy evidence disagree about whether a packaging
   factor exists at all.
5. **Review the 8 MOVEMENT_RECONCILIATION_REVIEW rows** — small
   theoretical negative endings that may be timing/rounding, not unit
   errors.
6. **Decide the 465 SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CANDIDATE rows**
   in `warehouse_unit_comparison.csv` — these carry a legacy or derived
   purchase-conversion factor and are the most likely places where the
   "buy big at Gudang Besar, hold small at transit" pattern needs an
   approved Purchase Unit/Conversion.
7. **The remaining LOW-confidence rows (1,008)** — single-source legacy
   evidence only; lowest priority, but still need eventual review before
   any conversion is approved.

## 17. Regression / test result

Full backend regression re-run after this phase's changes (which touched
only offline Python analysis tooling, not the PHP backend):
**82 / 82 assertions PASS, 0 failures** (`tests/run_mysql_tests.sh`) —
smoke (4), integration (35), importer (12), void (15), security (11),
concurrency (5 runs). No backend code was modified this phase, so this
re-run confirms no regression was introduced.

## 18. Confirmation: no production data was posted

Confirmed. This phase only read the 9 `input_real/` files + 2
`review_outputs/` reconciliation files and wrote analysis artifacts to
`migration/workspace/normalized/` and `migration/workspace/reports/`. No
opening stock was imported, no historical transactions were replayed, no
production FIFO batches were created, no cutover date was set, and the
production database schema/data were not touched by this phase (the MySQL
regression suite in §17 runs against a disposable `inventory_test`
database, reset from `database/schema.sql`, unrelated to any real
business data).

## 19. `unit_conversion_review.xlsx`

`migration/workspace/normalized/unit_conversion_review.xlsx` — regenerated
against the full real catalog, 10 sheets: INSTRUCTIONS, SUMMARY,
CONVERSION REVIEW (1,165 rows, all required columns), CONFLICTS (38),
PRICE RATIO CHECK (4), NAME HEURISTICS (374), LEGACY CONVERSIONS (1,144),
BLOCKED SKU (5), TRANSACTION EVIDENCE (1,007), WAREHOUSE UNIT COMPARISON
(1,002).

## 20. `warehouse_unit_comparison.csv`

`migration/workspace/normalized/warehouse_unit_comparison.csv` — 1,002
rows (every SKU present in 2+ warehouses), with columns SKU, Item Name,
SCM Unit, Transit Unit, Legacy Conversion, Derived Conversion, **Pattern**
(`BASE_UNIT_MISMATCH` / `SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CANDIDATE` /
`NO_PACKAGING_LAYER` / `NO_PACKAGING_EVIDENCE`), Evidence, Status.

---

## STOP

Per the explicit instruction: **this phase stops here.** No opening
import, no historical replay, and no further cutover-related phase should
proceed until a human has reviewed `unit_conversion_review.xlsx` and
`warehouse_unit_comparison.csv` and made the identity/conflict/candidate
decisions listed in §16.
