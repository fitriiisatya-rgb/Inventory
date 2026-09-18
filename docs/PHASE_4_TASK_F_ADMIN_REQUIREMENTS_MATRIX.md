# Phase 4 — Task F: Mandatory Admin Requirements — PASS/FAIL Matrix

**Status: 18/18 PASS, 0 FAIL, 0 SKIP.**

This revision uses the owner's exact 18-item list as re-stated in the
Phase 4 approval message (superseding the earlier reconstructed version
of this document, which had independently arrived at the same 18 items
in substantially the same order — no result below changed, only the
wording is now the owner's verbatim item text and the format now
separates automated test evidence from manual/browser evidence per the
owner's explicit request).

## Matrix

| # | Requirement (owner's exact wording) | PASS/FAIL | Source file/module | API involved | Automated test evidence | Manual/browser evidence |
|---|---|---|---|---|---|---|
| 1 | Laporan stok semua item lengkap | ✅ PASS | `services/StockReportService.php::list()` | `GET /reports/stock` | `tests/stock_report_test.php` §A — zero-stock item included by default; `include_zero_stock=false` explicitly excludes it, stocked item unaffected | — |
| 2 | Filter kategori | ✅ PASS | `StockReportService::list()` `category_id` param | `GET /reports/stock?category_id=` | `stock_report_test.php` §B | — |
| 3 | Search SKU / nama barang | ✅ PASS | `StockReportService::list()` `q` param | `GET /reports/stock?q=` | `stock_report_test.php` §C | — |
| 4 | Minimum stock | ✅ PASS | `item_warehouse_stock_policy.minimum_stock_base`, `StockPolicyService::resolve()` | `GET`/`PUT /stock-policy`, `GET /reports/stock` | `tests/stock_policy_test.php` §A-D, H | Live SCM/Cibadak backfill verified end-to-end in `docs/PHASE_4_TASK_E_STOCK_POLICY_MIGRATION_VERIFICATION.md` on a disposable DB |
| 5 | Buffer stock | ✅ PASS | `item_warehouse_stock_policy.buffer_stock_base` (nullable), `StockPolicyService::resolve()` | `GET`/`PUT /stock-policy`, `GET /reports/stock` | `stock_policy_test.php` §H (`buffer_configured` round-trips both `true`/`false`) | Task E §2/§5; §3 of this Phase 5 response (buffer-formula consistency re-verification) |
| 6 | Status aman / warning / kritis / habis / review | ✅ PASS | `StockPolicyService::stockStatus()` (PHP) + `StockReportService`'s SQL `CASE` (kept identical) | `GET /reports/stock` | `stock_policy_test.php` §G (34/34 — all 5 states + every boundary); `stock_report_test.php` §F (SQL/PHP cross-check on every row, no drift possible); §G (REVIEW always wins) | — |
| 7 | History IN/OUT | ✅ PASS | transaction-history route in `public/index.php` | `GET /reports/transactions` | `tests/transaction_history_test.php` §A | — |
| 8 | Area asal / tujuan | ✅ PASS (warehouse-level + `bakery_destination` for OUT — see the one known narrowing below) | same as #7 | `GET /reports/transactions` | `transaction_history_test.php` §A (warehouse + `bakery_destination` fields present per row) | — |
| 9 | Qty + unit | ✅ PASS | same as #7 | `GET /reports/transactions` | `transaction_history_test.php` §A | — |
| 10 | Vendor / Supplier | ✅ PASS | `services/SupplierService.php`, `public/assets/js/master-vendors.js` | `GET/POST/PUT /suppliers` | `tests/master_data_v2_test.php` §A | — |
| 11 | Bakery Tujuan | ✅ PASS | `services/BakeryDestinationService.php`, `public/assets/js/master-bakery-destinations.js` | `GET/POST/PUT /bakery-destinations` | `master_data_v2_test.php` §B-D (create/duplicate-code/update; round-trips through a real OUT transaction; never persisted for TRANSFER_OUT) | — |
| 12 | Export report | ✅ PASS | `StockReportService::exportAll()` | `GET /reports/stock?format=csv` | `stock_report_test.php` §J (unpaginated export, same row shape as `list()`, HTTP `text/csv` content-type + header row) | — |
| 13 | Pagination | ✅ PASS | `StockReportService::list()`, transaction-history route | `GET /reports/stock`, `GET /reports/transactions` (both `page`/`per_page`) | `stock_report_test.php` §D; `transaction_history_test.php` §E | — |
| 14 | Detail transaksi | ✅ PASS | transaction-detail route in `public/index.php`, `public/assets/js/transaction-history.js` | `GET /reports/transactions/{id}` | `transaction_history_test.php` §D — header, lines, FIFO allocations for OUT, `audit_log` present only when `includeAudit=true` | — |
| 15 | Detail item | ✅ PASS | `public/assets/js/stock-report.js::openItemDetail()` (Overview / FIFO Layers / Movement tabs) | `GET /inventory/batches`, `GET /reports/transactions?item_id=` | `tests/warehouse_isolation_regression_test.php` (batches endpoint, warehouse-scoped); `transaction_history_test.php` §B (`item_id` filter) | Drawer UI exercised via Playwright in this session's earlier stepper work (drawer/tabs shared component); full item-detail-drawer click-through is manual-only (no dedicated Playwright script for this specific drawer yet — noted as a gap in §9 of the Phase 5 plan) |
| 16 | FIFO layers | ✅ PASS | `buildFifoPreviewTable()` in `public/assets/js/transactions.js` (OUT stepper preview, client-side simulation, never sent to the server); `renderFifoTab()` in `stock-report.js` (item detail drawer); `fifo_allocations` in transaction detail | `GET /inventory/batches`, `GET /reports/transactions/{id}` | `transaction_history_test.php` §D (`fifo_allocations` on OUT line) | Playwright stepper smoke test, 24/24 PASS incl. "OUT review FIFO table shows an estimated-consumed value" — see the test-count reconciliation in `docs/PHASE_4_TASK_G_FULL_VERIFICATION.md` |
| 17 | Dark navy V2 UI | ✅ PASS | `public/assets/css/app.css` (`:root` design tokens — dark-navy `--bg`/`--bg2`/`--sidebar-bg` palette reused throughout); `public/assets/js/sidebar.js` | n/a — visual design, no API | n/a — a color palette/layout cannot be asserted against numerically | Playwright screenshot (`stepper-final.png`, taken this phase) shows the rendered dark-navy shell; owner's own mockup screenshots were the original spec (not preserved in this repo — see `docs/SESSION_HANDOFF_V2_AUDIT.md` §2) |
| 18 | Warehouse scoped UI + backend | ✅ PASS | `inv_require_warehouse_scope()` (`public/index.php:225`) — always re-derives the actual resource `warehouse_id` from the database, never trusts the request | every warehouse-scoped endpoint (`/reports/stock`, `/reports/transactions`, `/inventory/batches`, `/stock-policy`, transactions IN/OUT, etc.) | `stock_report_test.php` §K; `transaction_history_test.php` §F; `tests/warehouse_isolation_regression_test.php` (dedicated Phase 3a regression suite, 28/28) | STOCK-role UI: sidebar/forms hide the warehouse selector for STOCK users (see `public/assets/js/transactions.js` `isStockUser()` guard) — visual-only, backend enforcement is the authoritative control per item above |

## Summary

**18/18 PASS, 0 FAIL, 0 SKIP.**

Every PASS cites: the implementing source file/module, the API route
involved (where applicable — #17 is a visual/UI requirement with no API),
the specific automated regression-suite section that exercises it (part
of the standing `tests/run_mysql_tests.sh`, currently **285/285** — see
`docs/PHASE_4_TASK_G_FULL_VERIFICATION.md` for the full test-count
reconciliation), and manual/browser evidence where an assertion alone
doesn't fully cover the requirement (visual design, drawer click-through).

## One known narrowing (not a FAIL, documented per item 8 above)

`docs/PHASE_3_COMPLETION_REPORT.md` Section 17 records that transaction
history's "area asal/tujuan" is tracked at the warehouse level (plus
`bakery_destination` for OUT), not at a finer internal-division level
within a single warehouse. This was already disclosed as known technical
debt in the Phase 3 completion report and is restated here for
completeness, not as a new finding.
