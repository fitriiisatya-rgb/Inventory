-- ============================================================================
-- Rollback for database/migrations/2026_09_23_v2_12a_dual_count_opname.sql
--
-- Purely additive forward migration -> purely subtractive rollback. Drops
-- only the columns/constraints/permission this migration added; every
-- pre-existing stock_opname_sessions/stock_opname_lines column (including
-- the legacy counted_qty_base/variance_qty_base/adjustment_id the original
-- single-count finalize/post logic depends on) is untouched.
--
-- Only safe to run while no session has actually used the dual-count
-- columns for a session still in progress — check first:
--   SELECT COUNT(*) FROM stock_opname_sessions WHERE p1_user_id IS NOT NULL AND status NOT IN ('POSTED','CANCELLED');
-- A non-zero count means an in-progress dual-count session would lose its
-- P1/P2/recount data (its legacy counted_qty_base/status are unaffected,
-- but the session would silently look like a never-started legacy session).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

ALTER TABLE stock_opname_lines
    DROP FOREIGN KEY fk_sol_p1_user,
    DROP FOREIGN KEY fk_sol_p2_user,
    DROP FOREIGN KEY fk_sol_recount_user,
    DROP FOREIGN KEY fk_sol_excluded_by,
    DROP INDEX idx_sol_match_status,
    DROP COLUMN p1_qty_base,
    DROP COLUMN p1_user_id,
    DROP COLUMN p1_submitted_at,
    DROP COLUMN p2_qty_base,
    DROP COLUMN p2_user_id,
    DROP COLUMN p2_submitted_at,
    DROP COLUMN recount_qty_base,
    DROP COLUMN recount_user_id,
    DROP COLUMN recount_submitted_at,
    DROP COLUMN recount_reason,
    DROP COLUMN match_status,
    DROP COLUMN is_excluded,
    DROP COLUMN excluded_by,
    DROP COLUMN excluded_at;

ALTER TABLE stock_opname_sessions
    DROP FOREIGN KEY fk_sos_p1_user,
    DROP FOREIGN KEY fk_sos_p2_user,
    DROP FOREIGN KEY fk_sos_supervisor,
    DROP CONSTRAINT chk_sos_p1_p2_different,
    DROP INDEX uq_sos_session_number,
    DROP COLUMN session_number,
    DROP COLUMN scope,
    DROP COLUMN p1_user_id,
    DROP COLUMN p2_user_id,
    DROP COLUMN supervisor_id;

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'STOCK_OPNAME_SUPERVISE';

DELETE FROM permissions WHERE code = 'STOCK_OPNAME_SUPERVISE';
