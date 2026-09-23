-- ============================================================================
-- Inventory FIFO Pro V2.11A — SCM -> Bakery Distribution: schema for the
-- Delivery Order (DO) lifecycle + FIFO integration.
-- Phase V2.11A (first of three: A = DO/FIFO, B = Pricing/Invoice,
-- C = Receiving/Reports/Print — see docs/... commit messages for the
-- rest of this phase).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive. Distribution/sale SCM -> Bakery is a NEW document type
-- (distribution_orders / distribution_order_lines), explicitly NOT the
-- existing warehouse_transfers (that stays reserved for warehouse-to-
-- warehouse movement, e.g. SCM -> CIBADAK) and NOT a second inventory
-- engine — real stock leaves SCM only via the existing, unmodified
-- FifoService::postOut(), called once at DISPATCHED. No inventory
-- quantities/values change at migration time; no inventory batches are
-- created by this migration.
-- ============================================================================

-- Deterministic, concurrency-safe document numbering (DO-YYYYMMDD-####,
-- later reused for INV-YYYYMMDD-#### in Phase V2.11B) — one counter row
-- per (doc_type, date), incremented atomically via
-- INSERT ... ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq+1),
-- so two concurrent requests on the same day can never receive the same
-- number (the PRIMARY KEY row lock serializes the increment).
CREATE TABLE document_number_sequences (
    doc_type    VARCHAR(20) NOT NULL,
    date_key    CHAR(8)     NOT NULL,   -- YYYYMMDD
    last_seq    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (doc_type, date_key)
) ENGINE=InnoDB;

CREATE TABLE distribution_orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    do_number           VARCHAR(40) NOT NULL UNIQUE,
    do_date             DATE NOT NULL,
    from_warehouse_id   INT UNSIGNED NOT NULL,   -- resolved by warehouse CODE at the service layer (Section 3) — never a hard-coded id, but always validated = the SCM warehouse for THIS installation
    bakery_destination_id INT UNSIGNED NOT NULL,
    delivery_address_snapshot VARCHAR(255) NULL, -- copied from bakery_destinations.address at creation — a later address edit on the master never rewrites an already-created DO
    reference_no        VARCHAR(100) NULL,
    notes                VARCHAR(255) NULL,
    driver_name          VARCHAR(100) NULL,
    vehicle_no           VARCHAR(50)  NULL,
    delivery_notes        VARCHAR(255) NULL,
    status              ENUM('DRAFT','APPROVED','PICKING','DISPATCHED','RECEIVED','RECEIVED_WITH_DISCREPANCY','COMPLETED','CANCELLED')
                        NOT NULL DEFAULT 'DRAFT',
    created_by          INT UNSIGNED NOT NULL,
    approved_by         INT UNSIGNED NULL,
    approved_at         DATETIME NULL,
    picking_started_by  INT UNSIGNED NULL,
    picking_started_at  DATETIME NULL,
    dispatched_by       INT UNSIGNED NULL,
    dispatched_at       DATETIME NULL,
    received_by         INT UNSIGNED NULL,
    received_at         DATETIME NULL,
    completed_by        INT UNSIGNED NULL,
    completed_at        DATETIME NULL,
    cancelled_by         INT UNSIGNED NULL,
    cancelled_at         DATETIME NULL,
    cancel_reason        VARCHAR(255) NULL,
    -- Idempotency keys for the two side-effect-bearing actions (dispatch
    -- creates real FIFO OUT transactions; cancel/reverse after dispatch
    -- restores them) — same "store the request_uuid that actually
    -- succeeded, replay-detect by comparing" convention as
    -- warehouse_transfers.receive_request_uuid/cancel_request_uuid.
    dispatch_request_uuid VARCHAR(100) NULL,
    reverse_request_uuid  VARCHAR(100) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_do_from_warehouse FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_do_bakery FOREIGN KEY (bakery_destination_id) REFERENCES bakery_destinations(id),
    CONSTRAINT fk_do_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_do_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT fk_do_picking_started_by FOREIGN KEY (picking_started_by) REFERENCES users(id),
    CONSTRAINT fk_do_dispatched_by FOREIGN KEY (dispatched_by) REFERENCES users(id),
    CONSTRAINT fk_do_received_by FOREIGN KEY (received_by) REFERENCES users(id),
    CONSTRAINT fk_do_completed_by FOREIGN KEY (completed_by) REFERENCES users(id),
    CONSTRAINT fk_do_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id),
    INDEX idx_do_status (status),
    INDEX idx_do_bakery (bakery_destination_id),
    INDEX idx_do_date (do_date)
) ENGINE=InnoDB
COMMENT='PHASE V2.11A — SCM -> Bakery Delivery Order header. Stock leaves inventory only at DISPATCHED (real FifoService::postOut per line), never at DRAFT/APPROVED/PICKING.';

CREATE TABLE distribution_order_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    do_id               INT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    item_id             INT UNSIGNED NOT NULL,
    sku_snapshot        VARCHAR(40)  NOT NULL,
    item_name_snapshot  VARCHAR(200) NOT NULL,
    category_id_snapshot INT UNSIGNED NULL,
    input_qty           DECIMAL(20,6) NOT NULL,    -- planned/ordered qty, set at DRAFT — never overwritten after dispatch (Section 5/22)
    input_unit_id        INT UNSIGNED NOT NULL,
    qty_base             DECIMAL(20,6) NOT NULL,    -- planned qty re-expressed in base unit (preview only until dispatch)
    notes                VARCHAR(255) NULL,
    -- Set only at DISPATCHED, from the REAL FifoService::postOut() result
    -- for this line — never guessed/derived from input_qty, so a partial
    -- FIFO allocation (unlikely but possible under an allow-negative
    -- override) is reflected exactly.
    qty_sent_base         DECIMAL(20,6) NULL,
    out_transaction_line_id BIGINT UNSIGNED NULL,   -- the exact inventory_transaction_lines row FifoService::postOut() created for this DO line
    -- Set only at RECEIVED/RECEIVED_WITH_DISCREPANCY — the bakery's
    -- physical count. Never fed back into FIFO (Section 6) — a real
    -- stock/value correction for a discrepancy is a separate, explicit
    -- follow-up (out of this phase's scope), never an automatic silent
    -- mutation of the original dispatch.
    qty_received_base      DECIMAL(20,6) NULL,
    difference_qty_base    DECIMAL(20,6) NULL,      -- qty_received_base - qty_sent_base (persisted so it's never recomputed differently at read/report time)
    discrepancy_reason     ENUM('KURANG','RUSAK','REJECT','SALAH_BARANG','LAINNYA') NULL,
    discrepancy_notes      VARCHAR(255) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dol_do FOREIGN KEY (do_id) REFERENCES distribution_orders(id),
    CONSTRAINT fk_dol_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_dol_category FOREIGN KEY (category_id_snapshot) REFERENCES categories(id),
    CONSTRAINT fk_dol_unit FOREIGN KEY (input_unit_id) REFERENCES units(id),
    CONSTRAINT fk_dol_out_line FOREIGN KEY (out_transaction_line_id) REFERENCES inventory_transaction_lines(id),
    CONSTRAINT chk_dol_qty_positive CHECK (input_qty > 0),
    INDEX idx_dol_do (do_id),
    INDEX idx_dol_item (item_id)
) ENGINE=InnoDB
COMMENT='PHASE V2.11A — one row per item on a Delivery Order. A genuine multi-line document (Part 31) — several rows may share the same do_id.';
