-- ============================================================================
-- V2.16 — Stock Opname Excel Reference Import + Final SO Excel Export.
--
-- Four new, purely additive tables. NONE of them are ever read by
-- finalize()/post()/recomputeAggregate()/resolveMatchStatus() — this
-- feature is REFERENCE/RECONCILIATION ONLY, never a write path into
-- inventory_batches, stock_adjustments, stock_opname_lines' own counted/
-- system columns, or any existing finding. assertNotFindingsV1() in
-- StockOpnameService (finalize()/post()) is completely untouched by this
-- migration — the Checkpoint B hard-block stays exactly as it is.
--
-- stock_opname_reference_batches — one row per uploaded file. file_hash
-- (sha256) + the UNIQUE (session_id, file_hash) key is the hash-based
-- re-upload guard from spec Section B: re-uploading the identical file
-- into the same session is rejected at the DB level as a backstop, not
-- just in application code.
--
-- stock_opname_reference_rows — one row per Excel/CSV data row, forever
-- (append-only, never UPDATEd except mapped_by/mapped_at/item_id/
-- mapping_status/base_unit_code/conversion_factor/converted_base_qty
-- when a supervisor manually resolves a NEEDS_REVIEW row — the ORIGINAL
-- source_code/source_name/source_unit/source_qty columns are never
-- rewritten, preserving the audit trail spec Section C requires).
--
-- stock_opname_reference_item_mappings — a reusable, admin-approved
-- "legacy source code -> item_id" library (spec Section D priority 2),
-- independent of any one session/batch, checked by every future import
-- after an exact SKU/code match fails. "At most one ACTIVE mapping per
-- source_code" is a service-layer invariant (same pattern as "only one
-- OPEN/FINALIZED session per warehouse" elsewhere in this schema) rather
-- than a DB constraint, so a mapping can be superseded (old row kept,
-- is_active=0) without ever deleting history.
--
-- stock_opname_reference_movements — late/backdated IN/OUT/SCALING/
-- ADJUSTMENT reference entries (spec Section F). late_pre_cutoff is
-- computed ONCE at insert time (effective_at <= session cutoff AND the
-- real created_at > session cutoff) and frozen — a factual statement
-- about when the entry was actually made, never recomputed later.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS), MariaDB 10.11.14-compatible,
-- zero effect on any existing table's shape or data.
-- ============================================================================

CREATE TABLE IF NOT EXISTS stock_opname_reference_batches (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          INT UNSIGNED NOT NULL,
    original_filename   VARCHAR(255) NOT NULL,
    file_hash           CHAR(64) NOT NULL,
    uploaded_by         INT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    row_count           INT UNSIGNED NOT NULL DEFAULT 0,
    matched_count       INT UNSIGNED NOT NULL DEFAULT 0,
    unmatched_count     INT UNSIGNED NOT NULL DEFAULT 0,
    unit_mismatch_count INT UNSIGNED NOT NULL DEFAULT 0,
    negative_count      INT UNSIGNED NOT NULL DEFAULT 0,
    needs_review_count  INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count     INT UNSIGNED NOT NULL DEFAULT 0,
    status              ENUM('IMPORTED') NOT NULL DEFAULT 'IMPORTED',
    CONSTRAINT fk_sorb_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sorb_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
    UNIQUE KEY uq_sorb_session_hash (session_id, file_hash),
    INDEX idx_sorb_session (session_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_opname_reference_rows (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id           INT UNSIGNED NOT NULL,
    import_batch_id      BIGINT UNSIGNED NOT NULL,
    item_id              INT UNSIGNED NULL,
    source_row_reference INT UNSIGNED NOT NULL,
    source_code          VARCHAR(100) NOT NULL,
    source_name          VARCHAR(255) NOT NULL,
    source_unit          VARCHAR(60) NOT NULL,
    source_qty           DECIMAL(20,6) NOT NULL,
    base_unit_code       VARCHAR(32) NULL,
    conversion_factor    DECIMAL(20,6) NULL,
    converted_base_qty   DECIMAL(20,6) NULL,
    mapping_status       ENUM('MATCHED','UNMATCHED_SCM','UNIT_MISMATCH','NEGATIVE_REFERENCE','DUPLICATE','NEEDS_REVIEW') NOT NULL,
    mapped_by            INT UNSIGNED NULL,
    mapped_at            DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sorr_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sorr_batch FOREIGN KEY (import_batch_id) REFERENCES stock_opname_reference_batches(id),
    CONSTRAINT fk_sorr_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_sorr_mapper FOREIGN KEY (mapped_by) REFERENCES users(id),
    INDEX idx_sorr_batch (import_batch_id),
    INDEX idx_sorr_session_item (session_id, item_id),
    INDEX idx_sorr_session_status (session_id, mapping_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_opname_reference_item_mappings (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_code  VARCHAR(100) NOT NULL,
    item_id      INT UNSIGNED NOT NULL,
    approved_by  INT UNSIGNED NOT NULL,
    approved_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes        VARCHAR(255) NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_sorim_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_sorim_approver FOREIGN KEY (approved_by) REFERENCES users(id),
    INDEX idx_sorim_code_active (source_code, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_opname_reference_movements (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id         INT UNSIGNED NOT NULL,
    item_id            INT UNSIGNED NOT NULL,
    movement_type      ENUM('IN','OUT','SCALING','ADJUSTMENT') NOT NULL,
    qty_base           DECIMAL(20,6) NOT NULL,
    effective_at       DATETIME NOT NULL,
    document_reference VARCHAR(100) NULL,
    reason             VARCHAR(255) NOT NULL,
    late_pre_cutoff    TINYINT(1) NOT NULL DEFAULT 0,
    created_by         INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sorm_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sorm_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_sorm_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_sorm_session_item (session_id, item_id)
) ENGINE=InnoDB;
