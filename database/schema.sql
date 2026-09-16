-- ============================================================================
-- Inventory FIFO Pro (MySQL) — Clean Source-of-Truth Schema
-- Target: MySQL 8.0+ / MariaDB 10.4+ (utf8mb4, InnoDB, FK enforced)
--
-- This schema starts EMPTY. Row seeds below are limited to system
-- configuration (roles, permissions, base units) — never business data
-- (no items, no suppliers, no stock, no transactions).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- 1. IDENTITY & ACCESS
-- ============================================================================

CREATE TABLE roles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,   -- SUPERADMIN, ADMIN, STOCK, DIVISION, VIEWER
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(60)  NOT NULL UNIQUE,   -- e.g. TRANSACTION_IN_CREATE, STOCK_ALLOW_NEGATIVE
    description     VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id         INT UNSIGNED NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE divisions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(60)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,          -- password_hash() (bcrypt/argon2), never plaintext
    full_name       VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NULL,
    role_id         INT UNSIGNED NOT NULL,
    division_id     INT UNSIGNED NULL,              -- scopes DIVISION-role users
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_users_division FOREIGN KEY (division_id) REFERENCES divisions(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 2. ORGANIZATION MASTERS
-- ============================================================================

CREATE TABLE warehouses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================================
-- 3. ITEM MASTER, UNITS & VERSIONED CONVERSIONS
--
-- Base Unit is the immutable unit all stock/costing math is done in.
-- Purchase/Middle units only ever exist as a *conversion row* pointing to
-- base — never as separately-priced entities — so a price entered in a
-- purchase unit is always derived down to unit_cost_base automatically.
-- ============================================================================

CREATE TABLE units (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL UNIQUE,   -- GR, KG, ML, LTR, PCS, KARTON, KARUNG ...
    name            VARCHAR(60)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku             VARCHAR(40)  NOT NULL UNIQUE,
    barcode         VARCHAR(60)  NULL,
    name            VARCHAR(200) NOT NULL,
    category        VARCHAR(100) NULL,
    brand           VARCHAR(100) NULL,
    base_unit_id    INT UNSIGNED NOT NULL,
    minimum_stock   DECIMAL(20,6) NOT NULL DEFAULT 0,
    status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    -- Set the first time any posted transaction references this item.
    -- While NULL, base_unit_id and every conversion row may still be edited
    -- freely; once set, UnitConversionService must refuse structural edits.
    locked_at       DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_items_base_unit FOREIGN KEY (base_unit_id) REFERENCES units(id),
    INDEX idx_items_status (status),
    INDEX idx_items_barcode (barcode)
) ENGINE=InnoDB;

CREATE TABLE item_unit_conversions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    unit_id             INT UNSIGNED NOT NULL,          -- the purchase/middle unit being defined
    conversion_to_base  DECIMAL(20,6) NOT NULL,         -- 1 unit_id = conversion_to_base * base unit
    is_purchase_default TINYINT(1) NOT NULL DEFAULT 0,
    valid_from          DATETIME NOT NULL,
    valid_to            DATETIME NULL,                  -- NULL = currently active version
    note                VARCHAR(255) NULL,              -- e.g. "packaging changed by supplier"
    -- Generated column trick: MySQL unique indexes allow unlimited NULLs, so
    -- this evaluates to NULL for every closed (valid_to IS NOT NULL) row and
    -- to 1 only while a version is open — the unique index below then makes
    -- "more than one open version per item+unit" a constraint violation
    -- instead of an app-layer convention.
    open_marker         TINYINT GENERATED ALWAYS AS (IF(valid_to IS NULL, 1, NULL)) STORED,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_iuc_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_iuc_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    CONSTRAINT fk_iuc_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_iuc_factor CHECK (conversion_to_base > 0),
    UNIQUE KEY uq_iuc_one_open_version (item_id, unit_id, open_marker),
    INDEX idx_iuc_lookup (item_id, unit_id, valid_from, valid_to)
) ENGINE=InnoDB
COMMENT='Packaging-version history. A transaction always copies the version active at transaction_date into its own snapshot — this table is never re-read for historical costing.';

CREATE TABLE item_price_history (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    supplier_id         INT UNSIGNED NULL,
    unit_id             INT UNSIGNED NOT NULL,
    price_per_unit      DECIMAL(20,4) NOT NULL,
    unit_cost_base      DECIMAL(20,4) NOT NULL,         -- price_per_unit / conversion_to_base at that time
    effective_date      DATETIME NOT NULL,
    source_transaction_line_id BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_iph_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_iph_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_iph_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    INDEX idx_iph_item_date (item_id, effective_date)
) ENGINE=InnoDB
COMMENT='Reference price series used by the anomaly-detection check (Section 9).';

