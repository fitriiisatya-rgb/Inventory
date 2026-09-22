-- ============================================================================
-- Rollback for database/migrations/2026_09_22_v2_7_purchase_costing.sql
--
-- Drops the two new V2.7 tables and removes the default_ppn_rate setting.
-- Nothing else was touched by the forward migration, so nothing else needs
-- to be reverted — inventory_transactions/inventory_transaction_lines/
-- fifo_allocations/inventory_batches were never modified.
--
-- Safe at any time: both tables are pure audit/breakdown data, never read
-- by FifoService or any stock/FIFO computation. Dropping them cannot
-- change current stock, current stock value, or any FIFO batch — it only
-- removes the ability to see the PPN/discount/freight breakdown behind
-- costed purchases already posted (the transactions themselves, and the
-- unit_cost_base FIFO already used, are untouched and remain exactly as
-- posted).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

DROP TABLE IF EXISTS purchase_line_costs;
DROP TABLE IF EXISTS purchase_invoice_headers;

DELETE FROM system_settings WHERE setting_key = 'default_ppn_rate';
