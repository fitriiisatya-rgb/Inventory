-- =====================================================================
-- GO-LIVE MVP — legacy (localStorage app) data migration (2026-09-29)
-- =====================================================================

-- 1. Traceability: every item/stock row must be able to say whether it
--    came from normal manual entry or from the legacy migration tool —
--    required so a Session SO started tomorrow can show where its
--    system stock snapshot actually came from.
ALTER TABLE items
    ADD COLUMN migration_source ENUM('MANUAL','LEGACY') NOT NULL DEFAULT 'MANUAL' AFTER note;

ALTER TABLE stock_import_batches
    ADD COLUMN source_type ENUM('MANUAL','LEGACY') NOT NULL DEFAULT 'MANUAL' AFTER file_name;

-- 2. One row per migration run (Master or Stock), holding the aggregate
--    counts the go-live report needs and a link to whichever
--    stock_import_batches row a STOCK migration actually committed
--    through (legacy stock always flows through the existing, already
--    audited StockImportService pipeline — never a parallel writer).
CREATE TABLE legacy_migrations (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type                    ENUM('MASTER','STOCK') NOT NULL,
    source_file             VARCHAR(255) NOT NULL,
    imported_by             BIGINT UNSIGNED NOT NULL,
    imported_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_rows              INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows              INT UNSIGNED NOT NULL DEFAULT 0,
    warning_rows            INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_rows            INT UNSIGNED NOT NULL DEFAULT 0,
    committed_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    detail                  JSON NULL,   -- free-form: per-location batch ids, category auto-create list, etc.
    KEY idx_legacy_migration_type (type),
    CONSTRAINT fk_legacy_migration_user FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB;
