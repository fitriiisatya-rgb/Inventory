-- ============================================================================
-- Rollback for V2.14.10 Multi-Counter P1/P2 Team Stock Opname.
--
-- Removes ONLY the new team-membership table and the 4 new claim columns.
-- Every existing session's p1_user_id/p2_user_id, every qty/condition
-- column, match_status, variance, adjustment linkage — all untouched. A
-- session that had already been assigned a multi-member team loses that
-- team's membership rows (falls back to whatever legacy p1_user_id/
-- p2_user_id happen to hold, per StockOpnameService::getTeamMembers()'s
-- synthetic-fallback rule) — counts already submitted are NOT lost, since
-- they live on stock_opname_lines, not on the team-membership table.
--
-- Idempotent: DROP TABLE IF EXISTS / DROP COLUMN IF EXISTS. Verified via
-- the same forward -> forward -> rollback -> rollback proof as every prior
-- Stock Opname migration this cycle.
-- ============================================================================

ALTER TABLE stock_opname_lines
    DROP COLUMN IF EXISTS p1_claimed_by_user_id,
    DROP COLUMN IF EXISTS p1_claimed_at,
    DROP COLUMN IF EXISTS p2_claimed_by_user_id,
    DROP COLUMN IF EXISTS p2_claimed_at;

DROP TABLE IF EXISTS stock_opname_team_members;
