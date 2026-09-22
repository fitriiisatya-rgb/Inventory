-- ============================================================================
-- Rollback for database/migrations/2026_09_22_v2_9_minimum_stock_per_warehouse.sql
--
-- Only safe to run while no import_batches row has
-- import_type = 'MINIMUM_STOCK' — check first:
--   SELECT COUNT(*) FROM import_batches WHERE import_type = 'MINIMUM_STOCK';
-- Any item_warehouse_stock_policy rows ALREADY WRITTEN through a
-- minimum-stock import remain exactly as they are — this only removes the
-- ability to run that importer again, never reverts policy data (same
-- convention as every other migration/rollback pair in this project:
-- schema only, never posted/committed data).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

ALTER TABLE import_batches
    MODIFY COLUMN import_type ENUM('MASTER_ITEM','SUPPLIER','DIVISION','WAREHOUSE',
                                    'OPENING_STOCK','HISTORICAL_TRANSACTION','LIVE_TRANSACTION') NOT NULL;
