-- ============================================================================
-- Inventory FIFO Pro — V2.14.10 Multi-Counter P1/P2 Team Stock Opname.
--
-- Replaces the "P1 = exactly one user" assumption with "P1 = a team of one
-- or more users", without touching a single existing column's meaning.
--
-- NEW TABLE: stock_opname_team_members — normalized team membership, one
-- row per (session, team_role, user_id). A session with NO rows in this
-- table is a pre-V2.14.10 (or not-yet-team-assigned) session; the service
-- layer synthesizes a one-member team from the legacy
-- stock_opname_sessions.p1_user_id/p2_user_id columns for exactly that
-- case (see StockOpnameService::getTeamMembers()) — this migration never
-- back-fills a single row for old sessions, so no historical audit data is
-- rewritten.
--
-- NEW COLUMNS on stock_opname_lines: p{1,2}_claimed_by_user_id /
-- p{1,2}_claimed_at — a short-lived, per-team, per-line claim/lease so two
-- members of the SAME team cannot simultaneously start counting the same
-- SKU (StockOpnameService::claimItem()'s compare-and-swap UPDATE). A claim
-- is cleared the moment that side's count is actually submitted, and
-- expires automatically after the service's claim-lease window even if
-- never explicitly released — no separate "who counted this" column is
-- added, because the existing p1_user_id/p2_user_id on this table already
-- record exactly that identity once a count is submitted (unchanged
-- meaning, now simply also serving as the "counted by" audit field a team
-- of more than one person needs).
--
-- Idempotent throughout (ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT
-- EXISTS, MariaDB 10.0.2+, this environment runs 10.11.14) — verified via
-- the same forward -> forward -> rollback -> rollback proof used for every
-- prior Stock Opname migration this cycle.
--
-- NOT touched: stock_opname_sessions.p1_user_id/p2_user_id (legacy single-
-- assignment columns — assignCounters() still writes them, still fully
-- supported), every V2.14.9/.1/.2/.3 column and behavior (condition
-- fields, explicit-zero HTTP gate, condition resolution, finalize guard),
-- inventory_batches, FIFO, costing, cutover. Zero effect on any already-
-- posted or in-flight adjustment.
-- ============================================================================

CREATE TABLE IF NOT EXISTS stock_opname_team_members (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id    INT UNSIGNED NOT NULL,
    team_role     ENUM('P1','P2') NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    assigned_by   INT UNSIGNED NOT NULL,
    assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_by    INT UNSIGNED NULL,
    removed_at    DATETIME NULL,
    -- 1 = currently on the team; 0 = removed (row kept for audit — a
    -- member who counted some lines before removal must still be
    -- traceable, so this is never a hard DELETE).
    active        TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_sotm_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sotm_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sotm_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id),
    CONSTRAINT fk_sotm_removed_by FOREIGN KEY (removed_by) REFERENCES users(id),
    -- A given user can only have ONE row per (session, team_role) — a
    -- second "add" call for an already-active member is a no-op, not a
    -- duplicate row. Same-user-on-both-roles is rejected by the service
    -- layer (see StockOpnameService::assignTeamMembers()), not by a DB
    -- constraint here, because that rule must still permit "remove from
    -- P1, later add to P2" (a legitimate reassignment) without deleting
    -- the P1 history row a hard uniqueness-across-roles constraint would
    -- otherwise force.
    UNIQUE KEY uq_sotm_session_role_user (session_id, team_role, user_id),
    INDEX idx_sotm_session_active (session_id, active)
) ENGINE=InnoDB;

ALTER TABLE stock_opname_lines
    ADD COLUMN IF NOT EXISTS p1_claimed_by_user_id INT UNSIGNED NULL AFTER p1_notes,
    ADD COLUMN IF NOT EXISTS p1_claimed_at          DATETIME NULL      AFTER p1_claimed_by_user_id,
    ADD COLUMN IF NOT EXISTS p2_claimed_by_user_id INT UNSIGNED NULL AFTER p2_notes,
    ADD COLUMN IF NOT EXISTS p2_claimed_at          DATETIME NULL      AFTER p2_claimed_by_user_id;
