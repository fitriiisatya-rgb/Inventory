#!/usr/bin/env python3
"""
FINAL FAST-TRACK GO_LIVE READINESS -- SCM + CIBADAK.

Exports clean staging-import inputs (CSV) for the FINAL PRE-PRODUCTION DRY
RUN, computed from the SAME already-verified reconciliation logic as
migration/scripts/reconcile_opening_partial.py -- this script does NOT
re-implement that logic; it runs the reconciliation script in-process via
runpy (so scm_result/cb_result/master come from the exact same code path
that produced reconciliation_01_15_sep_partial.xlsx) and just reshapes the
in-memory results into importer-ready CSVs.

*** MOST IMPORTANT CAVEAT (surfaced again here, not just in the report) ***
Of the SKUs actually needed for SCM+CIBADAK opening, only a small minority
have an owner-APPROVED base unit (review_status == 'APPROVED', confidence
'BUSINESS_CONFIRMED'). The rest carry only a detector-guessed
global_base_unit_candidate (confidence LOW/MEDIUM, review_status PENDING).
This export uses the candidate for those SKUs so the pipeline can be
exercised end-to-end in STAGING, but every such item's `notes` column is
stamped CANDIDATE_ONLY (never silently presented as approved), and a
companion base_unit_confidence_report.json is written so the final report
can state exactly how many items are still awaiting real owner sign-off.

Outputs (migration/workspace/staging_export/):
  master_items.csv        -- ImportMasterItemService format
  warehouses.csv           -- ImportSimpleMasterService (warehouse) format, SCM+CIBADAK only
  historical.csv            -- ImportHistoricalTransactionService format:
                                Opening 1 Sep (ADJUSTMENT, evidence only),
                                IN 1-15 Sep, OUT 1-15 Sep, per SKU+warehouse
  opening_16sep.csv         -- ImportOpeningStockService (final_opening_stock_template) format:
                                Closing 15 Sep = LIVE Opening 16 Sep
  base_unit_confidence_report.json -- per-SKU approved vs candidate-only base unit

Usage: python3 migration/scripts/export_scm_cibadak_staging.py
"""
import csv
import json
import os
import runpy

OUT_DIR = "/home/user/Inventory/migration/workspace/staging_export"
RECON_SCRIPT = "/home/user/Inventory/migration/scripts/reconcile_opening_partial.py"

os.makedirs(OUT_DIR, exist_ok=True)

print("Running reconcile_opening_partial.py in-process to reuse its verified scm_result/cb_result/master (no logic duplicated)...")
ns = runpy.run_path(RECON_SCRIPT)
master = ns["master"]
scm_result = ns["scm_result"]
cb_result = ns["cb_result"]
KNOWN_NEGATIVE = ns["KNOWN_NEGATIVE"]

STATUS_MAP = {"Aktif": "ACTIVE", "Non-Aktif": "INACTIVE"}

# ---------------------------------------------------------------------
# 1. master_items.csv -- every SKU that appears in either SCM or CIBADAK
#    opening reconstruction (the population actually needed for this
#    dry run). Karang-Tengah-only SKUs are deliberately excluded.
# ---------------------------------------------------------------------
skus_needed = sorted({r["SKU"] for r in scm_result} | {r["SKU"] for r in cb_result})
print(f"SKUs needed for SCM+CIBADAK master import: {len(skus_needed)}")

master_rows = []
confidence_report = {"approved": [], "candidate_only": []}
for sku in skus_needed:
    m = master.get(sku)
    if m is None:
        continue  # should not happen -- reconcile_opening_partial.py already warns on this
    approved = m.get("review_status") == "APPROVED" and m.get("approved_base_unit")
    base_unit = m.get("approved_base_unit") or m.get("global_base_unit_candidate")
    if not base_unit:
        continue  # no usable base unit at all -- cannot import, will show up as a gap below
    confidence = m.get("confidence") or "UNKNOWN"
    status = STATUS_MAP.get(m.get("status_candidate"), "ACTIVE")
    purchase_unit = m.get("approved_purchase_unit") or ""
    purchase_conv = m.get("approved_purchase_conversion") or ""
    notes = (
        "APPROVED base unit (owner-confirmed)" if approved
        else f"CANDIDATE_ONLY base unit -- NOT owner-approved (confidence={confidence}); "
             f"pending real business sign-off before production go-live"
    )
    master_rows.append({
        "sku": sku, "barcode": "", "name": (m.get("item_name") or sku)[:200],
        "category": m.get("category_candidate") or "", "brand": "",
        "base_unit": base_unit, "purchase_unit": purchase_unit, "purchase_conversion": purchase_conv,
        "middle_unit": m.get("approved_middle_unit") or "", "middle_conversion": m.get("approved_middle_conversion") or "",
        "minimum_stock": "0", "status": status, "default_supplier_code": "", "notes": notes,
    })
    (confidence_report["approved"] if approved else confidence_report["candidate_only"]).append({
        "sku": sku, "item_name": m.get("item_name"), "base_unit": base_unit, "confidence": confidence,
    })

