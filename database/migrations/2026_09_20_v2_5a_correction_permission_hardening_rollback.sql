-- ============================================================================
-- Rollback for database/migrations/2026_09_20_v2_5a_correction_permission_hardening.sql
--
-- Restores ADMIN's TRANSACTION_VOID and TRANSFER_REVERSE grants — i.e.
-- reverts to the pre-V2.5A (SUPERADMIN+ADMIN can correct) behavior. Only
-- run this if the owner explicitly asks to undo the hardening.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'ADMIN' AND p.code IN ('TRANSACTION_VOID', 'TRANSFER_REVERSE');
