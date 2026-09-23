-- ============================================================================
-- Rollback for database/migrations/2026_09_23_v2_11b_pricing_invoice.sql
--
-- Purely additive forward migration -> purely subtractive rollback. Never
-- touches distribution_orders/distribution_order_lines (V2.11A),
-- inventory data, or item_price_history.
--
-- Only safe to run while no distribution_invoices row is ISSUED — check
-- first:
--   SELECT COUNT(*) FROM distribution_invoices WHERE status = 'ISSUED';
-- A non-zero count means real financial documents already exist;
-- dropping these tables would destroy that record.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'DISTRIBUTION_PRICING_MANAGE';
DELETE FROM permissions WHERE code = 'DISTRIBUTION_PRICING_MANAGE';

DROP TABLE IF EXISTS distribution_invoice_lines;
DROP TABLE IF EXISTS distribution_invoices;
DROP TABLE IF EXISTS distribution_pricing_policies;
