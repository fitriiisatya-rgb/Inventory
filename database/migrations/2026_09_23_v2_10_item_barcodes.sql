-- ============================================================================
-- Inventory FIFO Pro V2.10 — Transaction UX Upgrade: searchable item
-- selector, auto purchase price, multi-unit barcode support.
-- Phase V2.10
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive. items.barcode (single, legacy, VARCHAR(60), NOT unique)
-- is left completely untouched — still read/written by
-- ImportMasterItemService, StockReportService, master-items.js's
-- openEdit(), and PUT /items/{id}. This migration adds a NEW, separate
-- table for multi-unit barcode mappings (one SKU can have a different
-- barcode per purchase unit, e.g. PCS vs BOX) that coexists alongside the
-- legacy column rather than replacing it.
--
-- No price/master-price column is added anywhere: the auto-fill purchase
-- price feature reads the EXISTING item_price_history table (most recent
-- price_per_unit for the item+unit, already populated by
-- PriceAnomalyService::recordPrice() on every real Stock IN) — see
-- services/ItemPriceService.php. Nothing about item_price_history's shape
-- changes.
-- ============================================================================

CREATE TABLE item_barcodes (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id             INT UNSIGNED NOT NULL,
    unit_id             INT UNSIGNED NULL,     -- NULL = barcode identifies the item regardless of unit; set = barcode is specific to that purchase/base unit
    barcode             VARCHAR(64) NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    -- Generated column trick (same pattern as item_unit_conversions'
    -- open_marker/uq_iuc_one_open_version): MySQL unique indexes allow
    -- unlimited NULLs, so this evaluates to NULL for every inactive row
    -- and to 1 only while a mapping is active — the unique index below
    -- then makes "more than one ACTIVE mapping for the same barcode
    -- value" a real DB constraint (Part C4), not an app-layer convention.
    -- Scoped to the barcode value globally (not per item) because a
    -- barcode must never ambiguously resolve to two different active
    -- items either.
    active_marker       TINYINT GENERATED ALWAYS AS (IF(is_active = 1, 1, NULL)) STORED,
    created_by          INT UNSIGNED NULL,
    updated_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ib_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_ib_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    CONSTRAINT fk_ib_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_ib_updated_by FOREIGN KEY (updated_by) REFERENCES users(id),
    UNIQUE KEY uq_item_barcodes_active (barcode, active_marker),
    INDEX idx_item_barcodes_item (item_id),
    INDEX idx_item_barcodes_barcode (barcode)
) ENGINE=InnoDB
COMMENT='PHASE V2.10 — multi-unit barcode mappings, additive alongside the legacy single items.barcode column. Barcode is a selection mechanism only, never an authorization mechanism (Part I).';

-- Defensive backfill: carry over each item's existing single legacy
-- barcode as an ACTIVE, unit-unspecified (unit_id NULL) mapping, so
-- hardware/camera scanning works immediately for already-catalogued
-- barcodes without requiring the admin to re-enter anything.
--
-- Deliberately skips (does not insert) any barcode value that is
-- currently duplicated across more than one item in the legacy column —
-- items.barcode has never been unique, so real production data may
-- already contain such duplicates. Inserting all of them here would
-- immediately violate uq_item_barcodes_active; skipping them instead
-- leaves those specific items without an auto-migrated mapping (an admin
-- can re-enter the correct one manually via the new Master Barang UI —
-- Part C5), which is strictly safer than guessing which of the
-- duplicates is authoritative.
INSERT INTO item_barcodes (item_id, unit_id, barcode, is_active, created_by, updated_by)
SELECT i.id, NULL, i.barcode, 1, NULL, NULL
FROM items i
WHERE i.barcode IS NOT NULL
  AND i.barcode <> ''
  AND (SELECT COUNT(*) FROM items i2 WHERE i2.barcode = i.barcode) = 1;
