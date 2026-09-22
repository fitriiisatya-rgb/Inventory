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
    warehouse_id    INT UNSIGNED NULL,              -- scopes STOCK-role users to one warehouse; NULL = all warehouses
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    -- PHASE G21: a provisioned account (temp generated password) must change
    -- it before doing anything else — enforced server-side in AuthService,
    -- never left to the frontend to honor voluntarily.
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_users_division FOREIGN KEY (division_id) REFERENCES divisions(id)
    -- fk_users_warehouse added later via ALTER TABLE, once `warehouses` exists (Section 2)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(60) NOT NULL,
    ip_address      VARCHAR(45) NULL,
    success         TINYINT(1) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (username, created_at)
) ENGINE=InnoDB
COMMENT='Section 23/PHASE C3: backs simple server-side login rate limiting — counts recent failures per username (+ip) rather than trusting the client.';

-- ============================================================================
-- 2. ORGANIZATION MASTERS
-- ============================================================================

CREATE TABLE warehouses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(100) NOT NULL,
    -- PHASE G5: MAIN = a real stocking location; TRANSIT = an in-transit/
    -- staging point. Purely descriptive metadata — never changes FIFO or
    -- transfer behavior, which is driven by explicit transfer records.
    warehouse_type  ENUM('MAIN','TRANSIT') NOT NULL DEFAULT 'MAIN',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE suppliers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    contact_name    VARCHAR(100) NULL,          -- doubles as "PIC" in the V2 UI — never duplicated as a separate column
    address         VARCHAR(255) NULL,          -- PHASE V2: audited first (contact_name/phone/notes/is_active already existed) — only this and email were genuinely missing
    phone           VARCHAR(30)  NULL,
    email           VARCHAR(150) NULL,          -- PHASE V2
    notes           VARCHAR(255) NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- PHASE V2: normalized item category master. items.category (free text,
-- below) is preserved unchanged forever as historical provenance — this
-- table is additive, never a replacement. See docs/PHASE_V2_SCHEMA_IMPACT.md
-- for the backfill strategy (owner-reviewed mapping, never guessed).
CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(60)  NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    category        VARCHAR(100) NULL,          -- frozen historical text; see category_id below for the V2 normalized pointer
    category_id     INT UNSIGNED NULL,           -- PHASE V2: nullable — an item may be "Tanpa Kategori", never guessed
    brand           VARCHAR(100) NULL,
    base_unit_id    INT UNSIGNED NOT NULL,
    minimum_stock   DECIMAL(20,6) NOT NULL DEFAULT 0,
    default_supplier_id INT UNSIGNED NULL,
    notes           VARCHAR(255) NULL,
    status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    -- Set the first time any posted transaction references this item.
    -- While NULL, base_unit_id and every conversion row may still be edited
    -- freely; once set, UnitConversionService must refuse structural edits.
    locked_at       DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_items_base_unit FOREIGN KEY (base_unit_id) REFERENCES units(id),
    CONSTRAINT fk_items_default_supplier FOREIGN KEY (default_supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_items_status (status),
    INDEX idx_items_barcode (barcode),
    INDEX idx_items_category (category_id),
    INDEX idx_items_name (name)
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
-- 3B. PHASE V2 MASTERS — stock policy and bakery distribution
-- ============================================================================

-- PHASE V2: per-item-per-warehouse minimum/buffer. A missing row means
-- "use items.minimum_stock as fallback, buffer unset" — see
-- StockPolicyService::resolve(). items.minimum_stock (above) is kept
-- unchanged as that fallback source, never repurposed.
CREATE TABLE item_warehouse_stock_policy (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    minimum_stock_base  DECIMAL(20,6) NOT NULL DEFAULT 0,
    buffer_stock_base   DECIMAL(20,6) NULL,     -- NULL = not configured; never invented
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    notes               VARCHAR(255) NULL,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_iwsp_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_iwsp_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_iwsp_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_iwsp_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    UNIQUE KEY uq_iwsp_item_wh (item_id, warehouse_id)
) ENGINE=InnoDB;

-- PHASE V2: external distribution endpoint for OUT transactions.
-- Deliberately separate from warehouses (internal stock location) and
-- divisions (internal cost center) — see
-- docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5.
CREATE TABLE bakery_destinations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    address         VARCHAR(255) NULL,
    city_area       VARCHAR(100) NULL,
    pic_name        VARCHAR(100) NULL,
    phone           VARCHAR(30)  NULL,
    route_cluster   VARCHAR(100) NULL,
    notes           VARCHAR(255) NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
    -- Client-generated idempotency key. VARCHAR(100), not CHAR(36): a plain
    -- transaction posts a bare UUID, but TransferService/ProductionService/
    -- StockOpnameService derive composite keys (e.g. "{uuid}:OUT:{item_id}",
    -- one per line) so that idempotency is enforced per line, not just once
    -- for the whole multi-line request.
    transaction_uuid    VARCHAR(100) NOT NULL UNIQUE,
    transaction_type    ENUM('IN','OUT','TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT',
                              'OPNAME','PRODUCTION_IN','PRODUCTION_OUT','OPENING','REVERSAL') NOT NULL,
    transaction_date    DATETIME NOT NULL,                -- business-effective date (drives FIFO ordering)
    posting_date        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    warehouse_id        INT UNSIGNED NOT NULL,
    supplier_id         INT UNSIGNED NULL,
    division_id         INT UNSIGNED NULL,
    -- PHASE V2: external distribution endpoint, set only on transaction_type='OUT'.
    -- Distinct from warehouse_id (internal stock location) and division_id
    -- (internal cost center) — see docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5.
    -- The CHECK below is safe/backward-compatible because the column is new
    -- and nullable (every pre-V2 row already satisfies it) and because
    -- VoidService's REVERSAL insert never copies this column, so it is
    -- always NULL on a REVERSAL row — see docs/PHASE_V2_SCHEMA_IMPACT.md
    -- Section 3 for the full investigation this constraint is based on.
    bakery_destination_id INT UNSIGNED NULL,
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
    CONSTRAINT fk_tx_bakery_destination FOREIGN KEY (bakery_destination_id) REFERENCES bakery_destinations(id),
    CONSTRAINT chk_tx_bakery_destination_out_only
        CHECK (bakery_destination_id IS NULL OR transaction_type = 'OUT'),
    INDEX idx_tx_date (transaction_date),
    INDEX idx_tx_type_status (transaction_type, status),
    INDEX idx_tx_warehouse (warehouse_id),
    INDEX idx_tx_bakery_destination (bakery_destination_id)
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
-- 4A. PURCHASE COSTING (PHASE V2.7 — database/migrations/2026_09_22_v2_7_purchase_costing.sql)
--
-- Optional 1:1 companions to inventory_transactions/inventory_transaction_lines,
-- present only for a Stock IN posted through the costed purchase flow.
-- Every other transaction (OPENING, TRANSFER_IN, historical IN, pre-V2.7 IN)
-- has no matching row — read paths LEFT JOIN and treat NULL as "no costing
-- data", never as zero/blocking. FifoService's cost formula is untouched:
-- the final landed unit cost is computed by PurchaseCostingService BEFORE
-- FifoService::postIn() is called.
-- ============================================================================

CREATE TABLE purchase_invoice_headers (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id               BIGINT UNSIGNED NOT NULL UNIQUE,
    gross_purchase                DECIMAL(20,4) NOT NULL,
    line_discount_total           DECIMAL(20,4) NOT NULL DEFAULT 0,
    invoice_discount_type         ENUM('PERCENT','AMOUNT','NONE') NOT NULL DEFAULT 'NONE',
    invoice_discount_value        DECIMAL(20,4) NOT NULL DEFAULT 0,
    invoice_discount_amount       DECIMAL(20,4) NOT NULL DEFAULT 0,
    net_purchase_before_tax       DECIMAL(20,4) NOT NULL,
    -- CREDITABLE: 0 enters inventory cost. NON_CREDITABLE: full ppn_amount
    -- enters inventory cost. PARTIALLY_CREDITABLE: ppn_creditable_pct
    -- (0-100) splits ppn_amount into ppn_creditable_amount/
    -- ppn_non_creditable_amount. Never hard-coded — see PurchaseCostingService.
    ppn_treatment                  ENUM('CREDITABLE','NON_CREDITABLE','PARTIALLY_CREDITABLE','NONE') NOT NULL DEFAULT 'NONE',
    ppn_rate                       DECIMAL(8,4) NOT NULL DEFAULT 0,
    ppn_creditable_pct             DECIMAL(6,3) NOT NULL DEFAULT 0,
    ppn_amount                     DECIMAL(20,4) NOT NULL DEFAULT 0,
    ppn_creditable_amount          DECIMAL(20,4) NOT NULL DEFAULT 0,
    ppn_non_creditable_amount      DECIMAL(20,4) NOT NULL DEFAULT 0,
    -- CAPITALIZE: freight_amount is allocated into inventory line cost.
    -- EXPENSE: freight_amount is recorded (still part of invoice_total)
    -- but never enters inventory cost.
    freight_treatment               ENUM('CAPITALIZE','EXPENSE','NONE') NOT NULL DEFAULT 'NONE',
    freight_amount                  DECIMAL(20,4) NOT NULL DEFAULT 0,
    -- Supplier Invoice / Payable = net_purchase_before_tax + ppn_amount
    -- (full, both creditable and non-creditable) + freight_amount (full,
    -- regardless of capitalize/expense treatment).
    invoice_total                   DECIMAL(20,4) NOT NULL,
    -- Inventory / FIFO Cost = net_purchase_before_tax +
    -- ppn_non_creditable_amount + (freight_amount if CAPITALIZE else 0).
    -- Must equal SUM(purchase_line_costs.final_inventory_cost) for this
    -- transaction — enforced before POST, never silently corrected.
    inventory_cost_total            DECIMAL(20,4) NOT NULL,
    created_by                      INT UNSIGNED NOT NULL,
    created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pih_tx FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id),
    CONSTRAINT fk_pih_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB
COMMENT='PHASE V2.7 — one row per Stock IN posted through the costed purchase flow. Never present for OPENING/TRANSFER_IN/historical/pre-V2.7 IN rows.';

CREATE TABLE purchase_line_costs (
    id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_line_id            BIGINT UNSIGNED NOT NULL UNIQUE,
    transaction_id                 BIGINT UNSIGNED NOT NULL,
    gross_unit_price_input          DECIMAL(20,4) NOT NULL,
    gross_amount                    DECIMAL(20,4) NOT NULL,
    line_discount_type              ENUM('PERCENT','AMOUNT','NONE') NOT NULL DEFAULT 'NONE',
    line_discount_value             DECIMAL(20,4) NOT NULL DEFAULT 0,
    line_discount_amount            DECIMAL(20,4) NOT NULL DEFAULT 0,
    net_after_line_discount         DECIMAL(20,4) NOT NULL,
    invoice_discount_allocated      DECIMAL(20,4) NOT NULL DEFAULT 0,
    net_purchase_before_tax         DECIMAL(20,4) NOT NULL,
    ppn_allocated                    DECIMAL(20,4) NOT NULL DEFAULT 0,
    ppn_creditable_allocated         DECIMAL(20,4) NOT NULL DEFAULT 0,
    ppn_non_creditable_allocated     DECIMAL(20,4) NOT NULL DEFAULT 0,
    freight_allocated                DECIMAL(20,4) NOT NULL DEFAULT 0,
    final_inventory_cost             DECIMAL(20,4) NOT NULL,
    final_unit_cost_base             DECIMAL(20,4) NOT NULL,
    created_at                       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_plc_line FOREIGN KEY (transaction_line_id) REFERENCES inventory_transaction_lines(id),
    CONSTRAINT fk_plc_tx FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id),
    INDEX idx_plc_tx (transaction_id)
) ENGINE=InnoDB
COMMENT='PHASE V2.7 — one row per Stock IN LINE posted through the costed purchase flow.';

