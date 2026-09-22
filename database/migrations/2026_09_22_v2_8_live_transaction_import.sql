-- ============================================================================
-- Inventory FIFO Pro V2.8 — Live Transaction Import (Excel/CSV IN & OUT)
-- Phase V2.8
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive to the EXISTING generic import_batches table (no new
-- table): widens import_type to accept 'LIVE_TRANSACTION' and adds
-- source_file_hash for file-level duplicate-upload detection (V2.8.6
-- idempotency requirement). import_rows, inventory_transactions,
-- inventory_transaction_lines, inventory_batches, fifo_allocations,
-- purchase_invoice_headers, purchase_line_costs are all reused completely
-- unmodified — ImportLiveTransactionService posts through the real,
-- unmodified FifoService::postIn()/postOut() (and, for IN rows, the real
-- unmodified V2.7 PurchaseCostingService), so a live-imported row is
-- indistinguishable in the ledger from one posted through the manual
-- Transaksi Masuk/Keluar wizard.
-- ============================================================================

ALTER TABLE import_batches
    MODIFY COLUMN import_type ENUM('MASTER_ITEM','SUPPLIER','DIVISION','WAREHOUSE',
                                    'OPENING_STOCK','HISTORICAL_TRANSACTION','LIVE_TRANSACTION') NOT NULL,
    ADD COLUMN source_file_hash VARCHAR(64) NULL AFTER file_name;

-- Fast "has this exact file already been committed as this import type"
-- lookup — the stage-time half of the two-layer idempotency design (the
-- other half is import_batches.status itself, checked at commit time).
ALTER TABLE import_batches
    ADD INDEX idx_ib_type_hash (import_type, source_file_hash);
