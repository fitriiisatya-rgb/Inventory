-- ============================================================================
-- Rollback for database/migrations/2026_09_18_v2_schema.sql
--
-- Reverses exactly what that migration added, in the opposite order
-- (children before parents, to satisfy FK dependency order). Every added
-- column was nullable and every added table independent of pre-existing
-- data, so this rollback never touches a row of pre-existing business
-- data — run it any time after the migration with no data-loss risk to
-- anything that existed before the migration ran.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration. Verify with scripts/v2_schema_postcheck.php-style
-- row-count comparisons before and after if this is ever run against a
-- database that already has V2 backfill data in the new tables/columns —
-- this rollback DROPs those tables/columns, so any data already written
-- into categories / item_warehouse_stock_policy / bakery_destinations /
-- items.category_id / inventory_transactions.bakery_destination_id /
-- suppliers.address / suppliers.email is lost. It was never present in
-- pre-V2 production, so this is a return to the pre-V2 state, not a loss
-- of original data.
-- ============================================================================

-- 6. suppliers — drop the two additive columns
ALTER TABLE suppliers
    DROP COLUMN address,
    DROP COLUMN email;

-- 5. inventory_transactions.bakery_destination_id (+ its FK, index, CHECK)
ALTER TABLE inventory_transactions
    DROP CONSTRAINT chk_tx_bakery_destination_out_only,
    DROP FOREIGN KEY fk_tx_bakery_destination,
    DROP INDEX idx_tx_bakery_destination,
    DROP COLUMN bakery_destination_id;

-- 4. bakery_destinations
DROP TABLE IF EXISTS bakery_destinations;

-- 3. item_warehouse_stock_policy
DROP TABLE IF EXISTS item_warehouse_stock_policy;

-- 2. items.category_id (+ its FK, indexes)
ALTER TABLE items
    DROP FOREIGN KEY fk_items_category,
    DROP INDEX idx_items_category,
    DROP INDEX idx_items_name,
    DROP COLUMN category_id;

-- 1. categories
DROP TABLE IF EXISTS categories;