-- ============================================================================
-- 5. OPENING STOCK (authoritative baseline — see Section 14)
-- ============================================================================

CREATE TABLE stock_openings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cutoff_date     DATE NOT NULL,
    description     VARCHAR(255) NULL,
    status          ENUM('DRAFT','VALIDATED','COMMITTED') NOT NULL DEFAULT 'DRAFT',
    -- PHASE G15: control total computed from the staged rows themselves,
    -- BEFORE commit — the batches actually created at commit must sum to
    -- exactly this (within tolerance) or the import is rolled back rather
    -- than silently accepted with a mismatch.
    control_total_value        DECIMAL(20,4) NULL,
    control_total_by_warehouse JSON NULL,
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
    -- Nullable: OpeningValidationService::validateRow() returns item_id/
    -- warehouse_id = NULL for an unknown SKU / unknown warehouse_code ERROR
    -- row (so it can still be staged and reported, per
    -- OpeningReconciliationService's own unknown_sku/unknown_warehouse
    -- checks, which already count "item_id IS NULL" / "warehouse_id IS
    -- NULL") — a NOT NULL constraint here made staging such a row crash
    -- with an uncaught FK/NOT-NULL violation instead of recording a clean
    -- ERROR. commit() only ever processes VALID/WARNING rows, both of
    -- which always have both ids resolved, so this can never let a
    -- NULL-item/warehouse row reach FifoService.
    item_id             INT UNSIGNED NULL,
    warehouse_id        INT UNSIGNED NULL,
    qty_base            DECIMAL(20,6) NOT NULL,
    unit_cost_base      DECIMAL(20,4) NOT NULL,
    expiry_date         DATE NULL,
    batch_reference      VARCHAR(100) NULL,
    -- PHASE G-DATA 2: carried through from final_opening_stock_template.xlsx
    -- for audit/cross-check only — never authoritative. item_name_reference
    -- and global_base_unit_reference are compared against the real
    -- items/units master at validation time (mismatch = ERROR/WARNING);
    -- they are not what gets posted.
    item_name_reference        VARCHAR(200) NULL,
    global_base_unit_reference VARCHAR(20)  NULL,
    source                     VARCHAR(100) NULL,   -- e.g. "Final verified stock - Gudang Besar 2026-09-30"
    verification_status        VARCHAR(30)  NULL,   -- free-text from the template, e.g. "Verified by stock count"
    approved_by_name            VARCHAR(150) NULL,   -- free-text owner/admin name from the template (not a users.id — this predates any login)
    notes                       VARCHAR(255) NULL,
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
-- 5A. MOVEMENT RECONCILIATION REVIEW (PHASE G-DATA 2)
--
-- The 8 SKUs flagged during Phase G-DATA 1B/1B.1 real-data reconciliation
-- (opening + IN - OUT arithmetic disagreeing with a small theoretical
-- negative). Historical evidence ONLY — final opening stock (from the
-- owner's verified stock count) is always authoritative and is NEVER
-- adjusted to make this historical arithmetic match. Kept in its own
-- table, deliberately separate from stock_opening_lines/unit_conversion_
-- candidates, so a movement question is never confused with a unit-
-- conversion question or a final-opening-quantity question.
-- ============================================================================

CREATE TABLE movement_reconciliation_reviews (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku                         VARCHAR(40)  NOT NULL,
    item_name                   VARCHAR(200) NULL,
    warehouse_code               VARCHAR(30)  NOT NULL,
    unit                         VARCHAR(20)  NULL,
    historical_opening           DECIMAL(20,6) NULL,   -- September opening qty (evidence only)
    historical_in                DECIMAL(20,6) NULL,
    historical_out               DECIMAL(20,6) NULL,
    historical_calculated_ending DECIMAL(20,6) NULL,   -- opening + in - out, as historically computed
    verified_final_opening       DECIMAL(20,6) NULL,   -- filled in once the owner's final stock file arrives
    difference                   DECIMAL(20,6) NULL,   -- verified_final_opening - historical_calculated_ending
    status                        ENUM('PENDING_FINAL_STOCK','MATCHES','DIFFERS','ACCEPTED_AS_IS') NOT NULL DEFAULT 'PENDING_FINAL_STOCK',
    reason                        VARCHAR(255) NULL,    -- e.g. timing, rounding, missing small movement
    notes                         VARCHAR(255) NULL,
    source                        VARCHAR(150) NULL,    -- which analysis round/file this evidence came from
    -- POLICY CORRECTION (owner decision): a row the owner explicitly approved
    -- to carry forward as a real, visible negative LIVE Opening balance
    -- instead of being zeroed or provisionally adjusted. Approving here does
    -- NOT change historical_* evidence above — it only flags this sku+warehouse
    -- as allowed to post a negative opening line and enforces FIFO safety
    -- (see MigrationNegativeStockService) until an admin resolves it via a
    -- real, audited Stock Opname/Adjustment.
    is_migration_negative_approved TINYINT(1) NOT NULL DEFAULT 0,
    migration_negative_approved_by_name VARCHAR(150) NULL,
    migration_negative_note        VARCHAR(255) NULL,
    created_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mrr_sku_warehouse (sku, warehouse_code),
    INDEX idx_mrr_sku (sku),
    INDEX idx_mrr_migration_negative (is_migration_negative_approved)
) ENGINE=InnoDB
COMMENT='Phase G-DATA 2: historical movement-vs-final-stock evidence, informational only — never alters final opening. Also doubles as the migration-negative-stock whitelist (POLICY CORRECTION) for the same sku+warehouse rows.';

-- ============================================================================
-- 5B. UNIT CONVERSION CANDIDATE REVIEW (PHASE G-DATA 1B)
--
-- Pure staging/review data — NEVER read by FifoService/UnitConversionService
-- and NEVER affects a live posting. `sku` is a plain string, not a FK to
-- `items`, because some rows here are for SKUs that don't exist in the
-- master yet (new Global Master candidates) or whose identity is still in
-- conflict (BLOCKED rows). An approved row is promoted into the real,
-- FIFO-facing `item_unit_conversions` table via
-- UnitConversionService::openNewVersion() — a separate, explicit step,
-- never automatic.
-- ============================================================================

CREATE TABLE unit_conversion_candidates (
    id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku                             VARCHAR(40)  NOT NULL,
    item_name                       VARCHAR(200) NULL,
    current_source                 VARCHAR(100) NULL,   -- which warehouse/source file this candidate came from, or 'MULTIPLE'

    global_base_unit_candidate      VARCHAR(20)  NULL,
    legacy_base_unit                VARCHAR(20)  NULL,
    middle_unit_candidate           VARCHAR(20)  NULL,
    middle_conversion_to_base       DECIMAL(20,6) NULL,
    purchase_unit_candidate         VARCHAR(20)  NULL,
    purchase_conversion_to_base     DECIMAL(20,6) NULL,

    gudang_besar_display_unit       VARCHAR(20)  NULL,
    cibadak_display_unit            VARCHAR(20)  NULL,
    karangtengah_display_unit       VARCHAR(20)  NULL,

    name_derived_candidate          VARCHAR(255) NULL,   -- e.g. "25 KG from '@25Kg' in item name"
    price_ratio_evidence            JSON NULL,           -- {ratio, price_a, unit_a, source_a, price_b, unit_b, source_b}
    legacy_evidence                 JSON NULL,           -- {legacy_source, legacy_value, source_field}[]

    -- Section 10: source priority drives confidence, never auto-approval.
    -- BUSINESS_CONFIRMED (PHASE G-DATA 1B.1/1B.3) = an explicit owner/admin
    -- confirmation, distinct from the detector's own HIGH/MEDIUM/LOW scale —
    -- never conflated with an auto-derived score.
    confidence                      ENUM('HIGH','MEDIUM','LOW','BUSINESS_CONFIRMED') NULL,
    issue_code                      VARCHAR(255) NULL,   -- comma-separated if more than one applies
    issue_detail                    TEXT NULL,
    review_status                   ENUM('PENDING','BLOCKED','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',

    approved_base_unit               VARCHAR(20) NULL,
    approved_middle_unit             VARCHAR(20) NULL,
    approved_middle_conversion       DECIMAL(20,6) NULL,
    approved_purchase_unit           VARCHAR(20) NULL,
    approved_purchase_conversion     DECIMAL(20,6) NULL,
    approved                         ENUM('YES') NULL,   -- NULL/blank = not approved; never auto-set
    correction_note                  TEXT NULL,

    -- PHASE G-DATA 2: audit trail preserved when a business/admin decision
    -- promotes a candidate — never deletes the detector's own evidence above.
    conversion_source                VARCHAR(30)  NULL,   -- e.g. BUSINESS_CONFIRMED, ADMIN_DECISION_CONFIRMED
    admin_source_answer              TEXT NULL,            -- the owner/admin's own words, verbatim
    prior_detector_evidence          JSON NULL,            -- issue_code/confidence/review_status snapshot before the override

    approved_by                      INT UNSIGNED NULL,
    approved_by_name                 VARCHAR(150) NULL,    -- free-text owner/admin name, for rounds that predate a login-bound approver
    approved_at                      DATETIME NULL,
    created_at                       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ucc_approver FOREIGN KEY (approved_by) REFERENCES users(id),
    UNIQUE KEY uq_ucc_sku (sku),
    INDEX idx_ucc_review_status (review_status)
) ENGINE=InnoDB
COMMENT='Phase G-DATA 1B: unit conversion reconstruction candidates — review-only until a human sets approved=YES, which a separate promotion step then writes into item_unit_conversions.';

-- ============================================================================
-- 6. STOCK OPNAME, ADJUSTMENTS
-- ============================================================================

CREATE TABLE stock_opname_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id    INT UNSIGNED NOT NULL,
    session_date    DATE NOT NULL,
    session_uuid    CHAR(36) NOT NULL UNIQUE,
    -- OPEN: counting in progress: FINALIZED: counts locked, variance computed,
    -- awaiting review/post; POSTED: adjustments created, session closed.
    -- Movement in `warehouse_id` is blocked while status IN ('OPEN','FINALIZED').
    status          ENUM('OPEN','FINALIZED','POSTED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    created_by      INT UNSIGNED NOT NULL,
    finalized_by    INT UNSIGNED NULL,
    finalized_at    DATETIME NULL,
    posted_by       INT UNSIGNED NULL,
    posted_at       DATETIME NULL,
    cancelled_by    INT UNSIGNED NULL,
    cancelled_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sos_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_sos_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_sos_finalizer FOREIGN KEY (finalized_by) REFERENCES users(id),
    CONSTRAINT fk_sos_poster FOREIGN KEY (posted_by) REFERENCES users(id),
    CONSTRAINT fk_sos_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
    INDEX idx_sos_active_warehouse (warehouse_id, status)
) ENGINE=InnoDB
COMMENT='Only one OPEN/FINALIZED session per warehouse should exist at a time — enforced in StockOpnameService, not by a DB constraint (a partial-uniqueness need MySQL cannot express directly without the same generated-column trick as item_unit_conversions; left to the service layer here since it also has to explain itself in the API error).';

CREATE TABLE stock_opname_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          INT UNSIGNED NOT NULL,
    item_id             INT UNSIGNED NOT NULL,
    system_qty_base     DECIMAL(20,6) NOT NULL,          -- snapshotted at session start (StockOpnameService::start)
    counted_qty_base    DECIMAL(20,6) NULL,              -- NULL until /count submits a physical count
    is_counted          TINYINT(1) NOT NULL DEFAULT 0,
    variance_qty_base   DECIMAL(20,6) NULL,              -- counted - system, computed at /finalize
    unit_cost_base      DECIMAL(20,4) NOT NULL,           -- system cost at session start (used for OUT variance)
    adjustment_id       BIGINT UNSIGNED NULL,             -- link once variance is posted as a stock_adjustment
    cost_required       TINYINT(1) NOT NULL DEFAULT 0,    -- true when variance is IN and no reliable cost exists yet
    override_cost_base  DECIMAL(20,4) NULL,               -- admin-supplied cost when cost_required was flagged
    notes               VARCHAR(255) NULL,
    CONSTRAINT fk_sol2_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sol2_item FOREIGN KEY (item_id) REFERENCES items(id),
    UNIQUE KEY uq_sol2_session_item (session_id, item_id)
) ENGINE=InnoDB;

CREATE TABLE stock_adjustments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    warehouse_id        INT UNSIGNED NOT NULL,
    adjustment_type     ENUM('OPNAME','CORRECTION','DAMAGE','EXPIRED','LOSS','OTHER','NEGATIVE_OVERRIDE') NOT NULL,
    qty_base_delta      DECIMAL(20,6) NOT NULL,           -- signed
    before_qty_base     DECIMAL(20,6) NOT NULL,
    after_qty_base      DECIMAL(20,6) NOT NULL,
    unit_cost_base      DECIMAL(20,4) NOT NULL,
    transaction_id       BIGINT UNSIGNED NULL,             -- the ADJUSTMENT-type inventory_transactions row this posted as
    reference_no         VARCHAR(100) NULL,                -- e.g. opname session id, external doc number
    reason               VARCHAR(255) NOT NULL,
    -- POLICY CORRECTION: when this adjustment resolves a known migration-negative
    -- balance (movement_reconciliation_reviews.is_migration_negative_approved),
    -- the admin records which case it resolves — e.g. "MIGRATION-100304-SCM".
    -- NULL for every ordinary adjustment.
    migration_issue_reference VARCHAR(100) NULL,
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
) ENGINE=InnoDB
COMMENT='Never a silent adjustment: reason is NOT NULL, before/after qty are always recorded, and every row is either linked to an ADJUSTMENT-type inventory_transactions row or explicitly why not.';

-- ============================================================================
-- 7. WAREHOUSE TRANSFERS
-- ============================================================================

CREATE TABLE warehouse_transfers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_uuid       CHAR(36) NOT NULL UNIQUE,
    from_warehouse_id   INT UNSIGNED NOT NULL,
    to_warehouse_id     INT UNSIGNED NOT NULL,
    status              ENUM('PENDING','RECEIVED','CANCELLED','REVERSED') NOT NULL DEFAULT 'PENDING',
    ship_date           DATETIME NOT NULL,                -- FIFO batch date on the receiving side (Section: "tanggal kirim")
    receive_date        DATETIME NULL,
    cancel_reason        VARCHAR(255) NULL,
    cancelled_by          INT UNSIGNED NULL,
    cancelled_at          DATETIME NULL,
    -- PHASE V2.5: a RECEIVED transfer's correction path — the whole chain
    -- (TRANSFER_OUT + TRANSFER_IN) is reversed atomically by
    -- TransferService::reverse(), never a second CANCEL. Symmetric with the
    -- cancel_* columns above.
    reverse_reason        VARCHAR(255) NULL,
    reversed_by            INT UNSIGNED NULL,
    reversed_at            DATETIME NULL,
    -- Distinguish "same request retried" (idempotent no-op) from "a genuinely
    -- new attempt to receive/cancel/reverse a transfer that's already in that
    -- state" (TRANSFER_ALREADY_RECEIVED / TRANSFER_ALREADY_CANCELLED /
    -- TRANSFER_ALREADY_REVERSED) — see VoidService's sibling pattern for
    -- transactions.
    receive_request_uuid  VARCHAR(100) NULL,
    cancel_request_uuid   VARCHAR(100) NULL,
    reverse_request_uuid  VARCHAR(100) NULL,
    created_by           INT UNSIGNED NOT NULL,
    received_by          INT UNSIGNED NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wt_from FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_wt_to FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_wt_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_wt_receiver FOREIGN KEY (received_by) REFERENCES users(id),
    CONSTRAINT fk_wt_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
    CONSTRAINT fk_wt_reverser FOREIGN KEY (reversed_by) REFERENCES users(id),
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
    total_closing_value DECIMAL(24,4) NOT NULL DEFAULT 0,   -- ending inventory value across all warehouses
    total_in_transit_value DECIMAL(24,4) NOT NULL DEFAULT 0,
    purchase_total      DECIMAL(24,4) NOT NULL DEFAULT 0,   -- sum of IN transactions in the period
    usage_total         DECIMAL(24,4) NOT NULL DEFAULT 0,   -- HPP: sum of OUT (type 'pakai'/production input) transactions in the period
    shrinkage_total     DECIMAL(24,4) NOT NULL DEFAULT 0,   -- sum of negative stock_adjustments (DAMAGE/EXPIRED/LOSS/opname-out) in the period
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
                          'OPENING_STOCK','HISTORICAL_TRANSACTION','LIVE_TRANSACTION','MINIMUM_STOCK') NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    -- PHASE V2.8: SHA256 of the uploaded file's bytes — stage-time half of
    -- the two-layer duplicate-import protection (see
    -- ImportLiveTransactionService). NULL for every pre-V2.8 import type.
    source_file_hash VARCHAR(64) NULL,
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
    CONSTRAINT fk_ib_committer FOREIGN KEY (committed_by) REFERENCES users(id),
    INDEX idx_ib_type_hash (import_type, source_file_hash)
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

