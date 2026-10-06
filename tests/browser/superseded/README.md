Superseded by Reports v3 (the pages these tests drove — report-movement.js `.mvr-*` and stock-opname-report.js `.soa-*` — are no longer routed; the files stay in the tree for route compatibility):
* playwright_movement_report.mjs  -> tests/browser/playwright_pergerakan_v3.mjs
* playwright_so_audit_report.mjs  -> tests/browser/playwright_so_audit_v3.mjs
The older per-module package builders / rehearsals (scripts/build_{pur,val,io,sbc,soa,soa3,mvr}_package.sh, tests/*_package_rehearsal.sh) are superseded by scripts/build_rv3_package.php + tests/rv3_package_rehearsal.sh.
