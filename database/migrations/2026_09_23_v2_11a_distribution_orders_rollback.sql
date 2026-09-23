-- ============================================================================
-- Rollback for database/migrations/2026_09_23_v2_11a_distribution_orders.sql
--
-- Purely additive forward migration -> purely subtractive rollback. Never
-- touches items/inventory_batches/inventory_transactions/warehouses/
-- bakery_destinations — only the two new distribution tables and the
-- numbering-sequence table are dropped.
--
-- Only safe to run while no distribution_orders row exists in a
-- DISPATCHED/RECEIVED/RECEIVED_WITH_DISCREPANCY/COMPLETED status — check
-- first:
--   SELECT COUNT(*) FROM distribution_orders WHERE status NOT IN ('DRAFT','APPROVED','PICKING','CANCELLED');
-- A non-zero count means real FIFO OUT transactions already exist for
-- these DOs; dropping the tables would orphan inventory_transaction_lines.
-- source_transaction_line_id references without removing the underlying
-- posted transactions (which this rollback, correctly, never does).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

DROP TABLE IF EXISTS distribution_order_lines;
DROP TABLE IF EXISTS distribution_orders;
DROP TABLE IF EXISTS document_number_sequences;
