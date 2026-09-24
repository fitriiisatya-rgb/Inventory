-- ============================================================================
-- Inventory FIFO Pro — HOTFIX for database/migrations/2026_09_23_v2_11a_distribution_orders.sql
--
-- PRODUCTION DEFECT (found post-deploy on an existing database upgraded
-- through the V2.11A migration): that migration created the
-- distribution_orders/distribution_order_lines tables but never inserted
-- the six DISTRIBUTION_* permission rows, nor granted them to any role.
-- A FRESH install is unaffected — database/schema.sql already declares
-- these six permissions and grants them (SUPERADMIN via its blanket
-- CROSS JOIN, ADMIN via its NOT-IN exclusion list, STOCK via its explicit
-- list) — but those blanket grants only ever run once, at initial schema
-- load. An EXISTING database that reached this state by applying the
-- migration chain incrementally never got a permissions/role_permissions
-- row for any of the six, so every distribution route/permission check
-- failed with "Missing permission: DISTRIBUTION_VIEW" (or the other five)
-- for every role, including SUPERADMIN.
--
-- Do NOT edit 2026_09_23_v2_11a_distribution_orders.sql — that migration
-- is already deployed to production; this hotfix runs standalone, after
-- it, and after every migration through 2026_09_23_v2_12a (this repo's
-- migrations are read in filename order; the 2026_09_24 date makes this
-- sort after all five 2026_09_23 migrations without needing to touch any
-- of them).
--
-- IDEMPOTENT / SAFE TO RE-RUN, and safe on a database that already has
-- some or all of these rows (e.g. a fresh install, or a database this
-- hotfix already ran against once): every statement is INSERT IGNORE
-- against a UNIQUE key (permissions.code) or a PRIMARY KEY
-- (role_permissions.(role_id, permission_id)), so a row that already
-- exists is silently skipped rather than erroring or duplicating.
--
-- Never touches DISTRIBUTION_PRICING_MANAGE or DISTRIBUTION_REPORT_VIEW
-- (added correctly by their own V2.11B/V2.11C migrations) — this hotfix
-- only ever references the six V2.11A distribution-lifecycle permission
-- codes by exact name.
--
-- Changes no inventory quantity, no inventory value, no existing
-- distribution_orders/distribution_order_lines row. Permissions/
-- role_permissions only.
-- ============================================================================

INSERT IGNORE INTO permissions (code, description) VALUES
    ('DISTRIBUTION_VIEW',     'View Delivery Orders and distribution history'),
    ('DISTRIBUTION_CREATE',   'Create a Delivery Order (DRAFT)'),
    ('DISTRIBUTION_APPROVE',  'Approve a DRAFT Delivery Order and start picking'),
    ('DISTRIBUTION_DISPATCH', 'Dispatch a Delivery Order — posts the real Stock OUT'),
    ('DISTRIBUTION_RECEIVE',  'Record Bakery receipt of a dispatched Delivery Order'),
    ('DISTRIBUTION_REVERSE',  'Reverse a dispatched Delivery Order (restores FIFO) — privileged correction action');

-- SUPERADMIN: all six (matches schema.sql's fresh-install CROSS JOIN,
-- restated explicitly here for a database that already existed before
-- this hotfix ran).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'SUPERADMIN'
  AND p.code IN ('DISTRIBUTION_VIEW','DISTRIBUTION_CREATE','DISTRIBUTION_APPROVE','DISTRIBUTION_DISPATCH','DISTRIBUTION_RECEIVE','DISTRIBUTION_REVERSE');

-- ADMIN: all six EXCEPT DISTRIBUTION_REVERSE (matches schema.sql's
-- NOT-IN exclusion list — DISTRIBUTION_REVERSE is SUPERADMIN-only, the
-- same privileged-correction-action pattern as TRANSACTION_VOID and
-- TRANSFER_REVERSE).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN'
  AND p.code IN ('DISTRIBUTION_VIEW','DISTRIBUTION_CREATE','DISTRIBUTION_APPROVE','DISTRIBUTION_DISPATCH','DISTRIBUTION_RECEIVE');

-- STOCK: operational dispatch only — DISTRIBUTION_VIEW + DISTRIBUTION_DISPATCH
-- (matches schema.sql's explicit STOCK grant list — never
-- pricing/reporting/order-approval, per the owner's original "operational
-- permissions only" instruction for this role).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'STOCK'
  AND p.code IN ('DISTRIBUTION_VIEW','DISTRIBUTION_DISPATCH');

-- DIVISION and VIEWER: intentionally receive NONE of the six — no insert
-- statement for them at all (matches schema.sql, where neither role's
-- grant list mentions any DISTRIBUTION_* code).
