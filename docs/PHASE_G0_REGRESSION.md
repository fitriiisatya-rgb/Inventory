# Phase G0 — Pre-Cutover Regression Result

**Run date:** 2026-09-16 04:48 UTC
**Environment:** MariaDB 10.11 (throwaway sandbox instance), fresh `database/schema.sql` reloaded before every test file (`tests/run_mysql_tests.sh`)
**Command:** `MYSQL_TEST_ARGS="-h127.0.0.1 -P13306 -uroot" bash tests/run_mysql_tests.sh`
**Result: ALL PASS — 82/82 assertions, 0 failures, exit code 0.**

This is the gate required before any Phase G production-data work begins. Since every suite passed, Phase G tooling work (G1–G25 subset) proceeds as approved. If any suite below had failed, this document would stop at that point and no production-data work would follow.

## Suite-by-suite result

| Suite | File | Assertions | Result |
|---|---|---|---|
| Smoke (FIFO, real `FOR UPDATE` lock, insufficient-stock rollback) | `tests/mysql_smoke_test.php` | 4 | PASS |
| Integration — Transfer, Stock Opname, Production, Book Closing, Reconciliation | `tests/mysql_integration_test.php` | 35 | PASS |
| Importer — Supplier, Division, Warehouse, Opening Stock, Historical Transaction | `tests/mysql_importer_test.php` | 12 | PASS |
| Void/Reversal — IN void, OUT void (original-cost restore), void on LOCKED period | `tests/mysql_void_test.php` | 15 | PASS |
| Security — auth, CSRF, role authorization, warehouse/division scope, rate limit, unauthenticated | `tests/mysql_security_test.php` | 11 | PASS |
| Concurrency — two real DB connections racing OUT against the same batch | `tests/concurrency_test.sh 5` | 5 runs | PASS |

## Coverage against the requested checklist

- **auth** — valid/invalid login (assertions 1–2 of security suite): PASS
- **CSRF** — missing/invalid/valid token (assertions 3–5): PASS
- **role authorization** — VIEWER POST rejected, DIVISION user denied a permission not granted to their role (assertions 6, 8): PASS
- **warehouse restriction** — STOCK user rejected posting to a warehouse outside their scope, allowed on their own (assertions 7, 7b): PASS
- **division restriction** — covered by assertion 8 (DIVISION-role permission boundary); warehouse/division scope logic itself is exercised throughout the integration suite via `inv_require_warehouse_scope`/`inv_require_division_scope`
- **rate limit** — repeated failed logins eventually 429 RATE_LIMITED (assertion 9): PASS
- **unauthenticated access** — rejected 401 UNAUTHENTICATED (assertion 10): PASS
- **transaction / FIFO** — two-layer FIFO consumption, insufficient-stock rejection + rollback proof: PASS
- **transfer** — ship→PENDING→receive, idempotent receive, rejected duplicate-attempt receive, cancel restores exact batch, in-transit value accounting: PASS
- **opname** — warehouse lock during active session, variance→adjustment, idempotent re-post: PASS
- **production** — FIFO consumption cost → derived output unit cost, value conservation, idempotent duplicate: PASS
- **void/reversal** — IN void, OUT void restoring *original* recorded cost (not current price), double-void rejection, locked-period override path: PASS
- **closing** — successful close, ending value matches live value at close time, next-period opening continuity, period-lock rejection of a late post, idempotent re-close, chronological-order enforcement: PASS
- **concurrency** — 5 independent runs of two real OS processes racing `OUT` against the same stock, each resolving to exactly one winner and one rejection, final stock consistent: PASS
- **importer** — Supplier/Division/Warehouse/Opening Stock (real batch created)/Historical Transaction (stock unaffected, `inventory_effect=0`): PASS
- **reconciliation** — `go_live_ready` flag returned, all 7 existing checks PASS on the post-test dataset

## Conclusion

No regressions found from the Phase D frontend work. **Gate is GREEN — Phase G tooling (G0–G5, G8–G10, G12–G17 tooling, G19 tooling, G21 provisioning support, G24 checklist, G25 health endpoint) may proceed**, per the scope explicitly authorized for this batch. Production data import, opening-stock derivation from the old database, cutover-date selection, and historical production import remain explicitly out of scope until Phase G-DATA.