-- Deferred FK: users.warehouse_id references warehouses(id), defined after `users`.
ALTER TABLE users
    ADD CONSTRAINT fk_users_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id);

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
    ('TRANSACTION_VOID_LOCKED_PERIOD', 'Void a transaction dated inside an already-LOCKED period (documented superadmin correction mechanism)'),
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
    ('SYSTEM_SETTINGS_MANAGE',     'Edit system settings'),
    ('STOCK_ADJUSTMENT_CREATE',    'Post a manual stock adjustment'),
    ('STOCK_ADJUSTMENT_APPROVE',   'Approve a stock adjustment that requires approval'),
    ('RECONCILIATION_VIEW',        'View the pre-go-live reconciliation report'),
    ('INVENTORY_VIEW',             'Read current stock, batches, value, ledger (VIEWER baseline)'),
    -- PHASE V2: additive only — ADMIN/SUPERADMIN inherit these automatically via the
    -- existing seed pattern below (ADMIN = every permission NOT IN the exclusion list,
    -- which these are not added to). STOCK/DIVISION/VIEWER get none of these four;
    -- their reads of the new stock-policy/report endpoints reuse INVENTORY_VIEW.
    ('MASTER_CATEGORY_MANAGE',           'Create/edit item categories'),
    ('MASTER_SUPPLIER_MANAGE',           'Create/edit vendors/suppliers'),
    ('MASTER_BAKERY_DESTINATION_MANAGE', 'Create/edit bakery distribution destinations'),
    ('STOCK_POLICY_MANAGE',              'Set per-item-per-warehouse minimum/buffer stock policy'),
    -- PHASE V2.1: same additive convention as the PHASE V2 block above —
    -- ADMIN/SUPERADMIN inherit these automatically (not in ADMIN's
    -- exclusion list below); STOCK/DIVISION/VIEWER get neither.
    ('MASTER_WAREHOUSE_MANAGE', 'Create/edit/deactivate/delete warehouse master data'),
    ('MASTER_DIVISION_MANAGE',  'Create/edit/deactivate/delete division master data'),
    -- PHASE V2.5: reversing a RECEIVED transfer is a privileged correction
    -- action. PHASE V2.5A tightened this (and TRANSACTION_VOID below) to
    -- SUPERADMIN only — see ADMIN's exclusion list below.
    ('TRANSFER_REVERSE', 'Reverse a RECEIVED warehouse transfer (whole TRANSFER_OUT/TRANSFER_IN chain) — privileged correction action');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'SUPERADMIN';

