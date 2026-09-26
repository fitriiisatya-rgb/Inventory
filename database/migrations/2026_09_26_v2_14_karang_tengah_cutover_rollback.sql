-- ============================================================================
-- Rollback for database/migrations/2026_09_26_v2_14_karang_tengah_cutover.sql
--
-- Purely additive forward migration -> purely subtractive rollback. Never
-- touches warehouses/items/inventory_batches/inventory_transactions —
-- only the two new cutover tables and the new permission are removed.
--
-- Only safe to run while no warehouse_cutovers row has status LOADED or
-- ACTIVATED — check first:
--   SELECT COUNT(*) FROM warehouse_cutovers WHERE status IN ('LOADED','ACTIVATED');
-- A non-zero count means loadOpening() already posted real FIFO batches
-- for that cutover (created_batch_id populated on its lines); dropping
-- warehouse_cutover_lines would orphan the created_batch_id FK's target
-- row references (the inventory_batches rows themselves, and everything
-- built from them, are correctly NEVER touched by this rollback — restore
-- a database backup instead if that point is ever reached, same
-- convention as every other rollback in this project).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

DROP TABLE IF EXISTS warehouse_cutover_lines;
DROP TABLE IF EXISTS warehouse_cutovers;

DELETE rp FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE p.code = 'WAREHOUSE_CUTOVER_MANAGE';
DELETE FROM permissions WHERE code = 'WAREHOUSE_CUTOVER_MANAGE';
