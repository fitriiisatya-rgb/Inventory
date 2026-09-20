-- ============================================================================
-- Inventory FIFO Pro V2.5A — Correction Permission Hardening
-- Phase V2.5A (docs/PHASE_V2_5A_PERMISSION_HARDENING.md)
--
-- DO NOT RUN THIS AGAINST PRODUCTION without owner approval. Run against a
-- local/staging database only.
--
-- Requirement (owner correction to V2.5): ONLY SUPERADMIN may void a posted
-- transaction (IN/OUT/ADJUSTMENT) or reverse a RECEIVED transfer. ADMIN is
-- explicitly NOT treated as equivalent to SUPERADMIN for these two actions,
-- unlike every other ADMIN grant in this schema's permission model.
--
-- Purely revocative, and purely role_permissions rows — no permission code,
-- no table, no column is added, renamed, or dropped:
--   1. Revokes ADMIN's TRANSACTION_VOID grant. This permission predates
--      V2.5 (Phase D0.1) — ADMIN has held it since the original schema's
--      blanket "ADMIN gets everything except an exclusion list" grant, and
--      this migration is what first excludes it.
--   2. Revokes ADMIN's TRANSFER_REVERSE grant, in case the V2.5 migration
--      (2026_09_20_v2_5_transaction_correction.sql) already ran and granted
--      it. A no-op (DELETE affecting 0 rows) if V2.5 hasn't been applied yet
--      or was already applied with this same fix folded in.
--
-- SUPERADMIN is untouched (already holds every permission via its own
-- CROSS JOIN grant, independent of ADMIN's row here). STOCK/DIVISION/VIEWER
-- never held either permission — untouched. WAREHOUSE_TRANSFER_MANAGE
-- (normal PENDING-transfer create/receive/cancel) is a completely separate
-- permission code and is NOT touched by this migration — STOCK and ADMIN
-- keep cancelling PENDING transfers exactly as before.
-- ============================================================================

DELETE rp FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
WHERE r.code = 'ADMIN' AND p.code IN ('TRANSACTION_VOID', 'TRANSFER_REVERSE');
