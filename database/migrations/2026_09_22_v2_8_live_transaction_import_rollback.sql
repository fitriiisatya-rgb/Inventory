-- ============================================================================
-- Rollback for database/migrations/2026_09_22_v2_8_live_transaction_import.sql
--
-- Only safe to run while no import_batches row has
-- import_type = 'LIVE_TRANSACTION' (narrowing the ENUM back would fail, or
-- silently orphan the column's meaning, while such rows exist) — check
-- first:
--   SELECT COUNT(*) FROM import_batches WHERE import_type = 'LIVE_TRANSACTION';
-- Any transactions ALREADY POSTED through a live-transaction import remain
-- ordinary inventory_transactions rows (transaction_type IN/OUT,
-- inventory_effect=1) and are NOT reverted by this rollback — this only
-- removes the ability to run the importer again, exactly like every other
-- migration/rollback pair in this project reverts schema, never posted
-- transactions.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

ALTER TABLE import_batches
    DROP INDEX idx_ib_type_hash,
    DROP COLUMN source_file_hash,
    MODIFY COLUMN import_type ENUM('MASTER_ITEM','SUPPLIER','DIVISION','WAREHOUSE',
                                    'OPENING_STOCK','HISTORICAL_TRANSACTION') NOT NULL;
