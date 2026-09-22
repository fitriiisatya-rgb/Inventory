-- ============================================================================
-- Inventory FIFO Pro V2.7 — Purchase Costing (PPN / Discount / Freight)
-- Phase V2.7
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive: two new tables, no existing table altered, no existing
-- row touched.
--
--   - purchase_invoice_headers: an OPTIONAL 1:1 companion to
--     inventory_transactions, present only for a Stock IN posted through
--     the new costed purchase flow.
--   - purchase_line_costs: an OPTIONAL 1:1 companion to
--     inventory_transaction_lines, same scope (today always exactly 1:1
--     with its transaction, since FifoService::postIn() creates a single
--     line per call — see PurchaseCostingService's own docblock for why
--     the allocation math is still written generally for N lines).
--
-- Every existing transaction (OPENING, TRANSFER_IN, historical IN, and any
-- Stock IN posted before this phase, or after it without the new costing
-- fields) simply has NO matching row in either table — every read path
-- LEFT JOINs and treats a missing row as "no costing data was captured",
-- never as zero or as a blocking condition.
--
-- inventory_transactions, inventory_transaction_lines, fifo_allocations and
-- inventory_batches are NOT modified. FifoService's own cost formula is
-- untouched: the final landed unit cost is computed BEFORE calling
-- FifoService::postIn(), which still does exactly what it always did with
-- whatever unit_price_input it is given (see PurchaseCostingService).
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
    -- ppn_non_creditable_amount. Never hard-coded — see V2.7.1.
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
    -- (full, both creditable and non-creditable — still owed to the
    -- supplier either way) + freight_amount (full, regardless of
    -- capitalize/expense treatment).
    invoice_total                   DECIMAL(20,4) NOT NULL,
    -- Inventory / FIFO Cost = net_purchase_before_tax +
    -- ppn_non_creditable_amount + (freight_amount if CAPITALIZE else 0).
    -- Must equal SUM(purchase_line_costs.final_inventory_cost) for this
    -- transaction — enforced by PurchaseCostingService before POST, never
    -- silently corrected.
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
    gross_unit_price_input          DECIMAL(20,4) NOT NULL,  -- the RAW price the user entered, before any discount
    gross_amount                    DECIMAL(20,4) NOT NULL,  -- input_qty * gross_unit_price_input
    line_discount_type              ENUM('PERCENT','AMOUNT','NONE') NOT NULL DEFAULT 'NONE',
    line_discount_value             DECIMAL(20,4) NOT NULL DEFAULT 0,
    line_discount_amount            DECIMAL(20,4) NOT NULL DEFAULT 0,
    net_after_line_discount         DECIMAL(20,4) NOT NULL,  -- gross_amount - line_discount_amount
    invoice_discount_allocated      DECIMAL(20,4) NOT NULL DEFAULT 0,
    net_purchase_before_tax         DECIMAL(20,4) NOT NULL,  -- net_after_line_discount - invoice_discount_allocated
    ppn_allocated                    DECIMAL(20,4) NOT NULL DEFAULT 0,
    ppn_creditable_allocated         DECIMAL(20,4) NOT NULL DEFAULT 0,
    ppn_non_creditable_allocated     DECIMAL(20,4) NOT NULL DEFAULT 0,
    freight_allocated                DECIMAL(20,4) NOT NULL DEFAULT 0,  -- 0 if header freight_treatment=EXPENSE
    final_inventory_cost             DECIMAL(20,4) NOT NULL,  -- net_purchase_before_tax + ppn_non_creditable_allocated + freight_allocated
    final_unit_cost_base             DECIMAL(20,4) NOT NULL,  -- final_inventory_cost / base_qty — mirrors inventory_transaction_lines.unit_cost_base exactly
    created_at                       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_plc_line FOREIGN KEY (transaction_line_id) REFERENCES inventory_transaction_lines(id),
    CONSTRAINT fk_plc_tx FOREIGN KEY (transaction_id) REFERENCES inventory_transactions(id),
    INDEX idx_plc_tx (transaction_id)
) ENGINE=InnoDB
COMMENT='PHASE V2.7 — one row per Stock IN LINE posted through the costed purchase flow.';

-- Default PPN rate the UI pre-fills (never authoritative after the fact —
-- every transaction snapshots its own ppn_rate/ppn_amount; changing this
-- setting later never recalculates a historical transaction's cost).
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
    ('default_ppn_rate', '11', 'Default PPN rate (%) pre-filled on a new costed Stock IN — each transaction still snapshots its own rate');
