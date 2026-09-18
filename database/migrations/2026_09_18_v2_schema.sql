-- ============================================================================
-- Inventory FIFO Pro V2 — schema migration
-- Phase 3b (Technical Design: docs/PHASE_V2_TECHNICAL_DESIGN.md Section 1/13)
--
-- DO NOT RUN THIS AGAINST PRODUCTION. This file is staged for owner-approved
-- execution only, exactly like docs/PRODUCTION_CUTOVER_RUNBOOK.md's schema
-- step — run scripts/v2_schema_precheck.php first, then this file, then
-- scripts/v2_schema_postcheck.php, against a local/staging database.
--
-- Every change here is ADDITIVE: no existing column is dropped, renamed, or
-- narrowed; no existing table loses a row. See
-- database/migrations/2026_09_18_v2_schema_rollback.sql for the exact
-- inverse, and docs/PHASE_V2_SCHEMA_IMPACT.md for the full before/after
-- analysis including the bakery_destination_id CHECK-constraint decision.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. categories
-- ----------------------------------------------------------------------------
CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(60)  NOT NULL UNIQUE,
    name        VARCHAR(100) NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
COMMENT='Phase V2: normalized item category master. items.category (free text) is preserved unchanged as historical provenance — never dropped.';

-- ----------------------------------------------------------------------------
-- 2. items.category_id (additive column, nullable — an item may be
--    uncategorized rather than forced into a guessed category)
-- ----------------------------------------------------------------------------
ALTER TABLE items
    ADD COLUMN category_id INT UNSIGNED NULL AFTER category,
    ADD CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id),
    ADD INDEX idx_items_category (category_id),
    ADD INDEX idx_items_name (name);

-- ----------------------------------------------------------------------------
-- 3. item_warehouse_stock_policy
-- ----------------------------------------------------------------------------
CREATE TABLE item_warehouse_stock_policy (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id         INT UNSIGNED NOT NULL,
    warehouse_id    INT UNSIGNED NOT NULL,
    minimum_stock_base DECIMAL(20,6) NOT NULL DEFAULT 0,
    buffer_stock_base  DECIMAL(20,6) NULL,     -- NULL = not configured; never invented, see StockPolicyService
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    notes           VARCHAR(255) NULL,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_iwsp_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_iwsp_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT fk_iwsp_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_iwsp_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    UNIQUE KEY uq_iwsp_item_wh (item_id, warehouse_id)
) ENGINE=InnoDB
COMMENT='Phase V2: per-item-per-warehouse minimum/buffer. A missing row means "use items.minimum_stock as fallback, buffer unset" — see StockPolicyService::resolve(). items.minimum_stock is kept unchanged as the fallback source, never repurposed.';

-- ----------------------------------------------------------------------------
-- 4. bakery_destinations
-- ----------------------------------------------------------------------------
CREATE TABLE bakery_destinations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(30)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    address     VARCHAR(255) NULL,
    city_area   VARCHAR(100) NULL,
    pic_name    VARCHAR(100) NULL,
    phone       VARCHAR(30)  NULL,
    route_cluster VARCHAR(100) NULL,
    notes       VARCHAR(255) NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
COMMENT='Phase V2: external distribution endpoint for OUT transactions. Deliberately separate from warehouses (internal stock location) and divisions (internal cost center) — see docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5.';

-- ----------------------------------------------------------------------------
-- 5. inventory_transactions.bakery_destination_id (additive column)
--
-- CHECK-constraint decision (owner asked this be evaluated, not assumed —
-- full investigation in docs/PHASE_V2_SCHEMA_IMPACT.md Section 3):
-- transaction_type is one of IN/OUT/TRANSFER_OUT/TRANSFER_IN/ADJUSTMENT/
-- OPNAME/PRODUCTION_IN/PRODUCTION_OUT/OPENING/REVERSAL. "A real OUT
-- transaction" (the owner's phrase) means transaction_type='OUT'
-- specifically — TRANSFER_OUT and PRODUCTION_IN also flow through
-- FifoService::postOut() but represent a warehouse transfer and a
-- production-input consumption, not a distribution to an external bakery,
-- so they must NOT carry a bakery_destination_id either.
-- Verified safe to enforce at the DB level because:
--   (a) the column is new and nullable, so every existing row already
--       satisfies "bakery_destination_id IS NULL" regardless of type —
--       zero pre-existing data can violate this constraint;
--   (b) inventory_transactions rows are never UPDATEd after posting except
--       the status/void_reason/voided_by/voided_at columns (VoidService) —
--       transaction_type and bakery_destination_id are set once at INSERT
--       and never change, so there is no later-mutation risk to re-check;
--   (c) services/VoidService.php's REVERSAL insert (line ~77-80 as of this
--       migration) explicitly does NOT copy division_id/supplier_id onto
--       the reversal row, and will not copy bakery_destination_id either —
--       a REVERSAL row always inserts with bakery_destination_id NULL,
--       which trivially satisfies the constraint;
--   (d) MariaDB 10.4+ and MySQL 8.0.16+ (this project's stated targets)
--       both parse AND enforce CHECK constraints (unlike older MariaDB
--       10.2.0 or MySQL <8.0.16, which silently ignore them) — safe to rely
--       on here.
-- Conclusion: INCLUDED. If a future change ever needs FifoService::postOut()
-- to accept bakery_destination_id for TRANSFER_OUT/PRODUCTION_IN too, this
-- constraint must be dropped/widened deliberately as its own reviewed change
-- — it is not something a caller can quietly work around.
-- ----------------------------------------------------------------------------
ALTER TABLE inventory_transactions
    ADD COLUMN bakery_destination_id INT UNSIGNED NULL AFTER division_id,
    ADD CONSTRAINT fk_tx_bakery_destination FOREIGN KEY (bakery_destination_id) REFERENCES bakery_destinations(id),
    ADD INDEX idx_tx_bakery_destination (bakery_destination_id),
    ADD CONSTRAINT chk_tx_bakery_destination_out_only
        CHECK (bakery_destination_id IS NULL OR transaction_type = 'OUT');

-- ----------------------------------------------------------------------------
-- 6. suppliers — additive columns only (audit: code/name/contact_name/
--    phone/notes/is_active already exist; only address+email were missing)
-- ----------------------------------------------------------------------------
ALTER TABLE suppliers
    ADD COLUMN address VARCHAR(255) NULL AFTER contact_name,
    ADD COLUMN email    VARCHAR(150) NULL AFTER phone;
