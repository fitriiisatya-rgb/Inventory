-- SQLite mirror of the tables FifoService/UnitConversionService/PriceAnomalyService
-- touch, used ONLY to run the business-logic tests in this sandbox (no MySQL
-- server is available here). Production always runs against database/schema.sql
-- on real MySQL/MariaDB — this file is a test double, not a second source of truth.

CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL
);

CREATE TABLE permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE
);

CREATE TABLE role_permissions (
    role_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL,
    role_id INTEGER NOT NULL,
    division_id INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT
);

CREATE TABLE warehouses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL
);

CREATE TABLE divisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL
);

CREATE TABLE units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL
);

-- Minimal mirror of database/schema.sql's movement_reconciliation_reviews —
-- only the columns MigrationNegativeStockService actually reads/writes.
-- FifoService::postOut consults this (via MigrationNegativeStockService)
-- whenever available stock is <= 0, so it must exist even in this offline
-- SQLite harness or that check fatals with "no such table".
CREATE TABLE movement_reconciliation_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL,
    item_name TEXT NULL,
    warehouse_code TEXT NOT NULL,
    unit TEXT NULL,
    historical_opening REAL NULL,
    historical_in REAL NULL,
    historical_out REAL NULL,
    historical_calculated_ending REAL NULL,
    verified_final_opening REAL NULL,
    difference REAL NULL,
    status TEXT NOT NULL DEFAULT 'PENDING_FINAL_STOCK',
    reason TEXT NULL,
    notes TEXT NULL,
    source TEXT NULL,
    is_migration_negative_approved INTEGER NOT NULL DEFAULT 0,
    migration_negative_approved_by_name TEXT NULL,
    migration_negative_note TEXT NULL,
    UNIQUE (sku, warehouse_code)
);

CREATE TABLE items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT NOT NULL UNIQUE,
    barcode TEXT,
    name TEXT NOT NULL,
    category TEXT,
    brand TEXT,
    base_unit_id INTEGER NOT NULL,
    minimum_stock REAL NOT NULL DEFAULT 0,
    default_supplier_id INTEGER,
    notes TEXT,
    status TEXT NOT NULL DEFAULT 'ACTIVE',
    locked_at TEXT,
    created_at TEXT,
    updated_at TEXT
);

CREATE TABLE item_unit_conversions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    unit_id INTEGER NOT NULL,
    conversion_to_base REAL NOT NULL,
    is_purchase_default INTEGER NOT NULL DEFAULT 0,
    valid_from TEXT NOT NULL,
    valid_to TEXT,
    note TEXT,
    created_by INTEGER,
    created_at TEXT
);

CREATE TABLE item_price_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    supplier_id INTEGER,
    unit_id INTEGER NOT NULL,
    price_per_unit REAL NOT NULL,
    unit_cost_base REAL NOT NULL,
    effective_date TEXT NOT NULL,
    source_transaction_line_id INTEGER,
    created_at TEXT
);

CREATE TABLE inventory_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id INTEGER NOT NULL,
    warehouse_id INTEGER NOT NULL,
    qty_base REAL NOT NULL,
    original_qty_base REAL NOT NULL,
    unit_cost_base REAL NOT NULL,
    received_date TEXT NOT NULL,
    expiry_date TEXT,
    supplier_id INTEGER,
    source_transaction_line_id INTEGER,
    is_negative_layer INTEGER NOT NULL DEFAULT 0,
    created_at TEXT
);

CREATE TABLE inventory_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_uuid TEXT NOT NULL UNIQUE,
    transaction_type TEXT NOT NULL,
    transaction_date TEXT NOT NULL,
    posting_date TEXT NOT NULL,
    warehouse_id INTEGER NOT NULL,
    supplier_id INTEGER,
    division_id INTEGER,
    reference_no TEXT,
    status TEXT NOT NULL DEFAULT 'POSTED',
    void_reason TEXT,
    voided_by INTEGER,
    voided_at TEXT,
    reversal_of_id INTEGER,
    is_historical_import INTEGER NOT NULL DEFAULT 0,
    inventory_effect INTEGER NOT NULL DEFAULT 1,
    book_closing_id INTEGER,
    created_by INTEGER NOT NULL,
    created_at TEXT
);

CREATE TABLE inventory_transaction_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id INTEGER NOT NULL,
    line_no INTEGER NOT NULL DEFAULT 1,
    item_id INTEGER NOT NULL,
    item_name_snapshot TEXT NOT NULL,
    input_qty REAL NOT NULL,
    input_unit_id INTEGER NOT NULL,
    conversion_factor_snapshot REAL NOT NULL,
    base_qty REAL NOT NULL,
    unit_price_input REAL NOT NULL DEFAULT 0,
    unit_cost_base REAL NOT NULL DEFAULT 0,
    subtotal REAL NOT NULL DEFAULT 0,
    warehouse_id INTEGER NOT NULL,
    created_batch_id INTEGER,
    is_price_anomaly INTEGER NOT NULL DEFAULT 0,
    price_anomaly_ratio REAL,
    anomaly_approved_by INTEGER,
    anomaly_reason TEXT,
    allow_negative_stock INTEGER NOT NULL DEFAULT 0,
    negative_stock_reason TEXT,
    negative_stock_approved_by INTEGER,
    notes TEXT
);

CREATE TABLE fifo_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_line_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL,
    qty_allocated REAL NOT NULL,
    unit_cost_base REAL NOT NULL,
    subtotal REAL NOT NULL,
    created_at TEXT
);

CREATE TABLE import_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_type TEXT NOT NULL,
    file_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'STAGED',
    total_rows INTEGER NOT NULL DEFAULT 0,
    valid_rows INTEGER NOT NULL DEFAULT 0,
    warning_rows INTEGER NOT NULL DEFAULT 0,
    error_rows INTEGER NOT NULL DEFAULT 0,
    uploaded_by INTEGER NOT NULL,
    uploaded_at TEXT,
    committed_by INTEGER,
    committed_at TEXT
);

CREATE TABLE import_rows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_batch_id INTEGER NOT NULL,
    row_no INTEGER NOT NULL,
    raw_data TEXT NOT NULL,
    row_status TEXT NOT NULL DEFAULT 'VALID',
    messages TEXT,
    created_entity_id INTEGER
);

CREATE TABLE book_closings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'DRAFT'
);

CREATE TABLE stock_opname_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    warehouse_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'OPEN'
);

CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username_snapshot TEXT NOT NULL,
    action_code TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER,
    ip_address TEXT,
    before_data TEXT,
    after_data TEXT,
    reason TEXT,
    created_at TEXT
);

-- PHASE G2.1: mirrors the canonical unit list seeded in database/schema.sql
-- so UnitNormalizationService behaves identically under test — production
-- always has these rows present (seeded at install time) before any import
-- ever runs, so tests must start from the same baseline rather than relying
-- on auto-creation.
INSERT INTO units (code, name) VALUES
    ('GR','Gram'), ('KG','Kilogram'), ('ML','Mililiter'), ('LTR','Liter'),
    ('PCS','Pieces'), ('BOX','Box'), ('KARTON','Karton'), ('KARUNG','Karung'), ('LUSIN','Lusin'),
    ('PACK','Pack'), ('ROLL','Roll');