with open(f"{OUT_DIR}/master_items.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(master_rows[0].keys()))
    w.writeheader()
    w.writerows(master_rows)
print(f"Wrote master_items.csv: {len(master_rows)} rows "
      f"({len(confidence_report['approved'])} APPROVED, {len(confidence_report['candidate_only'])} CANDIDATE_ONLY)")

with open(f"{OUT_DIR}/base_unit_confidence_report.json", "w", encoding="utf-8") as f:
    json.dump(confidence_report, f, indent=2, ensure_ascii=False)

skus_missing_base_unit = [s for s in skus_needed if s not in {r["sku"] for r in master_rows}]
if skus_missing_base_unit:
    print(f"WARNING: {len(skus_missing_base_unit)} SKU(s) have NO usable base unit at all, excluded from master import: {skus_missing_base_unit[:20]}")

# ---------------------------------------------------------------------
# 2. warehouses.csv -- SCM + CIBADAK only (Karang Tengah deliberately
#    excluded from this fast-track; it stays PENDING_CUTOVER).
# ---------------------------------------------------------------------
with open(f"{OUT_DIR}/warehouses.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.writer(f)
    w.writerow(["warehouse_code", "warehouse_name", "warehouse_type", "status"])
    w.writerow(["SCM", "Gudang SCM / Gudang Besar", "MAIN", "ACTIVE"])
    w.writerow(["CIBADAK", "Gudang Cibadak", "MAIN", "ACTIVE"])
print("Wrote warehouses.csv: 2 rows (SCM, CIBADAK) -- Karang Tengah intentionally excluded")

# ---------------------------------------------------------------------
# 3. historical.csv -- Opening 1 Sep (ADJUSTMENT, evidence only) + IN 1-15
#    + OUT 1-15, per SKU+warehouse. inventory_effect=0 is enforced by
#    ImportHistoricalTransactionService itself, not by this export.
# ---------------------------------------------------------------------
imported_skus = {r["sku"] for r in master_rows}
hist_rows = []


def add_hist(sku, wh, ttype, date, qty, unit, price, note):
    if qty is None or float(qty) == 0.0:
        return
    hist_rows.append({
        "transaction_date": date, "transaction_type": ttype, "warehouse_code": wh, "sku": sku,
        "input_qty": qty, "input_unit": unit, "unit_price_input": price or 0,
        "supplier_code": "", "division_code": "", "reference_no": f"HIST-{wh}-{sku}-{ttype}", "notes": note,
    })


for wh, rows in (("SCM", scm_result), ("CIBADAK", cb_result)):
    for r in rows:
        if r["SKU"] not in imported_skus:
            continue
        unit = r["Base Unit"]
        add_hist(r["SKU"], wh, "ADJUSTMENT", "2026-09-01", r["Opening 1 Sep"], unit, r["Unit Cost"],
                  "HISTORICAL OPENING BALANCE 1 SEP 2026 (evidence only, inventory_effect=0)")
        add_hist(r["SKU"], wh, "IN", "2026-09-15", r["IN 1-15"], unit, r["Unit Cost"],
                  "HISTORICAL IN 1-15 SEP 2026 (aggregate, evidence only, inventory_effect=0)")
        add_hist(r["SKU"], wh, "OUT", "2026-09-15", r["OUT 1-15"], unit, r["Unit Cost"],
                  "HISTORICAL OUT 1-15 SEP 2026 (aggregate, evidence only, inventory_effect=0)")

with open(f"{OUT_DIR}/historical.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(hist_rows[0].keys()))
    w.writeheader()
    w.writerows(hist_rows)
print(f"Wrote historical.csv: {len(hist_rows)} rows (Opening 1 Sep + IN 1-15 + OUT 1-15, non-zero only)")

# ---------------------------------------------------------------------
# 4. opening_16sep.csv -- Closing 15 Sep = LIVE Opening 16 Sep.
#    Whitelisted migration-negative rows post their exact negative
#    balance; every other row must already be >= 0 per the verified
#    reconciliation (0 unflagged theoretical negatives for SCM/CIBADAK).
# ---------------------------------------------------------------------
open_rows = []
skipped_no_master = []
for wh, rows in (("SCM", scm_result), ("CIBADAK", cb_result)):
    for r in rows:
        if r["SKU"] not in imported_skus:
            skipped_no_master.append((wh, r["SKU"]))
            continue
        open_rows.append({
            "Cutoff Date": "2026-09-16", "Warehouse Code": wh, "SKU": r["SKU"], "Item Name": r["Item Name"],
            "Global Base Unit": r["Base Unit"], "Opening Qty Base": r["Calculated Ending 15 Sep"],
            "Unit Cost Base": r["Unit Cost"], "Opening Value": "", "Expiry Date": "",
            "Batch Reference": f"LIVE-OPEN-{wh}-16SEP",
            "Source": f"Closing 15 Sep 2026 reconstruction ({wh}) -- Opening 1 Sep + IN 1-15 - OUT 1-15",
            "Verification Status": ("MIGRATION_NEGATIVE_REVIEW" if (wh, r["SKU"]) in KNOWN_NEGATIVE
                                     else r["Status"]),
            "Approved By": "", "Notes": r["Normalization Note"],
        })

with open(f"{OUT_DIR}/opening_16sep.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, fieldnames=list(open_rows[0].keys()))
    w.writeheader()
    w.writerows(open_rows)
print(f"Wrote opening_16sep.csv: {len(open_rows)} rows"
      + (f" ({len(skipped_no_master)} skipped, no usable master row)" if skipped_no_master else ""))

print("\nDone. All outputs in migration/workspace/staging_export/ (gitignored raw workspace, not committed).")
