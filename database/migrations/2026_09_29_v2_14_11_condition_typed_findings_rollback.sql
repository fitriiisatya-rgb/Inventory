-- V2.14.11 rollback — idempotent (IF EXISTS throughout).

DROP TABLE IF EXISTS stock_opname_finding_photos;

-- Guarded by table existence, not just IF EXISTS on the index/columns —
-- if a downstream migration's rollback (e.g. the opname_findings
-- rollback, which DROPs this table outright) already ran, this whole
-- table is gone and a bare ALTER TABLE would error rather than no-op.
SET @sof_tbl_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings'
);
SET @sof_alter_sql = IF(@sof_tbl_exists > 0,
    'ALTER TABLE stock_opname_findings
        DROP INDEX IF EXISTS idx_sof_line_role_round,
        DROP COLUMN IF EXISTS counter_username_snapshot,
        DROP COLUMN IF EXISTS round',
    'SELECT 1');
PREPARE sof_alter_stmt FROM @sof_alter_sql;
EXECUTE sof_alter_stmt;
DEALLOCATE PREPARE sof_alter_stmt;

-- CHANGE COLUMN has no IF EXISTS form; guarded via information_schema so a
-- second run (already rolled back) is a no-op rather than an error.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'finding_good_base_qty'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE stock_opname_findings
        CHANGE COLUMN finding_good_base_qty      base_qty      DECIMAL(20,6) NOT NULL,
        CHANGE COLUMN finding_damaged_base_qty    rusak_qty     DECIMAL(20,6) NOT NULL,
        CHANGE COLUMN finding_expired_base_qty    expired_qty   DECIMAL(20,6) NOT NULL,
        CHANGE COLUMN finding_deadstock_base_qty  deadstock_qty DECIMAL(20,6) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tbl_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_quantities'
);
SET @sql1 = IF(@tbl_exists > 0,
    'ALTER TABLE stock_opname_finding_quantities
        DROP INDEX IF EXISTS idx_sofq_finding_condition,
        DROP INDEX IF EXISTS idx_sofq_finding,
        ADD INDEX IF NOT EXISTS idx_sofu_finding (finding_id),
        DROP COLUMN IF EXISTS condition_type,
        DROP COLUMN IF EXISTS unit_name_snapshot,
        DROP COLUMN IF EXISTS unit_code_snapshot',
    'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @sql2 = IF(@tbl_exists > 0, 'ALTER TABLE stock_opname_finding_quantities RENAME TO stock_opname_finding_units', 'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
