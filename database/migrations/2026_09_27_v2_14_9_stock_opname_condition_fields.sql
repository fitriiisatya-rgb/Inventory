-- ============================================================================
-- Inventory FIFO Pro — V2.14.9 Stock Opname physical-condition classification
-- (Rusak/Expired/Deadstock) + per-counter Keterangan.
--
-- Purely additive: twelve new nullable columns on the existing
-- stock_opname_lines table. Nothing about any existing column, row, FIFO/
-- inventory value, or the finalize()/post() authoritative-adjustment flow
-- is touched. Idempotent-safe for a fresh install (schema.sql already bakes
-- these columns in — this file exists only for a rolling upgrade of an
-- already-deployed database).
--
-- Business semantics implemented (audited: no prior rule existed for how
-- Qty Hitung relates to these classifications, so the "preferred safe
-- model" from the request is what's implemented here — see
-- StockOpnameService::submitCount() for the actual validation):
--   - p1_qty_base / p2_qty_base (existing, UNCHANGED) = total physical
--     quantity found by that counter.
--   - p{1,2}_rusak_qty / p{1,2}_expired_qty / p{1,2}_deadstock_qty = that
--     counter's own classification of a SUBSET of their own counted qty
--     (each independently >= 0, and each <= that counter's own qty_base —
--     enforced in the service layer, not by a DB CHECK, since it depends
--     on multiple columns' current values together).
--   - p{1,2}_notes = that counter's own free-text note (packaging damage,
--     short-dated, misplaced item, etc.) — NEVER shown to the other
--     counter, same blindness contract as p{1,2}_qty_base.
--   - final_rusak_qty / final_expired_qty / final_deadstock_qty are
--     auto-resolved (mirroring how counted_qty_base already resolves for
--     qty) ONLY when P1 and P2 agree on that specific classification;
--     left NULL on disagreement so the supervisor review screen can show
--     "Rusak P1 / Rusak P2" side-by-side rather than silently picking one
--     side. final_notes is never auto-filled from p1_notes/p2_notes.
--
-- These columns are NEVER read by finalize()/post()/StockAdjustmentService
-- — entering a Rusak/Expired/Deadstock quantity has zero effect on
-- inventory. Stock Opname finalization remains driven exclusively by
-- counted_qty_base/variance_qty_base, exactly as before this migration.
-- ============================================================================

ALTER TABLE stock_opname_lines
    ADD COLUMN p1_rusak_qty      DECIMAL(20,6) NULL AFTER p1_submitted_at,
    ADD COLUMN p1_expired_qty    DECIMAL(20,6) NULL AFTER p1_rusak_qty,
    ADD COLUMN p1_deadstock_qty  DECIMAL(20,6) NULL AFTER p1_expired_qty,
    ADD COLUMN p1_notes          VARCHAR(255)  NULL AFTER p1_deadstock_qty,
    ADD COLUMN p2_rusak_qty      DECIMAL(20,6) NULL AFTER p2_submitted_at,
    ADD COLUMN p2_expired_qty    DECIMAL(20,6) NULL AFTER p2_rusak_qty,
    ADD COLUMN p2_deadstock_qty  DECIMAL(20,6) NULL AFTER p2_expired_qty,
    ADD COLUMN p2_notes          VARCHAR(255)  NULL AFTER p2_deadstock_qty,
    -- Resolved-agreement values (Section: "supervisor must be able to see
    -- disagreements" — these are NEVER auto-copied from one side when the
    -- two disagree; they stay NULL until both sides genuinely match).
    ADD COLUMN final_rusak_qty     DECIMAL(20,6) NULL,
    ADD COLUMN final_expired_qty   DECIMAL(20,6) NULL,
    ADD COLUMN final_deadstock_qty DECIMAL(20,6) NULL,
    ADD COLUMN final_notes         VARCHAR(255)  NULL;
