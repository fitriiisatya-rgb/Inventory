-- ============================================================================
-- V2.16.1 — Stock Opname EOD Reconciliation ("STOK BUKU SO").
--
-- Revises V2.16's "Reference SCM" into three explicit concepts, per the
-- business rule that a Stock Opname session represents STOK AKHIR as of
-- its own session_date, with the technical cutoff
--   effective_at < (session_date + 1 day) 00:00:00   [EXCLUSIVE]
-- never created_at. All changes here are ADDITIVE (new columns with safe
-- defaults, two new columns on stock_opname_findings) — no existing
-- column is dropped or retyped, no existing row's meaning changes.
--
-- A. BASELINE STOK SCM (stock_opname_reference_batches/_rows, from V2.16,
--    extended here with per-movement-stream coverage metadata — the same
--    baseline file's IN/OUT figures and SCALING figures can be current
--    through DIFFERENT dates).
-- B. MOVEMENT BACKDATE (stock_opname_reference_movements, from V2.16,
--    extended here to (a) be bulk-importable from a file exactly like a
--    baseline, (b) never silently drop an unresolved/invalid/duplicate/
--    out-of-window row — every row is stored with an explicit
--    inclusion_status, inspectable, never just rejected outright, and
--    (c) carry its own duplicate-protection key).
-- C. counted_at on stock_opname_findings — the moment an item was
--    ACTUALLY physically counted, independent of created_at (when the
--    counter's device/app happened to save the record, which may be a
--    day or more later for a backdated/late-entered finding). Existing
--    findings get counted_at = NULL (never silently assumed equal to
--    created_at) until a supervisor explicitly backfills it with a
--    reason, which is logged, not inferred.
--
-- Idempotent (CREATE/ALTER ... IF NOT EXISTS via information_schema
-- guards, MariaDB 10.11.14-compatible). Zero effect on inventory_batches,
-- stock_adjustments, or FINDINGS_V1_CHECKPOINT_B_REQUIRED (finalize()/
-- post() are not touched by this migration at all).
-- ============================================================================

-- ---- A. Baseline coverage-per-stream + batch kind ----

SET @sorb_kind_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'batch_kind');
SET @sorb_add_kind_sql = IF(@sorb_kind_exists = 0,
    'ALTER TABLE stock_opname_reference_batches ADD COLUMN batch_kind ENUM(\'BASELINE\',\'MOVEMENT\') NOT NULL DEFAULT \'BASELINE\' AFTER status',
    'SELECT 1');
PREPARE sorb_add_kind_stmt FROM @sorb_add_kind_sql; EXECUTE sorb_add_kind_stmt; DEALLOCATE PREPARE sorb_add_kind_stmt;

SET @sorb_inout_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'baseline_inout_through');
SET @sorb_add_inout_sql = IF(@sorb_inout_exists = 0,
    'ALTER TABLE stock_opname_reference_batches ADD COLUMN baseline_inout_through DATETIME NULL AFTER batch_kind',
    'SELECT 1');
PREPARE sorb_add_inout_stmt FROM @sorb_add_inout_sql; EXECUTE sorb_add_inout_stmt; DEALLOCATE PREPARE sorb_add_inout_stmt;

SET @sorb_scaling_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'baseline_scaling_through');
SET @sorb_add_scaling_sql = IF(@sorb_scaling_exists = 0,
    'ALTER TABLE stock_opname_reference_batches ADD COLUMN baseline_scaling_through DATETIME NULL AFTER baseline_inout_through',
    'SELECT 1');
PREPARE sorb_add_scaling_stmt FROM @sorb_add_scaling_sql; EXECUTE sorb_add_scaling_stmt; DEALLOCATE PREPARE sorb_add_scaling_stmt;

SET @sorb_adj_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_batches' AND COLUMN_NAME = 'baseline_adjustment_through');
SET @sorb_add_adj_sql = IF(@sorb_adj_exists = 0,
    'ALTER TABLE stock_opname_reference_batches ADD COLUMN baseline_adjustment_through DATETIME NULL AFTER baseline_scaling_through',
    'SELECT 1');
PREPARE sorb_add_adj_stmt FROM @sorb_add_adj_sql; EXECUTE sorb_add_adj_stmt; DEALLOCATE PREPARE sorb_add_adj_stmt;

-- ---- B. Movement rows: bulk-import batch link, visible exclusion status,
--         raw/original source capture (so an UNMATCHED_ITEM/INVALID_DATE
--         row is still fully inspectable, never silently dropped), and a
--         duplicate-protection key. item_id/effective_at become NULLable
--         because an excluded row (bad SKU, unparseable date) must still
--         be stored for admin inspection — never rejected outright.

