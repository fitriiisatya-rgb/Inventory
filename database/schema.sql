-- =====================================================================
-- STOCK OPNAME MULTI USER — DATABASE SCHEMA
-- Engine target : MySQL 8.0+ / MariaDB 10.5+ (InnoDB, utf8mb4)
-- Status        : Phase 3 FINAL — approved design, foundation tables
--                  (users..audit_logs) are live/coded in Phase 3;
--                  stock_opname_* workflow tables are finalized here
--                  but their API/UI is built in Phase 4.
-- Timezone      : Asia/Jakarta, enforced app-side (PHP date_default_timezone_set).
--                  DATETIME columns store server-local time consistently;
--                  no reliance on MySQL's own named-timezone tables.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. IDENTITY & ORG
-- =====================================================================

CREATE TABLE users (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    job_title       VARCHAR(100) NULL,        -- printed on report signature blocks (e.g. "Store Manager")
    role            ENUM('SUPERADMIN','ADMIN','SUPERVISOR','APPROVER','COUNTER','VIEWER') NOT NULL,
    team            ENUM('P1','P2') NULL,     -- meaningful only when role = COUNTER
    status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role),
    KEY idx_users_team (team),
    KEY idx_users_status (status)
) ENGINE=InnoDB;

CREATE TABLE categories (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_code (code),
    KEY idx_categories_status (status)
) ENGINE=InnoDB;

CREATE TABLE locations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(30)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    status      ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_locations_code (code),
    KEY idx_locations_status (status)
) ENGINE=InnoDB;

-- =====================================================================
-- 2. MASTER BARANG + KONVERSI SATUAN
--
--   buy_content = jumlah BASE UNIT dalam 1 BUY UNIT
--   mid_content = jumlah MID UNIT dalam 1 BUY UNIT
--   mid_to_base (derived, never stored) = buy_content / mid_content
--
-- Example — Keju: 1 Karton = 20 Kg, 1 Karton = 20.000 Gr
--   buy_unit=Karton buy_content=20000  mid_unit=Kg mid_content=20  base_unit=Gr
--   => mid_to_base = 20000 / 20 = 1000 Gr per Kg
-- =====================================================================

