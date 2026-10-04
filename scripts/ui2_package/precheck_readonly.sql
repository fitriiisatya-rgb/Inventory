-- READ-ONLY precheck for the UI2 package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: the first result has 1 row (the column the Dead Stock card now reads exists).
SELECT column_name AS present_column FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'stock_opname_lines' AND column_name IN ('final_deadstock_qty');
-- context: what the Dead Stock card will show per warehouse (latest POSTED opname; 0 rows = the card shows 0 for that warehouse until an opname with a Deadstock quantity is posted)
SELECT w.name AS warehouse, s.session_number, s.session_date, COUNT(*) AS lines_with_deadstock,
       ROUND(SUM(sol.final_deadstock_qty * sol.unit_cost_base), 2) AS deadstock_value
  FROM stock_opname_lines sol
  JOIN stock_opname_sessions s ON s.id = sol.session_id AND s.status = 'POSTED'
  JOIN warehouses w ON w.id = s.warehouse_id
 WHERE sol.final_deadstock_qty > 0
   AND s.id = (SELECT s2.id FROM stock_opname_sessions s2 WHERE s2.warehouse_id = s.warehouse_id AND s2.status = 'POSTED' ORDER BY s2.session_date DESC, s2.id DESC LIMIT 1)
 GROUP BY w.name, s.session_number, s.session_date ORDER BY w.name;
SELECT w.name AS warehouse, MAX(s.session_date) AS latest_posted_opname FROM stock_opname_sessions s JOIN warehouses w ON w.id = s.warehouse_id WHERE s.status = 'POSTED' GROUP BY w.name ORDER BY w.name;
