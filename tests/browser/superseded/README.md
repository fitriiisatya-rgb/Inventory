Superseded by Reports v3 (the pages these tests drove — report-movement.js `.mvr-*` and stock-opname-report.js `.soa-*` — are no longer routed; the files stay in the tree for route compatibility):
* playwright_movement_report.mjs  -> tests/browser/playwright_pergerakan_v3.mjs
* playwright_so_audit_report.mjs  -> tests/browser/playwright_so_audit_v3.mjs
The older per-module package builders / rehearsals (scripts/build_{pur,val,io,sbc,soa,soa3,mvr}_package.sh, tests/*_package_rehearsal.sh) are superseded by scripts/build_rv3_package.php + tests/rv3_package_rehearsal.sh.
* playwright_jejak_real_data.mjs — drove the Jejak row-click on the OLD report-opname table; Jejak itself is untouched (tests/inventory_stock_opname_jejak_test.php 165/165) and is reached from the new page's "Lihat Jejak" (covered in playwright_so_audit_v3.mjs, section D).
* playwright_v2164.mjs — looked up the "Laporan Stock Opname" link in the Stock Opname sidebar group, which the earlier sidebar cleanup had already moved into the Laporan menu (stale before Reports v3).
