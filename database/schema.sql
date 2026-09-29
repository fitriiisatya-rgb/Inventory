-- =====================================================================
-- STOCK OPNAME MULTI USER — DATABASE SCHEMA (Phase 2 Design)
-- Engine target : MySQL 8.0+ / MariaDB 10.5+ (InnoDB, utf8mb4)
-- Status        : DRAFT — pending design-review approval. Not yet
--                  wired into any application code.
-- Timezone      : all DATETIME columns are stored and interpreted as
--                  Asia/Jakarta app-side; MySQL is kept in UTC or
--                  session-local, no reliance on MySQL's own TZ tables.
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
-- Conversion model (CORRECTED per design review, matches legacy
-- index_2.php semantics exactly):
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

    last_buy_price  DECIMAL(18,2)  NOT NULL DEFAULT 0,   -- price per BASE unit
    status          ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    note            TEXT NULL,

    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_items_sku (sku),
    KEY idx_items_barcode (barcode),
    KEY idx_items_category (category_id),
    KEY idx_items_status (status),
    CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

-- "Stok sistem" ground truth. This standalone app does not run a
-- purchasing/sales ledger (out of scope), so system_qty is maintained
-- as an explicit balance: seeded by import/manual entry, and rolled
-- forward automatically whenever a Stock Opname session for that
-- item+location FINISHES (final_qty becomes the new system_qty).
-- See section 19 (Design Decision: Source of "Stok Sistem").
CREATE TABLE item_stock (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id         BIGINT UNSIGNED NOT NULL,
    location_id     BIGINT UNSIGNED NOT NULL,
    system_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,   -- base unit
    updated_by      BIGINT UNSIGNED NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item_location (item_id, location_id),
    CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_stock_location FOREIGN KEY (location_id) REFERENCES locations(id),
    CONSTRAINT fk_stock_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE item_stock_adjustments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_stock_id       BIGINT UNSIGNED NOT NULL,
    old_qty             DECIMAL(18,4) NOT NULL,
    new_qty             DECIMAL(18,4) NOT NULL,
    reason              VARCHAR(255) NOT NULL,
    source_session_id   BIGINT UNSIGNED NULL,   -- set when caused by SO finalization
    changed_by          BIGINT UNSIGNED NOT NULL,
    changed_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_adj_stock (item_stock_id),
    CONSTRAINT fk_adj_stock FOREIGN KEY (item_stock_id) REFERENCES item_stock(id),
    CONSTRAINT fk_adj_user  FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 3. STOCK OPNAME SESSION
-- =====================================================================

CREATE TABLE stock_opname_sessions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_no          VARCHAR(30) NOT NULL,             -- SO-YYYYMMDD-XXXX
    name                VARCHAR(150) NOT NULL,
    location_id         BIGINT UNSIGNED NOT NULL,

    scope_type          ENUM('ALL','CATEGORY') NOT NULL DEFAULT 'ALL',
    category_id         BIGINT UNSIGNED NULL,

    status              ENUM('DRAFT','ACTIVE','REVIEW','FINISHED','CANCELLED') NOT NULL DEFAULT 'DRAFT',

    physical_date       DATE NULL,
    snapshot_at         DATETIME NULL,      -- cut-off moment; frozen when session -> ACTIVE
    started_at          DATETIME NULL,
    finished_at         DATETIME NULL,

    started_by          BIGINT UNSIGNED NULL,
    finished_by         BIGINT UNSIGNED NULL,

    -- Correction-session mechanism (section 15): a FINISHED session is
    -- immutable; corrections happen via a new session referencing it.
    parent_session_id   BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_session_parent FOREIGN KEY (parent_session_id) REFERENCES stock_opname_sessions(id)
) ENGINE=InnoDB;

