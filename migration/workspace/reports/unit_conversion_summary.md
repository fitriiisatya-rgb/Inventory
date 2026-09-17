# Unit Conversion Reconstruction — Summary Report (Phase G-DATA 1B)

**⚠️ THIS RUN USED SAMPLE DATA ONLY — see "Data availability" below before
reading any number in this report as a statement about the real business.**

## Data availability

The following inputs this phase specifies were **not available** in the
environment this run was performed in:

- `template_master_data_barang_gudang_besar.xlsx`
- `template_master_data_barang_cibadak.xlsx`
- `template_master_data_barang_karangtengah.xlsx`
- `stok_awal_september_gudang_besar.xlsx`
- `stok_awal_september_cibadak.xlsx`
- `stok_awal_september_karangtengah.xlsx`
- `phase_g_real_data_review_v4.xlsx`
- the legacy `dbinventory` database (no dump, no live connection)

None of these were ever attached to this conversation or present on disk —
confirmed by an exhaustive filesystem search before this run. Rather than
fabricate results for files never seen, this run uses **only the specific
SKUs, prices, and identity conflicts given directly, inline, in the Phase
G-DATA 1B instructions themselves** (18 SKU records) as a proof-of-concept
input, to demonstrate that `UnitConversionReconstructionService` classifies
each one exactly as the instructions describe. **Every count below is a
count over those 18 sample records, not the real SKU catalog.**

## 1. Total SKU analyzed

**18** (sample only — see above). Real catalog size unknown until the real
files are provided.

## 2. Global base unit resolved / unresolved

- Resolved: **0**
- Unresolved: **18** (every sample record left `global_base_unit_candidate`
  blank — none of the 18 inline examples included an explicit, unconflicted
  base-unit determination; a real Master Barang file would supply this per
  SKU directly for most items)

## 3. Purchase conversion resolved / unresolved

- Resolved (Approved = YES): **0**
- Candidate only (unresolved): **3** (999208, 999209 via price ratio;
  140539 flagged as a suspected label mismatch, not a genuine conversion)
- No candidate at all: **15**

## 4. Middle conversion resolved / unresolved

- Resolved: **0**
- Unresolved: **18** — no middle-unit evidence existed in any of the 18
  inline examples (this needs the real Master Barang files' Middle Unit /
  Middle Conversion columns).

## 5. HIGH / MEDIUM / LOW confidence counts

MEDIUM = 999208 and 999209 (price-ratio evidence). LOW = 0 in this sample
(no name-derived candidates were present in the 18 inline examples — none
of the sample item names contained an "@25Kg"-style pattern). NONE (no
evidence at all, or evidence present but not confidence-scored, e.g.
identity conflicts and duplicates) = 16.

| Confidence | Count |
|---|---|
| HIGH | 0 |
| MEDIUM | 2 |
| LOW | 0 |
| NONE | 16 |

## 6. Legacy conversion candidates

**0.** No `dbinventory` data was available in this session to extract
`buyUnit`/`buyContent`/`midUnit`/`midContent`/`baseUnit`-equivalent fields
from. `UnitConversionReconstructionService::buildCandidateRecord()`'s
legacy-extraction path (`LEGACY_CONVERSION_CANDIDATE` status, confidence
capped at MEDIUM per Section 10's source-priority rule, full
`legacy_evidence` JSON with `legacy_source`/`source_field`/`legacy_value`)
is implemented and ready — it simply had nothing to run against.

## 7. Price-ratio supported conversions

**2** — both exactly matching the instructions' own worked examples:

| SKU | Item Name | Ratio | Sources compared | Status |
|---|---|---|---|---|
| 999208 | PREMIX MENTEGA BASIC | 15.00x | Karang Tengah Rp580.930/PCS vs Master Rp38.728,67/KG | `PRICE_RATIO_SUPPORTS_CONVERSION`, review required |
| 999209 | PREMIX MENTEGA ROTI | 15.00x | Karang Tengah Rp537.848/PCS vs Master Rp35.856,53/KG | `PRICE_RATIO_SUPPORTS_CONVERSION`, review required |

Plus **1 distinct case** correctly classified differently — a ratio of
**1000x on the SAME claimed unit (KG)**, which the engine treats as
`UNIT_LABEL_MISMATCH` rather than a packaging conversion, per Section 7's
distinction:

| SKU | Item Name | Ratio | Sources compared | Status |
|---|---|---|---|---|
| 140539 | MUTIARA PUTIH 8 MM | 1000.00x | Master Rp305.000/KG vs Opening Gudang Besar Rp305/KG | `UNIT_LABEL_MISMATCH` — likely the Rp305 figure is actually a per-GR price recorded under a KG label; **not auto-corrected**, flagged for review |

## 8. Name-derived candidates

**0.** None of the 18 inline example item names contained an
`@<qty><unit>`-style pattern (e.g. "@25Kg", "@12x1Kg"). The parser
(`UnitConversionReconstructionService::extractNameHeuristic()`) is
implemented and regex-tested to handle both the single-quantity form and
the ambiguous "count x size" form (flagging `PACKAGE_STRUCTURE_UNCLEAR`
for the latter, per Section 5 — it never guesses whether the "12" is a
carton/box/pack count).

## 9. Identity conflicts

**2** — both exactly as described, both forced to `review_status = BLOCKED`:

