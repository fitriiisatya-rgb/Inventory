-- ============================================================================
-- Inventory Pro — reporting EFFECTIVE DATE of inventory transactions (period cutoff).
--
-- Purely additive: ONE new table, no existing table / row / column is touched, nothing is backfilled.
-- inventory_transactions.transaction_date, posting_date and created_at stay exactly as the posting workflow wrote them (audit trail).
-- A row here says "this posted transaction belongs to an EARLIER reporting period" (e.g. Stock Opname sessions whose inventory
-- cutoff is 30 Sep 2026 but which were posted in October). Reports read COALESCE(effective_at, transaction_date); with the table empty
-- or absent every report is unchanged. Rows are written ONLY by scripts/rv3/period_cutoff.php (dry-run by default, SHA256 bound).
--
-- Idempotent (CREATE TABLE IF NOT EXISTS). Rollback: 2026_10_07_inventory_effective_dates_rollback.sql
-- ============================================================================
CREATE TABLE IF NOT EXISTS inventory_effective_dates (
    transaction_id            BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    effective_at              DATETIME NOT NULL,                      -- the instant the transaction counts for in period reports
    original_transaction_date DATETIME NOT NULL,                      -- inventory_transactions.transaction_date when this row was written (proof nothing was edited)
    original_posting_date     DATETIME NULL,                          -- inventory_transactions.posting_date when this row was written
    source_type               VARCHAR(40)  NOT NULL,                  -- STOCK_OPNAME_SESSION
    source_id                 BIGINT UNSIGNED NOT NULL,               -- e.g. stock_opname_sessions.id
    source_ref                VARCHAR(100) NULL,                      -- e.g. the session number
    reason                    VARCHAR(255) NOT NULL,
    created_by                INT UNSIGNED NULL,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ied_source (source_type, source_id),
    INDEX idx_ied_effective (effective_at)
) ENGINE=InnoDB
COMMENT='Reporting effective date override for inventory transactions (period cutoff). Never edits inventory_transactions.';