SET @sorm_item_nullable = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'item_id' AND IS_NULLABLE = 'YES');
SET @sorm_item_nullable_sql = IF(@sorm_item_nullable = 0,
    'ALTER TABLE stock_opname_reference_movements MODIFY COLUMN item_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE sorm_item_nullable_stmt FROM @sorm_item_nullable_sql; EXECUTE sorm_item_nullable_stmt; DEALLOCATE PREPARE sorm_item_nullable_stmt;

SET @sorm_eff_nullable = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'effective_at' AND IS_NULLABLE = 'YES');
SET @sorm_eff_nullable_sql = IF(@sorm_eff_nullable = 0,
    'ALTER TABLE stock_opname_reference_movements MODIFY COLUMN effective_at DATETIME NULL',
    'SELECT 1');
PREPARE sorm_eff_nullable_stmt FROM @sorm_eff_nullable_sql; EXECUTE sorm_eff_nullable_stmt; DEALLOCATE PREPARE sorm_eff_nullable_stmt;

SET @sorm_batch_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'import_batch_id');
SET @sorm_add_batch_sql = IF(@sorm_batch_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN import_batch_id BIGINT UNSIGNED NULL AFTER item_id',
    'SELECT 1');
PREPARE sorm_add_batch_stmt FROM @sorm_add_batch_sql; EXECUTE sorm_add_batch_stmt; DEALLOCATE PREPARE sorm_add_batch_stmt;

SET @sorm_status_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'inclusion_status');
SET @sorm_add_status_sql = IF(@sorm_status_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN inclusion_status ENUM(\'INCLUDED\',\'ALREADY_IN_BASELINE\',\'AFTER_SO_CUTOFF\',\'UNMATCHED_ITEM\',\'UNIT_MISMATCH\',\'INVALID_DATE\',\'DUPLICATE\',\'NEEDS_REVIEW\') NOT NULL DEFAULT \'INCLUDED\' AFTER movement_type',
    'SELECT 1');
PREPARE sorm_add_status_stmt FROM @sorm_add_status_sql; EXECUTE sorm_add_status_stmt; DEALLOCATE PREPARE sorm_add_status_stmt;

SET @sorm_src_code_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_code');
SET @sorm_add_src_code_sql = IF(@sorm_src_code_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN source_code VARCHAR(100) NULL AFTER inclusion_status',
    'SELECT 1');
PREPARE sorm_add_src_code_stmt FROM @sorm_add_src_code_sql; EXECUTE sorm_add_src_code_stmt; DEALLOCATE PREPARE sorm_add_src_code_stmt;

SET @sorm_src_name_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_name');
SET @sorm_add_src_name_sql = IF(@sorm_src_name_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN source_name VARCHAR(255) NULL AFTER source_code',
    'SELECT 1');
PREPARE sorm_add_src_name_stmt FROM @sorm_add_src_name_sql; EXECUTE sorm_add_src_name_stmt; DEALLOCATE PREPARE sorm_add_src_name_stmt;

SET @sorm_src_unit_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_unit');
SET @sorm_add_src_unit_sql = IF(@sorm_src_unit_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN source_unit VARCHAR(60) NULL AFTER source_name',
    'SELECT 1');
PREPARE sorm_add_src_unit_stmt FROM @sorm_add_src_unit_sql; EXECUTE sorm_add_src_unit_stmt; DEALLOCATE PREPARE sorm_add_src_unit_stmt;

SET @sorm_src_qty_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_qty_raw');
SET @sorm_add_src_qty_sql = IF(@sorm_src_qty_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN source_qty_raw VARCHAR(60) NULL AFTER source_unit',
    'SELECT 1');
PREPARE sorm_add_src_qty_stmt FROM @sorm_add_src_qty_sql; EXECUTE sorm_add_src_qty_stmt; DEALLOCATE PREPARE sorm_add_src_qty_stmt;

SET @sorm_src_eff_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_effective_at_raw');
SET @sorm_add_src_eff_sql = IF(@sorm_src_eff_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN source_effective_at_raw VARCHAR(60) NULL AFTER source_qty_raw',
    'SELECT 1');
PREPARE sorm_add_src_eff_stmt FROM @sorm_add_src_eff_sql; EXECUTE sorm_add_src_eff_stmt; DEALLOCATE PREPARE sorm_add_src_eff_stmt;

SET @sorm_dedup_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'movement_dedup_key');
SET @sorm_add_dedup_sql = IF(@sorm_dedup_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN movement_dedup_key CHAR(64) NULL AFTER source_effective_at_raw',
    'SELECT 1');
