-- Rollback for V2.16.1 Stock Opname EOD Reconciliation.
--
-- Reverses every column/constraint/index added by the forward migration,
-- in reverse dependency order, each guarded on existence so this is safe
-- to run even if the forward migration only partially applied, or has
-- already been rolled back once. Does NOT touch any V2.16 table/column
-- that pre-dates this phase (those remain exactly as V2.16 left them —
-- see 2026_10_01_v2_16_stock_opname_reference_import_rollback.sql for
-- removing the V2.16 tables entirely, a separate, independent step).

-- ---- stock_opname_findings ----
SET @sof_backfill_reason_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at_backfill_reason');
SET @sof_drop_backfill_reason_sql = IF(@sof_backfill_reason_exists > 0, 'ALTER TABLE stock_opname_findings DROP COLUMN counted_at_backfill_reason', 'SELECT 1');
PREPARE sof_drop_backfill_reason_stmt FROM @sof_drop_backfill_reason_sql; EXECUTE sof_drop_backfill_reason_stmt; DEALLOCATE PREPARE sof_drop_backfill_reason_stmt;

SET @sof_backfill_at_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at_backfilled_at');
SET @sof_drop_backfill_at_sql = IF(@sof_backfill_at_exists > 0, 'ALTER TABLE stock_opname_findings DROP COLUMN counted_at_backfilled_at', 'SELECT 1');
PREPARE sof_drop_backfill_at_stmt FROM @sof_drop_backfill_at_sql; EXECUTE sof_drop_backfill_at_stmt; DEALLOCATE PREPARE sof_drop_backfill_at_stmt;

SET @sof_backfill_by_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at_backfilled_by');
SET @sof_drop_backfill_by_sql = IF(@sof_backfill_by_exists > 0, 'ALTER TABLE stock_opname_findings DROP COLUMN counted_at_backfilled_by', 'SELECT 1');
PREPARE sof_drop_backfill_by_stmt FROM @sof_drop_backfill_by_sql; EXECUTE sof_drop_backfill_by_stmt; DEALLOCATE PREPARE sof_drop_backfill_by_stmt;

SET @sof_counted_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at');
SET @sof_drop_counted_sql = IF(@sof_counted_exists > 0, 'ALTER TABLE stock_opname_findings DROP COLUMN counted_at', 'SELECT 1');
PREPARE sof_drop_counted_stmt FROM @sof_drop_counted_sql; EXECUTE sof_drop_counted_stmt; DEALLOCATE PREPARE sof_drop_counted_stmt;

