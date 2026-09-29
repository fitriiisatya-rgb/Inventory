<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHASE V2.14.11.3 — URGENT HOTFIX: account management for "Petugas Stock
 * Opname" (OPNAME_COUNTER), an independent login role a SUPERADMIN can
 * create and manage separately from P1/P2 SESSION assignment
 * (StockOpnameService::assignTeamMembers()). Concept: create the account
 * first, here; assign it to a specific session's P1/P2 team second,
 * elsewhere — this service never touches stock_opname_team_members.
 *
 * OPNAME_COUNTER itself carries ZERO role_permissions rows (see the
 * migration/schema.sql seed) — every method here is reachable ONLY via a
 * SUPERADMIN-only route (enforced in public/index.php, role_code check,
 * not a permission — matching this codebase's existing pattern for
 * security-sensitive account/role actions). A counter account can never
 * use these operations on itself or anyone else.
 */
final class StockOpnameCounterAccountService
{
    public const ROLE_CODE = 'OPNAME_COUNTER';

    /** Every account whose global role is OPNAME_COUNTER. Never returns password_hash. */
    public static function list(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.full_name, u.username, u.is_active, u.last_login_at, u.created_at
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = :role_code
             ORDER BY u.full_name'
        );
        $stmt->execute(['role_code' => self::ROLE_CODE]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'full_name' => $r['full_name'],
            'username' => $r['username'],
            'is_active' => (bool) $r['is_active'],
            'last_login_at' => $r['last_login_at'],
            'created_at' => $r['created_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Creates a new Petugas Stock Opname account. Role is never a caller
     * input — always resolved to OPNAME_COUNTER server-side. Sets
     * must_change_password = 0 deliberately (SUPERADMIN sets the initial
     * password directly for this quick operational workflow — never
     * require a first-login change by default here, unlike the
     * generated-temp-password provisioning path elsewhere in this app).
     */
    public static function create(PDO $pdo, string $fullName, string $username, string $password, bool $isActive, int $createdBy, string $createdByUsername): array
    {
        $fullName = trim($fullName);
        $username = strtolower(trim($username));

        if ($fullName === '') {
            throw new ValidationException(['full name is required']);
        }
        if ($username === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            throw new ValidationException(['username must be 3-40 characters: lowercase letters, digits, dot, underscore, or hyphen only']);
        }
        if (strlen($password) < 8) {
            throw new ValidationException(['password must be at least 8 characters']);
        }

        $roleId = self::roleId($pdo);

        $dupe = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $dupe->execute(['u' => $username]);
        if ($dupe->fetchColumn() !== false) {
            throw new ValidationException(['username is already taken']);
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role_id, division_id, warehouse_id, is_active, must_change_password, created_at, updated_at)
             VALUES (:username, :hash, :full_name, :role_id, NULL, NULL, :is_active, 0, :now1, :now2)'
        );
        $stmt->execute([
            'username' => $username,
            'hash' => password_hash($password, PASSWORD_BCRYPT),
            'full_name' => $fullName,
            'role_id' => $roleId,
            'is_active' => $isActive ? 1 : 0,
            'now1' => $now,
            'now2' => $now,
        ]);
        $userId = (int) $pdo->lastInsertId();

        // Never log the password, hashed or otherwise — only identity/state.
        AuditService::log($pdo, $createdBy, $createdByUsername, 'OPNAME_COUNTER_CREATE', 'users', $userId, null, [
            'username' => $username, 'full_name' => $fullName, 'is_active' => $isActive,
        ], null);

        return [
            'id' => $userId, 'full_name' => $fullName, 'username' => $username,
            'is_active' => $isActive, 'last_login_at' => null, 'created_at' => $now,
        ];
    }

    public static function resetPassword(PDO $pdo, int $userId, string $newPassword, int $actorId, string $actorUsername): void
    {
        if (strlen($newPassword) < 8) {
            throw new ValidationException(['password must be at least 8 characters']);
        }
        self::assertIsCounterAccount($pdo, $userId);

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, updated_at = :now WHERE id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_BCRYPT), 'now' => $now, 'id' => $userId]);

        AuditService::log($pdo, $actorId, $actorUsername, 'OPNAME_COUNTER_RESET_PASSWORD', 'users', $userId, null, null, null);
    }

    public static function activate(PDO $pdo, int $userId, int $actorId, string $actorUsername): void
    {
        self::setActive($pdo, $userId, true, $actorId, $actorUsername, 'OPNAME_COUNTER_ACTIVATE');
    }

    public static function deactivate(PDO $pdo, int $userId, int $actorId, string $actorUsername): void
    {
        self::setActive($pdo, $userId, false, $actorId, $actorUsername, 'OPNAME_COUNTER_DEACTIVATE');
    }

    private static function setActive(PDO $pdo, int $userId, bool $active, int $actorId, string $actorUsername, string $actionCode): void
    {
        $before = self::assertIsCounterAccount($pdo, $userId);

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE users SET is_active = :active, updated_at = :now WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'now' => $now, 'id' => $userId]);

        // PHASE V2.14.11.3 — deactivation NEVER deletes the account, nor
        // any row that references it (findings, stock_opname_team_members,
        // audit history) — a deactivated counter's historical identity on
        // past findings/assignments is preserved exactly as-is; only
        // future login and future team assignment are blocked (enforced
        // in AuthService::attemptLogin()'s existing is_active=1 check, and
        // in StockOpnameService::assignTeamMembers()'s existing active-user
        // check — neither needed to change for this).
        AuditService::log($pdo, $actorId, $actorUsername, $actionCode, 'users', $userId, ['is_active' => (bool) $before['is_active']], ['is_active' => $active], null);
    }

    /** @return array{is_active:bool} */
    private static function assertIsCounterAccount(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            'SELECT u.is_active FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id AND r.code = :role_code'
        );
        $stmt->execute(['id' => $userId, 'role_code' => self::ROLE_CODE]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new NotFoundException('Petugas Stock Opname account not found');
        }
        return ['is_active' => (bool) $row['is_active']];
    }

    private static function roleId(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE code = :code');
        $stmt->execute(['code' => self::ROLE_CODE]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            // Should be unreachable once the migration/schema seed has run —
            // fails loudly rather than silently assigning some other role.
            throw new ValidationException(['OPNAME_COUNTER role is not seeded — run the V2.14.11.3 migration first']);
        }
        return (int) $id;
    }
}
