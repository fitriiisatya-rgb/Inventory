-- READ-ONLY precheck for the Laporan Pembelian package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: INVENTORY_VIEW exists; the MISSING_column list is EMPTY; the purchase counts show how much real Stock IN V2 data the report will read.
SELECT pe.code AS permission, GROUP_CONCAT(r.code ORDER BY r.code) AS held_by
  FROM permissions pe JOIN role_permissions rp ON rp.permission_id = pe.id JOIN roles r ON r.id = rp.role_id
 WHERE pe.code = 'INVENTORY_VIEW' GROUP BY pe.code;
-- every table/column the report reads — must return 0 rows (MISSING_column)
SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'inventory_transactions' tbl, 'transaction_uuid' col UNION SELECT 'inventory_transactions','transaction_type' UNION SELECT 'inventory_transactions','status'
  UNION SELECT 'inventory_transactions','transaction_date' UNION SELECT 'inventory_transactions','reference_no' UNION SELECT 'inventory_transactions','supplier_id'
  UNION SELECT 'inventory_transactions','warehouse_id' UNION SELECT 'inventory_transactions','created_by' UNION SELECT 'inventory_transactions','created_at'
  UNION SELECT 'inventory_transactions','is_historical_import' UNION SELECT 'inventory_transactions','inventory_effect'
  UNION SELECT 'inventory_transaction_lines','transaction_id' UNION SELECT 'inventory_transaction_lines','item_id' UNION SELECT 'inventory_transaction_lines','line_no'
  UNION SELECT 'inventory_transaction_lines','input_qty' UNION SELECT 'inventory_transaction_lines','input_unit_id' UNION SELECT 'inventory_transaction_lines','base_qty'
  UNION SELECT 'inventory_transaction_lines','unit_price_input' UNION SELECT 'inventory_transaction_lines','unit_cost_base' UNION SELECT 'inventory_transaction_lines','subtotal'
  UNION SELECT 'purchase_invoice_headers','transaction_id' UNION SELECT 'purchase_invoice_headers','ppn_rate' UNION SELECT 'purchase_invoice_headers','ppn_treatment'
  UNION SELECT 'purchase_invoice_headers','ppn_creditable_pct' UNION SELECT 'purchase_invoice_headers','freight_amount' UNION SELECT 'purchase_invoice_headers','freight_treatment'
  UNION SELECT 'purchase_invoice_headers','invoice_total'
  UNION SELECT 'purchase_line_costs','transaction_line_id' UNION SELECT 'purchase_line_costs','gross_unit_price_input' UNION SELECT 'purchase_line_costs','gross_amount'
  UNION SELECT 'purchase_line_costs','line_discount_amount' UNION SELECT 'purchase_line_costs','net_after_line_discount' UNION SELECT 'purchase_line_costs','invoice_discount_allocated'
  UNION SELECT 'purchase_line_costs','net_purchase_before_tax' UNION SELECT 'purchase_line_costs','ppn_allocated' UNION SELECT 'purchase_line_costs','freight_allocated'
  UNION SELECT 'purchase_line_costs','final_inventory_cost' UNION SELECT 'purchase_line_costs','ppn_creditable_allocated' UNION SELECT 'purchase_line_costs','ppn_non_creditable_allocated'
  UNION SELECT 'items','category_id' UNION SELECT 'items','base_unit_id' UNION SELECT 'items','sku' UNION SELECT 'suppliers','name' UNION SELECT 'categories','name'
  UNION SELECT 'units','code' UNION SELECT 'warehouses','code' UNION SELECT 'users','username') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;
-- how much real data the report will read (read-only counts)
SELECT 'POSTED purchases (type IN, not historical)' AS what, COUNT(*) AS n FROM inventory_transactions WHERE transaction_type = 'IN' AND status = 'POSTED' AND COALESCE(is_historical_import, 0) = 0
UNION ALL SELECT '... of which Stock IN V2 (with invoice header)', COUNT(*) FROM purchase_invoice_headers
UNION ALL SELECT 'VOID purchases', COUNT(*) FROM inventory_transactions WHERE transaction_type = 'IN' AND status = 'VOID'
UNION ALL SELECT 'historical-import purchases (excluded by default)', COUNT(*) FROM inventory_transactions WHERE transaction_type = 'IN' AND COALESCE(is_historical_import, 0) = 1;
SELECT MIN(transaction_date) AS first_purchase, MAX(transaction_date) AS last_purchase FROM inventory_transactions WHERE transaction_type = 'IN' AND status = 'POSTED';
