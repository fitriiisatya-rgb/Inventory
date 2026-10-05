-- READ-ONLY precheck for the Laporan IN / OUT / Transfer package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: INVENTORY_VIEW exists; the MISSING_column list is EMPTY; the counts show how much real data the three tabs will read.
SELECT pe.code AS permission, GROUP_CONCAT(r.code ORDER BY r.code) AS held_by
  FROM permissions pe JOIN role_permissions rp ON rp.permission_id = pe.id JOIN roles r ON r.id = rp.role_id
 WHERE pe.code = 'INVENTORY_VIEW' GROUP BY pe.code;
-- every table/column the report reads — must return 0 rows (MISSING_column)
SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'inventory_transactions' tbl, 'transaction_type' col UNION SELECT 'inventory_transactions','status' UNION SELECT 'inventory_transactions','transaction_date' UNION SELECT 'inventory_transactions','created_at'
  UNION SELECT 'inventory_transactions','posting_date' UNION SELECT 'inventory_transactions','reference_no' UNION SELECT 'inventory_transactions','created_by' UNION SELECT 'inventory_transactions','inventory_effect'
  UNION SELECT 'inventory_transactions','is_historical_import' UNION SELECT 'inventory_transactions','warehouse_id' UNION SELECT 'inventory_transactions','supplier_id' UNION SELECT 'inventory_transactions','division_id'
  UNION SELECT 'inventory_transactions','bakery_destination_id' UNION SELECT 'inventory_transactions','transaction_uuid'
  UNION SELECT 'inventory_transaction_lines','transaction_id' UNION SELECT 'inventory_transaction_lines','line_no' UNION SELECT 'inventory_transaction_lines','item_id' UNION SELECT 'inventory_transaction_lines','input_qty'
  UNION SELECT 'inventory_transaction_lines','input_unit_id' UNION SELECT 'inventory_transaction_lines','base_qty' UNION SELECT 'inventory_transaction_lines','unit_price_input' UNION SELECT 'inventory_transaction_lines','unit_cost_base'
  UNION SELECT 'inventory_transaction_lines','subtotal' UNION SELECT 'inventory_transaction_lines','notes'
  UNION SELECT 'inventory_batches','received_date' UNION SELECT 'inventory_batches','is_negative_layer' UNION SELECT 'inventory_batches','source_transaction_line_id' UNION SELECT 'inventory_batches','supplier_id'
  UNION SELECT 'fifo_allocations','transaction_line_id' UNION SELECT 'fifo_allocations','batch_id' UNION SELECT 'fifo_allocations','qty_allocated' UNION SELECT 'fifo_allocations','unit_cost_base' UNION SELECT 'fifo_allocations','subtotal'
  UNION SELECT 'purchase_invoice_headers','transaction_id' UNION SELECT 'purchase_invoice_headers','ppn_treatment' UNION SELECT 'purchase_invoice_headers','ppn_rate' UNION SELECT 'purchase_invoice_headers','freight_treatment'
  UNION SELECT 'purchase_invoice_headers','freight_amount' UNION SELECT 'purchase_invoice_headers','invoice_total'
  UNION SELECT 'purchase_line_costs','transaction_line_id' UNION SELECT 'purchase_line_costs','gross_unit_price_input' UNION SELECT 'purchase_line_costs','gross_amount' UNION SELECT 'purchase_line_costs','line_discount_amount'
  UNION SELECT 'purchase_line_costs','net_after_line_discount' UNION SELECT 'purchase_line_costs','invoice_discount_allocated' UNION SELECT 'purchase_line_costs','net_purchase_before_tax' UNION SELECT 'purchase_line_costs','ppn_allocated'
  UNION SELECT 'purchase_line_costs','freight_allocated' UNION SELECT 'purchase_line_costs','final_inventory_cost'
  UNION SELECT 'distribution_orders','do_number' UNION SELECT 'distribution_orders','status' UNION SELECT 'distribution_orders','bakery_destination_id' UNION SELECT 'distribution_orders','dispatched_at' UNION SELECT 'distribution_orders','dispatched_by'
  UNION SELECT 'distribution_orders','created_by' UNION SELECT 'distribution_orders','reference_no' UNION SELECT 'distribution_orders','notes'
  UNION SELECT 'distribution_order_lines','do_id' UNION SELECT 'distribution_order_lines','out_transaction_line_id'
  UNION SELECT 'distribution_invoices','do_id' UNION SELECT 'distribution_invoices','invoice_number' UNION SELECT 'distribution_invoices','status' UNION SELECT 'distribution_invoices','subtotal' UNION SELECT 'distribution_invoices','shipping_amount'
  UNION SELECT 'distribution_invoices','discount_amount' UNION SELECT 'distribution_invoices','tax_amount' UNION SELECT 'distribution_invoices','grand_total' UNION SELECT 'distribution_invoices','issued_at'
  UNION SELECT 'distribution_invoice_lines','invoice_id' UNION SELECT 'distribution_invoice_lines','do_line_id' UNION SELECT 'distribution_invoice_lines','reference_purchase_price' UNION SELECT 'distribution_invoice_lines','pricing_method'
  UNION SELECT 'distribution_invoice_lines','margin_value' UNION SELECT 'distribution_invoice_lines','selling_unit_price' UNION SELECT 'distribution_invoice_lines','subtotal'
  UNION SELECT 'warehouse_transfers','status' UNION SELECT 'warehouse_transfers','ship_date' UNION SELECT 'warehouse_transfers','receive_date' UNION SELECT 'warehouse_transfers','created_at' UNION SELECT 'warehouse_transfers','created_by'
  UNION SELECT 'warehouse_transfers','received_by' UNION SELECT 'warehouse_transfers','cancel_reason' UNION SELECT 'warehouse_transfers','cancelled_by' UNION SELECT 'warehouse_transfers','cancelled_at' UNION SELECT 'warehouse_transfers','reverse_reason'
  UNION SELECT 'warehouse_transfers','reversed_by' UNION SELECT 'warehouse_transfers','reversed_at'
  UNION SELECT 'warehouse_transfer_lines','transfer_id' UNION SELECT 'warehouse_transfer_lines','item_id' UNION SELECT 'warehouse_transfer_lines','qty_base' UNION SELECT 'warehouse_transfer_lines','unit_cost_base'
  UNION SELECT 'warehouse_transfer_lines','out_transaction_line_id' UNION SELECT 'warehouse_transfer_lines','in_transaction_line_id'
  UNION SELECT 'items','category_id' UNION SELECT 'items','base_unit_id' UNION SELECT 'items','sku' UNION SELECT 'items','name' UNION SELECT 'categories','name' UNION SELECT 'units','code'
  UNION SELECT 'warehouses','code' UNION SELECT 'warehouses','is_active' UNION SELECT 'suppliers','name' UNION SELECT 'bakery_destinations','name' UNION SELECT 'divisions','name' UNION SELECT 'users','username') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;
