<?php
declare(strict_types=1);

/**
 * Central role -> action map (Phase 2 design review, section 6).
 * Phase 3 only wires the actions foundation modules actually need;
 * SO-workflow actions (mismatch/final/recount/etc.) are listed here
 * for completeness but have no caller until Phase 4.
 */
final class Permissions
{
    private const MATRIX = [
        'master.manage'          => ['SUPERADMIN', 'ADMIN'],
        'user.manage'            => ['SUPERADMIN', 'ADMIN'],
        'stock_import.manage'    => ['SUPERADMIN', 'ADMIN'],
        'report.export'          => ['SUPERADMIN', 'ADMIN', 'SUPERVISOR', 'VIEWER'],
        'history.view'           => ['SUPERADMIN', 'ADMIN', 'SUPERVISOR', 'VIEWER'],

        // Phase 4 actions, listed now so the matrix is the single source
        // of truth from day one instead of growing ad hoc later.
        'session.manage'         => ['SUPERADMIN', 'ADMIN'],
        'session.add_item'       => ['SUPERADMIN'],
        'session.set_not_countable' => ['SUPERADMIN'],
        'counter.count'          => ['COUNTER'],
        'reconciliation.view'    => ['SUPERADMIN'],
        'recount.request'        => ['SUPERADMIN'],
        'final.set'              => ['SUPERADMIN'],
        'approval.admin'         => ['SUPERADMIN', 'ADMIN'],
        'approval.approver'      => ['SUPERADMIN', 'APPROVER'],
    ];

    public static function can(string $role, string $action): bool
    {
        return in_array($role, self::MATRIX[$action] ?? [], true);
    }

    public static function require(string $role, string $action): void
    {
        if (!self::can($role, $action)) {
            Response::error("Forbidden: role {$role} cannot perform {$action}", 403);
        }
    }
}
