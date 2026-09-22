-- ============================================================================
-- Inventory FIFO Pro V2.9 — Minimum Stock Per Warehouse (bulk import type)
-- Phase V2.9
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive: widens the EXISTING generic import_batches.import_type
-- to accept 'MINIMUM_STOCK'. No new table — the per-item-per-warehouse
-- minimum/buffer data model (item_warehouse_stock_policy, with its
-- fallback-to-items.minimum_stock resolution) already existed from an
-- earlier phase (StockPolicyService) and is reused completely unmodified.
-- ImportStockPolicyService posts through the real, unmodified
-- StockPolicyService::upsert() — never a second policy-write path.
-- ============================================================================

ALTER TABLE import_batches
    MODIFY COLUMN import_type ENUM('MASTER_ITEM','SUPPLIER','DIVISION','WAREHOUSE',
                                    'OPENING_STOCK','HISTORICAL_TRANSACTION','LIVE_TRANSACTION','MINIMUM_STOCK') NOT NULL;
