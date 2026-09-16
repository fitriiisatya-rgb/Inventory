# Phase A — Existing System Analysis

Source audited: `inventory.html` (11,810 lines, single-file monolith — HTML + CSS +
vanilla JS, no build step) plus `trace-stok-awal.js` (a read-only diagnostic
tool, not part of the runtime app). The uploaded `index_1.php` is the same
codebase, 94 lines behind the repo's `inventory.html` (one added "kewajaran"
sanity-check feature) — the analysis below treats `inventory.html` as
canonical.

## A.1 Architecture today

```
Browser (single HTML file, all state in JS memory)
   │
   ├─ localStorage  ── persists full app state + an "outbox" of unsynced
   │                   mutations, keyed by device_id (line ~1590)
   │
   └─ SYNC_URL (Google Apps Script Web App, line 1573) ── push/pull full
                        JSON state to/from Google Sheets (pushToServer
                        line 1902, pullFromServer line 1965)
```

There is no server-side validation, no real authentication, and no
transactional guarantee anywhere — every rule (FIFO, unit conversion,
negative-stock, permissions) is enforced only in client-side JavaScript that
any user can bypass via devtools.

## A.2 Feature inventory (from function/page audit)

| Area | Status in existing system | Key functions (line refs) |
|---|---|---|
| Dashboard | Present | KPI cards, `getProduksiMasuk` (~7268), `getHpp` (3155), `getTransferByDestination` |
| Master Barang | Present | SKU, unit, price fields; no versioned conversion — direct mutation |
| Supplier / Divisi / Gudang | Present | simple CRUD arrays; divisions matched to transactions **by name string**, not id (`divisions.find(d=>d.name===...)`, e.g. line 4709, 4797) |
| Transaksi Masuk / Keluar | Present | core `qty/price` handling inline in one large transaction function (~2440-2560) |
| Transfer Antar Gudang | Present | `createTransfer`, `confirmTransfer`, `cancelTransfer`, pending-transfer KPI/audit UI |
| FIFO costing | Present, client-side | batch array per SKU (`stockBatches[sku]`), consumption loop at line ~2505-2530 |
| Batch inventory | Present | `kembangkanBatch`, `rampingkanBatch`, `sortBatches` (FEFO for *display*, FIFO by `dateIn` for *costing* — the code has an explicit comment warning not to conflate the two, line ~2505) |
| Produksi / Racik | Present | `getRacikKonsumsi`, `seedProduksiDivisi`; raw-material consumption vs. finished-good output tracked by `source` tag on log rows, not a relational link |
| Stok Opname | Present | "hitung buta ganda" (double-blind count) section (~7268), reconciliation-to-cutoff-date tooling (~7953) |
| Koreksi stok | Present | `terapkanSatuKoreksi`, `usulKoreksiRusak` — ad hoc adjustment, no approval workflow |
| Audit log | Present, client-only | `transactionLog` array rendered by `renderAuditLog`/`renderLog`; `reverseLogStockEffect`/`reapplyLogStockEffect` exist, i.e. the app already knows plain delete is unsafe, but nothing stops a user from calling delete-equivalent paths outside this UI |
| Role & permission | Present, **client-side only** | 3 fixed roles (`admin`/`viewer`/custom from `authConfig.users`), password compared in the browser (Section A.4) |
| Laporan stok / masuk / keluar / HPP | Present | Excel/PDF export via SheetJS + jsPDF, in-browser only |
| Penyusutan | Present | modeled as an adjustment/out type, not a first-class entity |
| Transfer pending | Present | `getPendingTransfersFor`, `notifyPendingTransfersActionable` |
| Tutup Buku | Present | freezes a period's ending value as next period's opening, then **deletes** the period's transactions from the working set (Section 20 risk, below) |
| Opening stock | Present, evolving | `snapshotAwalPeriode`, plus a **just-added** (repo HEAD only) reasonableness guard `kewajaranOpening()` (line ~10507) that blocks Tutup Buku if the opening value is >20x the live stock value — direct evidence the team already hit corrupted-opening incidents |
| Export Excel/PDF | Present | SheetJS + jsPDF/autotable, client-side generation only |
| Scan invoice / manual parsing | Partial | manual entry only; no OCR backend is wired (Section F) |
| Multi-device handling | Present, fragile | `deviceId` + `newUid()` per-mutation UID, outbox replay, full-state merge against Google Sheets (Section A.5) |

## A.3 Google Apps Script / Google Sheets dependency map

| Symbol | Line(s) | Role |
|---|---|---|
| `DEFAULT_SYNC_URL` (hardcoded `script.google.com/macros/...`) | 1573 | Default backend endpoint, overridable via a Sync-URL prompt stored in `localStorage.sync_url` |
| `SYNC_URL` | 1573-2049 | Global mutable endpoint used by every sync call |
| `pushToServer()` | 1902 | Serializes full local state (or outbox) and POSTs to the Apps Script Web App |
| `pullFromServer(silent)` | 1965 | Fetches full state from Google Sheets and merges/overwrites local arrays |
| Settings UI wiring `SYNC_URL = url.trim()` | 2047-2049 | Lets any user repoint the app at an arbitrary Apps Script URL from the browser, no server-side allow-list |

**Every one of these must be deleted, not just disabled**, once MySQL is
live (Section 21) — none of the new services in `/services` call out to
Google in any form.

## A.4 localStorage dependency map

