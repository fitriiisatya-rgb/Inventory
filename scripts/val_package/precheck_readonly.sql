-- READ-ONLY precheck for the Laporan Nilai Stok & HPP (FIFO + Average) package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: INVENTORY_VIEW exists; the MISSING_column list is EMPTY; the counts show how much real ledger / FIFO data the report will read.
SELECT pe.code AS permission, GROUP_CONCAT(r.code ORDER BY r.code) AS held_by
  FROM permissions pe JOIN role_permissions rp ON rp.permission_id = pe.id JOIN roles r ON r.id = rp.role_id
 WHERE pe.code = 'INVENTORY_VIEW' GROUP BY pe.code;
-- every table/column the report reads — must return 0 rows (MISSING_column)
SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'inventory_transactions' tbl, 'transaction_type' col UNION SELECT 'inventory_transactions','status' UNION SELECT 'inventory_transactions','transaction_date'
  UNION SELECT 'inventory_transactions','created_at' UNION SELECT 'inventory_transactions','reference_no' UNION SELECT 'inventory_transactions','reversal_of_id'
  UNION SELECT 'inventory_transactions','created_by' UNION SELECT 'inventory_transactions','inventory_effect' UNION SELECT 'inventory_transactions','warehouse_id'
  UNION SELECT 'inventory_transaction_lines','transaction_id' UNION SELECT 'inventory_transaction_lines','line_no' UNION SELECT 'inventory_transaction_lines','item_id'
  UNION SELECT 'inventory_transaction_lines','warehouse_id' UNION SELECT 'inventory_transaction_lines','base_qty' UNION SELECT 'inventory_transaction_lines','subtotal' UNION SELECT 'inventory_transaction_lines','notes'
  UNION SELECT 'inventory_batches','item_id' UNION SELECT 'inventory_batches','warehouse_id' UNION SELECT 'inventory_batches','original_qty_base' UNION SELECT 'inventory_batches','qty_base'
  UNION SELECT 'inventory_batches','unit_cost_base' UNION SELECT 'inventory_batches','received_date' UNION SELECT 'inventory_batches','is_negative_layer' UNION SELECT 'inventory_batches','source_transaction_line_id'
  UNION SELECT 'fifo_allocations','transaction_line_id' UNION SELECT 'fifo_allocations','batch_id' UNION SELECT 'fifo_allocations','qty_allocated' UNION SELECT 'fifo_allocations','unit_cost_base' UNION SELECT 'fifo_allocations','subtotal'
  UNION SELECT 'warehouse_transfer_lines','item_id' UNION SELECT 'warehouse_transfer_lines','in_transaction_line_id' UNION SELECT 'warehouse_transfer_lines','out_transaction_line_id'
  UNION SELECT 'items','category_id' UNION SELECT 'items','base_unit_id' UNION SELECT 'items','sku' UNION SELECT 'items','name' UNION SELECT 'categories','name' UNION SELECT 'units','code'
  UNION SELECT 'warehouses','code' UNION SELECT 'users','username') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;
-- how much real data the report replays (read-only counts)
SELECT 'ledger lines (inventory_effect = 1, POSTED / VOID)' AS what, COUNT(*) AS n FROM inventory_transaction_lines l JOIN inventory_transactions t ON t.id = l.transaction_id WHERE t.inventory_effect = 1 AND t.status IN ('POSTED','VOID')
UNION ALL SELECT 'FIFO layers (inventory_batches)', COUNT(*) FROM inventory_batches
UNION ALL SELECT 'FIFO allocations', COUNT(*) FROM fifo_allocations
UNION ALL SELECT 'negative (deficit) layers', COUNT(*) FROM inventory_batches WHERE is_negative_layer = 1
UNION ALL SELECT 'items with a negative current balance (their Average will read "tidak dapat direkonstruksi")', COUNT(*) FROM (SELECT item_id, warehouse_id FROM inventory_batches GROUP BY item_id, warehouse_id HAVING SUM(qty_base) < 0) x;
SELECT MIN(transaction_date) AS first_movement, MAX(transaction_date) AS last_movement FROM inventory_transactions WHERE inventory_effect = 1;
