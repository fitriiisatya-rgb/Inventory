-- V2.14.11.1 rollback — idempotent (IF EXISTS throughout), AND safe when
-- stock_opname_finding_photos itself no longer exists (e.g. a downstream
-- rollback — 2026_09_29_v2_14_11_condition_typed_findings_rollback.sql —
-- already dropped the table outright before this one runs against the
-- same disposable clone). Every statement below is guarded on the TABLE's
-- existence first, not just the column/index's, following the same
-- information_schema + dynamic-SQL pattern already used elsewhere in this
-- migration for the unique-key guard.
SET @sofp_table_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_photos'
);

SET @sofp_expiry_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_photos' AND INDEX_NAME = 'idx_sofp_pending_expiry'
);
SET @sofp_drop_expiry_idx_sql = IF(@sofp_table_exists > 0 AND @sofp_expiry_idx_exists > 0,
    'ALTER TABLE stock_opname_finding_photos DROP INDEX idx_sofp_pending_expiry',
    'SELECT 1');
PREPARE sofp_drop_expiry_idx_stmt FROM @sofp_drop_expiry_idx_sql;
EXECUTE sofp_drop_expiry_idx_stmt;
DEALLOCATE PREPARE sofp_drop_expiry_idx_stmt;

SET @sofp_token_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_photos' AND INDEX_NAME = 'uq_sofp_token'
);
SET @sofp_drop_idx_sql = IF(@sofp_table_exists > 0 AND @sofp_token_idx_exists > 0,
    'ALTER TABLE stock_opname_finding_photos DROP INDEX uq_sofp_token',
    'SELECT 1');
PREPARE sofp_drop_idx_stmt FROM @sofp_drop_idx_sql;
EXECUTE sofp_drop_idx_stmt;
DEALLOCATE PREPARE sofp_drop_idx_stmt;

SET @sofp_token_col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_photos' AND COLUMN_NAME = 'upload_token'
);
SET @sofp_drop_col_sql = IF(@sofp_table_exists > 0 AND @sofp_token_col_exists > 0,
    'ALTER TABLE stock_opname_finding_photos DROP COLUMN upload_token',
    'SELECT 1');
PREPARE sofp_drop_col_stmt FROM @sofp_drop_col_sql;
EXECUTE sofp_drop_col_stmt;
DEALLOCATE PREPARE sofp_drop_col_stmt;
