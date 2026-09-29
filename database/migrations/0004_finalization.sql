-- =====================================================================
-- GO-LIVE MVP — session finalization schema changes (2026-09-29)
-- =====================================================================

-- 1. stock_opname_finals was created in Phase 2 with only a single
--    final_qty column, before conditions (damaged/expired/deadstock)
--    existed. It has never been written to by any code yet. Finalizing
--    a session needs the full per-condition breakdown, not just one
--    number, so the review UI and Excel export can show each bucket.
--
--    PHYSICAL = GOOD + DAMAGED + EXPIRED + DEADSTOCK (everything counted
--    physically present, whatever its condition).
--    AVAILABLE = GOOD (the sellable quantity — damaged/expired/deadstock
--    are physically present but not sellable, so they are excluded).
--
--    variance_qty/variance_value (existing columns) are reinterpreted to
--    compare AVAILABLE against system_qty_snapshot, not PHYSICAL: the
--    system stock ledger tracks sellable stock, so that is the number a
--    stock-take variance is actually meant to reconcile against.
--    final_qty (existing column) is kept populated as PHYSICAL, for any
--    future reporting that wants "everything that was physically there".
ALTER TABLE stock_opname_finals
    ADD COLUMN final_good_base_qty      DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER version,
    ADD COLUMN final_damaged_base_qty   DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER final_good_base_qty,
    ADD COLUMN final_expired_base_qty   DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER final_damaged_base_qty,
    ADD COLUMN final_deadstock_base_qty DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER final_expired_base_qty,
    ADD COLUMN final_physical_base_qty  DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER final_deadstock_base_qty,
    ADD COLUMN final_available_base_qty DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER final_physical_base_qty,
    ADD COLUMN source                   ENUM('AUTO_MATCH','MANUAL') NOT NULL DEFAULT 'MANUAL' AFTER final_available_base_qty,
    -- variance_value depends on unit_cost_snapshot, which is legitimately
    -- NULL when cost is genuinely unknown (source = NONE) — that must
    -- propagate as NULL here too, never coerced to a fabricated 0.
    MODIFY COLUMN variance_value DECIMAL(18,2) NULL;

-- 2. Session status already has REVIEW/FINISHED in the enum (Phase 3
--    schema), and finished_at/finished_by columns already exist — no
--    session-table change needed. Add a review-transition actor/time
--    pair, mirroring started_by/started_at, so "who moved ACTIVE ->
--    REVIEW and when" is recorded the same way session start already is.
ALTER TABLE stock_opname_sessions
    ADD COLUMN review_started_at DATETIME NULL AFTER started_by,
    ADD COLUMN review_started_by BIGINT UNSIGNED NULL AFTER review_started_at,
    ADD CONSTRAINT fk_session_review_started_by FOREIGN KEY (review_started_by) REFERENCES users(id);
