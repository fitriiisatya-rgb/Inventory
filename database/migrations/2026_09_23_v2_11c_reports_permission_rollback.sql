-- ============================================================================
-- Rollback for database/migrations/2026_09_23_v2_11c_reports_permission.sql
-- Purely additive forward migration -> purely subtractive rollback.
-- ============================================================================

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'DISTRIBUTION_REPORT_VIEW';
DELETE FROM permissions WHERE code = 'DISTRIBUTION_REPORT_VIEW';
