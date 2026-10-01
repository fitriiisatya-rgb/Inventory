-- Rollback for V2.16 Stock Opname Excel Reference Import + Final SO Export.
--
-- Removes ONLY the four new tables, in FK-safe child-first order. No
-- existing table (stock_opname_sessions/lines/findings, items,
-- inventory_batches, stock_adjustments, etc.) is touched in any way.
--
-- Idempotent: DROP TABLE IF EXISTS.

DROP TABLE IF EXISTS stock_opname_reference_movements;
DROP TABLE IF EXISTS stock_opname_reference_item_mappings;
DROP TABLE IF EXISTS stock_opname_reference_rows;
DROP TABLE IF EXISTS stock_opname_reference_batches;
