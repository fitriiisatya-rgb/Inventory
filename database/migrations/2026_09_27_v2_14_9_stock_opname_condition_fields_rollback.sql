-- ============================================================================
-- Rollback for database/migrations/2026_09_27_v2_14_9_stock_opname_condition_fields.sql
--
-- Purely additive forward migration -> purely subtractive rollback. Never
-- touches p{1,2}_qty_base/counted_qty_base/variance_qty_base/match_status
-- or any other pre-existing column — only the twelve new condition/notes
-- columns are dropped.
--
-- Safe at any time: these columns are never read by finalize()/post()/
-- StockAdjustmentService, so no inventory value or FIFO batch ever
-- depends on them. Dropping them only discards Rusak/Expired/Deadstock/
-- Keterangan classifications an operator may have entered — never a
-- physical count (p1_qty_base/p2_qty_base survive untouched).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as
-- every other rollback in this project.
--
-- V2.14.9.1 CORRECTION: every clause uses DROP COLUMN IF EXISTS (MariaDB
-- 10.0.2+) so a second rollback run against an already-rolled-back
-- database succeeds as a no-op instead of failing on "check that column
-- exists". Verified idempotent (forward -> forward -> rollback ->
-- rollback), with existing stock_opname_lines data and inventory
-- unchanged by any of the four runs.
-- ============================================================================

ALTER TABLE stock_opname_lines
    DROP COLUMN IF EXISTS p1_rusak_qty,
    DROP COLUMN IF EXISTS p1_expired_qty,
    DROP COLUMN IF EXISTS p1_deadstock_qty,
    DROP COLUMN IF EXISTS p1_notes,
    DROP COLUMN IF EXISTS p2_rusak_qty,
    DROP COLUMN IF EXISTS p2_expired_qty,
    DROP COLUMN IF EXISTS p2_deadstock_qty,
    DROP COLUMN IF EXISTS p2_notes,
    DROP COLUMN IF EXISTS final_rusak_qty,
    DROP COLUMN IF EXISTS final_expired_qty,
    DROP COLUMN IF EXISTS final_deadstock_qty,
    DROP COLUMN IF EXISTS final_notes;