-- ============================================================================
-- 4. INVENTORY BATCHES (FIFO layers) & TRANSACTIONS
-- ============================================================================

CREATE TABLE inventory_batches (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    qty_base            DECIMAL(20,6) NOT NULL,         -- remaining qty in this layer (can go to 0, never below without explicit negative override)
    original_qty_base   DECIMAL(20,6) NOT NULL,
    unit_cost_base      DECIMAL(20,4) NOT NULL,
    received_date       DATETIME NOT NULL,               -- FIFO ordering key: effective date, not click time
    expiry_date         DATE NULL,
    supplier_id         INT UNSIGNED NULL,
    source_transaction_line_id BIGINT UNSIGNED NULL,     -- the IN/OPENING/PRODUCTION_OUT line that created it
    is_negative_layer   TINYINT(1) NOT NULL DEFAULT 0,   -- true only when created under explicit Allow-Negative-Stock override
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_batch_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_batch_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_batch_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    INDEX idx_batch_fifo_order (item_id, warehouse_id, received_date, id),
    INDEX idx_batch_expiry (item_id, warehouse_id, expiry_date)
) ENGINE=InnoDB
COMMENT='FIFO layers. Consumption order is always (received_date ASC, id ASC) — never expiry order; FEFO is a display/alert concern only.';

CREATE TABLE inventory_transactions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_uuid    CHAR(36) NOT NULL UNIQUE,        -- client-generated idempotency key
    transaction_type    ENUM('IN','OUT','TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT',
                              'OPNAME','PRODUCTION_IN','PRODUCTION_OUT','OPENING') NOT NULL,
    transaction_date    DATETIME NOT NULL,                -- business-effective date (drives FIFO ordering)
    posting_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    warehouse_id        INT UNSIGNED NOT NULL,
    supplier_id         INT UNSIGNED NULL,
    division_id         INT UNSIGNED NULL,
    reference_no        VARCHAR(100) NULL,
    status              ENUM('POSTED','VOID','REVERSED') NOT NULL DEFAULT 'POSTED',
    void_reason         VARCHAR(255) NULL,
    voided_by           INT UNSIGNED NULL,
    voided_at           DATETIME NULL,
    reversal_of_id      BIGINT UNSIGNED NULL,             -- points at the transaction this one reverses
    is_historical_import TINYINT(1) NOT NULL DEFAULT 0,
    inventory_effect    TINYINT(1) NOT NULL DEFAULT 1,    -- FALSE for historical-import rows: no stock/batch mutation
    book_closing_id     INT UNSIGNED NULL,                -- set once the period is locked
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tx_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_tx_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_tx_division FOREIGN KEY (division_id) REFERENCES divisions(id),
    CONSTRAINT fk_tx_reversal FOREIGN KEY (reversal_of_id) REFERENCES inventory_transactions(id),
    CONSTRAINT fk_tx_user FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_tx_date (transaction_date),
    INDEX idx_tx_type_status (transaction_type, status),
    INDEX idx_tx_warehouse (warehouse_id)
) ENGINE=InnoDB
COMMENT='Header only. Never DELETEd — mistakes are VOIDed or reversed (Section 19).';

