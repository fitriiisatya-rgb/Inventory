# Phase V2.3 — Laporan Nilai Stok & HPP (Inventory Value & HPP Reconciliation)

Built on top of the completed Universal Traceability architecture (Phase
V2.2 + V2.2B). Per the owner's explicit instruction, every clickable
number in this report opens the **existing** `TraceDrawer`/`TraceService`
— this phase adds zero new trace logic of its own.

## Audit done before writing code (Section 13 of the spec)

- Confirmed `InventoryService`'s sign convention (`POSITIVE_TYPES`/
  `NEGATIVE_TYPES`/already-signed ADJUSTMENT+REVERSAL) is the single
  source of truth for "signed value" and reused it verbatim
  (`InventoryHppReportService::SIGNED_VALUE_SQL`).
- Confirmed `fifo_allocations.subtotal` is always a positive magnitude
  (`qty_allocated > 0` CHECK constraint, `subtotal = qty * unit_cost_base`)
  — the authoritative source for FIFO HPP, read directly rather than
  trusting `inventory_transaction_lines.subtotal` (which happens to equal
  the same number for an OUT line, but the allocation table is the actual
  auditable cost trail the spec asks HPP to be traceable through).
- Confirmed a decrease-type `ADJUSTMENT` also writes real `fifo_allocations`
  rows (`StockAdjustmentService::postDecrease`) — correctly excluded from
  FIFO HPP (`transaction_type = 'OUT'` only) so an adjustment's cost never
  silently inflates "barang keluar FIFO."
- No new tables. No migration. `Karang Tengah` never referenced anywhere.

## Formulas (services/InventoryHppReportService.php)

```
Opening Value   = SUM(signed_value) WHERE transaction_date <  start_date
Ending Value    = SUM(signed_value) WHERE transaction_date <= end_date
External Purchase = SUM(ABS(subtotal)) WHERE type='IN' in [start,end]
FIFO HPP        = SUM(fifo_allocations.subtotal) for allocations whose
                  consuming line's transaction is type='OUT' in [start,end]
HPP Reconciliation = Opening + External Purchase − Ending   (literal spec formula)
Variance        = HPP Reconciliation − FIFO HPP
```

Variance is **never** forced to zero — it algebraically equals the
negated sum of every other in-period movement (Adjustment, Opname [a
sub-slice of Adjustment, disclosed separately], Reversal, Production,
mid-period Opening, net Transfer imbalance), all of which are returned
alongside it in `non_hpp_movements` so a nonzero variance is always
explainable from the same response, never a mystery number.

Proven against a hand-computed synthetic scenario in
`tests/inventory_hpp_report_test.php` (Opening 100k → Purchase 60k → FIFO
OUT 30k → Adjustment −5k ⇒ Ending 125k, Reconciliation 35k, Variance 5k,
and `variance == -adjustment_net` checked as a real number, not just
reasoned about).

## API surface (all read-only, `INVENTORY_VIEW`, STOCK forced to own warehouse)

| Route | Purpose |
|---|---|
| `GET /reports/inventory-hpp/summary` | 6 KPI cards + formula strip + non-HPP disclosure + vs-previous-period deltas |
| `GET /reports/inventory-hpp/warehouses` | Per-warehouse panel data + daily trend for the sparkline |
| `GET /reports/inventory-hpp/daily` | Paginated daily recap (every calendar day, including zero-movement ones) |
| `GET /reports/inventory-hpp/day-detail` | The Trace Detail HPP panel's data for one date — every row carries a real `transaction_id` |
| `GET /reports/inventory-hpp/export` | Streams a real 5-sheet .xlsx |

`category_id`/`q` (SKU/name) filter `summary` and `daily`/`export`
consistently (so the KPI cards and the table beneath them never disagree);
they deliberately do **not** filter the two structural warehouse panels,
which represent an org-level rollup — a documented scope boundary, not an
oversight.

## Deep trace integration (the owner's explicit requirement)

