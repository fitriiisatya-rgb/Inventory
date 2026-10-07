-- Rollback of 2026_10_07_inventory_effective_dates.sql. Reports return to transaction_date exactly as before (the table only ever ADDED a reporting date).
-- Prefer scripts/rv3/period_cutoff.php rollback (removes only the rows of the listed sessions, audited). This drops the whole table.
DROP TABLE IF EXISTS inventory_effective_dates;
