-- ============================================================================
-- Rollback for database/migrations/2026_09_19_v2_1_master_data_management.sql
--
-- Reverses exactly what that migration added. No table/column changes were
-- made by the forward migration, so this rollback never touches any
-- pre-existing business data — it only removes the two new permission codes
-- and their role grants.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code IN ('MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE');

DELETE FROM permissions
WHERE code IN ('MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE');
