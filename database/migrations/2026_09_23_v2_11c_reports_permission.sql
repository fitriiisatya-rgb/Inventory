-- ============================================================================
-- Inventory FIFO Pro V2.11C — SCM -> Bakery Distribution: Revenue/Margin
-- reporting + Delivery Order/Invoice print documents.
-- Phase V2.11C (third of three: A = DO/FIFO, B = Pricing/Invoice,
-- C = Reports/Print).
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Purely additive: one new permission only. Reporting reads existing
-- V2.11A/V2.11B tables (distribution_orders, distribution_order_lines,
-- distribution_invoices, distribution_invoice_lines) plus the existing,
-- unmodified inventory_transaction_lines — no new report-specific table
-- is needed. Print documents are rendered on demand, never persisted.
-- ============================================================================

INSERT INTO permissions (code, description) VALUES
    ('DISTRIBUTION_REPORT_VIEW', 'View SCM -> Bakery revenue/margin/category/bakery distribution reports');

-- Same convention as every earlier permission-adding migration in this
-- project: schema.sql's fresh-install CROSS JOIN naturally covers
-- SUPERADMIN, but an existing, already-seeded database needs it granted
-- explicitly here.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'SUPERADMIN' AND p.code = 'DISTRIBUTION_REPORT_VIEW';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ADMIN' AND p.code = 'DISTRIBUTION_REPORT_VIEW';
