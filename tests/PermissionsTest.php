<?php
declare(strict_types=1);

function test_permissions(): void
{
    T::section('Permissions — Phase 2 revised role matrix (mismatch/final = SUPERADMIN only)');

    T::assertTrue(Permissions::can('SUPERADMIN', 'master.manage'), 'SUPERADMIN can manage master data');
    T::assertTrue(Permissions::can('ADMIN', 'master.manage'), 'ADMIN can manage master data');
    T::assertFalse(Permissions::can('SUPERVISOR', 'master.manage'), 'SUPERVISOR cannot manage master data');
    T::assertFalse(Permissions::can('COUNTER', 'master.manage'), 'COUNTER cannot manage master data');

    T::assertTrue(Permissions::can('SUPERADMIN', 'stock_import.manage'), 'SUPERADMIN can import stock');
    T::assertTrue(Permissions::can('ADMIN', 'stock_import.manage'), 'ADMIN can import stock');
    T::assertFalse(Permissions::can('COUNTER', 'stock_import.manage'), 'COUNTER cannot import stock');

    // The corrected, tightened matrix: mismatch/final/recount = SUPERADMIN only,
    // explicitly excluding ADMIN and SUPERVISOR "for now".
    T::assertTrue(Permissions::can('SUPERADMIN', 'reconciliation.view'), 'SUPERADMIN can view mismatch/reconciliation');
    T::assertFalse(Permissions::can('ADMIN', 'reconciliation.view'), 'ADMIN CANNOT view mismatch (tightened per correction)');
    T::assertFalse(Permissions::can('SUPERVISOR', 'reconciliation.view'), 'SUPERVISOR CANNOT view mismatch (tightened per correction)');
    T::assertFalse(Permissions::can('COUNTER', 'reconciliation.view'), 'COUNTER cannot view mismatch');

    T::assertTrue(Permissions::can('SUPERADMIN', 'recount.request'), 'SUPERADMIN can request recount');
    T::assertFalse(Permissions::can('ADMIN', 'recount.request'), 'ADMIN cannot request recount');

    T::assertTrue(Permissions::can('SUPERADMIN', 'final.set'), 'SUPERADMIN can set final');
    T::assertFalse(Permissions::can('ADMIN', 'final.set'), 'ADMIN cannot set final');

    T::assertTrue(Permissions::can('SUPERADMIN', 'session.add_item'), 'SUPERADMIN can add item to active session');
    T::assertFalse(Permissions::can('ADMIN', 'session.add_item'), 'ADMIN cannot add item to active session');

    T::assertTrue(Permissions::can('COUNTER', 'counter.count'), 'COUNTER can submit counts');
    T::assertFalse(Permissions::can('VIEWER', 'counter.count'), 'VIEWER cannot submit counts');

    T::assertTrue(Permissions::can('ADMIN', 'approval.admin'), 'ADMIN can record ADMIN approval');
    T::assertTrue(Permissions::can('APPROVER', 'approval.approver'), 'APPROVER can record APPROVER approval');
    T::assertFalse(Permissions::can('ADMIN', 'approval.approver'), 'ADMIN cannot record the APPROVER-slot approval');
}
