-- ============================================================================
-- Rollback for database/migrations/2026_09_24_v2_11a_hotfix_distribution_permissions.sql
--
-- SCOPE: this rollback removes ONLY what the hotfix added — the six
-- DISTRIBUTION_VIEW/CREATE/APPROVE/DISPATCH/RECEIVE/REVERSE permission
-- rows and their role_permissions grants. It does NOT touch
-- distribution_orders, distribution_order_lines, or any other table —
-- those were created by the original 2026_09_23_v2_11a migration and are
-- rolled back (if ever needed) only by that migration's own
-- _rollback.sql, never by this file.
--
-- WARNING — read before running against any database with real usage:
-- if this hotfix has already been live and any user has actually used a
-- distribution permission (viewed/created/approved/dispatched/received/
-- reversed a Delivery Order) while relying on these role_permissions
-- rows, removing the rows now does not undo anything they already did —
-- it only means the NEXT permission check for that role fails again,
-- reproducing the exact "Missing permission: DISTRIBUTION_VIEW" defect
-- this hotfix fixed. This rollback is for undoing a bad hotfix deploy
-- before it has been relied on, not a way to "undo" distribution
-- activity — distribution business data is untouched either way.
--
-- Safe to run multiple times (each DELETE is naturally idempotent — a
-- row that's already gone is simply not matched again).
-- ============================================================================

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code IN ('DISTRIBUTION_VIEW','DISTRIBUTION_CREATE','DISTRIBUTION_APPROVE','DISTRIBUTION_DISPATCH','DISTRIBUTION_RECEIVE','DISTRIBUTION_REVERSE');

DELETE FROM permissions
WHERE code IN ('DISTRIBUTION_VIEW','DISTRIBUTION_CREATE','DISTRIBUTION_APPROVE','DISTRIBUTION_DISPATCH','DISTRIBUTION_RECEIVE','DISTRIBUTION_REVERSE');
