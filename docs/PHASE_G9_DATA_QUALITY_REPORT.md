# Phase G9 — Data Quality Report

Generates a summary report for any staged import batch (before or after
commit), matching the three categories the cutover process needs:

```bash
php scripts/generate_import_quality_report.php <type> <id>
```

| `type` | `id` is... |
|---|---|
| `MASTER_ITEM` | `import_batches.id` |
| `SUPPLIER` / `DIVISION` / `WAREHOUSE` | `import_batches.id` |
| `OPENING_STOCK` | `stock_openings.id` |
| `HISTORICAL_TRANSACTION` | `import_batches.id` |

Writes both `<type>_<id>_<timestamp>.json` and `.md` to
`migration/workspace/reports/` and prints the Markdown to stdout.

## What it reports

**Master** (and Supplier/Division/Warehouse, same shape): Total rows,
Valid, Warning, Error, Duplicate SKU, Duplicate Barcode, Invalid Unit,
Invalid Conversion — the first four come straight from
`import_batches`/`import_rows.row_status`; the category counts are derived
by pattern-matching each row's stored validation `messages` (never
re-running validation — this is read-only reporting over what staging
already decided).

**Opening**: Total rows, Valid, Warning, Error, Negative Qty, Zero Cost,
Abnormal Cost, Duplicate Opening. Negative Qty / Zero Cost read directly
from `stock_opening_lines`. **Abnormal Cost** and **Duplicate Opening** are
this script's own independent analysis (G6's full validation-blocking
logic for these is out of this batch's scope) — Abnormal Cost compares
each line's cost to the median cost of other positive-cost lines *for the
same item within this same file* and flags anything more than 5x above or
below 0.2x (same thresholds as `ReconciliationService`'s
`abnormal_cost_batch` check, for consistency); Duplicate Opening flags a
repeated `(warehouse, item, batch_reference)` combination within the file.
Neither of these changes what the importer accepts or rejects — they are
visibility only.

**Historical**: Total rows, Valid, Warning, Error, Unknown SKU, Unknown
Warehouse, Unknown Supplier, Unknown Division, Duplicate Legacy ID. Unknown
SKU/Warehouse come from the existing importer's own validation messages.
**Unknown Supplier**, **Unknown Division**, and **Duplicate Legacy ID** are
this script's own analysis (`ImportHistoricalTransactionService` doesn't
validate supplier_code/division_code today — G7 detail is out of this
batch's scope) — it checks each row's `supplier_code`/`division_code`
against the master tables directly, and flags a repeated
`reference_no`/`original_legacy_id` within the file as a likely duplicate
legacy record.

## Verified in this session

Ran against three real staged batches on the sandbox database:

- **Master Item** (3 rows: 1 valid, 1 duplicate-barcode warning, 1
  invalid-unit error) → report correctly showed `valid=1, warning=1,
  error=1, duplicate_barcode=1, invalid_unit=1`.
- **Opening Stock** (2 rows for the same item, one costed 100x the other)
  → report correctly flagged `abnormal_cost=1`.
- **Historical Transaction** (3 rows: one with an unrecognized warehouse,
  two sharing the same `reference_no`, both referencing unknown
  supplier/division codes) → report correctly showed `error=1,
  unknown_warehouse=1, unknown_supplier=2, unknown_division=2,
  duplicate_legacy_id=1`.

A bug was caught and fixed during this verification: the Unknown
SKU/Unknown Warehouse counters were seeded but never actually incremented
in the first version of the script — fixed before this report was
finalized.