CREATE TABLE inventory_transaction_lines (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id          BIGINT UNSIGNED NOT NULL,
    line_no                 SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    item_id                 INT UNSIGNED NOT NULL,
    item_name_snapshot      VARCHAR(200) NOT NULL,
    input_qty               DECIMAL(20,6) NOT NULL,
    input_unit_id           INT UNSIGNED NOT NULL,
    conversion_factor_snapshot DECIMAL(20,6) NOT NULL,
    base_qty                DECIMAL(20,6) NOT NULL,
    unit_price_input         DECIMAL(20,4) NOT NULL DEFAULT 0,
    unit_cost_base           DECIMAL(20,4) NOT NULL DEFAULT 0,  -- IN: derived from price; OUT: weighted-avg from fifo_allocations
    subtotal                 DECIMAL(20,4) NOT NULL DEFAULT 0,
    warehouse_id             INT UNSIGNED NOT NULL,
    created_batch_id         BIGINT UNSIGNED NULL,        -- IN/OPENING/PRODUCTION_OUT lines: the batch they created
    is_price_anomaly         TINYINT(1) NOT NULL DEFAULT 0,
    price_anomaly_ratio      DECIMAL(10,4) NULL,
    anomaly_approved_by      INT UNSIGNED NULL,
    anomaly_reason           VARCHAR(255) NULL,
    allow_negative_stock     TINYINT(1) NOT NULL DEFAULT 0,
    negative_stock_reason    VARCHAR(255) NULL,
    negative_stock_approved_by INT UNSIGNED NULL,
    notes                    VARCHAR(255) NULL,
    CONSTRAINT fk_txl_transaction FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id),
    CONSTRAINT fk_txl_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_txl_unit FOREIGN KEY (input_unit_id) REFERENCES units(id),
    CONSTRAINT fk_txl_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_txl_batch FOREIGN KEY (created_batch_id) REFERENCES inventory_batches(id),
    CONSTRAINT fk_txl_anomaly_user FOREIGN KEY (anomaly_approved_by) REFERENCES users(id),
    CONSTRAINT fk_txl_negstock_user FOREIGN KEY (negative_stock_approved_by) REFERENCES users(id),
    CONSTRAINT chk_txl_qty CHECK (input_qty <> 0 AND conversion_factor_snapshot > 0),
    INDEX idx_txl_transaction (transaction_id),
    INDEX idx_txl_item_wh (item_id, warehouse_id)
) ENGINE=InnoDB
COMMENT='Full snapshot per line — never recomputed from current master data (Section 6).';

CREATE TABLE fifo_allocations (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_line_id     BIGINT UNSIGNED NOT NULL,     -- the OUT/TRANSFER_OUT/PRODUCTION_IN line consuming stock
    batch_id                BIGINT UNSIGNED NOT NULL,
    qty_allocated           DECIMAL(20,6) NOT NULL,
    unit_cost_base          DECIMAL(20,4) NOT NULL,       -- cost copied from the batch at allocation time
    subtotal                DECIMAL(20,4) NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alloc_line FOREIGN KEY (transaction_line_id) REFERENCES inventory_transaction_lines(id),
    CONSTRAINT fk_alloc_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id),
    CONSTRAINT chk_alloc_qty CHECK (qty_allocated > 0),
    INDEX idx_alloc_line (transaction_line_id),
    INDEX idx_alloc_batch (batch_id)
) ENGINE=InnoDB
COMMENT='The auditable FIFO cost trail: which batches (and at what cost) were consumed by which outbound line.';

-- ============================================================================
-- 5. OPENING STOCK (authoritative baseline — see Section 14)
-- ============================================================================

CREATE TABLE stock_openings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cutoff_date     DATE NOT NULL,
    description     VARCHAR(255) NULL,
    status          ENUM('DRAFT','VALIDATED','COMMITTED') NOT NULL DEFAULT 'DRAFT',
    created_by      INT UNSIGNED NOT NULL,
    committed_by    INT UNSIGNED NULL,
    committed_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_so_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_so_committer FOREIGN KEY (committed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_opening_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_opening_id    INT UNSIGNED NOT NULL,
    item_id             INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    qty_base            DECIMAL(20,6) NOT NULL,
    unit_cost_base      DECIMAL(20,4) NOT NULL,
    expiry_date         DATE NULL,
    batch_reference      VARCHAR(100) NULL,
    row_status          ENUM('VALID','WARNING','ERROR') NOT NULL DEFAULT 'VALID',
    row_messages         JSON NULL,
    created_batch_id     BIGINT UNSIGNED NULL,            -- filled in once committed
    CONSTRAINT fk_sol_opening FOREIGN KEY (stock_opening_id) REFERENCES stock_openings(id),
    CONSTRAINT fk_sol_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_sol_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_sol_batch FOREIGN KEY (created_batch_id) REFERENCES inventory_batches(id),
    UNIQUE KEY uq_sol_item_wh (stock_opening_id, item_id, warehouse_id, batch_reference)
) ENGINE=InnoDB;

-- ============================================================================
-- 6. STOCK OPNAME, ADJUSTMENTS
-- ============================================================================