-- PHASE V2.5A: correction actions (void a posted transaction, reverse a
-- RECEIVED transfer) are SUPERADMIN-only — ADMIN is explicitly NOT
-- equivalent here, unlike every other ADMIN grant in this exclusion-list
-- pattern. TRANSACTION_VOID_LOCKED_PERIOD was already SUPERADMIN-only from
-- the original schema; TRANSACTION_VOID and TRANSFER_REVERSE join it here.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN' AND p.code NOT IN ('SYSTEM_SETTINGS_MANAGE','USER_MANAGE','TRANSACTION_VOID_LOCKED_PERIOD','TRANSACTION_VOID','TRANSFER_REVERSE');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'STOCK' AND p.code IN
    ('TRANSACTION_IN_CREATE','TRANSACTION_OUT_CREATE','WAREHOUSE_TRANSFER_MANAGE','STOCK_OPNAME_MANAGE',
     'STOCK_ADJUSTMENT_CREATE','INVENTORY_VIEW');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'DIVISION' AND p.code IN ('TRANSACTION_OUT_CREATE','PRODUCTION_MANAGE','INVENTORY_VIEW');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'VIEWER' AND p.code IN ('INVENTORY_VIEW','AUDIT_LOG_VIEW','RECONCILIATION_VIEW');

