-- READ-ONLY precheck for the Master Data "Tambah ..." package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: the first result lists the six MASTER_*_MANAGE permissions, the second shows who holds them (SUPERADMIN + ADMIN only).
SELECT code AS permission FROM permissions
 WHERE code IN ('MASTER_ITEM_MANAGE','MASTER_WAREHOUSE_MANAGE','MASTER_DIVISION_MANAGE','MASTER_SUPPLIER_MANAGE','MASTER_BAKERY_DESTINATION_MANAGE','MASTER_CATEGORY_MANAGE') ORDER BY code;
SELECT pe.code AS permission, GROUP_CONCAT(r.code ORDER BY r.code) AS held_by
  FROM permissions pe JOIN role_permissions rp ON rp.permission_id = pe.id JOIN roles r ON r.id = rp.role_id
 WHERE pe.code IN ('MASTER_ITEM_MANAGE','MASTER_WAREHOUSE_MANAGE','MASTER_DIVISION_MANAGE','MASTER_SUPPLIER_MANAGE','MASTER_BAKERY_DESTINATION_MANAGE','MASTER_CATEGORY_MANAGE')
 GROUP BY pe.code ORDER BY pe.code;
-- every column the new creates write to — must return 0 rows (MISSING_column)
SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'warehouses' tbl, 'warehouse_type' col UNION SELECT 'warehouses','activation_locked' UNION SELECT 'divisions','code'
  UNION SELECT 'items','category_id' UNION SELECT 'items','default_supplier_id' UNION SELECT 'items','minimum_stock' UNION SELECT 'items','locked_at'
  UNION SELECT 'suppliers','email' UNION SELECT 'suppliers','is_active' UNION SELECT 'bakery_destinations','route_cluster' UNION SELECT 'bakery_destinations','is_active'
  UNION SELECT 'categories','is_active' UNION SELECT 'item_unit_conversions','is_purchase_default' UNION SELECT 'item_price_history','unit_cost_base') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;
-- context: what exists today (the new screens simply list/add to these)
SELECT (SELECT COUNT(*) FROM items) AS items, (SELECT COUNT(*) FROM warehouses) AS warehouses, (SELECT COUNT(*) FROM divisions) AS divisions,
       (SELECT COUNT(*) FROM suppliers) AS suppliers, (SELECT COUNT(*) FROM bakery_destinations) AS bakery_destinations, (SELECT COUNT(*) FROM categories) AS categories, (SELECT COUNT(*) FROM units) AS units;
-- category names that are already duplicated (case-insensitive): the new "Tambah Kategori" refuses a NEW duplicate; existing ones are left alone
SELECT LOWER(name) AS duplicated_category_name, COUNT(*) AS n FROM categories GROUP BY LOWER(name) HAVING COUNT(*) > 1;
