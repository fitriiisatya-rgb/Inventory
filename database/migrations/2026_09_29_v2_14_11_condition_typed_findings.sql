-- V2.14.11 — CONDITION-TYPED MULTI-UNIT FINDINGS + PHOTO EVIDENCE.
-- Baseline: 9902ad9 (V2.14.10.1), itself layered on 731244d (V2.14.9.3,
-- production). Idempotent throughout (MariaDB 10.11 IF NOT EXISTS/IF
-- EXISTS). No inventory mutation, no existing row rewritten beyond a
-- DEFAULT-driven backfill.
--
-- WHY: V2.14.10's stock_opname_findings stored exactly one raw multi-unit
-- breakdown per finding (stock_opname_finding_units), implicitly for GOOD
-- only — rusak_qty/expired_qty/deadstock_qty were flat scalars the client
-- was trusted to have already converted to base units. Go-live review
-- (2026-09-29) requires the SAME reconstructable raw-unit-input guarantee
-- for DAMAGED/EXPIRED/DEADSTOCK that GOOD already had: a finding may now
-- carry an independent multi-unit breakdown PER CONDITION.
--
-- stock_opname_finding_units is renamed to stock_opname_finding_quantities
-- and gains condition_type — every row that already existed is a GOOD row
-- (that was the only condition it could ever represent), so the rename's
-- backfill is exact, not a guess. stock_opname_findings' four raw scalar
-- columns (base_qty/rusak_qty/expired_qty/deadstock_qty) become CACHED
-- ROLLUPS renamed to finding_good_base_qty/finding_damaged_base_qty/
-- finding_expired_base_qty/finding_deadstock_base_qty — still written
-- exactly once, in the same transaction as their own
-- stock_opname_finding_quantities rows, by StockOpnameService::
-- submitFinding() alone; nothing else may ever write them, so there is
-- still exactly one source of truth (the quantity rows), just with a
-- read-optimized cache alongside it — same pattern stock_opname_lines'
-- own p{1,2}_qty_base aggregate has always used relative to the findings
-- table itself.
-- RENAME TO has no IF EXISTS form; guarded via information_schema so a
-- second run (already renamed) is a no-op rather than an error.
SET @old_tbl_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_units'
);
SET @rename_sql = IF(@old_tbl_exists > 0, 'ALTER TABLE stock_opname_finding_units RENAME TO stock_opname_finding_quantities', 'SELECT 1');
PREPARE rename_stmt FROM @rename_sql;
EXECUTE rename_stmt;
DEALLOCATE PREPARE rename_stmt;

-- condition_type gets a temporary DEFAULT 'GOOD' so ADD COLUMN backfills
-- every pre-existing row (the only condition V2.14.10 could ever record
-- here — the backfill is exact, not a guess) without a separate UPDATE;
-- unit_code_snapshot/unit_name_snapshot didn't exist at all in V2.14.10's
-- finding_units, so they're backfilled explicitly below from the units
-- table via unit_id. The DEFAULT is then dropped (see the MODIFY COLUMN
-- near the end of this block) so the end state matches a fresh
-- schema.sql install exactly — going forward every row must state its
-- condition_type explicitly, never silently default to GOOD.
ALTER TABLE stock_opname_finding_quantities
    ADD COLUMN IF NOT EXISTS condition_type ENUM('GOOD','DAMAGED','EXPIRED','DEADSTOCK') NOT NULL DEFAULT 'GOOD' AFTER finding_id,
    ADD COLUMN IF NOT EXISTS unit_code_snapshot VARCHAR(32) NULL AFTER unit_id,
    ADD COLUMN IF NOT EXISTS unit_name_snapshot VARCHAR(128) NULL AFTER unit_code_snapshot;

UPDATE stock_opname_finding_quantities q
JOIN units u ON u.id = q.unit_id
SET q.unit_code_snapshot = u.code, q.unit_name_snapshot = u.name
WHERE q.unit_code_snapshot IS NULL;

ALTER TABLE stock_opname_finding_quantities
    MODIFY COLUMN unit_code_snapshot VARCHAR(32) NOT NULL,
    MODIFY COLUMN unit_name_snapshot VARCHAR(128) NOT NULL,
    MODIFY COLUMN condition_type ENUM('GOOD','DAMAGED','EXPIRED','DEADSTOCK') NOT NULL;

