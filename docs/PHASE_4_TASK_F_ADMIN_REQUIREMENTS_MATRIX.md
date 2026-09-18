# Phase 4 — Task F: Mandatory Admin Requirements — PASS/FAIL Matrix

**Important caveat before the matrix — read this first.** The owner's
Phase 4 message restated the mandatory admin requirements as an
**18-numbered list**, extending the 10-item list this session had already
verified and recorded in `docs/PHASE_3_COMPLETION_REPORT.md` Section 10.
This session's context was compacted between receiving that Phase 4
message and doing this write-up, and **the exact original wording/numbering
of the 18 items was not preserved verbatim** in what survived compaction —
only a paraphrased list of the same 18 concepts. Rather than invent exact
item text and risk it not matching what the owner actually wrote item-by-item,
the table below is reconstructed from:
1. The 10 items already verified verbatim in `PHASE_3_COMPLETION_REPORT.md`
   Section 10 (items 1-11 below map directly onto those, unchanged).
2. The additional concepts this session's own summary of the Phase 4
   message named as newly added (export, pagination, transaction detail,
   item detail, FIFO layers, dark-navy V2 UI, warehouse-scoped
   enforcement) — items 12-18 below.

**If this reconstructed numbering/wording does not match the owner's
original 18-item list exactly, the owner should say so and provide the
exact list — this document will be corrected against it.** Every row below
is independently true and evidenced regardless of numbering, so the
underlying verification work is not invalidated by a numbering mismatch;
only the mapping to "item N" could need adjustment.

## Matrix

