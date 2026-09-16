# Phase D — Frontend Adapter: status and migration guide

**Honest status:** `inventory.html` is 11,810 lines of tightly-coupled
markup/CSS/JS. Rewiring all of it in one pass — every menu, every report,
every modal — is a multi-week effort on its own and has *not* been done in
this pass. What exists so far:

- `public/assets/js/api-client.js` — a working fetch client (`InvApi`)
  against the endpoints implemented in `public/index.php` (Phase C).
- The specific legacy call sites that must be swapped out, and what to swap
  them for, listed below.

Treat this document as the punch list for finishing Phase D, not a
changelog of work already done.

## D.1 What must be removed (do this first, so nothing can silently keep
talking to Google)

| Legacy code | Line(s) in `inventory.html` | Action |
|---|---|---|
| `DEFAULT_SYNC_URL`, `SYNC_URL` | 1573-1578 | Delete. No replacement constant needed — `api-client.js` always calls `/api/...`. |
| `pushToServer()` | 1902 | Delete. Every mutation now calls its specific `InvApi.postX()` at the moment the user saves, instead of queuing for a later full-state push. |
| `pullFromServer(silent)` | 1965 | Delete. Replace full-state pulls with targeted `InvApi.listItems()` / `InvApi.currentStock()` etc. called on page load / on demand. |
| `deviceId`, `newUid()`, `sync_outbox`, `mutasiLokal` | ~1590-1650 | Delete. The idempotency key (`InvApi.newRequestUuid()`) replaces per-mutation UIDs; the server is now always reachable synchronously (no offline-outbox model — see note below if true offline support is still required). |
| `authConfig`, `hashPass()`, `tryLogin()`, hardcoded `admin123`/`lihat123` defaults | 11577-11720 | Delete entirely. Replace with a login form that calls `InvApi.login(username, password)` and a `InvApi.me()` check on load to restore `currentRole`/`currentUsername`. |
| Settings-panel "Sync URL" field | ~2044-2049 | Remove the input; there is nothing for a user to configure per Section 21 of the brief. |

## D.2 What must be added, function by function

| Old in-memory operation | New call | Notes |
|---|---|---|
| Read `masterSKU` array | `InvApi.listItems()` on load | Populate the same local `masterSKU` variable the rest of the UI already reads, so downstream rendering code (tables, dropdowns) needs no further change. |
| `getStock(sku, gudang)` (line 2169) | `InvApi.currentStock(itemId, warehouseId)` | The function *signature* the rest of the UI calls can stay `getStock(sku, gudang)` — make it an async wrapper around the API call, resolve `sku`→`itemId` and `gudang`→`warehouseId` via the already-loaded master lists. This minimizes churn in the ~200 call sites across the file. |
| The IN/OUT transaction-save handler (~2440-2560) | `InvApi.postTransactionIn(...)` / `InvApi.postTransactionOut(...)` | This is the highest-value, highest-risk swap — it is where FIFO, unit conversion and negative-stock now live server-side. Map the existing form fields directly onto the API payload shape used in `tests/run.php`'s fixtures (`item_id`, `warehouse_id`, `input_qty`, `input_unit_id`, `unit_price_input`, `transaction_date`, ...). On a `STOCK_INSUFFICIENT` or `PRICE_ANOMALY` error response, show the existing SweetAlert2 modal instead of the old silent negative-batch creation. |
| `createTransfer`/`confirmTransfer`/`cancelTransfer` | New `/api/transfers*` endpoints (not yet implemented — see Phase C follow-up list) | Compose server-side as one `Database::transaction()` calling `FifoService::postOut()` on the source warehouse then `FifoService::postIn()` on the destination, exactly like the legacy code's two-sided log entries, but atomic. |
| Stok Opname posting | New `/api/stock-opname*` endpoints (not yet implemented) | Each variance line becomes a `stock_adjustments` row + an `ADJUSTMENT`-type `inventory_transactions` row via `FifoService`; the "hitung buta ganda" double-blind UI flow itself does not need to change. |
| Tutup Buku | New `/api/book-closing*` endpoint (not yet implemented) | Per Section 20, this must never delete rows — only insert `book_closings`/`book_closing_lines` and set `status='LOCKED'`. Do not port the legacy delete-after-freeze behavior (Phase A analysis, item 6). |

## D.3 Sequencing recommendation

Do not attempt a single "big bang" cutover of the 11,810-line file. Because
`masterSKU`, `stockBatches`, `transactionLog` etc. are read from dozens of
report/render functions, the safest order is:

1. Swap the **read paths** first (load master/warehouses/current-stock from
   the API instead of `localStorage`), while transactions still post the
   old way. Confirms the read side end-to-end.
2. Swap **Transaksi Masuk / Keluar** next (the highest-value correctness
   fix — FIFO/negative-stock/anomaly now enforced server-side).
3. Swap Transfer, Stok Opname, Produksi, Tutup Buku, each behind its own
   small API surface, in that order (matches the brief's own Phase E
   ordering of import types, which mirrors operational dependency order).
4. Only after all writes go through the API should `localStorage` business
   keys (Section A.4 of the Phase A analysis) actually be deleted from the
   file — keep them dead-but-present during the swap so a partial rollout
   never leaves a mixed read/write state that looks like data loss.

## D.4 Offline / connectivity note

The legacy app's outbox model existed to tolerate spotty connectivity on
warehouse devices. The new model assumes a reachable server for every
write. If offline capture is still a real requirement (confirm with the
user before building this — it is not in the original 32-point brief), the
right place for it is a dedicated offline queue in the frontend that still
targets the idempotent `/api/transactions/*` endpoints on reconnect, not a
return to full-state sync.