- **Nilai Stok Awal / Akhir** → drill via the existing `TraceDrawer.openInventory(item_id, warehouse_id)` (item/warehouse movement history, unchanged code).
- **Pembelian Eksternal / Barang Keluar FIFO** → every `day-detail` row carries `transaction_id`; clicking it calls `TraceDrawer.openTransaction()` — proven in `tests/inventory_hpp_report_test.php` section E by calling the real `TraceService::transactionTrace()` on the id the HPP report returned and asserting it shows the same FIFO allocation.
- **HPP Reconciliation / Variance** → the formula strip and `non_hpp_movements` disclosure name the contributing transaction types; the Excel export's "Pergerakan Non-HPP" sheet lists every contributing row individually.
- **Warehouse cards** → "Lihat Detail →" sets the report's own `warehouse_id` filter (the same one the filter bar's Gudang select drives) rather than opening a second view.
- **Trace Detail HPP panel** → built entirely from `day-detail`; every transaction row opens `TraceDrawer.openTransaction()` directly — verified in the browser (screenshot: clicking a row opens "Jejak Transaksi #3 — OUT" with the real Overview/Lines/FIFO/Relations/Audit tabs).

No second trace implementation exists anywhere in this phase's code.

## Excel export

No PhpSpreadsheet/composer/vendor exists anywhere in this codebase
(confirmed by audit). `services/ExcelWriterService.php` is a minimal,
dependency-free OOXML writer (`ZipArchive` + hand-written XML, inline
strings, a bold header row) — the standard no-dependency technique, not a
CSV renamed to `.xlsx`. Verified with `python3 -c "import openpyxl; ..."`
(a real, independent XLSX reader) and with `ZipArchive::open()` in the
test suite. 5 sheets: Ringkasan, Breakdown Gudang, Rekap Harian, Detail
Transaksi FIFO, Pergerakan Non-HPP.

## Known scope boundaries (disclosed, not silently gapped)

- **Periode Harian/Mingguan/Bulanan** buttons are a client-side date-range
  preset (sets today / this week / this month into the two date inputs) —
  there is no server-side weekly/monthly aggregation grouping; the daily
  recap always lists individual calendar days. Matches what the mockup's
  screenshot itself shows (a specific date range with "Bulanan" merely
  highlighted as the active preset).
- Category/SKU filters apply to the KPI summary and daily recap, not to
  the two warehouse structural panels (see above).
- No Safari/WebKit engine in this sandbox — browser verification is
  Chrome-only, same disclosed gap as every prior phase.

## Tests

`tests/inventory_hpp_report_test.php` — 50 checks: formula correctness
against hand-computed numbers, warehouse scoping, pagination, day-detail
trace-linkage (a real cross-check against `TraceService`), category/SKU
filtering, read-only guarantee (before/after state snapshot), Excel
export validity (openable ZIP, correct sheet names/row counts), and
HTTP-level warehouse isolation (STOCK forced to own warehouse, a
zero-activity warehouse correctly shows zero not another warehouse's
numbers, unauthenticated rejected). Added to `tests/run_mysql_tests.sh`.

Full regression after every change in this phase: **500/500 passed, 0
failed** (450 pre-existing + 50 new), across all 16 suites + concurrency.

## Browser verification

Logged in as SUPERADMIN, set the date range to the fixture data's period,
confirmed: KPI cards/formula strip match the derived formulas exactly,
both warehouse panels render with sparklines, the daily recap table
matches per-day values, clicking a row opens the Trace Detail HPP panel
with the correct FIFO breakdown, clicking a transaction row inside it
opens the real `TraceDrawer`, warehouse drill-down correctly re-scopes the
whole report, Export Excel returns a real 200 with the correct
`spreadsheetml.sheet` content-type, the default (no-activity) period
renders a graceful all-zero/"Seimbang" state, and mobile (390px) has zero
horizontal page overflow after fixing a flex `align-items: flex-start`
regression found during this verification (root cause: it wasn't reset to
`stretch` in the mobile column-layout breakpoint, so `.hpp-main-col`
sized to its content's max-content width instead of the viewport).
