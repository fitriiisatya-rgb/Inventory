-- ============================================================================
-- Rollback for database/migrations/2026_09_26_v2_13_karang_tengah_warehouse.sql
--
-- SCOPE: removes ONLY the KARANG_TENGAH warehouse row, and only if it is
-- still safe to remove — i.e. it has never been referenced by any other
-- table. This DELETE will simply fail with a foreign-key constraint error
-- (not silently corrupt data) if any inventory_batches, inventory_
-- transactions, warehouse_transfers, stock_opname_sessions, or other
-- child row already references this warehouse_id — which is the correct,
-- safe behavior: once real data references Karang Tengah, removing the
-- warehouse master row is no longer a schema-only operation and this
-- rollback must not be used (restore a database backup instead, per the
-- same convention documented in the 2c04d30->274dc78 release's own
-- ROLLBACK_PLAN.txt).
--
-- Safe to run multiple times (a row that's already gone is simply not
-- matched again).
-- ============================================================================

DELETE FROM warehouses WHERE code = 'KARANG_TENGAH';
