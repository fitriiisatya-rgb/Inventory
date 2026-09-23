-- ============================================================================
-- Inventory FIFO Pro V2.11B — SCM -> Bakery Distribution: Pricing Policy
-- (company/category/SKU hierarchy) + Invoice generation from a Delivery
-- Order, with an immutable per-line pricing snapshot.
-- Phase V2.11B (second of three: A = DO/FIFO, B = Pricing/Invoice,
-- C = Receiving polish/Reports/Print).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- PRICE-SOURCE AUDIT (Part 11): the reference purchase price used here is
-- EXACTLY the same source V2.10's Stock IN auto-fill already uses --
-- item_price_history.price_per_unit via ItemPriceService (the only
-- legitimate existing price source; no separate "master price" field
-- exists, and this is never confused with FIFO/HPP cost or a selling
-- price). Reused unmodified, not re-audited or re-implemented here.
--
-- Purely additive. Never changes inventory quantities/values, never
-- creates inventory batches, never touches distribution_orders/
-- distribution_order_lines (V2.11A, unmodified).
-- ============================================================================

CREATE TABLE distribution_pricing_policies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope           ENUM('COMPANY','CATEGORY','SKU') NOT NULL,
    category_id     INT UNSIGNED NULL,   -- set only when scope='CATEGORY'
    item_id         INT UNSIGNED NULL,   -- set only when scope='SKU'
    pricing_method  ENUM('AT_COST','COST_PLUS_PERCENT','COST_PLUS_AMOUNT') NOT NULL,
    -- Percent (e.g. 5.0000 = 5%) when pricing_method=COST_PLUS_PERCENT,
    -- a nominal amount per selling unit when COST_PLUS_AMOUNT, ignored
    -- (but stored as 0) when AT_COST.
    margin_value    DECIMAL(20,4) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    effective_from  DATE NULL,
    notes           VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Same generated-column active-window trick used throughout this
    -- project (item_unit_conversions, item_barcodes): each evaluates to
    -- NULL unless this row is both the right scope AND active, so the
    -- UNIQUE index below makes "at most one ACTIVE policy per
    -- company/category/SKU" a real DB constraint, never just an app-layer
    -- convention a race condition could violate.
    company_active_marker  TINYINT GENERATED ALWAYS AS (IF(scope = 'COMPANY' AND is_active = 1, 1, NULL)) STORED,
    category_active_marker INT GENERATED ALWAYS AS (IF(scope = 'CATEGORY' AND is_active = 1, category_id, NULL)) STORED,
    sku_active_marker      INT GENERATED ALWAYS AS (IF(scope = 'SKU' AND is_active = 1, item_id, NULL)) STORED,
    CONSTRAINT fk_dpp_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_dpp_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_dpp_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_dpp_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    UNIQUE KEY uq_dpp_company_active (company_active_marker),
    UNIQUE KEY uq_dpp_category_active (category_active_marker),
    UNIQUE KEY uq_dpp_sku_active (sku_active_marker),
    INDEX idx_dpp_scope (scope)
) ENGINE=InnoDB
COMMENT='PHASE V2.11B — company/category/SKU selling-price policy for SCM -> Bakery distribution. Resolution order: SKU, then CATEGORY, then COMPANY (PricingPolicyService::resolve()).';

CREATE TABLE distribution_invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number      VARCHAR(40) NOT NULL UNIQUE,
    invoice_date        DATE NOT NULL,
    do_id               INT UNSIGNED NOT NULL,
    bakery_destination_id INT UNSIGNED NOT NULL,
    subtotal            DECIMAL(20,4) NOT NULL DEFAULT 0,
    discount_amount     DECIMAL(20,4) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(20,4) NOT NULL DEFAULT 0,
    shipping_amount     DECIMAL(20,4) NOT NULL DEFAULT 0,
    grand_total         DECIMAL(20,4) NOT NULL DEFAULT 0,
    status              ENUM('DRAFT','ISSUED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    created_by          INT UNSIGNED NOT NULL,
    issued_by           INT UNSIGNED NULL,
    issued_at           DATETIME NULL,
    cancelled_by        INT UNSIGNED NULL,
    cancelled_at        DATETIME NULL,
    cancel_reason       VARCHAR(255) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_di_do FOREIGN KEY (do_id) REFERENCES distribution_orders(id),
    CONSTRAINT fk_di_bakery FOREIGN KEY (bakery_destination_id) REFERENCES bakery_destinations(id),
    CONSTRAINT fk_di_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_di_issued_by FOREIGN KEY (issued_by) REFERENCES users(id),
    CONSTRAINT fk_di_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id),
    -- Section 7: "Each DO must be able to generate ONE linked Invoice" —
    -- a real one-to-one, enforced at the DB level.
    UNIQUE KEY uq_di_do (do_id),
    INDEX idx_di_status (status),
    INDEX idx_di_bakery (bakery_destination_id)
) ENGINE=InnoDB
COMMENT='PHASE V2.11B — one Invoice per Delivery Order. Financial snapshot only; never recomputed from a later pricing policy change (Section 22).';