CREATE TABLE stock_opname_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id    INT UNSIGNED NOT NULL,
    session_date    DATE NOT NULL,
    status          ENUM('OPEN','POSTED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    created_by      INT UNSIGNED NOT NULL,
    posted_by       INT UNSIGNED NULL,
    posted_at       DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sos_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_sos_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_sos_poster FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_opname_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          INT UNSIGNED NOT NULL,
    item_id             INT UNSIGNED NOT NULL,
    system_qty_base     DECIMAL(20,6) NOT NULL,
    counted_qty_base    DECIMAL(20,6) NOT NULL,
    variance_qty_base   DECIMAL(20,6) NOT NULL,          -- counted - system
    unit_cost_base      DECIMAL(20,4) NOT NULL,
    adjustment_id       BIGINT UNSIGNED NULL,             -- link once variance is posted as a stock_adjustment
    notes               VARCHAR(255) NULL,
    CONSTRAINT fk_sol2_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sol2_item FOREIGN KEY (item_id) REFERENCES items(id),
    UNIQUE KEY uq_sol2_session_item (session_id, item_id)
) ENGINE=InnoDB;

CREATE TABLE stock_adjustments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    adjustment_type     ENUM('DAMAGE','CORRECTION','OPNAME_VARIANCE','NEGATIVE_OVERRIDE') NOT NULL,
    qty_base_delta      DECIMAL(20,6) NOT NULL,           -- signed
    unit_cost_base      DECIMAL(20,4) NOT NULL,
    transaction_id       BIGINT UNSIGNED NULL,             -- the ADJUSTMENT-type inventory_transactions row this posted as
    reason               VARCHAR(255) NOT NULL,
    requires_approval    TINYINT(1) NOT NULL DEFAULT 0,
    approved_by          INT UNSIGNED NULL,
    approved_at          DATETIME NULL,
    created_by           INT UNSIGNED NOT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_sa_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_sa_tx FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id),
    CONSTRAINT fk_sa_approver FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_sa_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 7. WAREHOUSE TRANSFERS
-- ============================================================================

CREATE TABLE warehouse_transfers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_uuid       CHAR(36) NOT NULL UNIQUE,
    from_warehouse_id   INT UNSIGNED NOT NULL,
    to_warehouse_id     INT UNSIGNED NOT NULL,
    status              ENUM('PENDING','RECEIVED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    ship_date           DATETIME NOT NULL,                -- FIFO batch date on the receiving side (Section: "tanggal kirim")
    receive_date        DATETIME NULL,
    cancel_reason        VARCHAR(255) NULL,
    created_by           INT UNSIGNED NOT NULL,
    received_by          INT UNSIGNED NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_from FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_wt_to FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_wt_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_wt_receiver FOREIGN KEY (received_by) REFERENCES users(id),
    CONSTRAINT chk_wt_diff_wh CHECK (from_warehouse_id <> to_warehouse_id),
    INDEX idx_wt_status (status)
) ENGINE=InnoDB;

