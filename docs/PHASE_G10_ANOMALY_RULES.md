# Phase G10 — Anomaly Detection Rules

Four flag types, per the spec: `PRICE_HIGH`, `PRICE_LOW`,
`CONVERSION_SUSPECT`, `QTY_ABNORMAL`. Every one of these is a **flag for
human review**, never an automatic correction — see **G11: No Silent
Fix**, below, which governs all of them.

## Where each rule lives today

| Flag | Where it fires | Threshold |
|---|---|---|
| `PRICE_HIGH` / `PRICE_LOW` (live transactions) | `PriceAnomalyService` (Section 9, pre-existing) — blocks `POST /transactions/in` unless explicitly approved | configurable (`price_anomaly_high_multiplier` / `_low_multiplier`, default 5x / 0.2x of reference price) |
| `PRICE_HIGH` / `PRICE_LOW` (Opening Stock import) | `scripts/generate_import_quality_report.php`'s `abnormal_cost` category | same 5x/0.2x band, computed against the median cost of other positive-cost lines **for the same item within the same file** (no live reference price exists yet before an opening is committed) |
| `CONVERSION_SUSPECT` | `ImportMasterItemService::validateRow()` (Phase G2.2) — WARNING, never blocks commit | conversion factor `< 0.001` or `> 1,000,000` |
| `QTY_ABNORMAL` | `scripts/generate_import_quality_report.php`'s `abnormal_qty` category (Opening Stock) | same 5x/0.2x band as cost, against median quantity for the same item within the same file |

## Why Opening/Master use file-relative medians, not a global reference

A brand-new item being opened for the first time has no prior purchase
history to compare against — `PriceAnomalyService`'s live-transaction check
(which reads `item_price_history`) doesn't apply yet. Comparing each line
to the median of *other lines for the same item in the same file* still
catches the realistic failure mode this phase cares about (a decimal point
typo, a unit mix-up producing a 1000x price, a fat-fingered extra zero on
quantity) without requiring data that doesn't exist yet at import time.

## G11 — No Silent Fix

None of the rules above ever rewrites a value. Concretely:

- Master Item import never guesses a unit — `UnitNormalizationService`
  (G2.1) only maps known spelling/case *variants of the same unit*
  (`"gram"` → `GR`); an unrecognized string is a hard `ERROR`, never a
  best-guess unit or an invented conversion factor. There is no code path
  anywhere in this system that turns `333000` into `66.6` or `5000` into
  `5` because it "looks like" a scaling or unit error — a human decides
  that, by editing the source file and re-uploading, not the importer.
  A future UI *could* surface these flags as `SUSPECTED_UNIT_ERROR` /
  `SUSPECTED_PRICE_SCALE_ERROR` badges for the reviewer, but this batch
  only builds the CLI-level detection (G9's report) — the wording/badge
  layer is frontend work for whichever Phase G-DATA milestone builds the
  review UI.
- Every anomaly category above is additive metadata (a report count, or a
  `WARNING` row status that still requires human sign-off before commit)
  — none of them change what value ends up in the database. The only
  thing that ever writes a database value from a validated row is the
  unmodified `commit()` path, using exactly what was in the file.

## Status

Implemented and verified in this session:
`abnormal_cost` and `abnormal_qty` (Opening Stock) tested via
`generate_import_quality_report.php` with real staged data (5x+ outliers
correctly flagged, in-band values correctly not flagged).
`CONVERSION_SUSPECT` (Master Item) tested via `ImportMasterItemService`
(Phase G2.2 verification). Live-transaction `PriceAnomalyService` was
pre-existing and unchanged.
