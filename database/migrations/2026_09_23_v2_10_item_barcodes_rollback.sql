-- ============================================================================
-- Rollback for database/migrations/2026_09_23_v2_10_item_barcodes.sql
--
-- Purely additive forward migration -> purely subtractive rollback. Drops
-- only the new table; items.barcode (legacy column) and item_price_history
-- are never touched by either direction.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

DROP TABLE IF EXISTS item_barcodes;
