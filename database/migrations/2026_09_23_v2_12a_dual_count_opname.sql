-- ============================================================================
-- Inventory FIFO Pro V2.12A — Dual Count Stock Opname (schema + P1/P2 blind
-- count foundation). Phase 1 of 3 (A = schema/session/blind-count,
-- B = comparison/recount/supervisor finalize, C = post/report/print).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive: every new column on stock_opname_sessions/
-- stock_opname_lines is NULLable (or has a safe DEFAULT), so every existing
-- row from the legacy single-count workflow stays exactly as it was and
-- remains fully readable (StockOpnameReportService, TraceService::
-- opnameTrace, the print/CSV export added in V2.12C all just see NULLs for
-- the new dual-count columns on old sessions). No existing row's status,
-- counted_qty_base, variance_qty_base, adjustment_id, or any FIFO/inventory
-- value is touched by this migration. The legacy `stock_opname_lines.
-- counted_qty_base` / `is_counted` / `variance_qty_base` columns keep their
-- exact original meaning and are still the ONLY thing StockOpnameService::
-- finalize()/post() read to compute variance and post the real FIFO
-- adjustment (Part 13/"do not invent new HPP logic") — the new P1/P2/
-- recount columns are populated by the new StockOpnameService::submitCount()
-- and StockOpnameService::recount() methods, which RESOLVE the agreed
-- physical quantity into the existing counted_qty_base column once a line
-- reaches MATCH or RECOUNTED, so the legacy finalize/post code path runs
-- completely unmodified in its actual arithmetic.
-- ============================================================================

-- ---- stock_opname_sessions: session number, scope, P1/P2/supervisor ----
ALTER TABLE stock_opname_sessions
    ADD COLUMN session_number VARCHAR(30) NULL AFTER session_uuid,
    ADD COLUMN scope ENUM('ALL_ACTIVE_STOCK','SELECTED_ITEMS') NOT NULL DEFAULT 'ALL_ACTIVE_STOCK' AFTER session_date,
    ADD COLUMN p1_user_id INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN p2_user_id INT UNSIGNED NULL AFTER p1_user_id,
    ADD COLUMN supervisor_id INT UNSIGNED NULL AFTER p2_user_id,
    ADD UNIQUE KEY uq_sos_session_number (session_number),
    ADD CONSTRAINT fk_sos_p1_user FOREIGN KEY (p1_user_id) REFERENCES users(id),
    ADD CONSTRAINT fk_sos_p2_user FOREIGN KEY (p2_user_id) REFERENCES users(id),
    ADD CONSTRAINT fk_sos_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id),
    -- Backend-enforced rule (Section 3, "do not rely only on frontend")
    -- doubled at the DB layer: once both are assigned they can never be the
    -- same user, whatever path a future code change takes.
    ADD CONSTRAINT chk_sos_p1_p2_different CHECK (p1_user_id IS NULL OR p2_user_id IS NULL OR p1_user_id <> p2_user_id);

-- ---- stock_opname_lines: independent P1/P2 blind entries + recount ----
ALTER TABLE stock_opname_lines
    ADD COLUMN p1_qty_base DECIMAL(20,6) NULL AFTER system_qty_base,
    ADD COLUMN p1_user_id INT UNSIGNED NULL AFTER p1_qty_base,
    ADD COLUMN p1_submitted_at DATETIME NULL AFTER p1_user_id,
    ADD COLUMN p2_qty_base DECIMAL(20,6) NULL AFTER p1_submitted_at,
    ADD COLUMN p2_user_id INT UNSIGNED NULL AFTER p2_qty_base,
    ADD COLUMN p2_submitted_at DATETIME NULL AFTER p2_user_id,
    ADD COLUMN recount_qty_base DECIMAL(20,6) NULL AFTER p2_submitted_at,
    ADD COLUMN recount_user_id INT UNSIGNED NULL AFTER recount_qty_base,
    ADD COLUMN recount_submitted_at DATETIME NULL AFTER recount_user_id,
    ADD COLUMN recount_reason VARCHAR(255) NULL AFTER recount_submitted_at,
    -- PENDING: not yet both counted. MATCH: P1==P2. MISMATCH: P1!=P2,
    -- awaiting recount. RECOUNTED: mismatch resolved by a supervisor/
    -- authorized recount. EXCLUDED: supervisor explicitly excused this line
    -- from blocking finalize (Section 10), reason required in `notes`.
    ADD COLUMN match_status ENUM('PENDING','MATCH','MISMATCH','RECOUNTED','EXCLUDED') NOT NULL DEFAULT 'PENDING' AFTER counted_qty_base,
    ADD COLUMN is_excluded TINYINT(1) NOT NULL DEFAULT 0 AFTER match_status,
    ADD COLUMN excluded_by INT UNSIGNED NULL AFTER is_excluded,
    ADD COLUMN excluded_at DATETIME NULL AFTER excluded_by,
    ADD CONSTRAINT fk_sol_p1_user FOREIGN KEY (p1_user_id) REFERENCES users(id),
    ADD CONSTRAINT fk_sol_p2_user FOREIGN KEY (p2_user_id) REFERENCES users(id),
    ADD CONSTRAINT fk_sol_recount_user FOREIGN KEY (recount_user_id) REFERENCES users(id),
    ADD CONSTRAINT fk_sol_excluded_by FOREIGN KEY (excluded_by) REFERENCES users(id),
    ADD INDEX idx_sol_match_status (session_id, match_status);

-- ---- new supervisory permission (operational STOCK_OPNAME_MANAGE stays
-- as-is: open session, assign P1/P2, submit P1/P2/recount counts;
-- STOCK_OPNAME_SUPERVISE is the new higher-trust tier: view the
-- comparison/review dashboard, exclude an uncounted item, finalize, post,
-- cancel — same operational/supervisory split already established for
-- DISTRIBUTION_DISPATCH vs DISTRIBUTION_APPROVE/RECEIVE in V2.11A) ----
INSERT INTO permissions (code, description) VALUES
    ('STOCK_OPNAME_SUPERVISE', 'Review dual-count comparison, exclude uncounted items, finalize and post stock opname sessions');

-- Same convention as every earlier permission-adding migration in this
-- project: schema.sql's fresh-install CROSS JOIN naturally covers
-- SUPERADMIN, but an existing, already-seeded database needs it granted
-- explicitly here. ADMIN gets every permission except the explicit
-- exclusion list in schema.sql, so it also needs the explicit grant here
-- for an already-seeded database (schema.sql's own ADMIN grant only runs
-- once, at initial install).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'SUPERADMIN' AND p.code = 'STOCK_OPNAME_SUPERVISE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN' AND p.code = 'STOCK_OPNAME_SUPERVISE';