-- how much real data the three tabs read (read-only counts)
SELECT 'IN transactions (POSTED, not historical)' AS what, COUNT(*) AS n FROM inventory_transactions WHERE transaction_type = 'IN' AND status = 'POSTED' AND is_historical_import = 0 AND inventory_effect = 1
UNION ALL SELECT '  of which Stock IN V2 (with purchase_invoice_headers)', COUNT(*) FROM purchase_invoice_headers
UNION ALL SELECT 'OUT transactions (POSTED, not historical)', COUNT(*) FROM inventory_transactions WHERE transaction_type = 'OUT' AND status = 'POSTED' AND is_historical_import = 0 AND inventory_effect = 1
UNION ALL SELECT '  of which linked to a Delivery Order line', COUNT(*) FROM distribution_order_lines WHERE out_transaction_line_id IS NOT NULL
UNION ALL SELECT 'Delivery Orders (all statuses)', COUNT(*) FROM distribution_orders
UNION ALL SELECT '  invoices ISSUED', COUNT(*) FROM distribution_invoices WHERE status = 'ISSUED'
UNION ALL SELECT 'Warehouse transfers (all statuses)', COUNT(*) FROM warehouse_transfers;
SELECT status, COUNT(*) AS transfers FROM warehouse_transfers GROUP BY status;
SELECT status, COUNT(*) AS delivery_orders FROM distribution_orders GROUP BY status;
SELECT MIN(transaction_date) AS first_movement, MAX(transaction_date) AS last_movement FROM inventory_transactions WHERE inventory_effect = 1 AND transaction_type IN ('IN','OUT','TRANSFER_OUT','TRANSFER_IN');
