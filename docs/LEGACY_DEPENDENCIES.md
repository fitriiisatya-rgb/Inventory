# Legacy Dependencies (Section 26)

Kept explicitly out of scope for the MySQL migration itself. Do not let a
user believe any of these work against the new backend until they are
actually implemented there.

| Dependency | Where it lives today | Status in new system |
|---|---|---|
| Google Apps Script Web App (`SYNC_URL`, `script.google.com/macros/...`) | `inventory.html` lines 1573-2049, `pushToServer`/`pullFromServer` | **LEGACY_DEPENDENCY** — must be deleted, not ported. No file under `/api` or `/services` calls out to Google in any form. |
| Google Sheets as data store | implicit, via the Apps Script endpoint | **LEGACY_DEPENDENCY** — MySQL (`database/schema.sql`) is the only source of truth going forward (Section 2/9 of the brief). |
| OCR / scanned-invoice parsing | Not actually wired to a working backend in the audited source (manual parsing only; no OCR call site found) | **LEGACY_DEPENDENCY** — Section 26 asks that this be clearly flagged rather than implied to work. The new API layer defines no OCR endpoint yet. When it is built, it should sit behind an `OcrProviderInterface` in `/services` so a provider (Google Vision, Azure Document Intelligence, a local Tesseract service, etc.) can be swapped without touching `FifoService` or the transaction endpoints. **Do not surface an "OCR scan invoice" button in the adapted frontend until a real provider is wired — a dead button that silently no-ops is worse than no button.** |
| Google Drive (attachment storage, if used for invoice photos) | Not found as a direct dependency in the audited file, but referenced conceptually alongside OCR in the brief | Flagged preemptively as **LEGACY_DEPENDENCY** — if a future invoice-photo feature is added, store the file via the new app's own upload endpoint (local disk or S3-compatible bucket per deployment), never a Google Drive call from the browser. |

## What "LEGACY_DEPENDENCY" means operationally

- The feature may still exist in the UI as a visible affordance, but it must
  either (a) be disabled with a clear "not yet available on the new system"
  message, or (b) be re-implemented against the new API before going live.
- None of these are blockers for Phases A-F of this migration (system
  analysis, schema, backend, frontend adapter, import module, tests) — they
  are called out here so they are not silently assumed solved.
