-- ============================================================================
-- Inventory FIFO Pro — V2.14.10 Append-Only Multi-Unit Stock Opname Findings.
--
-- Replaces the "P1/P2 count is written exactly once" assumption with
-- "P1/P2's result is the SUM of one or more append-only findings" — the
-- SAME physical SKU can be found more than once during a walk of the
-- warehouse (Tambah Temuan) without ever overwriting an earlier count.
--
-- stock_opname_findings — one row per counting EVENT (never edited after
-- creation; a mistake is corrected by voiding, never by rewriting
-- base_qty/rusak_qty/etc — see voided_at/voided_by/void_reason).
-- team_role/counter_user_id is who actually submitted this specific
-- event, independent of how many people are on that team.
--
-- stock_opname_finding_units — the multi-unit breakdown of ONE finding
-- (e.g. "5 Karton + 4 Pcs" is two rows under one finding), mirroring the
-- exact snapshot pattern inventory_transaction_lines already uses
-- (input_qty / input_unit_id / conversion_factor_snapshot / base_qty) so
-- a later change to an item's packaging (item_unit_conversions) never
-- retroactively alters an already-saved finding's math.
--
-- stock_opname_lines.p1_qty_base/p2_qty_base (and the p{1,2}_rusak_qty/
-- expired_qty/deadstock_qty columns) become a MAINTAINED AGGREGATE for
-- any line that has active finding rows (SUM of non-voided findings for
-- that role) — StockOpnameService recomputes them on every
-- finding insert/void, in the SAME transaction. A line with ZERO finding
-- rows (a legacy write-once submission, or a session that predates this
-- migration) is completely unaffected: those columns keep meaning
-- exactly what they always have, and the old write-once submitCount()
-- path remains fully functional and untouched.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS, MariaDB 10.11.14-compatible),
-- purely additive, zero effect on inventory_batches/FIFO/costing — a
-- finding only ever writes to these two new tables plus the existing
-- aggregate columns on stock_opname_lines; finalize()/post() are
-- unchanged and still only ever read counted_qty_base/variance_qty_base.
-- ============================================================================

CREATE TABLE IF NOT EXISTS stock_opname_findings (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id           INT UNSIGNED NOT NULL,
    stock_opname_line_id BIGINT UNSIGNED NOT NULL,
    team_role            ENUM('P1','P2') NOT NULL,
    counter_user_id      INT UNSIGNED NOT NULL,
    base_qty             DECIMAL(20,6) NOT NULL,
    rusak_qty            DECIMAL(20,6) NOT NULL,
    expired_qty          DECIMAL(20,6) NOT NULL,
    deadstock_qty        DECIMAL(20,6) NOT NULL,
    notes                VARCHAR(255) NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_by            INT UNSIGNED NULL,
    voided_at            DATETIME NULL,
    void_reason          VARCHAR(255) NULL,
    CONSTRAINT fk_sof_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sof_line FOREIGN KEY (stock_opname_line_id) REFERENCES stock_opname_lines(id),
    CONSTRAINT fk_sof_user FOREIGN KEY (counter_user_id) REFERENCES users(id),
    CONSTRAINT fk_sof_voided_by FOREIGN KEY (voided_by) REFERENCES users(id),
    INDEX idx_sof_line_role (stock_opname_line_id, team_role, voided_at)
) ENGINE=InnoDB;

-- V2.14.11 NOTE — this table is later RENAMED to
-- stock_opname_finding_quantities by
-- 2026_09_29_v2_14_11_condition_typed_findings.sql. "IF NOT EXISTS" alone
-- is not enough to make a re-run of THIS migration idempotent once that
-- rename has happened (a plain CREATE TABLE IF NOT EXISTS
-- stock_opname_finding_units would then try to create a FRESH table whose
-- constraint/index names collide with the ones still attached to the
-- renamed table) — guarded via information_schema so this is a no-op once
-- either name already exists, whichever migration created it.
SET @sofu_or_sofq_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('stock_opname_finding_units', 'stock_opname_finding_quantities')
);
SET @create_sofu_sql = IF(@sofu_or_sofq_exists = 0,
    'CREATE TABLE stock_opname_finding_units (
        id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        finding_id                 BIGINT UNSIGNED NOT NULL,
        unit_id                    INT UNSIGNED NOT NULL,
        input_qty                  DECIMAL(20,6) NOT NULL,
        conversion_factor_snapshot DECIMAL(20,6) NOT NULL,
        base_qty_contribution      DECIMAL(20,6) NOT NULL,
        CONSTRAINT fk_sofu_finding FOREIGN KEY (finding_id) REFERENCES stock_opname_findings(id),
        CONSTRAINT fk_sofu_unit FOREIGN KEY (unit_id) REFERENCES units(id),
        INDEX idx_sofu_finding (finding_id)
    ) ENGINE=InnoDB',
    'SELECT 1');
PREPARE create_sofu_stmt FROM @create_sofu_sql;
EXECUTE create_sofu_stmt;
DEALLOCATE PREPARE create_sofu_stmt;