CREATE TABLE items (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku             VARCHAR(50)  NOT NULL,
    barcode         VARCHAR(50)  NULL,
    name            VARCHAR(200) NOT NULL,
    category_id     BIGINT UNSIGNED NOT NULL,
    brand           VARCHAR(100) NULL,
    distributor     VARCHAR(150) NULL,

    buy_unit        VARCHAR(30)    NOT NULL,             -- e.g. "Karton"
    buy_content     DECIMAL(18,4)  NOT NULL,             -- base units per 1 buy unit
    mid_unit        VARCHAR(30)    NULL,                 -- e.g. "Kg"; NULL if item has only 2 levels
    mid_content     DECIMAL(18,4)  NULL,                 -- MID units per 1 buy unit
    base_unit       VARCHAR(30)    NOT NULL,             -- e.g. "Gr"

    last_buy_price  DECIMAL(18,2)  NULL DEFAULT NULL,    -- price per BASE unit; NULL = never recorded, never coerced to 0
    status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    note            TEXT NULL,
    migration_source ENUM('MANUAL','LEGACY') NOT NULL DEFAULT 'MANUAL',   -- added migration 0005

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_items_sku (sku),
    KEY idx_items_barcode (barcode),
    KEY idx_items_category (category_id),
    KEY idx_items_status (status),
    CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 3. SYSTEM STOCK (source of truth = IMPORT for V1 — decision 2026-09-29:
--    NOT rolled forward automatically from a finished SO session, because
--    this app does not record purchases/usage/transfers between opname
--    periods. Every session's system_qty_snapshot comes from whatever is
--    currently in item_stock, which is populated only by an explicit
--    Import Stok Sistem. See includes/SystemStockProvider/*.php for the
--    abstraction that lets a future API-based provider replace this
--    without changing session code.
-- =====================================================================

CREATE TABLE item_stock (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id         BIGINT UNSIGNED NOT NULL,
    location_id     BIGINT UNSIGNED NOT NULL,
    system_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,   -- base unit
    unit_cost       DECIMAL(18,2) NULL DEFAULT NULL,    -- per base unit; NULL = unknown, not Rp0
    updated_by      BIGINT UNSIGNED NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item_location (item_id, location_id),
    CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_stock_location FOREIGN KEY (location_id) REFERENCES locations(id),
    CONSTRAINT fk_stock_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_import_batches (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id     BIGINT UNSIGNED NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    source_type     ENUM('MANUAL','LEGACY') NOT NULL DEFAULT 'MANUAL',   -- added migration 0005
    status          ENUM('PREVIEWED','COMMITTED','CANCELLED') NOT NULL DEFAULT 'PREVIEWED',
    total_rows      INT UNSIGNED NOT NULL DEFAULT 0,
    matched_count   INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_count   INT UNSIGNED NOT NULL DEFAULT 0,
    warning_count   INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by     BIGINT UNSIGNED NOT NULL,
    uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    committed_by    BIGINT UNSIGNED NULL,
    committed_at    DATETIME NULL,
    KEY idx_import_location (location_id),
    KEY idx_import_status (status),
    CONSTRAINT fk_import_location FOREIGN KEY (location_id) REFERENCES locations(id),
    CONSTRAINT fk_import_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id),
    CONSTRAINT fk_import_committed_by FOREIGN KEY (committed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_import_rows (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id            BIGINT UNSIGNED NOT NULL,
    row_no          INT UNSIGNED NOT NULL,
    raw_sku             VARCHAR(50) NULL,
    raw_qty             VARCHAR(50) NULL,
    raw_unit            VARCHAR(30) NULL,
    raw_unit_cost       VARCHAR(50) NULL,
    item_id             BIGINT UNSIGNED NULL,           -- resolved; NULL if SKU_NOT_FOUND
    parsed_qty_base     DECIMAL(18,4) NULL,
    parsed_unit_cost    DECIMAL(18,2) NULL,
    unit_cost_source    ENUM('IMPORT','MASTER_LAST_BUY_PRICE','NONE') NULL,  -- resolved once, here, never re-derived later
    status              ENUM('MATCHED','DUPLICATE','SKU_NOT_FOUND','INVALID_QTY','INVALID_UNIT','WARNING') NOT NULL,
    message             VARCHAR(255) NULL,
    committed           TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_import_row_batch (batch_id),
    KEY idx_import_row_item (item_id),
    CONSTRAINT fk_import_row_batch FOREIGN KEY (batch_id) REFERENCES stock_import_batches(id),
    CONSTRAINT fk_import_row_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE item_stock_adjustments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_stock_id       BIGINT UNSIGNED NOT NULL,
    old_qty             DECIMAL(18,4) NOT NULL,
    new_qty             DECIMAL(18,4) NOT NULL,
    source              ENUM('MANUAL','IMPORT') NOT NULL,
    import_batch_id     BIGINT UNSIGNED NULL,
    reason              VARCHAR(255) NOT NULL,
    changed_by          BIGINT UNSIGNED NOT NULL,
    changed_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_adj_stock (item_stock_id),
    CONSTRAINT fk_adj_stock FOREIGN KEY (item_stock_id) REFERENCES item_stock(id),
    CONSTRAINT fk_adj_batch FOREIGN KEY (import_batch_id) REFERENCES stock_import_batches(id),
    CONSTRAINT fk_adj_user  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- One row per legacy migration run (Master or Stock), added migration
-- 0005. Legacy stock always flows through stock_import_batches (the
-- existing, already-audited pipeline) — this table never writes
-- item_stock directly, it only records the aggregate counts and links
-- to whichever batch(es) a STOCK migration committed through.
CREATE TABLE legacy_migrations (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type                    ENUM('MASTER','STOCK') NOT NULL,
    source_file             VARCHAR(255) NOT NULL,
    imported_by             BIGINT UNSIGNED NOT NULL,
    imported_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_rows              INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows              INT UNSIGNED NOT NULL DEFAULT 0,
    warning_rows            INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_rows            INT UNSIGNED NOT NULL DEFAULT 0,
    committed_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    detail                  JSON NULL,
    KEY idx_legacy_migration_type (type),
    CONSTRAINT fk_legacy_migration_user FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. STOCK OPNAME SESSION  (schema finalized in Phase 3; API/UI = Phase 4)
-- =====================================================================

CREATE TABLE stock_opname_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_no          VARCHAR(30) NOT NULL,             -- SO-YYYYMMDD-XXXX
    name                VARCHAR(150) NOT NULL,
    location_id         BIGINT UNSIGNED NOT NULL,

    -- Explicit traceability (design review 2026-09-29): which committed
    -- stock_import_batch this session's system_qty snapshot reflects.
    -- Auto-selected as the latest COMMITTED batch for location_id at
    -- session creation (DRAFT); immutable once ACTIVE. NULL only means
    -- no batch had been committed for this location yet — start-session
    -- preflight blocks on that.
    system_stock_batch_id BIGINT UNSIGNED NULL,

    scope_type          ENUM('ALL','CATEGORY') NOT NULL DEFAULT 'ALL',
    category_id         BIGINT UNSIGNED NULL,

    status              ENUM('DRAFT','ACTIVE','REVIEW','FINISHED','CANCELLED') NOT NULL DEFAULT 'DRAFT',

    physical_date       DATE NULL,
    snapshot_at         DATETIME NULL,      -- cut-off moment; frozen when session -> ACTIVE
    started_at          DATETIME NULL,
    finished_at         DATETIME NULL,

    started_by          BIGINT UNSIGNED NULL,
    review_started_at   DATETIME NULL,      -- when ACTIVE -> REVIEW happened
    review_started_by   BIGINT UNSIGNED NULL,
    finished_by         BIGINT UNSIGNED NULL,

    parent_session_id   BIGINT UNSIGNED NULL,   -- correction-session mechanism
    correction_reason   VARCHAR(255) NULL,

    cancel_reason       VARCHAR(255) NULL,

    note                TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_session_no (session_no),
    KEY idx_session_location (location_id),
    KEY idx_session_status (status),
    KEY idx_session_parent (parent_session_id),
    CONSTRAINT fk_session_location FOREIGN KEY (location_id) REFERENCES locations(id),
    CONSTRAINT fk_session_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT fk_session_started_by FOREIGN KEY (started_by) REFERENCES users(id),
    CONSTRAINT fk_session_finished_by FOREIGN KEY (finished_by) REFERENCES users(id),
    CONSTRAINT fk_session_parent FOREIGN KEY (parent_session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_session_stock_batch FOREIGN KEY (system_stock_batch_id) REFERENCES stock_import_batches(id)
) ENGINE=InnoDB;

-- A user holds at most one team slot per session (UNIQUE on session+user,
-- not session+user+team) — assigning someone to both P1 and P2 in the
-- same session is nonsensical and now unrepresentable. status lets an
-- assignment be soft-removed without losing the historical record of who
-- was ever assigned. A user may hold MULTIPLE rows over time in the same
-- session (design review points 7-11): reassigning P1->P2 is always
-- remove-old-row + add-new-row, both audited, never a silent UPDATE of
-- team on the existing row (so a user's counts stay attributed to the
-- team they actually held at the time). At most one row per user should
-- be ACTIVE at once — application-enforced (SessionService), not a DB
-- constraint, the same trade-off already accepted for
-- stock_opname_finals.is_current.
CREATE TABLE stock_opname_session_counters (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    team            ENUM('P1','P2') NOT NULL,
    status          ENUM('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
    assigned_by     BIGINT UNSIGNED NOT NULL,
    assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_reason VARCHAR(255) NULL,   -- required by app logic when assigned while session ACTIVE
    removed_by      BIGINT UNSIGNED NULL,
    removed_at      DATETIME NULL,
    removed_reason  VARCHAR(255) NULL,   -- required by app logic when removed while session ACTIVE
    KEY idx_session_counter_session (session_id, team, status),
    KEY idx_session_counter_user (session_id, user_id, status),
    CONSTRAINT fk_sc_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_sc_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_sc_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id),
    CONSTRAINT fk_sc_removed_by FOREIGN KEY (removed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- One row per item included in the session. Everything here is a
-- SNAPSHOT taken at session start (ACTIVE) and is immutable afterward,
-- even if the Master Barang record or item_stock changes later.
CREATE TABLE stock_opname_session_items (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id              BIGINT UNSIGNED NOT NULL,
    item_id                 BIGINT UNSIGNED NOT NULL,

    sku_snapshot            VARCHAR(50)  NOT NULL,
    barcode_snapshot        VARCHAR(50)  NULL,
    name_snapshot           VARCHAR(200) NOT NULL,
    category_snapshot       VARCHAR(100) NOT NULL,
    brand_snapshot          VARCHAR(100) NULL,

    buy_unit_snapshot       VARCHAR(30)   NOT NULL,
    buy_content_snapshot    DECIMAL(18,4) NOT NULL,
    mid_unit_snapshot       VARCHAR(30)   NULL,
    mid_content_snapshot    DECIMAL(18,4) NULL,
    base_unit_snapshot      VARCHAR(30)   NOT NULL,

    system_qty_snapshot     DECIMAL(18,4) NOT NULL,   -- base unit, frozen at snapshot_at, from item_stock (via SystemStockProvider)
    unit_cost_snapshot      DECIMAL(18,2) NULL,       -- price per base unit, frozen at snapshot_at; NULL = genuinely unknown, never coerced to Rp0
    unit_cost_source        ENUM('IMPORT','MASTER_LAST_BUY_PRICE','NONE') NOT NULL DEFAULT 'NONE',

    -- item explicitly added to an already-ACTIVE session by Superadmin
    added_after_start_by    BIGINT UNSIGNED NULL,
    added_after_start_at    DATETIME NULL,
    added_after_start_reason VARCHAR(255) NULL,

    -- Explicit item disposition within the session (decision 2026-09-29,
    -- EXCLUDED removed 2026-09-29 review — no approved business rule for
    -- it; re-add deliberately in its own migration if ever needed).
    -- 0 qty is a real physical count and must never be confused with
    -- "could not be counted at all". NOT_COUNTABLE is SUPERADMIN-only,
    -- requires a reason, and is audited.
    item_status              ENUM('NORMAL','NOT_COUNTABLE') NOT NULL DEFAULT 'NORMAL',
    not_countable_reason     VARCHAR(255) NULL,
    not_countable_set_by     BIGINT UNSIGNED NULL,
    not_countable_set_at     DATETIME NULL,

    -- Which round is currently open for counting. Bumped only via
    -- ReconciliationService::requestRecount() (SUPERADMIN-only); old
    -- rounds' counts are immutable once superseded.
    current_round            INT UNSIGNED NOT NULL DEFAULT 1,

    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_session_item (session_id, item_id),
    KEY idx_si_session (session_id),
    KEY idx_si_item (item_id),
    KEY idx_si_status (item_status),
    CONSTRAINT fk_si_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_si_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_si_added_by FOREIGN KEY (added_after_start_by) REFERENCES users(id),
    CONSTRAINT fk_si_not_countable_by FOREIGN KEY (not_countable_set_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 5. LOCKING — per team, not global. UNIQUE(session_item_id, team) is
--    the concurrency primitive: P1 and P2 hold independent lock slots.
-- =====================================================================

CREATE TABLE stock_opname_item_locks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_item_id     BIGINT UNSIGNED NOT NULL,
    team                ENUM('P1','P2') NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    locked_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          DATETIME NOT NULL,
    heartbeat_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lock_item_team (session_item_id, team),
    KEY idx_lock_expires (expires_at),
    CONSTRAINT fk_lock_session_item FOREIGN KEY (session_item_id) REFERENCES stock_opname_session_items(id),
    CONSTRAINT fk_lock_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 6. COUNTS
-- =====================================================================

CREATE TABLE stock_opname_counts (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_item_id         BIGINT UNSIGNED NOT NULL,
    team                    ENUM('P1','P2') NOT NULL,
    user_id                 BIGINT UNSIGNED NOT NULL,
    user_name_snapshot      VARCHAR(150) NOT NULL,
    round                   INT UNSIGNED NOT NULL DEFAULT 1,

    good_buy_qty            DECIMAL(18,4) NOT NULL DEFAULT 0,
    good_mid_qty            DECIMAL(18,4) NOT NULL DEFAULT 0,
    good_base_input_qty     DECIMAL(18,4) NOT NULL DEFAULT 0,
    good_base_qty           DECIMAL(18,4) NOT NULL,   -- normalized total, base unit

    damaged_qty             DECIMAL(18,4) NOT NULL DEFAULT 0,
    damaged_unit            VARCHAR(30) NULL,
    damaged_base_qty        DECIMAL(18,4) NOT NULL DEFAULT 0,

    expired_qty             DECIMAL(18,4) NOT NULL DEFAULT 0,
    expired_unit            VARCHAR(30) NULL,
    expired_base_qty        DECIMAL(18,4) NOT NULL DEFAULT 0,

    deadstock_qty           DECIMAL(18,4) NOT NULL DEFAULT 0,
    deadstock_unit          VARCHAR(30) NULL,
    deadstock_base_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,

    physical_base_qty       DECIMAL(18,4) NOT NULL,  -- good+damaged+expired+deadstock, base unit

    -- A count with a condition qty > 0 but no satisfying photo yet stays
    -- EVIDENCE_REQUIRED: excluded from reconciliation, shown to the
    -- counter as incomplete, and its lock is NOT released (PhotoEvidenceService).
    evidence_status          ENUM('COMPLETE','EVIDENCE_REQUIRED') NOT NULL DEFAULT 'COMPLETE',

    is_recount              TINYINT(1) NOT NULL DEFAULT 0,
    note                    TEXT NULL,

    counted_at              DATETIME NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_count_item_team_round (session_item_id, team, round),
    KEY idx_count_user (user_id),
    KEY idx_count_counted_at (counted_at),
    CONSTRAINT fk_count_session_item FOREIGN KEY (session_item_id) REFERENCES stock_opname_session_items(id),
    CONSTRAINT fk_count_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Append-only, full-state JSON snapshot per edit.
CREATE TABLE stock_opname_count_revisions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    count_id        BIGINT UNSIGNED NOT NULL,
    old_value       JSON NULL,     -- NULL on first-ever save (no prior state)
    new_value       JSON NOT NULL,
    reason          VARCHAR(255) NOT NULL,
    changed_by      BIGINT UNSIGNED NOT NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    KEY idx_revision_count (count_id),
    CONSTRAINT fk_revision_count FOREIGN KEY (count_id) REFERENCES stock_opname_counts(id),
    CONSTRAINT fk_revision_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Recount requests are SUPERADMIN-only (app-layer rule).
CREATE TABLE stock_opname_recounts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_item_id BIGINT UNSIGNED NOT NULL,
    round_from      INT UNSIGNED NOT NULL,
    round_to        INT UNSIGNED NOT NULL,
    requested_by    BIGINT UNSIGNED NOT NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason          VARCHAR(255) NOT NULL,
    KEY idx_recount_session_item (session_item_id),
    CONSTRAINT fk_recount_session_item FOREIGN KEY (session_item_id) REFERENCES stock_opname_session_items(id),
    CONSTRAINT fk_recount_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 7. PHOTO EVIDENCE — tied to count_id so each round keeps its own
--    distinct evidence; never overwritten across rounds.
-- =====================================================================

CREATE TABLE stock_opname_photos (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          BIGINT UNSIGNED NOT NULL,
    session_item_id     BIGINT UNSIGNED NOT NULL,
    count_id            BIGINT UNSIGNED NOT NULL,
    condition_type      ENUM('DAMAGED','EXPIRED','DEADSTOCK') NOT NULL,
    file_path           VARCHAR(255) NOT NULL,   -- random UUID filename, relative path
    -- ACTIVE = counts as current evidence; SUPERSEDED = an edit zeroed
    -- this condition's qty, so it's kept as history but no longer
    -- satisfies the evidence requirement. Never hard-deleted once a
    -- count is COMPLETE (see PhotoEvidenceService for the pre-COMPLETE
    -- delete path, which does hard-delete).
    status              ENUM('ACTIVE','SUPERSEDED') NOT NULL DEFAULT 'ACTIVE',
    superseded_at       DATETIME NULL,
    superseded_reason   VARCHAR(255) NULL,
    caption             VARCHAR(255) NULL,
    uploaded_by         BIGINT UNSIGNED NOT NULL,
    uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_photo_session (session_id),
    KEY idx_photo_session_item (session_item_id),
    KEY idx_photo_count (count_id),
    CONSTRAINT fk_photo_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_photo_session_item FOREIGN KEY (session_item_id) REFERENCES stock_opname_session_items(id),
    CONSTRAINT fk_photo_count FOREIGN KEY (count_id) REFERENCES stock_opname_counts(id),
    CONSTRAINT fk_photo_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 8. FINAL — append-only, single source of truth. No session_items
--    column duplicates final_qty. NOT_COUNTABLE items never get a row
--    here (there is nothing measured to finalize); reports LEFT JOIN
--    this table so their variance naturally renders as NULL/blank.
-- =====================================================================

CREATE TABLE stock_opname_finals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_item_id     BIGINT UNSIGNED NOT NULL,
    version             INT UNSIGNED NOT NULL DEFAULT 1,

    -- Per-condition breakdown (added go-live migration 0004; the table
    -- started with only final_qty before conditions existed).
    -- PHYSICAL = GOOD+DAMAGED+EXPIRED+DEADSTOCK; AVAILABLE = GOOD.
    final_good_base_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,
    final_damaged_base_qty   DECIMAL(18,4) NOT NULL DEFAULT 0,
    final_expired_base_qty   DECIMAL(18,4) NOT NULL DEFAULT 0,
    final_deadstock_base_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    final_physical_base_qty  DECIMAL(18,4) NOT NULL DEFAULT 0,
    final_available_base_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    source              ENUM('AUTO_MATCH','MANUAL') NOT NULL DEFAULT 'MANUAL',

    final_qty           DECIMAL(18,4) NOT NULL,   -- base unit; kept = final_physical_base_qty
    variance_qty        DECIMAL(18,4) NOT NULL,   -- final_available_base_qty - system_qty_snapshot
    variance_value      DECIMAL(18,2) NULL,       -- variance_qty * unit_cost_snapshot; NULL when cost unknown
    reason              VARCHAR(255) NOT NULL,
    set_by              BIGINT UNSIGNED NOT NULL,
    set_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_current          TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_final_session_item (session_item_id),
    KEY idx_final_current (session_item_id, is_current),
    CONSTRAINT fk_final_session_item FOREIGN KEY (session_item_id) REFERENCES stock_opname_session_items(id),
    CONSTRAINT fk_final_user FOREIGN KEY (set_by) REFERENCES users(id)
) ENGINE=InnoDB;
-- Application enforces: inserting a new version for a session_item
-- flips the previous is_current=1 row to 0 in the same transaction.
-- Not DB-constrained (MariaDB-portability), so a scheduled consistency
-- check query is required — see Phase 2 security checklist item 21.

-- =====================================================================
-- 9. APPROVAL
-- =====================================================================

CREATE TABLE stock_opname_approvals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          BIGINT UNSIGNED NOT NULL,
    approval_type       ENUM('ADMIN','APPROVER') NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    job_title_snapshot  VARCHAR(100) NULL,   -- printed jabatan, frozen at approval time
    approved_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note                VARCHAR(255) NULL,
    UNIQUE KEY uq_approval_session_type (session_id, approval_type),
    CONSTRAINT fk_approval_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_approval_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 10. AUDIT LOG (general-purpose; count-specific edits use
--     stock_opname_count_revisions instead, which is richer)
-- =====================================================================

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id        BIGINT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,     -- e.g. 'SESSION_CANCEL', 'ITEM_UPDATE', 'STOCK_IMPORT_COMMIT'
    entity_type     VARCHAR(50) NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    old_value       JSON NULL,
    new_value       JSON NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_actor (actor_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 11. MIGRATION BOOKKEEPING
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(255) NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_migration_filename (filename)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
