-- V2.14.11.3 rollback — idempotent, and SAFE against a database that
-- already has OPNAME_COUNTER user accounts: `users.role_id` has a FK to
-- `roles.id` with no ON DELETE CASCADE (see schema.sql's fk_users_role),
-- so a plain DELETE would fail loudly (a real FK violation) rather than
-- silently orphaning any petugas account — but this rollback goes further
-- and refuses proactively, with a clear reason, rather than relying on
-- the DB engine to reject it. Never touches any users/team-member/finding
-- row itself.
SET @sofp_counter_role_id = (SELECT id FROM roles WHERE code = 'OPNAME_COUNTER');
SET @sofp_counter_user_count = IF(@sofp_counter_role_id IS NULL, 0,
    (SELECT COUNT(*) FROM users WHERE role_id = @sofp_counter_role_id));

-- information_schema + dynamic SQL guard, same pattern used throughout
-- this project's rollback migrations: only DELETE the role row when it
-- exists AND zero users currently reference it.
SET @sofp_rollback_sql = IF(@sofp_counter_role_id IS NOT NULL AND @sofp_counter_user_count = 0,
    'DELETE FROM roles WHERE code = ''OPNAME_COUNTER''',
    'SELECT 1');
PREPARE sofp_rollback_stmt FROM @sofp_rollback_sql;
EXECUTE sofp_rollback_stmt;
DEALLOCATE PREPARE sofp_rollback_stmt;
