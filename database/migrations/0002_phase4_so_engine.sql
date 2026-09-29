-- =====================================================================
-- PHASE 4 — SO ENGINE schema changes (design review corrections, 2026-09-29)
-- =====================================================================

-- 1. Remove EXCLUDED (no approved business rule/workflow for it). If a
--    future phase needs it, re-add deliberately with its own migration —
--    do not resurrect it silently.
ALTER TABLE stock_opname_session_items
    MODIFY COLUMN item_status ENUM('NORMAL','NOT_COUNTABLE') NOT NULL DEFAULT 'NORMAL';

-- 2. Which round is currently open for counting on this item. Bumped
--    only by ReconciliationService::requestRecount() (SUPERADMIN-only).
--    Old rounds are never touched once superseded.
ALTER TABLE stock_opname_session_items
    ADD COLUMN current_round INT UNSIGNED NOT NULL DEFAULT 1 AFTER not_countable_set_at;

-- 3. Explicit, immutable-after-ACTIVE traceability: which committed
--    stock_import_batch the session's system_qty snapshot reflects.
--    NULL while DRAFT (chosen automatically at session creation as the
--    latest COMMITTED batch for the session's location; see
--    SessionService::createSession()).
ALTER TABLE stock_opname_sessions
    ADD COLUMN system_stock_batch_id BIGINT UNSIGNED NULL AFTER location_id,
    ADD CONSTRAINT fk_session_stock_batch FOREIGN KEY (system_stock_batch_id) REFERENCES stock_import_batches(id);

-- 4. Replace stock_opname_assignments (unused by any Phase 3 code — safe
--    to drop and recreate rather than ALTER) with the corrected shape:
--    a user can hold only one team slot per session (UNIQUE session+user,
--    not session+user+team), plus assigned_by and a soft-remove status
--    so history survives an unassign.
DROP TABLE IF EXISTS stock_opname_assignments;

CREATE TABLE stock_opname_session_counters (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id   BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    team         ENUM('P1','P2') NOT NULL,
    status       ENUM('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
    assigned_by  BIGINT UNSIGNED NOT NULL,
    assigned_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_counter_user (session_id, user_id),
    KEY idx_session_counter_session (session_id, team, status),
    CONSTRAINT fk_sc_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sc_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sc_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;