-- PHASE G2.1: this is the canonical unit list. Any spelling/case variant a
-- user types (e.g. "gram", "Kg", "sack") is normalized in
-- services/UnitNormalizationService.php to one of these codes before it
-- ever reaches the database — never guessed, never auto-converted.
-- PHASE G-DATA 1B.2: PAIL/JAR/SET/SHEET/METER/BATANG added after being
-- confirmed as real units in use across the actual catalog (never added
-- speculatively — see migration/workspace/reports/unit_conversion_summary_v3.md
-- section 13 and the semantic-grouping round's BATANG discovery).
INSERT INTO units (code, name) VALUES
    ('GR','Gram'), ('KG','Kilogram'), ('ML','Mililiter'), ('LTR','Liter'),
    ('PCS','Pieces'), ('BOX','Box'), ('KARTON','Karton'), ('KARUNG','Karung'), ('LUSIN','Lusin'),
    ('PACK','Pack'), ('ROLL','Roll'),
    ('PAIL','Pail'), ('JAR','Jar'), ('SET','Set'), ('SHEET','Sheet'), ('METER','Meter'), ('BATANG','Batang');

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('price_anomaly_high_multiplier', '5',    'Reject/flag when new unit cost > reference price x this multiplier'),
    ('price_anomaly_low_multiplier',  '0.2',  'Reject/flag when new unit cost < reference price x this multiplier'),
    ('allow_negative_stock_default',  '0',    'Global default; per-transaction override still requires permission + reason'),
    ('idempotency_window_hours',      '72',   'How long a transaction_uuid is checked for replay before archival'),
    -- PHASE V2.7: default only, pre-fills the Cost Preview UI. Never
    -- authoritative after posting — every costed Stock IN snapshots its
    -- own ppn_rate/ppn_amount in purchase_invoice_headers; changing this
    -- setting never recalculates a historical transaction's cost.
    ('default_ppn_rate',              '11',   'Default PPN rate (%) pre-filled on a new costed Stock IN — each transaction still snapshots its own rate');

-- No rows are seeded into: warehouses, suppliers, divisions, items,
-- item_unit_conversions, item_price_history, inventory_batches,
-- inventory_transactions*, fifo_allocations, stock_openings*,
-- stock_opname_*, stock_adjustments, warehouse_transfers*,
-- production_*, book_closings*, import_*, or users (create the first
-- SUPERADMIN via the CLI installer in /migration, not via seed SQL).
