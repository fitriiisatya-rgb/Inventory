-- READ-ONLY schema precheck for Stock IN / OUT V2. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: first result = 12 rows (all tables exist); every MISSING_column result = 0 rows.
SELECT table_name AS present_table FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name IN (
   'distribution_orders','distribution_order_lines','distribution_invoices','distribution_invoice_lines','distribution_pricing_policies',
   'document_number_sequences','purchase_invoice_headers','purchase_line_costs','item_price_history','bakery_destinations','suppliers','inventory_transaction_lines')
 ORDER BY table_name;

SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'distribution_orders' tbl, 'dispatch_request_uuid' col UNION SELECT 'distribution_orders','delivery_address_snapshot' UNION SELECT 'distribution_orders','approved_by'
  UNION SELECT 'distribution_orders','dispatched_by' UNION SELECT 'distribution_orders','status' UNION SELECT 'distribution_orders','from_warehouse_id'
  UNION SELECT 'distribution_order_lines','qty_sent_base' UNION SELECT 'distribution_order_lines','out_transaction_line_id'
  UNION SELECT 'distribution_invoices','shipping_amount' UNION SELECT 'distribution_invoices','grand_total' UNION SELECT 'distribution_invoices','issued_by'
  UNION SELECT 'distribution_invoice_lines','reference_purchase_price' UNION SELECT 'distribution_invoice_lines','pricing_method' UNION SELECT 'distribution_invoice_lines','margin_value'
  UNION SELECT 'distribution_invoice_lines','selling_unit_price' UNION SELECT 'distribution_invoice_lines','policy_calculated_price'
  UNION SELECT 'purchase_invoice_headers','ppn_treatment' UNION SELECT 'purchase_invoice_headers','freight_treatment' UNION SELECT 'purchase_line_costs','final_inventory_cost'
  UNION SELECT 'inventory_transaction_lines','notes' UNION SELECT 'bakery_destinations','address' UNION SELECT 'bakery_destinations','pic_name' UNION SELECT 'bakery_destinations','phone') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;

-- the permission the new endpoints use must exist
SELECT p.code AS permission FROM permissions p WHERE p.code IN ('TRANSACTION_IN_CREATE','TRANSACTION_OUT_CREATE','INVENTORY_VIEW','DISTRIBUTION_VIEW') ORDER BY p.code;

-- context (read-only look): active bakery destinations (Bakery Tujuan is REQUIRED on the new Stock OUT), active pricing policies (markup defaults),
-- number of existing Delivery Orders / Invoices / document sequences
SELECT id, name, is_active, (address IS NOT NULL AND address <> '') AS has_address FROM bakery_destinations ORDER BY id;
SELECT scope, pricing_method, margin_value, category_id FROM distribution_pricing_policies WHERE is_active = 1;
SELECT (SELECT COUNT(*) FROM distribution_orders) AS delivery_orders, (SELECT COUNT(*) FROM distribution_invoices) AS invoices, (SELECT COUNT(*) FROM document_number_sequences) AS sequence_rows;
-- items WITHOUT any purchase price cannot be sold on the new Stock OUT (no Harga Modal): how many active ones are there?
SELECT COUNT(*) AS active_items_without_price_history FROM items i WHERE i.status = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM item_price_history h WHERE h.item_id = i.id);