| Key | Line(s) | Current use | New-system status |
|---|---|---|---|
| `device_id` | ~1590 | Per-browser identity for multi-device merge | Drop — server sessions replace this |
| `sync_outbox` | ~1600 | Queue of unsynced mutations, replayed against Google Sheets | Drop — every API call is synchronous + idempotent (Section 12) |
| `sync_url` | 1578, 2047 | Points the app at a Google Apps Script endpoint | Drop entirely |
| Full app state (items, batches, transactionLog, divisions, ...) | throughout | **This is the actual database** — everything lives here between syncs | Drop — MySQL is now the only source of truth; browser keeps zero business state |
| `AUTH_KEY` (`authConfig`) | 11577-11591 | Stores password hashes and default admin/viewer credentials **client-side** | Drop — replaced by `users`/`password_hash()` server-side (Section A.6) |
| `device_operator`, `gudang_extra_names`, `produksi_divisi`, `last_backup_at` | scattered | UI convenience / device-scoped preference | Keep the *pattern* (UI preference only) — this is the one legitimate remaining localStorage use per the new design (Section 22) |

## A.5 Risky logic found (must not be ported as-is)

1. **Full-state sync as the concurrency model.** `pushToServer`/`pullFromServer`
   move the entire dataset back and forth; two devices editing concurrently
   resolve via UID-tagged outbox replay, not a transactional server. This is
   exactly the "full state sync / localStorage merge / Google Sheet version
   merge" pattern Section 11 of the brief calls out to eliminate.
2. **Client-side authentication with hardcoded default credentials.**
   `authConfig` defaults to `adminHash = hashPass('admin123')`,
   `viewerHash = hashPass('lihat123')` (line 11591) when no config exists,
   and `tryLogin()` (11709) compares hashes entirely in the browser. Anyone
   with devtools access can read `authConfig` out of `localStorage` or just
   monkey-patch `applyRole()`. This is not a hardening gap, it is **no real
   access control** — must not be replicated in the API (Section 23 fixes
   this with `password_hash()`/`password_verify()` server-side).
3. **FIFO vs. FEFO ordering ambiguity.** The code carries an explicit,
   hard-won comment (line ~2505) that `sortBatches()` orders by expiry for
   display but costing must stay ordered by `dateIn` — i.e. the team already
   fixed one class of bug here. The new `FifoService::postOut()` preserves
   exactly this rule (`ORDER BY received_date ASC, id ASC`, never expiry),
   and only that rule, at the database layer where it cannot regress.
4. **Batch date = "effective" date, not click time**, for transfers (line
   ~2490): a shipment's FIFO batch date must be the ship date, not the
   confirm-receipt timestamp, or replay/audit tooling and live batches
   disagree. Preserved in the new schema (`warehouse_transfers.ship_date`
   feeds `inventory_batches.received_date`).
5. **Division tracked by name string on transactions, not id**
   (`l.division === d.name`, lines 2557, 3155, 4709, 4797). Renaming a
   division is handled carefully in the existing UI (old transactions keep
   the old name on purpose — that part is *intentionally* a snapshot), but
   matching current-side logic by string equality is brittle (typos, trims,
   case) and has already produced an `isOrphanDiv()` detector (line 4709) to
   find transactions whose division name no longer matches any division
   row. The new schema keeps the *snapshot intent* but with a real FK
   (`inventory_transactions.division_id`) plus the immutable `items`-style
   snapshot pattern generalized (Section 6), removing the string-matching
   failure mode entirely.
6. **Tutup Buku deletes the period's transactions** after freezing the
   ending value as next period's opening — so if the frozen number was
   wrong, the evidence needed to recompute it is already gone. The repo's
   newest commits (`kewajaranOpening`, "Tahan angka Stok Awal yang tidak
   masuk akal sebelum dibekukan") are the team bolting a sanity check onto
   this exact failure mode after apparently being burned by it. The new
   `book_closings` design (schema.sql Section 10) locks a period and stores
   a value snapshot **without ever deleting** `inventory_transactions` rows,
   which structurally removes the failure mode instead of alarming on it
   after the fact.
7. **Negative batches created silently.** Comments in the existing OUT path
   (line ~5301, "Batch minus (negatif) yang masih nyangkut saat ini") show
   negative-quantity batches already occur in production data and get
   carried forward through later FIFO math — with no permission gate, no
   reason, no audit entry. Section 8/`FifoService::postOut()` replaces this
   with an explicit, permissioned, audited override path.
8. **No server-side validation of any kind.** Price, quantity, unit,
   warehouse and SKU validity are all assumed by the browser. A crafted
   request (or a bug in a future UI change) can post anything.
9. **`catch(e){}`-style silent failure around persistence** — e.g. the
   `persistLocal()` quota-exceeded path degrades to a UI toast rather than
   guaranteeing the operation either fully happened or fully didn't. The new
   design's `Database::transaction()` (Section 10 of the brief) makes this
   structurally impossible: either the whole DB transaction commits or it
   rolls back, and callers are required to let exceptions propagate rather
   than swallow them.

## A.6 Data objects (→ mapped 1:1 onto `database/schema.sql`)

`masterSKU[]`, `stockBatches{sku:[...]}`, `transactionLog[]`, `divisions[]`,
`suppliers[]` (implicit, string-keyed), warehouse list (`GUDANG_LIST`,
partly hardcoded — `'scm','cibadak','karangtengah'` appear as literals in
`nilaiStokRealtime()`, line ~10514 — another sign master data and code are
entangled today), transfer records, production/racik records,
`authConfig.users[]`. See `docs/ERD.md` for the full new-schema mapping.

## A.7 Legacy dependencies still outstanding after this phase

See `docs/LEGACY_DEPENDENCIES.md` for OCR/invoice-scan and Google
Drive/Apps Script items that are explicitly out of scope for the MySQL
migration itself (Section 26 of the brief).
