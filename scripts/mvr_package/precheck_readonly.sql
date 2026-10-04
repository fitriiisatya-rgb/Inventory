-- READ-ONLY precheck for the Pergerakan Stok Harian package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: INVENTORY_VIEW exists and is held by the roles you expect to see the report; the MISSING_column list is EMPTY.
SELECT pe.code AS permission, GROUP_CONCAT(r.code ORDER BY r.code) AS held_by
  FROM permissions pe JOIN role_permissions rp ON rp.permission_id = pe.id JOIN roles r ON r.id = rp.role_id
 WHERE pe.code = 'INVENTORY_VIEW' GROUP BY pe.code;
-- every column the new report reads — must return 0 rows (MISSING_column)
SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'inventory_transactions' tbl, 'transaction_type' col UNION SELECT 'inventory_transactions','status' UNION SELECT 'inventory_transactions','inventory_effect'
  UNION SELECT 'inventory_transactions','transaction_date' UNION SELECT 'inventory_transactions','created_at' UNION SELECT 'inventory_transactions','created_by'
  UNION SELECT 'inventory_transactions','reference_no' UNION SELECT 'inventory_transactions','void_reason' UNION SELECT 'inventory_transactions','supplier_id' UNION SELECT 'inventory_transactions','bakery_destination_id'
  UNION SELECT 'inventory_transaction_lines','base_qty' UNION SELECT 'inventory_transaction_lines','subtotal' UNION SELECT 'inventory_transaction_lines','unit_cost_base'
  UNION SELECT 'inventory_transaction_lines','unit_price_input' UNION SELECT 'inventory_transaction_lines','warehouse_id' UNION SELECT 'inventory_transaction_lines','notes'
  UNION SELECT 'fifo_allocations','transaction_line_id' UNION SELECT 'fifo_allocations','qty_allocated' UNION SELECT 'fifo_allocations','subtotal'
  UNION SELECT 'stock_adjustments','adjustment_type' UNION SELECT 'stock_adjustments','transaction_id' UNION SELECT 'stock_adjustments','reference_no' UNION SELECT 'stock_adjustments','reason'
  UNION SELECT 'items','sku' UNION SELECT 'items','name' UNION SELECT 'items','category_id' UNION SELECT 'items','base_unit_id'
  UNION SELECT 'units','code' UNION SELECT 'users','username' UNION SELECT 'users','full_name' UNION SELECT 'warehouses','code') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;
-- context: size of what the report scans (it is read-only; the per-day query cost grows with these)
SELECT (SELECT COUNT(*) FROM inventory_transactions) AS transactions, (SELECT COUNT(*) FROM inventory_transaction_lines) AS lines,
       (SELECT COUNT(*) FROM fifo_allocations) AS fifo_allocations, (SELECT COUNT(*) FROM items) AS items,
       (SELECT MIN(transaction_date) FROM inventory_transactions) AS first_transaction, (SELECT MAX(transaction_date) FROM inventory_transactions) AS last_transaction;