CREATE TABLE warehouse_transfer_lines (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id             INT UNSIGNED NOT NULL,
    item_id                 INT UNSIGNED NOT NULL,
    qty_base                DECIMAL(20,6) NOT NULL,
    unit_cost_base          DECIMAL(20,4) NOT NULL,       -- snapshot from FIFO at ship time
    out_transaction_line_id BIGINT UNSIGNED NULL,          -- TRANSFER_OUT line (consumes FIFO on from_warehouse)
    in_transaction_line_id  BIGINT UNSIGNED NULL,          -- TRANSFER_IN line (creates batch on to_warehouse), NULL until received
    CONSTRAINT fk_wtl_transfer FOREIGN KEY (transfer_id) REFERENCES warehouse_transfers(id),
    CONSTRAINT fk_wtl_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_wtl_out FOREIGN KEY (out_transaction_line_id) REFERENCES inventory_transaction_lines(id),
    CONSTRAINT fk_wtl_in FOREIGN KEY (in_transaction_line_id) REFERENCES inventory_transaction_lines(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 8. PRODUCTION / RACIK
-- ============================================================================

CREATE TABLE production_headers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_uuid      CHAR(36) NOT NULL UNIQUE,
    warehouse_id          INT UNSIGNED NOT NULL,
    division_id           INT UNSIGNED NULL,
    production_date       DATETIME NOT NULL,
    status                ENUM('POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    notes                 VARCHAR(255) NULL,
    created_by            INT UNSIGNED NOT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ph_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_ph_division FOREIGN KEY (division_id) REFERENCES divisions(id),
    CONSTRAINT fk_ph_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE production_inputs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id        INT UNSIGNED NOT NULL,
    item_id               INT UNSIGNED NOT NULL,
    qty_base              DECIMAL(20,6) NOT NULL,
    unit_cost_base        DECIMAL(20,4) NOT NULL,        -- weighted-avg from fifo_allocations of the consuming line
    transaction_line_id    BIGINT UNSIGNED NOT NULL,       -- PRODUCTION_IN line consuming raw material FIFO
    CONSTRAINT fk_pi_header FOREIGN KEY (production_id) REFERENCES production_headers(id),
    CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_pi_line FOREIGN KEY (transaction_line_id) REFERENCES inventory_transaction_lines(id)
) ENGINE=InnoDB;

CREATE TABLE production_outputs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    production_id         INT UNSIGNED NOT NULL,
    item_id               INT UNSIGNED NOT NULL,
    qty_base              DECIMAL(20,6) NOT NULL,
    unit_cost_base        DECIMAL(20,4) NOT NULL,        -- sum(production_inputs cost) / output qty_base, unless overridden
    transaction_line_id    BIGINT UNSIGNED NOT NULL,       -- PRODUCTION_OUT line creating the finished-good batch
    CONSTRAINT fk_po_header FOREIGN KEY (production_id) REFERENCES production_headers(id),
    CONSTRAINT fk_po_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_po_line FOREIGN KEY (transaction_line_id) REFERENCES inventory_transaction_lines(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 9. AUDIT LOG
-- ============================================================================

CREATE TABLE audit_logs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NULL,
    username_snapshot   VARCHAR(60) NOT NULL,
    action_code         VARCHAR(60) NOT NULL,   -- MASTER_CHANGE, UNIT_CHANGE, PRICE_OVERRIDE, STOCK_ADJUSTMENT,
                                                  -- TRANSACTION_VOID, TRANSACTION_REVERSAL, OPENING_IMPORT,
                                                  -- TRANSFER_CANCEL, STOCK_OPNAME_POST, BOOK_CLOSE, LOGIN, LOGIN_FAILED ...
    entity_type         VARCHAR(60) NOT NULL,
    entity_id           BIGINT UNSIGNED NULL,
    ip_address          VARCHAR(45) NULL,
    before_data         JSON NULL,
    after_data          JSON NULL,
    reason              VARCHAR(255) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action_code),
    INDEX idx_audit_date (created_at)
) ENGINE=InnoDB;

-- ============================================================================
-- 10. BOOK CLOSING (Section 20 — locks a period, never moves rows out)
-- ============================================================================

CREATE TABLE book_closings (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    status              ENUM('DRAFT','LOCKED') NOT NULL DEFAULT 'DRAFT',
    total_closing_value DECIMAL(24,4) NOT NULL DEFAULT 0,
    locked_by            INT UNSIGNED NULL,
    locked_at            DATETIME NULL,
    created_by            INT UNSIGNED NOT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bc_locker FOREIGN KEY (locked_by) REFERENCES users(id),
    CONSTRAINT fk_bc_creator FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_bc_period (period_start, period_end)
) ENGINE=InnoDB;

CREATE TABLE book_closing_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_closing_id      INT UNSIGNED NOT NULL,
    item_id               INT UNSIGNED NOT NULL,
    warehouse_id          INT UNSIGNED NOT NULL,
    qty_base              DECIMAL(20,6) NOT NULL,
    unit_cost_base_avg     DECIMAL(20,4) NOT NULL,
    value                  DECIMAL(24,4) NOT NULL,
    CONSTRAINT fk_bcl_closing FOREIGN KEY (book_closing_id) REFERENCES book_closings(id),
    CONSTRAINT fk_bcl_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_bcl_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    UNIQUE KEY uq_bcl_item_wh (book_closing_id, item_id, warehouse_id)
) ENGINE=InnoDB;

-- ============================================================================
-- 11. SYSTEM SETTINGS
-- ============================================================================

CREATE TABLE system_settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key     VARCHAR(80) NOT NULL UNIQUE,
    setting_value   VARCHAR(500) NOT NULL,
    description     VARCHAR(255) NULL,
    updated_by      INT UNSIGNED NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ss_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================================
-- 12. IMPORT STAGING (Sections 13-17)
-- ============================================================================