-- Who is assigned to a session (distinct from who actually counted —
-- see "Assigned vs Participated", section 16 / report rule).
CREATE TABLE stock_opname_assignments (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id  BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    team        ENUM('P1','P2') NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assignment (session_id, user_id, team),
    KEY idx_assignment_session (session_id),
    CONSTRAINT fk_assignment_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_assignment_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- One row per item included in the session. Everything here is a
-- SNAPSHOT taken at session start (ACTIVE) and is immutable afterward,
-- even if the Master Barang record changes later.
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

    system_qty_snapshot     DECIMAL(18,4) NOT NULL,   -- base unit, frozen at snapshot_at
    unit_cost_snapshot      DECIMAL(18,2) NOT NULL,   -- price per base unit, frozen at snapshot_at

    -- item explicitly added to an already-ACTIVE session by Superadmin
    -- (section 16). NULL for items included at normal session start.
    added_after_start_by    BIGINT UNSIGNED NULL,
    added_after_start_at    DATETIME NULL,
    added_after_start_reason VARCHAR(255) NULL,

    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_session_item (session_id, item_id),
    KEY idx_si_session (session_id),
    KEY idx_si_item (item_id),
    CONSTRAINT fk_si_session FOREIGN KEY (session_id) REFERENCES stock_opname_sessions(id),
    CONSTRAINT fk_si_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_si_added_by FOREIGN KEY (added_after_start_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. LOCKING (CORRECTED: per-team, not global)
--
-- P1 and P2 count the same item independently and simultaneously.
-- Only same-team collisions (P1 vs P1, P2 vs P2) are prevented.
-- UNIQUE(session_item_id, team) is the concurrency primitive: acquiring
-- a lock is an INSERT that fails on duplicate-key if the team's slot
-- is already held and unexpired -> caller gets "locked by <user>".
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
-- 5. COUNTS
-- =====================================================================

CREATE TABLE stock_opname_counts (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_item_id         BIGINT UNSIGNED NOT NULL,
    team                    ENUM('P1','P2') NOT NULL,
    user_id                 BIGINT UNSIGNED NOT NULL,
    user_name_snapshot      VARCHAR(150) NOT NULL,
    round                   INT UNSIGNED NOT NULL DEFAULT 1,

    -- GOOD: raw input per unit level actually rendered in the UI
    -- (buy/mid/base fields are shown or hidden per item's own level
    -- count -- see section 4 "Good Stock Input" in the review reply).
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

-- Append-only, full-state JSON snapshot per edit (CORRECTED: JSON
-- snapshot, not narrow per-field diff, so any historical count state
-- can be reconstructed exactly). A per-field diff for the UI is
-- generated at read-time by diffing consecutive JSON blobs.
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

-- Recount requests are SUPERADMIN-only for now (app-layer rule).
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
-- 6. PHOTO EVIDENCE (CORRECTED: tied to count_id, not just session_item,
--    so each round keeps its own distinct evidence)
-- =====================================================================

CREATE TABLE stock_opname_photos (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id          BIGINT UNSIGNED NOT NULL,
    session_item_id     BIGINT UNSIGNED NOT NULL,
    count_id            BIGINT UNSIGNED NOT NULL,
    condition_type      ENUM('DAMAGED','EXPIRED','DEADSTOCK') NOT NULL,
    file_path           VARCHAR(255) NOT NULL,   -- random UUID filename, relative path
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
-- 7. FINAL (CORRECTED: append-only, single source of truth —
--    no duplicate final_qty column on session_items)
-- =====================================================================

CREATE TABLE stock_opname_finals (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_item_id     BIGINT UNSIGNED NOT NULL,
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    final_qty           DECIMAL(18,4) NOT NULL,   -- base unit
    variance_qty        DECIMAL(18,4) NOT NULL,   -- final_qty - system_qty_snapshot
    variance_value      DECIMAL(18,2) NOT NULL,   -- variance_qty * unit_cost_snapshot
    reason              VARCHAR(255) NOT NULL,
    set_by              BIGINT UNSIGNED NOT NULL,
    set_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_current          TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_final_session_item (session_item_id),
    KEY idx_final_current (session_item_id, is_current),
    CONSTRAINT fk_final_session_item FOREIGN KEY (session_item_id) REFERENCES stock_opname_session_items(id),
    CONSTRAINT fk_final_user FOREIGN KEY (set_by) REFERENCES users(id)
) ENGINE=InnoDB;
-- Application enforces: on INSERT of a new version for a session_item,
-- flip the previous is_current=1 row to 0 inside the same transaction.
-- A partial UNIQUE index (session_item_id) WHERE is_current=1 is not
-- portable to MariaDB < 10.5 the same way as MySQL 8 functional
-- indexes, so this is enforced in application code + a nightly
-- consistency check query, not a DB constraint. See section 21.

-- =====================================================================
-- 8. APPROVAL
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
-- 9. AUDIT LOG (general-purpose; count-specific edits use
--    stock_opname_count_revisions instead, which is richer)
-- =====================================================================

CREATE TABLE audit_logs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id        BIGINT UNSIGNED NULL,
    action          VARCHAR(100) NOT NULL,     -- e.g. 'SESSION_CANCEL', 'ITEM_UPDATE', 'RECOUNT_REQUEST'
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

SET FOREIGN_KEY_CHECKS = 1;
