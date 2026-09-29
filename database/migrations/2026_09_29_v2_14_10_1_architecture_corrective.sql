-- V2.14.10.1 ARCHITECTURE SAFETY CORRECTIVE
-- Baseline: 731244d (V2.14.9.3, production). Additive on top of the V2.14.10
-- multi-counter-team migrations (which this release also carries forward as
-- part of the same standalone package). Fully idempotent (MariaDB 10.11
-- IF NOT EXISTS / IF EXISTS throughout). No inventory mutation. No existing
-- column is dropped, renamed, or narrowed; no existing row is rewritten
-- beyond the DEFAULT value ALTER TABLE itself supplies for pre-existing rows.

-- GATE 2 — explicit session counting-model discriminator. Pre-existing rows
-- (every session created before this migration, whether truly legacy
-- single-count or V2.14.10 team/dual-count) get LEGACY_DUAL_COUNT via this
-- column's DEFAULT — they keep writing through /count/{role} exactly as
-- before. StockOpnameService::start() is changed to explicitly set
-- FINDINGS_V1 on every session it creates from this release onward. A
-- LEGACY_DUAL_COUNT session may be explicitly upgraded in place by a
-- supervisor via StockOpnameService::upgradeToFindingsMode() (only while it
-- has zero submitted P1/P2 counts) — see that method's docblock.
ALTER TABLE stock_opname_sessions
    ADD COLUMN IF NOT EXISTS counting_model ENUM('LEGACY_DUAL_COUNT','FINDINGS_V1') NOT NULL DEFAULT 'LEGACY_DUAL_COUNT'
        COMMENT 'V2.14.10.1 Gate 2: explicit write-model discriminator. Never inferred from whether findings happen to exist.';

-- GATE 4 — a strong claim identity alongside the existing owner/timestamp
-- pair, so a stale browser tab cannot save under a claim that has since
-- moved to someone else or simply expired. Minted fresh by claimItem() on
-- every successful claim; verified by submitFinding() inside the same
-- transaction that would insert the finding.
ALTER TABLE stock_opname_lines
    ADD COLUMN IF NOT EXISTS p1_claim_token VARCHAR(36) NULL
        COMMENT 'V2.14.10.1 Gate 4: opaque token minted by claimItem(); submitFinding() must present the exact current token or the insert is refused (CLAIM_LOST).',
    ADD COLUMN IF NOT EXISTS p2_claim_token VARCHAR(36) NULL;

-- GATE 1 — FROZEN unit conversions per Stock Opname session. Snapshotted
-- once (at session start, or at the moment a supervisor upgrades a legacy
-- session to FINDINGS_V1 — see upgradeToFindingsMode()) from whatever
-- item_unit_conversions rows are active at that instant. Once a session is
-- FINDINGS_V1, submitFinding() resolves every unit_id against THIS table
-- only — never a live item_unit_conversions/UnitConversionService lookup —
-- so a packaging change made mid-session (e.g. 1 KARTON = 12 PCS becoming
-- 1 KARTON = 24 PCS) can never change what an already-open counting session
-- computes, for either team, at any point in that session's lifetime. A
-- brand-new session started after the change picks up the new factor
-- naturally, because it snapshots fresh at its own start time.
CREATE TABLE IF NOT EXISTS stock_opname_line_units (
    id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id                 INT UNSIGNED NOT NULL,
    stock_opname_line_id       BIGINT UNSIGNED NOT NULL,
    item_id                    INT UNSIGNED NOT NULL,
    unit_id                    INT UNSIGNED NOT NULL,
    unit_code_snapshot         VARCHAR(32) NOT NULL,
    unit_name_snapshot         VARCHAR(128) NOT NULL,
    conversion_factor_snapshot DECIMAL(20,6) NOT NULL,
    is_base_unit               TINYINT(1) NOT NULL DEFAULT 0,
    snapshot_at                DATETIME NOT NULL,
    CONSTRAINT fk_solu_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_solu_line FOREIGN KEY (stock_opname_line_id) REFERENCES stock_opname_lines(id),
    CONSTRAINT fk_solu_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_solu_unit FOREIGN KEY (unit_id) REFERENCES units(id),
    UNIQUE KEY uq_solu_line_unit (stock_opname_line_id, unit_id),
    INDEX idx_solu_session (session_id)
) ENGINE=InnoDB
COMMENT='V2.14.10.1 Gate 1: the sole authoritative unit source for a FINDINGS_V1 session once counting has started. GET /items/{id}/units is used only to BUILD this snapshot (at session start / upgrade) — never read again by a counter afterward.';

-- GATE 9 — indexes supporting common finding-scale queries without a full
-- scan. idx_sof_line_role (stock_opname_line_id, team_role, voided_at) and
-- idx_sofu_finding (finding_id) already exist from the V2.14.10 findings
-- migration and are not duplicated here.
ALTER TABLE stock_opname_findings
    ADD INDEX IF NOT EXISTS idx_sof_session_role_void (session_id, team_role, voided_at),
    ADD INDEX IF NOT EXISTS idx_sof_counter_created (counter_user_id, created_at);

ALTER TABLE stock_opname_team_members
    ADD INDEX IF NOT EXISTS idx_sotm_session_role_active (session_id, team_role, active);