CREATE TABLE import_batches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type     ENUM('MASTER_ITEM','SUPPLIER','DIVISION','WAREHOUSE',
                          'OPENING_STOCK','HISTORICAL_TRANSACTION') NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    status          ENUM('STAGED','VALIDATED','COMMITTED','REJECTED') NOT NULL DEFAULT 'STAGED',
    total_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    warning_rows    INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by     INT UNSIGNED NOT NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_by    INT UNSIGNED NULL,
    committed_at    DATETIME NULL,
    CONSTRAINT fk_ib_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT fk_ib_committer FOREIGN KEY (committed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE import_rows (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id      INT UNSIGNED NOT NULL,
    row_no            INT UNSIGNED NOT NULL,
    raw_data               JSON NOT NULL,
    row_status             ENUM('VALID','WARNING','ERROR') NOT NULL DEFAULT 'VALID',
    messages               JSON NULL,
    created_entity_id       BIGINT UNSIGNED NULL,
    CONSTRAINT fk_ir_batch FOREIGN KEY (import_batch_id) REFERENCES import_batches(id),
    INDEX idx_ir_batch (import_batch_id, row_status)
) ENGINE=InnoDB;

-- Deferred FK: book_closings is only defined above, well after
-- inventory_transactions — added here instead of as a forward reference.
ALTER TABLE inventory_transactions
    ADD CONSTRAINT fk_tx_book_closing FOREIGN KEY (book_closing_id) REFERENCES book_closings(id);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED: system configuration only (no business data)
-- ============================================================================

INSERT INTO roles (code, name, description) VALUES
    ('SUPERADMIN', 'Super Admin', 'Full system access, including security-sensitive overrides'),
    ('ADMIN',      'Admin',       'Full operational access'),
    ('STOCK',      'Stock Staff', 'Warehouse transactions, transfers, opname'),
    ('DIVISION',   'Division Staff', 'Scoped to own division usage/production'),
    ('VIEWER',     'Viewer',      'Read-only access to reports and dashboards');

INSERT INTO permissions (code, description) VALUES
    ('TRANSACTION_IN_CREATE',      'Create incoming stock transactions'),
    ('TRANSACTION_OUT_CREATE',     'Create outgoing stock transactions'),
    ('TRANSACTION_VOID',           'Void/reverse a posted transaction'),
    ('STOCK_ALLOW_NEGATIVE',       'Override negative-stock block'),
    ('PRICE_ANOMALY_APPROVE',      'Approve a price-anomaly transaction'),
    ('MASTER_ITEM_MANAGE',         'Create/edit item master data'),
    ('MASTER_ITEM_UNIT_UNLOCK',    'Amend locked unit/conversion on an item with transaction history'),
    ('WAREHOUSE_TRANSFER_MANAGE',  'Create/receive/cancel warehouse transfers'),
    ('STOCK_OPNAME_MANAGE',        'Create and post stock opname sessions'),
    ('PRODUCTION_MANAGE',          'Post production/racik records'),
    ('IMPORT_MANAGE',              'Run data-migration import module'),
    ('BOOK_CLOSING_MANAGE',        'Lock/unlock accounting periods'),
    ('AUDIT_LOG_VIEW',             'View audit log'),
    ('USER_MANAGE',                'Manage users and roles'),
    ('SYSTEM_SETTINGS_MANAGE',     'Edit system settings');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'SUPERADMIN';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN' AND p.code NOT IN ('SYSTEM_SETTINGS_MANAGE','USER_MANAGE');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'STOCK' AND p.code IN
    ('TRANSACTION_IN_CREATE','TRANSACTION_OUT_CREATE','WAREHOUSE_TRANSFER_MANAGE','STOCK_OPNAME_MANAGE');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'DIVISION' AND p.code IN ('TRANSACTION_OUT_CREATE','PRODUCTION_MANAGE');

INSERT INTO units (code, name) VALUES
    ('GR','Gram'), ('KG','Kilogram'), ('ML','Mililiter'), ('LTR','Liter'),
    ('PCS','Pieces'), ('BOX','Box'), ('KARTON','Karton'), ('KARUNG','Karung'), ('LUSIN','Lusin');

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('price_anomaly_high_multiplier', '5',    'Reject/flag when new unit cost > reference price x this multiplier'),
    ('price_anomaly_low_multiplier',  '0.2',  'Reject/flag when new unit cost < reference price x this multiplier'),
    ('allow_negative_stock_default',  '0',    'Global default; per-transaction override still requires permission + reason'),
    ('idempotency_window_hours',      '72',   'How long a transaction_uuid is checked for replay before archival');

-- No rows are seeded into: warehouses, suppliers, divisions, items,
-- item_unit_conversions, item_price_history, inventory_batches,
-- inventory_transactions*, fifo_allocations, stock_openings*,
-- stock_opname_*, stock_adjustments, warehouse_transfers*,
-- production_*, book_closings*, import_*, or users (create the first
-- SUPERADMIN via the CLI installer in /migration, not via seed SQL).
