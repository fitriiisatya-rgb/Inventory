-- =====================================================================
-- PHASE 4 CLOSURE + PHASE 5 EVIDENCE — schema changes (design review, 2026-09-29)
-- =====================================================================

-- 1. Cost can now be genuinely absent, not just zero. Rp0 must mean "a
--    real recorded price of zero", never "we don't know". Both places
--    that ultimately feed unit_cost_snapshot must allow NULL.
ALTER TABLE items
    MODIFY COLUMN last_buy_price DECIMAL(18,2) NULL DEFAULT NULL;

ALTER TABLE item_stock
    MODIFY COLUMN unit_cost DECIMAL(18,2) NULL DEFAULT NULL;

-- 2. Explicit provenance for whichever cost value a stock_import_rows
--    row ended up with, resolved once at import time (never silently
--    re-resolved later against a possibly-drifted Master price).
ALTER TABLE stock_import_rows
    ADD COLUMN unit_cost_source ENUM('IMPORT','MASTER_LAST_BUY_PRICE','NONE') NULL AFTER parsed_unit_cost;

-- 3. Same provenance carried into the session snapshot, plus qty
--    snapshot column stays NOT NULL — missing QTY blocks session start
--    entirely (see SessionService::preflight), it never reaches here.
--    Cost, however, may legitimately be NULL (source = NONE).
ALTER TABLE stock_opname_session_items
    MODIFY COLUMN unit_cost_snapshot DECIMAL(18,2) NULL,
    ADD COLUMN unit_cost_source ENUM('IMPORT','MASTER_LAST_BUY_PRICE','NONE') NOT NULL DEFAULT 'NONE' AFTER unit_cost_snapshot;

-- 4. Active-session reassignment (design review points 7-11): a user
--    can now hold multiple historical rows in the same session (old
--    team REMOVED, new team ACTIVE) so team changes are never a silent
--    UPDATE of an existing row — always remove-then-add, both audited.
--    The "only one ACTIVE row per user per session" invariant is
--    application-enforced (SessionService), the same trade-off already
--    accepted for stock_opname_finals.is_current.
ALTER TABLE stock_opname_session_counters
    DROP INDEX uq_session_counter_user,
    ADD COLUMN assigned_reason VARCHAR(255) NULL AFTER assigned_at,
    ADD COLUMN removed_by BIGINT UNSIGNED NULL AFTER assigned_reason,
    ADD COLUMN removed_at DATETIME NULL AFTER removed_by,
    ADD COLUMN removed_reason VARCHAR(255) NULL AFTER removed_at,
    ADD KEY idx_session_counter_user (session_id, user_id, status),
    ADD CONSTRAINT fk_sc_removed_by FOREIGN KEY (removed_by) REFERENCES users(id);

-- 5. A count is not "done" just because it was saved — if any condition
--    with qty > 0 lacks its required photo evidence, it stays
--    EVIDENCE_REQUIRED and is excluded from reconciliation.
ALTER TABLE stock_opname_counts
    ADD COLUMN evidence_status ENUM('COMPLETE','EVIDENCE_REQUIRED') NOT NULL DEFAULT 'COMPLETE' AFTER physical_base_qty;

-- 6. Photos are never hard-deleted once a count is COMPLETE — an edit
--    that zeroes out a previously-evidenced condition marks that
--    condition's photos SUPERSEDED (kept, labeled historical) rather
--    than deleting them. Pre-COMPLETE deletes by the uploader remain a
--    real DELETE (see PhotoEvidenceService) since nothing official has
--    been finalized around them yet.
ALTER TABLE stock_opname_photos
    ADD COLUMN status ENUM('ACTIVE','SUPERSEDED') NOT NULL DEFAULT 'ACTIVE' AFTER file_path,
    ADD COLUMN superseded_at DATETIME NULL,
    ADD COLUMN superseded_reason VARCHAR(255) NULL;
