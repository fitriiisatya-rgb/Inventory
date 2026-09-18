# Phase 4 — Task B: `GET /items` Compatibility Confirmation & Technical Debt

**Status: COMPLETE.** `GET /items` is unmodified, still returns every row
unpaginated in the frozen `{success, data, message}` envelope, and none of
the new V2 report/history pages load their data through it.

## 1. Confirmation: `GET /items` is untouched

Handler, `public/index.php:279-282`:

```php
'GET /items' => function () use ($pdo) {
    inv_require_auth();
    inv_ok($pdo->query('SELECT * FROM items ORDER BY name')->fetchAll(), 'OK');
},
```

- No pagination parameters, no filtering, no sorting options — same
  `SELECT * FROM items ORDER BY name` query as the original Phase A-F
  implementation.
- Response shape unchanged: `{"success":true,"data":[...],"message":"OK"}`,
  `data` being the full unfiltered array of item rows.
- Git history check (`git log -p -S"'GET /items' =>" -- public/index.php`)
  shows this handler body was introduced once, in the initial MySQL rebuild
  commit (`66b85f5`), and has only ever been touched by the D0.3 API-contract
  standardization pass (switching `inv_respond(200, true, ...)` to the
  `inv_ok()` helper — a wrapper-only change applied identically to every
  endpoint in the app, not specific to `/items`). No Phase 3 or Phase 4 V2
  commit has modified this route.
- Confirmed no `LIMIT`/`OFFSET`/`page`/`per_page` was added: grepped the
  handler body and `InvApi.listItems()` (`api-client.js:109`, `request('GET',
  '/items')`) — no query-string parameters are sent or accepted.

## 2. Confirmation: new V2 report/history pages do NOT depend on `GET /items`

Traced every caller of `InvApi.listItems()` / `Master.items()`
(`public/assets/js/master.js:19-28` — the only place `listItems()` is
called, cached once per page-load as `Master.items()`):

| Consumer | Uses `Master.items()` for | Why this is fine |
|---|---|---|
| `stock-report.js` ("Stok Barang" / Laporan Stok) | **Not used at all.** Data comes exclusively from `InvApi.stockReport()` → `GET /reports/stock`, server-side paginated/filtered/sorted (`stock-report.js:38-62`). Filters are `q` (text), `category_id`, `status`, `warehouse_id` — no item dropdown. | New report page is fully decoupled from `/items`. |
| `transaction-history.js` ("History Transaksi") | **Not used at all.** Data comes from `InvApi.transactionReport()` → `GET /reports/transactions` and `InvApi.transactionDetail()`, both server-side paginated. Filters use `Master.warehouses()/categories()/suppliers()/bakeryDestinations()` for dropdowns and a free-text `q` for item/SKU search (matched server-side) — no item dropdown, no `item_id` filter control. | New history page is fully decoupled from `/items`. |
| `dashboard.js` | **Not used at all.** KPIs come from `InvApi.stockReport({ per_page: 1 })` (for the summary block only) plus existing reconciliation/transfer/opname endpoints. | Decoupled. |
| `transactions.js` (Stock IN/OUT stepper, Task A) | Populates the item `<select>` dropdown in step 2 of both steppers (`transactions.js:175,339`), and resolves a selected item's `sku`/`name` for the Review step via `Master.itemById()`. | Legitimate, pre-existing use — a data-entry form needs the item list to let the operator pick one. This is unchanged from the pre-V2 flat form and is not a "report loading all rows through `GET /items`" case the owner's constraint was aimed at. |
| `transfers.js`, `production.js`, `adjustments.js`, `audit.js` (legacy Phase C/D pages, untouched by V2) | Same "populate an item picker dropdown" pattern as above. | Pre-existing, unchanged by Phase 3/4. |

**Conclusion:** every new V2 report/history page (`stock-report.js`,
`transaction-history.js`, `dashboard.js`) reads through the new paginated
`/reports/*` endpoints exclusively. `GET /items` is only ever used to
populate item-picker `<select>` dropdowns on data-entry forms (both
pre-existing and the new stepper), never to back a list/report view.

## 3. Technical debt (documented, not fixed this phase)

`GET /items` returns the entire `items` table on every page load
(`Master.loadAll()` calls it once when the app shell boots, cached in
memory for the page's lifetime). At the real-scale data volume referenced
in `docs/PHASE_G_DATA_FINAL_DRY_RUN_SCM_CIBADAK.md` (1,007 items across
SCM + Cibadak), this is a single ~1k-row unpaginated payload per session —
acceptable today, but it does not scale indefinitely as the item master
grows (more warehouses being onboarded, e.g. Karang Tengah, plus organic
SKU growth).

This was intentionally left alone per the Phase 2 approval condition: *"Do
NOT paginate or change the `GET /items` response contract... consider but
do NOT mandate a future `GET /items/options?q=` endpoint."*

**Recommendation for a future phase (not Phase 4):** add a new,
additive-only `GET /items/options?q=` endpoint returning a lightweight
`{id, sku, name}` shape with server-side search, and migrate the item
picker `<select>` components (stepper, transfers, production, adjustments)
to a searchable async-load control backed by it. `GET /items` itself would
remain untouched for any other consumer. No action taken this phase —
flagging only, as instructed.

## 4. Evidence commands

```bash
grep -n "'GET /items'" public/index.php
git log -p -S"'GET /items' =>" -- public/index.php
grep -rn "listItems\|Master\.items(" public/assets/js
```
