# Phase V2.5A — Correction Permission Hardening

Reviewed commit: `ac22664`. Corrects a permission mismatch in Phase V2.5
(`docs/PHASE_V2_5_TRANSACTION_CORRECTION.md`): the owner's original
requirement was **SUPERADMIN only** for VOID (posted IN/OUT/ADJUSTMENT) and
REVERSE RECEIVED TRANSFER; V2.5 shipped with ADMIN granted equivalently.
No production access, no deployment, no production data mutation.

## 1. Files changed

| File | Change |
|---|---|
| `database/schema.sql` | ADMIN's blanket "everything except an exclusion list" grant now also excludes `TRANSACTION_VOID` and `TRANSFER_REVERSE`. |
| `database/migrations/2026_09_20_v2_5a_correction_permission_hardening.sql` (+`_rollback.sql`) | **New**, staged migration — revokes ADMIN's `TRANSACTION_VOID` and `TRANSFER_REVERSE` role_permissions rows. Not run against production. |
| `public/assets/js/auth.js` | The frontend's cosmetic permission mirror (`ROLE_PERMISSIONS.ADMIN = ['*']`) didn't understand exclusions at all — a real gap this phase closes with a new `ADMIN_EXCLUDED_PERMISSIONS` list (`TRANSACTION_VOID`, `TRANSFER_REVERSE`, plus the two pre-existing ADMIN exclusions — `SYSTEM_SETTINGS_MANAGE`, `USER_MANAGE`, `TRANSACTION_VOID_LOCKED_PERIOD` — that the wildcard was also silently over-granting cosmetically before this fix). `Auth.hasPermission()` now checks it before falling through to the wildcard. |
| `public/index.html` | Cache-busting version bump. |
| `tests/inventory_v25_transaction_correction_test.php` | HTTP block extended: renamed the misleadingly-named SUPERADMIN fixture variables, added a real ADMIN-role session, 5 new assertions (V2.5A tests 1, 2, 5, 10 — 3 and 4 reuse the existing STOCK/SUPERADMIN checks). |
| `docs/PHASE_V2_5_TRANSACTION_CORRECTION.md` | Amendment note pointing to this document (left otherwise unedited as the historical record). |
| `docs/PHASE_V2_5A_PERMISSION_HARDENING.md` | This document. |

**No backend route code changed.** `POST /transactions/{id}/void` and
`POST /transfers/{id}/reverse` already gate purely on
`AuthService::hasPermission()`, which is 100% `role_permissions`-table-driven
with no role-code special-casing (confirmed by reading it) — revoking the
grant in the data was sufficient to enforce this server-side for both
routes; no equivalent correction endpoint exists elsewhere in the codebase.

## 2. Permission code — before / after

| Role | `TRANSACTION_VOID` before | `TRANSACTION_VOID` after | `TRANSFER_REVERSE` before | `TRANSFER_REVERSE` after |
|---|---|---|---|---|
| SUPERADMIN | ✅ | ✅ (unchanged) | ✅ | ✅ (unchanged) |
| **ADMIN** | ✅ | **❌ (revoked)** | ✅ | **❌ (revoked)** |
| STOCK | ❌ | ❌ (unchanged) | ❌ | ❌ (unchanged) |
| DIVISION | ❌ | ❌ (unchanged) | ❌ | ❌ (unchanged) |
| VIEWER | ❌ | ❌ (unchanged) | ❌ | ❌ (unchanged) |

`TRANSACTION_VOID_LOCKED_PERIOD` was already SUPERADMIN-only before V2.5
(original schema) — untouched.

`WAREHOUSE_TRANSFER_MANAGE` (normal PENDING-transfer create/receive/cancel)
is a **separate permission code**, not touched by this hardening — STOCK and
ADMIN keep exactly the same PENDING-transfer-cancel behavior as before
(confirmed by test and by direct schema diff: no change to that grant).

Verified directly against a fresh schema build:

```
mysql> SELECT r.code, p.code FROM role_permissions rp
       JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
       WHERE p.code IN ('TRANSACTION_VOID','TRANSFER_REVERSE') ORDER BY r.code, p.code;
+------------+-------------------+
| code       | code              |
+------------+-------------------+
| SUPERADMIN | TRANSACTION_VOID  |
| SUPERADMIN | TRANSFER_REVERSE  |
+------------+-------------------+
```

## 3. Test results

**`tests/inventory_v25_transaction_correction_test.php`: 86/86 PASSED**
(was 81/81 — 5 new assertions), including the 10 explicitly required cases:

| # | Case | Result |
|---|---|---|
| 1 | SUPERADMIN can void eligible IN | PASS (real HTTP round trip) |
| 2 | ADMIN receives 403 for void | PASS — `FORBIDDEN`, transaction left `POSTED` |
| 3 | STOCK receives 403 for void | PASS — `FORBIDDEN` |
| 4 | SUPERADMIN can reverse eligible RECEIVED transfer | PASS (real HTTP round trip) |
| 5 | ADMIN receives 403 for transfer reversal | PASS — `FORBIDDEN`, transfer left `RECEIVED` |
| 6 | STOCK receives 403 for transfer reversal | PASS — `FORBIDDEN` |
| 7 | Correction buttons hidden for ADMIN | PASS — browser-verified, 0 buttons |
| 8 | Correction buttons hidden for STOCK | PASS — browser-verified, 0 buttons |
| 9 | SUPERADMIN buttons still visible | PASS — browser-verified, 1 each |
| 10 | Existing PENDING-transfer-cancel permission unchanged | PASS — ADMIN successfully cancels a fresh PENDING transfer over HTTP |

Browser matrix (`v25a_button_matrix.js` against a live seeded instance,
zero console errors on any role):

| Role | Void Transaksi (POSTED OUT) | Reverse Transfer (RECEIVED) | Batalkan Transfer (PENDING) |
|---|---|---|---|
| SUPERADMIN | 1 (visible) | 1 (visible) | 1 (visible) |
| ADMIN | 0 (hidden) | 0 (hidden) | 1 (visible, unchanged) |
| STOCK | 0 (hidden) | 0 (hidden) | 1 (visible, unchanged) |

## 4. Full regression total

```
mysql_smoke_test.php                             4/4
mysql_integration_test.php                      35/35
mysql_importer_test.php                         17/17
mysql_void_test.php                             15/15
mysql_security_test.php                         11/11
opening_g_data_2_test.php                       20/20
migration_negative_stock_test.php               31/31
warehouse_isolation_regression_test.php         28/28
stock_policy_test.php                           34/34
master_data_v2_test.php                         27/27
stock_report_test.php                           26/26
transaction_history_test.php                    32/32
master_data_v2_1_test.php                       54/54
trace_test.php                                 111/111
inventory_hpp_report_test.php                   50/50
inventory_hpp_costing_audit_test.php            51/51
inventory_hpp_v23c_production_hotfix_test.php   32/32
inventory_hpp_v23d_cutover_test.php             48/48
inventory_v25_transaction_correction_test.php   86/86  (+5 from V2.5A)
concurrency_test.sh                              5/5
-------------------------------------------------------
TOTAL                                         717/717 PASSED, 0 FAILED
```

## 5. Final commit

`a66c9f9d987493e7fa3c997d2ca3258957c93469` on branch
`claude/funny-ramanujan-wmrlig`.

## 6. Push status

Pushed to `origin/claude/funny-ramanujan-wmrlig`. Not deployed to
production, per the explicit instruction.
