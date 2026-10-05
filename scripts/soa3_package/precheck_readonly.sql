-- READ-ONLY precheck for the Laporan Stock Opname audit package. Run against the PRODUCTION database:
--   mysql -u<user> -p <database> < precheck_readonly.sql
-- Expected: INVENTORY_VIEW exists; the MISSING_column list is EMPTY; sessions 11 and 12 are POSTED and listed with their model.
SELECT pe.code AS permission, GROUP_CONCAT(r.code ORDER BY r.code) AS held_by
  FROM permissions pe JOIN role_permissions rp ON rp.permission_id = pe.id JOIN roles r ON r.id = rp.role_id
 WHERE pe.code = 'INVENTORY_VIEW' GROUP BY pe.code;
-- every table/column the report reads — must return 0 rows (MISSING_column)
SELECT CONCAT(w.tbl, '.', w.col) AS MISSING_column FROM (
  SELECT 'stock_opname_sessions' tbl, 'counting_model' col UNION SELECT 'stock_opname_sessions','session_number' UNION SELECT 'stock_opname_sessions','supervisor_id' UNION SELECT 'stock_opname_sessions','finalized_by'
  UNION SELECT 'stock_opname_sessions','finalized_at' UNION SELECT 'stock_opname_sessions','posted_by' UNION SELECT 'stock_opname_sessions','posted_at' UNION SELECT 'stock_opname_sessions','session_uuid'
  UNION SELECT 'stock_opname_lines','match_status' UNION SELECT 'stock_opname_lines','p1_submitted_at' UNION SELECT 'stock_opname_lines','p2_submitted_at' UNION SELECT 'stock_opname_lines','recount_submitted_at'
  UNION SELECT 'stock_opname_lines','final_rusak_qty' UNION SELECT 'stock_opname_lines','final_expired_qty' UNION SELECT 'stock_opname_lines','final_deadstock_qty' UNION SELECT 'stock_opname_lines','final_notes'
  UNION SELECT 'stock_opname_lines','p1_notes' UNION SELECT 'stock_opname_lines','unit_cost_base' UNION SELECT 'stock_opname_lines','adjustment_id'
  UNION SELECT 'stock_opname_team_members','team_role' UNION SELECT 'stock_opname_team_members','active'
  UNION SELECT 'stock_opname_findings','counted_at' UNION SELECT 'stock_opname_findings','voided_at' UNION SELECT 'stock_opname_findings','counter_username_snapshot' UNION SELECT 'stock_opname_findings','round'
  UNION SELECT 'stock_opname_finding_quantities','condition_type' UNION SELECT 'stock_opname_finding_quantities','base_qty_contribution'
  UNION SELECT 'stock_opname_finding_photos','storage_path' UNION SELECT 'stock_opname_finding_photos','finding_id' UNION SELECT 'stock_opname_finding_photos','uploaded_at' UNION SELECT 'stock_opname_finding_photos','caption'
  UNION SELECT 'stock_adjustments','qty_base_delta' UNION SELECT 'stock_adjustments','unit_cost_base' UNION SELECT 'stock_adjustments','reference_no'
  UNION SELECT 'audit_logs','action_code' UNION SELECT 'audit_logs','entity_type' UNION SELECT 'audit_logs','before_data' UNION SELECT 'audit_logs','after_data'
  UNION SELECT 'users','full_name') w
 LEFT JOIN information_schema.columns c ON c.table_schema = DATABASE() AND c.table_name = w.tbl AND c.column_name = w.col
 WHERE c.column_name IS NULL;
-- the sessions the report will list (read-only): the two real posted sessions should appear here
SELECT s.id, s.session_number, s.session_date, w.code AS warehouse, s.status, s.counting_model,
       (SELECT COUNT(*) FROM stock_opname_lines l WHERE l.session_id = s.id) AS lines,
       (SELECT COUNT(*) FROM stock_opname_finding_photos p WHERE p.session_id = s.id AND p.finding_id IS NOT NULL) AS attached_photos
  FROM stock_opname_sessions s JOIN warehouses w ON w.id = s.warehouse_id ORDER BY s.id;