-- Left unindexed beyond the FK's own index — queries always start from
-- finding_id (see idx_sofq_finding below).
ALTER TABLE stock_opname_finding_quantities
    DROP INDEX IF EXISTS idx_sofu_finding,
    ADD INDEX IF NOT EXISTS idx_sofq_finding (finding_id),
    ADD INDEX IF NOT EXISTS idx_sofq_finding_condition (finding_id, condition_type);

-- CHANGE COLUMN has no IF EXISTS form; guarded the same way as the rename
-- above so a second run (already renamed) is a no-op.
SET @old_col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_findings' AND COLUMN_NAME = 'base_qty'
);
SET @change_sql = IF(@old_col_exists > 0,
    'ALTER TABLE stock_opname_findings
        CHANGE COLUMN base_qty      finding_good_base_qty      DECIMAL(20,6) NOT NULL DEFAULT 0,
        CHANGE COLUMN rusak_qty     finding_damaged_base_qty   DECIMAL(20,6) NOT NULL DEFAULT 0,
        CHANGE COLUMN expired_qty   finding_expired_base_qty   DECIMAL(20,6) NOT NULL DEFAULT 0,
        CHANGE COLUMN deadstock_qty finding_deadstock_base_qty DECIMAL(20,6) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE change_stmt FROM @change_sql;
EXECUTE change_stmt;
DEALLOCATE PREPARE change_stmt;

-- round: which counting pass this finding belongs to (1 = original
-- counting; a supervisor-triggered Hitung Ulang, Checkpoint B, opens a new
-- round rather than mutating round 1's findings). Not yet driven by any
-- service logic in this release — added now so Checkpoint B's recount
-- flow needs no further schema churn — every V2.14.11 finding is written
-- with round=1.
ALTER TABLE stock_opname_findings
    ADD COLUMN IF NOT EXISTS round SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER counter_user_id,
    -- Denormalized identity captured AT CREATION TIME: a finding must
    -- remain attributable to who actually counted it even if that user is
    -- later renamed or deactivated — counter_user_id's FK stays the
    -- authoritative link, this is display-only audit redundancy.
    ADD COLUMN IF NOT EXISTS counter_username_snapshot VARCHAR(100) NULL AFTER round;

-- Backfill the snapshot for the (small, never-shipped) set of rows this
-- migration might encounter on a dev/test DB carried over from V2.14.10.
UPDATE stock_opname_findings f
JOIN users u ON u.id = f.counter_user_id
SET f.counter_username_snapshot = u.username
WHERE f.counter_username_snapshot IS NULL;

ALTER TABLE stock_opname_findings
    ADD INDEX IF NOT EXISTS idx_sof_line_role_round (stock_opname_line_id, team_role, round, voided_at);

-- Photo evidence: attached to a specific finding and a specific positive
-- condition on that finding — never a single generic photo for a whole
-- finding, and never for GOOD (only DAMAGED/EXPIRED/DEADSTOCK require
-- evidence). finding_id is nullable because StockOpnamePhotoService
-- uploads a photo BEFORE the finding it will belong to exists (the mobile
-- flow attaches photos while filling the count card, then Simpan Temuan
-- creates the finding and atomically claims every matching still-
-- unattached photo for this exact session/item/role/condition/uploader —
-- see StockOpnameService::submitFinding()); an upload nobody ever
-- attaches (an abandoned form) stays a harmless orphan row.
CREATE TABLE IF NOT EXISTS stock_opname_finding_photos (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    stock_opname_line_id BIGINT UNSIGNED NOT NULL,
    team_role       ENUM('P1','P2') NOT NULL,
    condition_type  ENUM('DAMAGED','EXPIRED','DEADSTOCK') NOT NULL,
    finding_id      BIGINT UNSIGNED NULL,
    uploaded_by     INT UNSIGNED NOT NULL,
    storage_path    VARCHAR(255) NOT NULL COMMENT 'relative to storage/stock_opname_photos/, random filename — never the original name',
    mime_type       VARCHAR(100) NOT NULL,
    byte_size       INT UNSIGNED NOT NULL,
    caption         VARCHAR(255) NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attached_at     DATETIME NULL,
    CONSTRAINT fk_sofp_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sofp_line FOREIGN KEY (stock_opname_line_id) REFERENCES stock_opname_lines(id),
    CONSTRAINT fk_sofp_finding FOREIGN KEY (finding_id) REFERENCES stock_opname_findings(id),
    CONSTRAINT fk_sofp_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_sofp_pending_claim (session_id, stock_opname_line_id, team_role, condition_type, uploaded_by, finding_id),
    INDEX idx_sofp_finding (finding_id, condition_type)
) ENGINE=InnoDB;