| SKU | Source A | Source B | Status |
|---|---|---|---|
| 140541 | Stok Karang Tengah: "MUTIARA PUTIH 10 MM" | Master: "CREAMFILL CLASSIC BANANA" | `IDENTITY_CONFLICT`, BLOCKED |
| 140509 | Stok Karang Tengah: "MUTIARA WARNA" | Master: "MUTE SILVER" | `IDENTITY_CONFLICT`, BLOCKED |

Per Section 12/25, no conversion work is attempted on these two SKUs at
all until a human resolves which product each SKU code actually refers to.

## 10. Duplicate conflicts

**2** — Karang Tengah SKU 900240 and 900251, flagged `DUPLICATE_SOURCE`
with a correction note: retain one global SKU identity, do not create a
second global item, discrepancy retained in this report for audit (Section
14 — exactly as instructed, no data was invented for these two since the
instructions didn't supply their item names/prices).

## 11. Unit dictionary changes

**None in this run.** The canonical unit list from Phase G2.1
(`GR, KG, ML, LTR, PCS, BOX, KARTON, KARUNG, LUSIN, PACK, ROLL`) already
covers every unit mentioned in the 18 sample records (`PCS`, `KG`). Section
8's fuller dimension table (WEIGHT/VOLUME/COUNT/LENGTH/PACKAGING, adding
`PAIL`, `JAR`, `SET`, `SHEET`, `METER`) is **not yet reflected** in the
schema's `units` seed — flagged as a needed follow-up once real Master
Barang data shows which of those additional units are actually in use
(adding unused canonical units speculatively would be exactly the kind of
un-evidenced change this phase's own principles argue against).

## 12. Schema / code changes

- **New table** `unit_conversion_candidates` (`database/schema.sql`) —
  pure review/staging data, never read by `FifoService` or
  `UnitConversionService`, never affects a live posting. `sku` is a plain
  string (not an FK to `items`) so it can hold rows for SKUs that don't
  exist in the master yet (Global Master candidates) or whose identity is
  still in conflict (BLOCKED rows).
- **New service** `services/UnitConversionReconstructionService.php` —
  the detection engine (name-heuristic parser, price-ratio/unit-label-
  mismatch detector, identity-conflict detector, conversion-conflict
  detector, candidate-record assembler). Every method is a pure detector;
  none of them write a "final" value — see G11 in
  `docs/PHASE_G10_ANOMALY_RULES.md`.
- **New script** `scripts/reconstruct_unit_conversions.php` — the runner;
  currently loads the 18 inline sample records (`loadSampleInput()`,
  clearly marked for replacement once real files exist) and persists
  results to `unit_conversion_candidates` + a JSON dump for the workbook
  generator.
- **Verified, not changed:** `inventory_transaction_lines`
  (`input_qty`, `input_unit_id`, `conversion_factor_snapshot`, `base_qty`,
  `unit_price_input`, `unit_cost_base`) and `inventory_batches`
  (`qty_base`, `unit_cost_base`) already satisfy Section 19's snapshot/
  base-quantity-only requirements exactly — confirmed against the live
  schema, no changes were needed.

## 13. Test results for conversion + transfer balance

- Full backend regression re-run after the schema addition:
  **82/82 assertions PASS, 0 failures** (`tests/run_mysql_tests.sh`),
  including the Transfer section (source decreases, destination increases
  only after receive, in-transit value + company total value both
  conserved across the full ship→receive round trip) and 5/5 concurrency
  runs.
- New engine-specific verification (this session): ran
  `scripts/reconstruct_unit_conversions.php` against the 18 sample records
  and confirmed by direct SQL query that every classification matches the
  instructions' own stated conclusions exactly (ratios of 15.00 and
  1000.00 computed precisely, correct issue codes, correct BLOCKED status,
  **zero** rows with `approved = 'YES'`).

## 14. File `unit_conversion_review.xlsx`

Generated at `migration/workspace/normalized/unit_conversion_review.xlsx`
with all 8 required sheets (INSTRUCTIONS, SUMMARY, CONVERSION REVIEW,
CONFLICTS, PRICE RATIO CHECK, NAME HEURISTICS, LEGACY CONVERSIONS, BLOCKED
SKU), populated from this sample run and clearly labeled throughout as
sample/proof-of-concept data. Attached to this conversation.

## 15. All rows requiring human decision

**All 18 rows** — per Section 17, none were auto-approved (`Approved`
column is blank on every row in this run, confirmed by direct query). In
priority order for a human reviewer:

1. **Resolve identity first** (blocks everything else for these SKUs):
   140541, 140509 — confirm which physical product each SKU code actually
   refers to before any conversion work proceeds.
2. **Confirm or reject the price-ratio candidates**: 999208, 999209 (is
   1 PCS really ≈15 KG?) and 140539 (is the Rp305 opening price actually a
   per-GR price mislabeled as per-KG?).
3. **Decide the 11 Global Master candidates** (33515, 140542–140551):
   confirm they should be added to the global item master, and supply a
   category (never invented by this engine).
4. **Confirm the 2 duplicate-source SKUs** (900240, 900251): pick which
   SKU code is the single canonical identity going forward.

---

**Next step (not started, per explicit instruction):** provide the 6 real
xlsx files, the `phase_g_real_data_review_v4.xlsx` review workbook, and
either a `dbinventory` export or connection, so
`scripts/reconstruct_unit_conversions.php`'s `loadSampleInput()` can be
replaced with a real loader and this entire analysis re-run against the
actual SKU catalog.
