-- ============================================================================
-- Inventory FIFO Pro V2.1 — Master Data UX & Management Enhancement
-- Phase V2.1 (docs/PHASE_V2_1_MASTER_DATA_MANAGEMENT.md)
--
-- DO NOT RUN THIS AGAINST PRODUCTION. This file is staged for owner-approved
-- execution only — run scripts/v2_1_precheck.php first, then this file, then
-- scripts/v2_1_postcheck.php, against a local/staging database.
--
-- Purely additive: two new permission codes, granted only to SUPERADMIN and
-- ADMIN (matching the exact pattern of the original V2 migration's
-- MASTER_CATEGORY_MANAGE/MASTER_SUPPLIER_MANAGE/MASTER_BAKERY_DESTINATION_MANAGE/
-- STOCK_POLICY_MANAGE grants). No table/column changes — items.status,
-- warehouses.is_active, and divisions.is_active already exist and are reused
-- as-is for activate/deactivate; safe-delete is pure query logic against
-- existing tables, requiring no schema change at all.
-- ============================================================================

INSERT INTO permissions (code, description) VALUES
    ('MASTER_WAREHOUSE_MANAGE', 'Create/edit/deactivate/delete warehouse master data'),
    ('MASTER_DIVISION_MANAGE',  'Create/edit/deactivate/delete division master data')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'SUPERADMIN'
  AND p.code IN ('MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'ADMIN'
  AND p.code IN ('MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE');

-- STOCK/DIVISION/VIEWER intentionally get neither permission — their reads of
-- the new /items/report, /warehouses/report, and /divisions list endpoints
-- reuse INVENTORY_VIEW, same convention as the original V2 permissions.
