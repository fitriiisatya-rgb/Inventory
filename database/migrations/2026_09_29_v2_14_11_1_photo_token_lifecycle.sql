-- V2.14.11.1 — CHECKPOINT A AUDIT CORRECTIVE: explicit pending-photo
-- ownership token. Baseline: a99e185 (V2.14.11), itself layered on
-- 731244d (V2.14.9.3, production). Idempotent throughout (MariaDB 10.11
-- IF NOT EXISTS/IF EXISTS). No inventory mutation.
--
-- WHY: V2.14.11's submitFinding() auto-attached EVERY still-unattached
-- photo matching (session, line, role, condition_type, uploader) — an
-- independent audit found this unsafe: a photo selected then abandoned
-- (condition changed back to 0, or the form abandoned entirely) stayed
-- "pending" and could be silently swept into a LATER, unrelated finding
-- for the same item/team/condition. The fix requires the client to name
-- exactly which uploaded photo(s) belong to THIS finding — naming them
-- by an unguessable per-upload token, never by the raw auto-increment id
-- alone (sequential ids are guessable). upload_token is that token,
-- minted once by StockOpnamePhotoService::upload() and never reused.
ALTER TABLE stock_opname_finding_photos
    ADD COLUMN IF NOT EXISTS upload_token CHAR(36) NULL AFTER id;

-- Backfill for any row a dev/test database might already have from the
-- V2.14.11-only shape (never shipped to production — this table is new
-- in that same unreleased round) — a real random token per existing row,
-- never a placeholder, so the column can be made NOT NULL immediately
-- after.
SET @sofp_needs_backfill = (
    SELECT COUNT(*) FROM stock_opname_finding_photos WHERE upload_token IS NULL
);
-- MariaDB has no native gen_random_uuid() before 10.7; UUID() is
-- available on every version this project targets (10.11) and produces
-- an equally unguessable v1 UUID — the security property that matters is
-- "not a small sequential integer", not the RFC UUID version.
SET @sofp_backfill_sql = IF(@sofp_needs_backfill > 0,
    'UPDATE stock_opname_finding_photos SET upload_token = UUID() WHERE upload_token IS NULL',
    'SELECT 1');
PREPARE sofp_backfill_stmt FROM @sofp_backfill_sql;
EXECUTE sofp_backfill_stmt;
DEALLOCATE PREPARE sofp_backfill_stmt;

ALTER TABLE stock_opname_finding_photos
    MODIFY COLUMN upload_token CHAR(36) NOT NULL;

-- Guarded manually (no "ADD UNIQUE KEY IF NOT EXISTS" in MariaDB 10.11):
-- a unique index for O(1) token lookup, and as a second, independent
-- guarantee that no token is ever reused across two rows.
SET @sofp_token_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_opname_finding_photos' AND INDEX_NAME = 'uq_sofp_token'
);
SET @sofp_token_idx_sql = IF(@sofp_token_idx_exists = 0,
    'ALTER TABLE stock_opname_finding_photos ADD UNIQUE KEY uq_sofp_token (upload_token)',
    'SELECT 1');
PREPARE sofp_token_idx_stmt FROM @sofp_token_idx_sql;
EXECUTE sofp_token_idx_stmt;
DEALLOCATE PREPARE sofp_token_idx_stmt;

-- Supports StockOpnamePhotoService::cleanupExpiredPending()'s query
-- (finding_id IS NULL AND uploaded_at < cutoff) without a full scan.
ALTER TABLE stock_opname_finding_photos
    ADD INDEX IF NOT EXISTS idx_sofp_pending_expiry (finding_id, uploaded_at);