-- ---- stock_opname_reference_movements ----
SET @sorm_batch_fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND CONSTRAINT_NAME = 'fk_sorm_batch');
SET @sorm_drop_batch_fk_sql = IF(@sorm_batch_fk_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP FOREIGN KEY fk_sorm_batch', 'SELECT 1');
PREPARE sorm_drop_batch_fk_stmt FROM @sorm_drop_batch_fk_sql; EXECUTE sorm_drop_batch_fk_stmt; DEALLOCATE PREPARE sorm_drop_batch_fk_stmt;

SET @sorm_dedup_idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND INDEX_NAME = 'uq_sorm_session_dedup');
SET @sorm_drop_dedup_idx_sql = IF(@sorm_dedup_idx_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP INDEX uq_sorm_session_dedup', 'SELECT 1');
PREPARE sorm_drop_dedup_idx_stmt FROM @sorm_drop_dedup_idx_sql; EXECUTE sorm_drop_dedup_idx_stmt; DEALLOCATE PREPARE sorm_drop_dedup_idx_stmt;

SET @sorm_source_row_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_row_reference');
SET @sorm_drop_source_row_sql = IF(@sorm_source_row_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN source_row_reference', 'SELECT 1');
PREPARE sorm_drop_source_row_stmt FROM @sorm_drop_source_row_sql; EXECUTE sorm_drop_source_row_stmt; DEALLOCATE PREPARE sorm_drop_source_row_stmt;

SET @sorm_dedup_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'movement_dedup_key');
SET @sorm_drop_dedup_sql = IF(@sorm_dedup_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN movement_dedup_key', 'SELECT 1');
PREPARE sorm_drop_dedup_stmt FROM @sorm_drop_dedup_sql; EXECUTE sorm_drop_dedup_stmt; DEALLOCATE PREPARE sorm_drop_dedup_stmt;

SET @sorm_src_eff_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_effective_at_raw');
SET @sorm_drop_src_eff_sql = IF(@sorm_src_eff_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN source_effective_at_raw', 'SELECT 1');
PREPARE sorm_drop_src_eff_stmt FROM @sorm_drop_src_eff_sql; EXECUTE sorm_drop_src_eff_stmt; DEALLOCATE PREPARE sorm_drop_src_eff_stmt;

SET @sorm_src_qty_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_qty_raw');
SET @sorm_drop_src_qty_sql = IF(@sorm_src_qty_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN source_qty_raw', 'SELECT 1');
PREPARE sorm_drop_src_qty_stmt FROM @sorm_drop_src_qty_sql; EXECUTE sorm_drop_src_qty_stmt; DEALLOCATE PREPARE sorm_drop_src_qty_stmt;

SET @sorm_src_unit_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_unit');
SET @sorm_drop_src_unit_sql = IF(@sorm_src_unit_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN source_unit', 'SELECT 1');
PREPARE sorm_drop_src_unit_stmt FROM @sorm_drop_src_unit_sql; EXECUTE sorm_drop_src_unit_stmt; DEALLOCATE PREPARE sorm_drop_src_unit_stmt;

SET @sorm_src_name_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_name');
SET @sorm_drop_src_name_sql = IF(@sorm_src_name_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN source_name', 'SELECT 1');
PREPARE sorm_drop_src_name_stmt FROM @sorm_drop_src_name_sql; EXECUTE sorm_drop_src_name_stmt; DEALLOCATE PREPARE sorm_drop_src_name_stmt;

SET @sorm_src_code_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_code');
SET @sorm_drop_src_code_sql = IF(@sorm_src_code_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN source_code', 'SELECT 1');
PREPARE sorm_drop_src_code_stmt FROM @sorm_drop_src_code_sql; EXECUTE sorm_drop_src_code_stmt; DEALLOCATE PREPARE sorm_drop_src_code_stmt;

SET @sorm_status_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'inclusion_status');
SET @sorm_drop_status_sql = IF(@sorm_status_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN inclusion_status', 'SELECT 1');
PREPARE sorm_drop_status_stmt FROM @sorm_drop_status_sql; EXECUTE sorm_drop_status_stmt; DEALLOCATE PREPARE sorm_drop_status_stmt;

SET @sorm_batch_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'import_batch_id');
SET @sorm_drop_batch_sql = IF(@sorm_batch_exists > 0, 'ALTER TABLE stock_opname_reference_movements DROP COLUMN import_batch_id', 'SELECT 1');
PREPARE sorm_drop_batch_stmt FROM @sorm_drop_batch_sql; EXECUTE sorm_drop_batch_stmt; DEALLOCATE PREPARE sorm_drop_batch_stmt;

-- item_id/effective_at are left NULLable on rollback (reverting to NOT
-- NULL would fail if any excluded row with a NULL value still exists;
-- harmless either way since every existing V2.16 caller already always
-- supplies both).

-- ---- stock_opname_reference_batches ----
SET @sorb_adj_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'baseline_adjustment_through');
SET @sorb_drop_adj_sql = IF(@sorb_adj_exists > 0, 'ALTER TABLE stock_opname_reference_batches DROP COLUMN baseline_adjustment_through', 'SELECT 1');
PREPARE sorb_drop_adj_stmt FROM @sorb_drop_adj_sql; EXECUTE sorb_drop_adj_stmt; DEALLOCATE PREPARE sorb_drop_adj_stmt;

SET @sorb_scaling_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'baseline_scaling_through');
SET @sorb_drop_scaling_sql = IF(@sorb_scaling_exists > 0, 'ALTER TABLE stock_opname_reference_batches DROP COLUMN baseline_scaling_through', 'SELECT 1');
PREPARE sorb_drop_scaling_stmt FROM @sorb_drop_scaling_sql; EXECUTE sorb_drop_scaling_stmt; DEALLOCATE PREPARE sorb_drop_scaling_stmt;

SET @sorb_inout_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'baseline_inout_through');
SET @sorb_drop_inout_sql = IF(@sorb_inout_exists > 0, 'ALTER TABLE stock_opname_reference_batches DROP COLUMN baseline_inout_through', 'SELECT 1');
PREPARE sorb_drop_inout_stmt FROM @sorb_drop_inout_sql; EXECUTE sorb_drop_inout_stmt; DEALLOCATE PREPARE sorb_drop_inout_stmt;

SET @sorb_kind_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'batch_kind');
SET @sorb_drop_kind_sql = IF(@sorb_kind_exists > 0, 'ALTER TABLE stock_opname_reference_batches DROP COLUMN batch_kind', 'SELECT 1');
PREPARE sorb_drop_kind_stmt FROM @sorb_drop_kind_sql; EXECUTE sorb_drop_kind_stmt; DEALLOCATE PREPARE sorb_drop_kind_stmt;
