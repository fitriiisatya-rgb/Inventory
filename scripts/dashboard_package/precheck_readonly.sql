-- READ-ONLY schema precheck for DashboardInventoryService. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: first result = 14 rows (all tables exist); every later MISSING_ result = 0 rows.
SELECT table_name AS present_table FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name IN (
   'inventory_transactions','inventory_transaction_lines','inventory_batches','items','categories','units','warehouses',
   'warehouse_transfers','warehouse_transfer_lines','stock_opname_sessions','stock_opname_lines','suppliers',
   'bakery_destinations','divisions')
 ORDER BY table_name;

SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'inventory_transactions' tbl, 'transaction_type' col UNION SELECT 'inventory_transactions','status'
  UNION SELECT 'inventory_transactions','transaction_date' UNION SELECT 'inventory_transactions','reference_no'
  UNION SELECT 'inventory_transactions','supplier_id' UNION SELECT 'inventory_transactions','warehouse_id'
  UNION SELECT 'inventory_transaction_lines','base_qty' UNION SELECT 'inventory_transactions','inventory_effect'
  UNION SELECT 'items','minimum_stock' UNION SELECT 'items','category_id' UNION SELECT 'items','base_unit_id'
  UNION SELECT 'stock_opname_lines','final_rusak_qty' UNION SELECT 'stock_opname_lines','unit_cost_base'
  UNION SELECT 'stock_opname_sessions','status' UNION SELECT 'stock_opname_sessions','posted_at'
  UNION SELECT 'warehouse_transfers','status' UNION SELECT 'warehouse_transfers','from_warehouse_id') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;

-- context (read-only look): warehouses the Gudang filter will list, and open/active opname sessions
SELECT id, code, name, is_active FROM warehouses ORDER BY id;
SELECT status, COUNT(*) AS sessions FROM stock_opname_sessions GROUP BY status;