| # | Requirement | Status | Source file/module | API | Test evidence |
|---|---|---|---|---|---|
| 1 | Laporan stok shows ALL items, including zero-stock, by default | ✅ PASS | `services/StockReportService.php::list()` | `GET /reports/stock` | `tests/stock_report_test.php` §A — zero-stock item included by default; `include_zero_stock=false` explicitly excludes it, stocked item unaffected either way |
| 2 | Filter per category | ✅ PASS | `StockReportService::list()` `category_id` param | `GET /reports/stock?category_id=` | `stock_report_test.php` §B |
| 3 | Search SKU / nama barang | ✅ PASS | `StockReportService::list()` `q` param | `GET /reports/stock?q=` | `stock_report_test.php` §C |
| 4 | Minimum stock, per item per warehouse | ✅ PASS | `item_warehouse_stock_policy.minimum_stock_base`, `StockPolicyService::resolve()` | `GET`/`PUT /stock-policy` | `tests/stock_policy_test.php` §A-D, H; live SCM/Cibadak backfill verified in `docs/PHASE_4_TASK_E_STOCK_POLICY_MIGRATION_VERIFICATION.md` |
| 5 | Buffer stock, per item per warehouse, nullable with explicit `buffer_configured` flag | ✅ PASS | `item_warehouse_stock_policy.buffer_stock_base` (nullable), `StockPolicyService::resolve()` | `GET`/`PUT /stock-policy`, `GET /reports/stock` | `stock_policy_test.php` §H (`buffer_configured` round-trips both `true`/`false` correctly); Task E §2/§5 |
| 6 | Status states (SAFE/LOW/CRITICAL/OUT_OF_STOCK, plus REVIEW for migration-negative) | ✅ PASS | `StockPolicyService::stockStatus()` (PHP) + `StockReportService`'s SQL `CASE` (kept in sync) | `GET /reports/stock` | `stock_policy_test.php` §G (34/34, all 5 states + boundaries); `stock_report_test.php` §F (SQL/PHP cross-check, every row); §G (REVIEW always wins, never folded into the other 4 states) |
| 7 | History transaksi IN/OUT | ✅ PASS | `services/TransactionHistoryService`-equivalent logic in `public/index.php` route + `StockReportService`-adjacent transaction query | `GET /reports/transactions` | `tests/transaction_history_test.php` §A |
| 8 | History shows area asal/tujuan (source/destination) | ✅ PASS (warehouse-level; bakery destination for OUT — see Section 17 of `PHASE_3_COMPLETION_REPORT.md` for the one known narrowing: division-level "asal" within a warehouse is not separately tracked) | same as #7 | `GET /reports/transactions` | `transaction_history_test.php` §A (warehouse + `bakery_destination` fields present per row) |
| 9 | History shows qty/unit | ✅ PASS | same as #7 | `GET /reports/transactions` | `transaction_history_test.php` §A |
| 10 | Master Vendor/Supplier | ✅ PASS | `services/SupplierService.php`, `public/assets/js/master-vendors.js` | `GET/POST/PUT /suppliers` | `tests/master_data_v2_test.php` §A-B |
| 11 | Master Bakery Tujuan | ✅ PASS | `services/BakeryDestinationService.php`, `public/assets/js/master-bakery-destinations.js` | `GET/POST/PUT /bakery-destinations` | `master_data_v2_test.php` §A, C, D (round-trips through a real OUT transaction; never persisted for non-OUT types) |
| 12 | Export laporan (CSV) | ✅ PASS | `StockReportService::exportAll()` | `GET /reports/stock?format=csv` | `stock_report_test.php` §J (unpaginated export, same row shape as `list()`, HTTP `text/csv` content-type + header row verified) |
| 13 | Pagination (server-side, not client-side) | ✅ PASS | `StockReportService::list()`, transaction-history equivalent | `GET /reports/stock`, `GET /reports/transactions` (both take `page`/`per_page`) | `stock_report_test.php` §D; `transaction_history_test.php` §E |
| 14 | Detail transaksi (transaction detail drawer: header, lines, FIFO allocations, audit) | ✅ PASS | transaction detail route in `public/index.php`, `public/assets/js/transaction-history.js` | `GET /reports/transactions/{id}` | `transaction_history_test.php` §D — header, lines, FIFO allocations for OUT, `audit_log` present only when `includeAudit=true` |
| 15 | Detail item (item detail drawer: Overview / FIFO Layers / Movement tabs) | ✅ PASS | `public/assets/js/stock-report.js::openItemDetail()` (Overview tab from the report row itself; FIFO tab via `GET /inventory/batches`; Movement tab via `GET /reports/transactions?item_id=`) | `GET /inventory/batches`, `GET /reports/transactions` | `tests/warehouse_isolation_regression_test.php` (batches endpoint, warehouse-scoped); `transaction_history_test.php` §B (`item_id` filter) |
| 16 | FIFO layers shown (read-only, operator never picks a layer manually) | ✅ PASS | `buildFifoPreviewTable()` in `public/assets/js/transactions.js` (OUT stepper preview, client-side simulation only — never sent to the server); `renderFifoTab()` in `stock-report.js` (item detail drawer); `fifo_allocations` in transaction detail | `GET /inventory/batches`, `GET /reports/transactions/{id}` | `docs/PHASE_4_TASK_A` stepper smoke test (24/24, includes "OUT review FIFO table shows an estimated-consumed value"); `transaction_history_test.php` §D (`fifo_allocations` on OUT line) |
| 17 | Dark-navy V2 UI (sidebar-based enterprise ERP/WMS visual design) | ✅ PASS | `public/assets/css/app.css` (`:root` design tokens, dark-navy `--bg`/`--bg2`/`--sidebar-bg` palette reused throughout); `public/assets/js/sidebar.js` | n/a (visual design, not an API) | Manual/visual verification — Playwright smoke test screenshots taken this phase (`stepper-final.png`) show the rendered dark-navy shell; owner's own mockup screenshots were the original spec (not preserved in this repo — see `docs/SESSION_HANDOFF_V2_AUDIT.md` §2) |
| 18 | Warehouse-scoped UI + backend enforcement (STOCK role always re-derived from DB, never trusts request) | ✅ PASS | `inv_require_warehouse_scope()` (`public/index.php:225`) — always re-derives the actual resource `warehouse_id` from the database, never from the request | every warehouse-scoped endpoint (`/reports/stock`, `/reports/transactions`, `/inventory/batches`, `/stock-policy`, transactions IN/OUT, etc.) | `stock_report_test.php` §K; `transaction_history_test.php` §F; `tests/warehouse_isolation_regression_test.php` (dedicated regression suite, Phase 3a) |

## Summary

**18/18 PASS** under the reconstructed numbering above. 0 FAIL, 0 SKIP.

Every PASS cites: the implementing source file/module, the API route
involved (where applicable — #17 is a visual/UI requirement with no API),
and the specific automated test section that exercises it — all of which
are part of the standing regression suite (`tests/run_mysql_tests.sh`,
currently 276/276), not one-off manual checks, except #17 (visual design),
which is confirmed by screenshot evidence rather than an assertion, as a
color palette and layout cannot be asserted against numerically the way an
API response can.

## One known narrowing (not a FAIL, documented per item 8 above)

`docs/PHASE_3_COMPLETION_REPORT.md` Section 17 records that transaction
history's "source/destination area" is tracked at the warehouse level
(plus `bakery_destination` for OUT), not at a finer internal-division
level within a single warehouse. This was already disclosed as known
technical debt in the Phase 3 completion report and is restated here
for completeness, not as a new finding.
