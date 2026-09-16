# Data Cleaning Workspace (Phase G8)

Scratch area for the raw production files that will be provided one at a
time in **Phase G-DATA**. Nothing here is committed to git as data (only
this README and the directory structure itself via `.gitkeep`) — every
subdirectory is listed in `.gitignore` except for its placeholder, so a raw
file dropped here never accidentally ends up in version control.

## Flow

```
RAW FILE
  |  (as received, untouched — original filename kept)
  v
raw/
  |  (UnitNormalizationService + any other deterministic, lossless
  |   normalization — case/spelling fixes only, NEVER a guessed
  |   conversion or price rewrite; see docs/PHASE_G10_ANOMALY_RULES.md,
  |   principle G11 "no silent fix")
  v
normalized/
  |  (uploaded via POST /import/upload -> staged via the relevant
  |   .../stage endpoint -> validated server-side; see
  |   docs/PHASE_G9_DATA_QUALITY_REPORT.md for what gets generated here)
  v
ERROR REPORT  (migration/workspace/reports/<file>.report.json + .md)
  v
MANUAL CORRECTION  (a human edits the file outside the system, or the
  |                 import batch is REJECTED and re-uploaded)
  v
APPROVED FILE
  v
approved/
  |  (only files a human has explicitly signed off get moved here)
  v
STAGING  (imported into inventory_staging per docs/PHASE_G13_DRY_RUN.md,
  |       never straight into the production candidate database)
  v
FINAL COMMIT  (only after the staging dry run and reconciliation both PASS)
```

## Subdirectories

| Directory | Contents |
|---|---|
| `raw/` | Exactly what was received — never edited in place. |
| `normalized/` | Same data after unit/spelling normalization only (no value changes). |
| `approved/` | Files a human has reviewed and explicitly approved for import. |
| `rejected/` | Files (or row-level extracts) that failed validation and were sent back. |
| `reports/` | Generated data-quality reports (`scripts/generate_import_quality_report.php` output) — one report per staged batch. |

## Rules

- Nothing in this workspace is ever imported directly into the production
  database — everything goes through `POST /import/upload` → `.../stage` →
  preview → `.../commit`, same as any other import, against
  `inventory_staging` first (Phase G13).
- A file only moves forward (`raw` → `normalized` → `approved`) on an
  explicit human decision. No script in this repository auto-promotes a
  file between these directories.
- Rejected data is never silently dropped — it stays in `rejected/` with
  its accompanying report until someone corrects and resubmits it.