CREATE TABLE distribution_invoice_lines (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT UNSIGNED NOT NULL,
    do_line_id          BIGINT UNSIGNED NOT NULL,   -- traceability back to the exact DO line (and, through it, the real FifoService::postOut() line for Actual HPP)
    line_no             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    item_id             INT UNSIGNED NOT NULL,
    sku_snapshot        VARCHAR(40)  NOT NULL,
    item_name_snapshot  VARCHAR(200) NOT NULL,
    category_id_snapshot INT UNSIGNED NULL,
    qty                 DECIMAL(20,6) NOT NULL,     -- the DO line's real dispatched qty, in its own input_unit_id
    unit_id             INT UNSIGNED NOT NULL,
    -- Full pricing snapshot (Section 12/32) — persisted so a later change
    -- to distribution_pricing_policies (or a later Stock IN changing the
    -- "latest" reference price) can NEVER alter an already-issued invoice.
    reference_purchase_price DECIMAL(20,4) NULL,    -- NULL only if truly no purchase history existed at invoice-creation time (never a fabricated 0)
    pricing_source      ENUM('COMPANY','CATEGORY','SKU') NOT NULL,
    pricing_method      ENUM('AT_COST','COST_PLUS_PERCENT','COST_PLUS_AMOUNT') NOT NULL,
    margin_value        DECIMAL(20,4) NOT NULL DEFAULT 0,
    policy_calculated_price DECIMAL(20,4) NOT NULL, -- what the resolved policy computed, before any admin override
    selling_unit_price  DECIMAL(20,4) NOT NULL,     -- the actual price used on this invoice (== policy_calculated_price unless overridden)
    is_price_overridden TINYINT(1) NOT NULL DEFAULT 0,
    override_reason     VARCHAR(255) NULL,
    override_by         INT UNSIGNED NULL,
    subtotal            DECIMAL(20,4) NOT NULL,     -- qty * selling_unit_price
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dil_invoice FOREIGN KEY (invoice_id) REFERENCES distribution_invoices(id),
    CONSTRAINT fk_dil_do_line FOREIGN KEY (do_line_id) REFERENCES distribution_order_lines(id),
    CONSTRAINT fk_dil_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_dil_category FOREIGN KEY (category_id_snapshot) REFERENCES categories(id),
    CONSTRAINT fk_dil_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    CONSTRAINT fk_dil_override_by FOREIGN KEY (override_by) REFERENCES users(id),
    INDEX idx_dil_invoice (invoice_id),
    INDEX idx_dil_do_line (do_line_id)
) ENGINE=InnoDB
COMMENT='PHASE V2.11B — one row per invoiced item. reference_purchase_price/pricing_source/pricing_method/margin_value/policy_calculated_price are an immutable snapshot at invoice-creation time (Section 12).';

-- PHASE V2.11B: pricing policy management permission. Same additive
-- convention as every earlier phase — ADMIN/SUPERADMIN inherit it
-- automatically (not added to ADMIN's exclusion list); STOCK/DIVISION/
-- VIEWER get neither, per the owner's explicit "STOCK must not gain
-- pricing policy management" instruction (Section 24).
INSERT INTO permissions (code, description) VALUES
    ('DISTRIBUTION_PRICING_MANAGE', 'Manage SCM -> Bakery selling-price policy (company/category/SKU) and issue/override Invoices');

-- Unlike schema.sql's fresh-install seed (a CROSS JOIN that naturally
-- covers a brand-new permission), a migration against an ALREADY-SEEDED
-- database must grant SUPERADMIN explicitly too — it does not retroactively
-- backfill. Same convention as 2026_09_19_v2_1_master_data_management.sql.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'SUPERADMIN' AND p.code = 'DISTRIBUTION_PRICING_MANAGE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN' AND p.code = 'DISTRIBUTION_PRICING_MANAGE';
