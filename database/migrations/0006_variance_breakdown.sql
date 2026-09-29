-- =====================================================================
-- GO-LIVE MVP — explicit PHYSICAL vs AVAILABLE variance (2026-09-30)
-- =====================================================================
--
-- Correction: variance_qty/variance_value (added in migration 0004) were
-- computed against AVAILABLE only, under one ambiguous name. An audit
-- report needs BOTH variances named explicitly:
--
--   variance_physical_qty   = final_physical_base_qty  - system_qty_snapshot
--   variance_available_qty  = final_available_base_qty - system_qty_snapshot
--   variance_physical_value  = variance_physical_qty  * unit_cost_snapshot (NULL if cost unknown)
--   variance_available_value = variance_available_qty * unit_cost_snapshot (NULL if cost unknown)
--
-- Backward-safe: purely additive. The existing variance_qty/variance_value
-- columns are left in place, unchanged in meaning (still the AVAILABLE
-- variant — AVAILABLE remains the default operational/sellable-stock
-- adjustment reference), so nothing that already reads them breaks.
-- New code should read the explicit *_physical_*/*_available_* columns;
-- variance_qty/variance_value are kept only for backward compatibility.
ALTER TABLE stock_opname_finals
    ADD COLUMN variance_physical_qty    DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER variance_qty,
    ADD COLUMN variance_physical_value  DECIMAL(18,2) NULL     AFTER variance_physical_qty,
    ADD COLUMN variance_available_qty   DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER variance_physical_value,
    ADD COLUMN variance_available_value DECIMAL(18,2) NULL     AFTER variance_available_qty;

-- Backfill existing rows (none expected pre-go-live, but safe either way):
-- variance_available_* mirrors the pre-existing variance_qty/variance_value
-- exactly (same formula, same AVAILABLE basis); variance_physical_* is
-- derived from columns already on the table.
UPDATE stock_opname_finals f
JOIN stock_opname_session_items si ON si.id = f.session_item_id
SET
    f.variance_available_qty   = f.variance_qty,
    f.variance_available_value = f.variance_value,
    f.variance_physical_qty    = f.final_physical_base_qty - si.system_qty_snapshot,
    f.variance_physical_value  = CASE WHEN si.unit_cost_snapshot IS NULL THEN NULL
                                       ELSE ROUND((f.final_physical_base_qty - si.system_qty_snapshot) * si.unit_cost_snapshot, 2) END;
