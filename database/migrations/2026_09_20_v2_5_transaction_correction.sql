-- ============================================================================
-- Inventory FIFO Pro V2.5 — Transaction Correction / Void / Transfer Reversal
-- Phase V2.5 (docs/PHASE_V2_5_TRANSACTION_CORRECTION.md)
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive:
--   1. warehouse_transfers.status enum gains 'REVERSED' (existing values
--      PENDING/RECEIVED/CANCELLED are untouched — a MODIFY COLUMN that only
--      widens an ENUM never rewrites existing rows' values).
--   2. warehouse_transfers gains reverse_reason/reversed_by/reversed_at/
--      reverse_request_uuid — exactly symmetric with the pre-existing
--      cancel_reason/cancelled_by/cancelled_at/cancel_request_uuid columns,
--      all nullable, all NULL on every pre-existing row.
--   3. One new permission, TRANSFER_REVERSE, granted only to SUPERADMIN and
--      ADMIN (same pattern as every other privileged-correction permission
--      in this schema — see TRANSACTION_VOID). STOCK never gets it: a
--      RECEIVED transfer can only be reversed by a privileged role, per the
--      V2.5 spec's permission matrix.
--
-- No existing column is renamed, retyped incompatibly, or dropped. No
-- existing row's data is modified. inventory_transactions.status already
-- has 'REVERSED' from the original schema (used by TransferService::cancel()
-- for the source-side leg) — nothing to add there.
-- ============================================================================

ALTER TABLE warehouse_transfers
    MODIFY COLUMN status ENUM('PENDING','RECEIVED','CANCELLED','REVERSED') NOT NULL DEFAULT 'PENDING';

ALTER TABLE warehouse_transfers
    ADD COLUMN reverse_reason VARCHAR(255) NULL AFTER cancelled_at,
    ADD COLUMN reversed_by INT UNSIGNED NULL AFTER reverse_reason,
    ADD COLUMN reversed_at DATETIME NULL AFTER reversed_by,
    ADD COLUMN reverse_request_uuid VARCHAR(100) NULL AFTER cancel_request_uuid;

ALTER TABLE warehouse_transfers
    ADD CONSTRAINT fk_wt_reverser FOREIGN KEY (reversed_by) REFERENCES users(id);

INSERT INTO permissions (code, description) VALUES
    ('TRANSFER_REVERSE', 'Reverse a RECEIVED warehouse transfer (whole TRANSFER_OUT/TRANSFER_IN chain) — privileged correction action')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'SUPERADMIN' AND p.code = 'TRANSFER_REVERSE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'ADMIN' AND p.code = 'TRANSFER_REVERSE';

-- STOCK/DIVISION/VIEWER intentionally get neither permission.
