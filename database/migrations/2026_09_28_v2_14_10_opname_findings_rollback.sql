-- ============================================================================
-- Rollback for V2.14.10 Append-Only Multi-Unit Stock Opname Findings.
--
-- Removes ONLY the two new finding tables. stock_opname_lines' aggregate
-- columns (p1_qty_base/p2_qty_base/condition columns) are NOT touched —
-- whatever they held at the moment of rollback (the last computed
-- aggregate) remains exactly as-is; only the per-finding drilldown
-- history (who submitted what, when, in which units) is lost. No
-- inventory table is affected either way.
--
-- Idempotent: DROP TABLE IF EXISTS, child table first (FK-safe order).
-- ============================================================================

DROP TABLE IF EXISTS stock_opname_finding_units;
DROP TABLE IF EXISTS stock_opname_findings;