PREPARE sorm_add_dedup_stmt FROM @sorm_add_dedup_sql; EXECUTE sorm_add_dedup_stmt; DEALLOCATE PREPARE sorm_add_dedup_stmt;

SET @sorm_source_row_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND COLUMN_NAME = 'source_row_reference');
SET @sorm_add_source_row_sql = IF(@sorm_source_row_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD COLUMN source_row_reference INT UNSIGNED NULL AFTER movement_dedup_key',
    'SELECT 1');
PREPARE sorm_add_source_row_stmt FROM @sorm_add_source_row_sql; EXECUTE sorm_add_source_row_stmt; DEALLOCATE PREPARE sorm_add_source_row_stmt;

SET @sorm_dedup_idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND INDEX_NAME = 'uq_sorm_session_dedup');
SET @sorm_add_dedup_idx_sql = IF(@sorm_dedup_idx_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD UNIQUE KEY uq_sorm_session_dedup (session_id, movement_dedup_key)',
    'SELECT 1');
PREPARE sorm_add_dedup_idx_stmt FROM @sorm_add_dedup_idx_sql; EXECUTE sorm_add_dedup_idx_stmt; DEALLOCATE PREPARE sorm_add_dedup_idx_stmt;

SET @sorm_batch_fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_reference_movements' AND CONSTRAINT_NAME = 'fk_sorm_batch');
SET @sorm_add_batch_fk_sql = IF(@sorm_batch_fk_exists = 0,
    'ALTER TABLE stock_opname_reference_movements ADD CONSTRAINT fk_sorm_batch FOREIGN KEY (import_batch_id) REFERENCES stock_opname_reference_batches(id)',
    'SELECT 1');
PREPARE sorm_add_batch_fk_stmt FROM @sorm_add_batch_fk_sql; EXECUTE sorm_add_batch_fk_stmt; DEALLOCATE PREPARE sorm_add_batch_fk_stmt;

-- ---- C. counted_at on findings — the ACTUAL physical-count moment,
--         independent of created_at (when the record was saved). NULL
--         on every pre-existing finding until a supervisor explicitly
--         backfills it (never auto-assumed equal to created_at).

SET @sof_counted_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at');
SET @sof_add_counted_sql = IF(@sof_counted_exists = 0,
    'ALTER TABLE stock_opname_findings ADD COLUMN counted_at DATETIME NULL AFTER created_at',
    'SELECT 1');
PREPARE sof_add_counted_stmt FROM @sof_add_counted_sql; EXECUTE sof_add_counted_stmt; DEALLOCATE PREPARE sof_add_counted_stmt;

SET @sof_backfill_by_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at_backfilled_by');
SET @sof_add_backfill_by_sql = IF(@sof_backfill_by_exists = 0,
    'ALTER TABLE stock_opname_findings ADD COLUMN counted_at_backfilled_by INT UNSIGNED NULL AFTER counted_at',
    'SELECT 1');
PREPARE sof_add_backfill_by_stmt FROM @sof_add_backfill_by_sql; EXECUTE sof_add_backfill_by_stmt; DEALLOCATE PREPARE sof_add_backfill_by_stmt;

SET @sof_backfill_at_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at_backfilled_at');
SET @sof_add_backfill_at_sql = IF(@sof_backfill_at_exists = 0,
    'ALTER TABLE stock_opname_findings ADD COLUMN counted_at_backfilled_at DATETIME NULL AFTER counted_at_backfilled_by',
    'SELECT 1');
PREPARE sof_add_backfill_at_stmt FROM @sof_add_backfill_at_sql; EXECUTE sof_add_backfill_at_stmt; DEALLOCATE PREPARE sof_add_backfill_at_stmt;

SET @sof_backfill_reason_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'counted_at_backfill_reason');
SET @sof_add_backfill_reason_sql = IF(@sof_backfill_reason_exists = 0,
    'ALTER TABLE stock_opname_findings ADD COLUMN counted_at_backfill_reason VARCHAR(255) NULL AFTER counted_at_backfilled_at',
    'SELECT 1');
PREPARE sof_add_backfill_reason_stmt FROM @sof_add_backfill_reason_sql; EXECUTE sof_add_backfill_reason_stmt; DEALLOCATE PREPARE sof_add_backfill_reason_stmt;
