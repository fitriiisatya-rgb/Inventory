-- ============================================================================
-- Inventory FIFO Pro — V2.14 Karang Tengah controlled cutover mechanism.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval — this phase is
-- development-only. It builds the machinery to eventually load an approved
-- opening balance for Karang Tengah (or any other future warehouse cutover
-- — the tables are GENERIC, not Karang-Tengah-specific), but this migration
-- itself creates ZERO rows in these new tables, loads NO inventory, and
-- does not touch warehouses.is_active/activation_locked in any way.
--
-- Purely additive: two brand-new tables, one new permission. Nothing about
-- any existing table, row, or FIFO/inventory value is touched.
--
-- warehouse_cutovers: one header row per cutover attempt for a warehouse
-- (a warehouse could in principle have more than one over time — e.g. a
-- redone cutover after a failed one — so this is not UNIQUE per warehouse).
-- Status flow (enforced in code, WarehouseCutoverService, not by a DB
-- CHECK, since the exact blocker rules — "any CRITICAL unresolved",
-- "REVIEW unresolved", etc. — depend on the CURRENT state of this
-- cutover's lines, which no single-row CHECK constraint can express):
--   DRAFT -> VALIDATED -> REVIEW_REQUIRED -> RECONCILED -> APPROVED
--         -> LOADED -> ACTIVATED
--
-- warehouse_cutover_lines: one row per source SKU from the accepted
-- reconciliation workbook. item_id is nullable until master-item mapping
-- resolves it (Section 6) — this is intentionally NOT a foreign key
-- required at insert time, so the importer can preserve every source row
-- verbatim before any mapping decision is made. decision/approved_qty/
-- approved_unit_cost are NULL/PENDING until an admin explicitly resolves
-- the line (Section 7) — no value here is ever auto-filled by the
-- importer itself.
-- ============================================================================

CREATE TABLE warehouse_cutovers (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id         INT UNSIGNED NOT NULL,
    status               ENUM('DRAFT','VALIDATED','REVIEW_REQUIRED','RECONCILED','APPROVED','LOADED','ACTIVATED') NOT NULL DEFAULT 'DRAFT',
    source_name          VARCHAR(255) NOT NULL,
    source_period_start  DATE NULL,
    source_period_end    DATE NULL,
    -- The date the approved opening balance represents (NOT a historical
    -- transaction date range) — the single FIFO OPENING posting date used
    -- by loadOpening(). Never used to fabricate per-day historical
    -- transactions across the source period.
    opening_as_of        DATE NOT NULL,
    total_rows           INT UNSIGNED NOT NULL DEFAULT 0,
    pass_rows            INT UNSIGNED NOT NULL DEFAULT 0,
    review_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    critical_rows        INT UNSIGNED NOT NULL DEFAULT 0,
    no_activity_rows     INT UNSIGNED NOT NULL DEFAULT 0,
    created_by           INT UNSIGNED NOT NULL,
    approved_by          INT UNSIGNED NULL,
    approved_at          DATETIME NULL,
    loaded_by            INT UNSIGNED NULL,
    loaded_at            DATETIME NULL,
    activated_by         INT UNSIGNED NULL,
    activated_at         DATETIME NULL,
    notes                VARCHAR(1000) NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wc_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_wc_creator   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT fk_wc_approver  FOREIGN KEY (approved_by)  REFERENCES users(id),
    CONSTRAINT fk_wc_loader    FOREIGN KEY (loaded_by)    REFERENCES users(id),
    CONSTRAINT fk_wc_activator FOREIGN KEY (activated_by) REFERENCES users(id),
    INDEX idx_wc_warehouse (warehouse_id, status)
) ENGINE=InnoDB;

CREATE TABLE warehouse_cutover_lines (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cutover_id                INT UNSIGNED NOT NULL,
    -- Nullable until Section 6 matching resolves it; never invented.
    item_id                   INT UNSIGNED NULL,
    source_sku                VARCHAR(60) NOT NULL,
    source_name               VARCHAR(255) NOT NULL,
    source_unit               VARCHAR(30) NOT NULL,
    opening_qty               DECIMAL(20,6) NOT NULL DEFAULT 0,
    opening_value             DECIMAL(20,4) NOT NULL DEFAULT 0,
    in_qty                    DECIMAL(20,6) NOT NULL DEFAULT 0,
    in_value                  DECIMAL(20,4) NOT NULL DEFAULT 0,
    out_qty                   DECIMAL(20,6) NOT NULL DEFAULT 0,
    out_value                 DECIMAL(20,4) NOT NULL DEFAULT 0,
    -- Preserved EXACTLY as computed in the accepted reconciliation workbook
    -- (opening + IN - OUT) — never re-derived, never clamped to zero here.
    theoretical_closing_qty   DECIMAL(20,6) NOT NULL DEFAULT 0,
    theoretical_closing_value DECIMAL(20,4) NOT NULL DEFAULT 0,
    source_price              DECIMAL(20,4) NULL,
    reconciliation_status     ENUM('PASS','REVIEW','CRITICAL','NO_ACTIVITY') NOT NULL,
    -- Semicolon-separated structured codes derived (never invented) from
    -- the workbook's own Notes text, e.g. NEGATIVE_THEORETICAL_CLOSING,
    -- SOURCE_UNIT_CONFLICT, DUPLICATE_MOVEMENT_BALANCE_IMPACT,
    -- DUPLICATE_DATA_QUALITY_ONLY, ACTUAL_MOVEMENT_WITHOUT_OPENING,
    -- MISSING_PRICE_WITH_ACTUAL_MOVEMENT, SOURCE_NAME_MISMATCH,
    -- MASTER_REFERENCE_REVIEW.
    exception_codes           VARCHAR(500) NULL,
    record_type               VARCHAR(30) NULL,
    activity_class            VARCHAR(60) NULL,
    mapping_status            ENUM('NOT_FOUND','MATCHED','NAME_MISMATCH','UNIT_MISMATCH','MULTIPLE_MATCH') NOT NULL DEFAULT 'NOT_FOUND',
    -- PENDING: no resolution yet. ACCEPT_SOURCE: approved_qty/cost taken
    -- from the source's own theoretical closing figures as-is.
    -- BUSINESS_OVERRIDE: a human explicitly supplied a different
    -- approved_qty/approved_unit_cost (e.g. resolving a negative closing,
    -- a unit conflict, or a duplicate). EXCLUDE: this SKU is deliberately
    -- left out of the opening load (e.g. a NO_ACTIVITY/catalog-only row,
    -- or a CRITICAL row business decides not to carry forward at all).
    decision                  ENUM('PENDING','ACCEPT_SOURCE','BUSINESS_OVERRIDE','EXCLUDE') NOT NULL DEFAULT 'PENDING',
    approved_qty              DECIMAL(20,6) NULL,
    approved_unit_cost        DECIMAL(20,4) NULL,
    approved_by               INT UNSIGNED NULL,
    approved_at               DATETIME NULL,
    notes                     VARCHAR(1000) NULL,
    -- The workbook's own row number (2-based, i.e. first data row = 2),
    -- for tracing a line back to the exact source cell range.
    source_row_reference      INT UNSIGNED NOT NULL,
    -- Populated only after loadOpening() successfully posts this line's
    -- FIFO batch — links the cutover line to its resulting inventory
    -- batch, mirroring stock_opname_lines.adjustment_id's audit pattern.
    created_batch_id          BIGINT UNSIGNED NULL,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wcl_cutover  FOREIGN KEY (cutover_id) REFERENCES warehouse_cutovers(id),
    CONSTRAINT fk_wcl_item     FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_wcl_approver FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_wcl_batch    FOREIGN KEY (created_batch_id) REFERENCES inventory_batches(id),
    INDEX idx_wcl_cutover_status (cutover_id, reconciliation_status),
    INDEX idx_wcl_cutover_sku (cutover_id, source_sku)
) ENGINE=InnoDB;

-- ---- new permission: manage a warehouse cutover (import, resolve lines,
-- approve). loadOpening() is additionally restricted to SUPERADMIN only
-- (checked by role_code in the route, same pattern as other irreversible/
-- high-risk actions in this codebase — see public/index.php) ----
INSERT INTO permissions (code, description) VALUES
    ('WAREHOUSE_CUTOVER_MANAGE', 'Import reconciliation workbooks, resolve cutover lines, and approve a warehouse cutover');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'SUPERADMIN' AND p.code = 'WAREHOUSE_CUTOVER_MANAGE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN' AND p.code = 'WAREHOUSE_CUTOVER_MANAGE';
