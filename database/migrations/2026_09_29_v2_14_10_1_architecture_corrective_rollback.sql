-- V2.14.10.1 ARCHITECTURE SAFETY CORRECTIVE — rollback
-- Idempotent (IF EXISTS throughout).
--
-- NOTE ON THE GATE 9 INDEXES: idx_sotm_session_role_active (on
-- stock_opname_team_members) and idx_sof_session_role_void /
-- idx_sof_counter_created (on stock_opname_findings) are deliberately NOT
-- dropped here with an explicit ALTER TABLE ... DROP INDEX. Both tables
-- are entirely DROPPED by the ORIGINAL V2.14.10 rollback scripts
-- (2026_09_28_v2_14_10_multi_counter_teams_rollback.sql and
-- 2026_09_28_v2_14_10_opname_findings_rollback.sql respectively), which
-- removes those indexes along with the table — attempting to DROP INDEX
-- first, while the table (and its still-live FOREIGN KEY on session_id)
-- exists, was tried and fails with MariaDB error 1553 ("needed in a
-- foreign key constraint"): idx_sof_session_role_void's leftmost column
-- (session_id) is the only index left supporting stock_opname_findings'
-- FK to stock_opname_sessions once this index exists, so it cannot be
-- dropped out from under that FK — only dropping the whole table (which
-- the other rollback script does) removes it safely.

DROP TABLE IF EXISTS stock_opname_line_units;

ALTER TABLE stock_opname_lines
    DROP COLUMN IF EXISTS p2_claim_token,
    DROP COLUMN IF EXISTS p1_claim_token;

ALTER TABLE stock_opname_sessions
    DROP COLUMN IF EXISTS counting_model;
