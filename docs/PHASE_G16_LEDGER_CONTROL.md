# Phase G16 — Ledger Control: Opening vs Historical

## Requirement

Opening Stock import must produce a real ledger "OPENING BALANCE" entry.
Historical Transaction import may be visible in reports, but must **never**
affect current balance or appear in the Ledger.

## How this is enforced (verified, not just asserted)

`InventoryService::ledger()` (`services/InventoryService.php`) is the single
query every ledger view in the system reads from — `GET /inventory/ledger`,
and `reports.js`'s "Histori Barang" screen. Its `WHERE` clause is:

```sql
WHERE l.item_id = :item_id AND l.warehouse_id = :wh
  AND t.status = 'POSTED' AND t.inventory_effect = 1
```

`ImportHistoricalTransactionService::insertHistoricalRow()` always inserts
with `inventory_effect = 0` (hardcoded in the INSERT, not a parameter the
importer or frontend can override — see G7's requirement that this is
server-forced). The `inventory_effect = 1` filter above means a historical
row is not merely "zeroed out" in the balance — it is **structurally
excluded from the ledger query's result set entirely**. There is no code
path in this system that can compute a balance that includes a historical
row, because the ledger never selects one in the first place.

`ImportOpeningStockService::commit()` posts each line through
`FifoService::postIn(..., 'transaction_type' => 'OPENING', ...)`, which
sets `inventory_effect = 1` like any other real transaction. It therefore
appears in the ledger as a normal line, with `transaction_type = 'OPENING'`
— confirmed in this session (`mysql_importer_test.php`, assertion "Ledger
shows an OPENING-type line (OPENING)": PASS) and again in the Phase G0
regression run (35/35 integration assertions PASS, including that same
check).

## UI distinction

`public/assets/js/imports.js` (Phase D12) already renders a permanent,
non-removable banner on the Historical Transaction import screen:
*"HISTORICAL IMPORT — tidak mempengaruhi saldo stok"* — there is no control
anywhere in that file that could flip `inventory_effect`. The Ledger view
(`reports.js`) simply never has historical rows to show, by construction
above, so no additional client-side filtering was needed to keep the two
visually distinct.

## What is explicitly NOT built yet (out of this batch's scope)

A dedicated "Historical Transactions report" view (distinct from the
Ledger, for browsing imported historical rows on their own) is G7/Phase-D
report-module territory, not part of G12–G17. Historical rows are queryable
today via `inventory_transactions WHERE is_historical_import = 1`, but no
frontend screen surfaces that yet. Noted as a follow-up, not built in this
batch.

## Status

**Verified**, not just designed: the exclusion is enforced by the shared
`InventoryService::ledger()` query (one place, not duplicated logic to
drift out of sync), and both directions were exercised by real tests in
this session — Opening appears in the ledger, Historical does not, and
stock is provably unchanged by a historical import (`before=125 after=125`
in `mysql_importer_test.php`).
