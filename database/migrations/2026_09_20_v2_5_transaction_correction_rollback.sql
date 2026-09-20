-- ============================================================================
-- Rollback for database/migrations/2026_09_20_v2_5_transaction_correction.sql
--
-- Safe only if no transfer has actually been reversed yet (status='REVERSED'
-- rows would lose their reverse_* audit columns and the enum would reject
-- their status value). This script checks that guard first and aborts
-- loudly rather than silently corrupting a REVERSED transfer's history.
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval, same as the
-- forward migration.
-- ============================================================================

-- Abort if any transfer has already been reversed — rolling back the enum
-- would leave that row's status value invalid.
SELECT CASE WHEN EXISTS (SELECT 1 FROM warehouse_transfers WHERE status = 'REVERSED')
            THEN (SELECT 'ROLLBACK ABORTED: at least one warehouse_transfers row has status=REVERSED — resolve manually before rolling back this migration' FROM DUAL)
       END AS guard_check;

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'TRANSFER_REVERSE';

DELETE FROM permissions WHERE code = 'TRANSFER_REVERSE';

ALTER TABLE warehouse_transfers
    DROP FOREIGN KEY fk_wt_reverser;

ALTER TABLE warehouse_transfers
    DROP COLUMN reverse_reason,
    DROP COLUMN reversed_by,
    DROP COLUMN reversed_at,
    DROP COLUMN reverse_request_uuid;

ALTER TABLE warehouse_transfers
    MODIFY COLUMN status ENUM('PENDING','RECEIVED','CANCELLED') NOT NULL DEFAULT 'PENDING';
