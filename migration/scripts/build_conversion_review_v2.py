#!/usr/bin/env python3
"""
PHASE G-DATA 1B.1 — builds v2 deliverables from
unit_conversion_candidates_real_v2.json (detector output + the 3
business-confirmed overrides layered on top by
apply_business_confirmed_overrides.py).

Outputs:
  migration/workspace/normalized/unit_conversion_review_v2.xlsx
  migration/workspace/normalized/warehouse_unit_comparison_v2.csv

Pure report generation -- no production data is touched or posted.
"""
import json

import pandas as pd

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
RESULTS_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v2.json"
XLSX_PATH = f"{OUT_DIR}/unit_conversion_review_v2.xlsx"
CSV_PATH = f"{OUT_DIR}/warehouse_unit_comparison_v2.csv"

results = json.load(open(RESULTS_PATH, encoding="utf-8"))


def g(r, key):
    v = r.get(key)
    return "" if v is None else v


def evidence_str(ev, *fields):
    if not ev:
        return ""
    return "; ".join(f"{f}={ev.get(f)}" for f in fields if ev.get(f) is not None)


# ---------------------------------------------------------------------
# CONVERSION REVIEW sheet -- same columns as v1, plus Conversion Source /
# Price Source so a reviewer can see at a glance which rows are still
# pure-detector output vs business-confirmed.
# ---------------------------------------------------------------------
review_rows = []
for r in results:
    name_ev = r.get("product_name_evidence")
    price_ev = r.get("price_ratio_evidence")
    legacy_ev = r.get("legacy_evidence")
    scm_ev = r.get("scm_transaction_evidence")
    cb_ev = r.get("cibadak_transaction_evidence")

    review_rows.append({
        "SKU": r["sku"],
        "Item Name": g(r, "item_name"),
        "Gudang Besar Unit": g(r, "gudang_besar_unit"),
        "Cibadak Unit": g(r, "cibadak_unit"),
        "Karang Tengah Unit": g(r, "karangtengah_unit"),
        "Legacy Base Unit": g(r, "legacy_base_unit"),
        "Global Base Unit Candidate": g(r, "global_base_unit_candidate"),
        "Legacy Middle Unit": g(r, "legacy_middle_unit"),
        "Legacy Middle Conversion": g(r, "legacy_middle_conversion"),
        "Middle Unit Candidate": g(r, "middle_unit_candidate"),
        "Middle Conversion Candidate": g(r, "middle_conversion_candidate"),
        "Legacy Purchase Unit": g(r, "legacy_purchase_unit"),
        "Legacy Purchase Conversion": g(r, "legacy_purchase_conversion"),
        "Purchase Unit Candidate": g(r, "purchase_unit_candidate"),
        "Purchase Conversion Candidate": g(r, "purchase_conversion_candidate"),
        "SCM Transaction Evidence": evidence_str(scm_ev, "unit", "price", "qty_in", "qty_out"),
        "Cibadak Transaction Evidence": evidence_str(cb_ev, "unit", "price", "qty_in", "qty_out"),
        "Product Name Evidence": evidence_str(name_ev, "raw", "total_qty", "unit", "ambiguous_structure"),
        "Price Ratio Evidence": evidence_str(price_ev, "ratio", "candidate_factor", "suspected_scale", "source_a", "source_b"),
        "Legacy Evidence": evidence_str(legacy_ev, "legacy_kode_bahan", "match_score", "legacy_kemasan_beli", "legacy_isi_kemasan", "legacy_satuan_kemasan", "legacy_isi_dasar", "legacy_satuan_dasar_hpp"),
        "Evidence Count": g(r, "evidence_count"),
        "Confidence": g(r, "confidence") or "NONE",
        "Conversion Source": g(r, "conversion_source"),
        "Price Source": g(r, "price_source"),
        "Unit Cost Base (KG)": g(r, "unit_cost_base_kg"),
        "Unit Cost Base (GR equivalent)": g(r, "unit_cost_base_gr_equivalent"),
        "Issue Code": g(r, "issue_code"),
        "Issue Detail": g(r, "issue_detail"),
        "Review Status": g(r, "review_status"),
        "Approved Base Unit": g(r, "approved_base_unit"),
        "Approved Middle Unit": g(r, "approved_middle_unit"),
        "Approved Middle Conversion": g(r, "approved_middle_conversion"),
        "Approved Purchase Unit": g(r, "approved_purchase_unit"),
        "Approved Purchase Conversion": g(r, "approved_purchase_conversion"),
        "Approved": g(r, "approved"),
        "Correction Note": g(r, "correction_note"),
    })
df_review = pd.DataFrame(review_rows)

# ---------------------------------------------------------------------
# SUMMARY sheet
# ---------------------------------------------------------------------
total = len(results)
conf_counts = df_review["Confidence"].value_counts().to_dict()
issue_series = df_review["Issue Code"].str.split(",").explode().replace("", pd.NA).dropna()
issue_counts = issue_series.value_counts().to_dict()

summary_rows = [
    ("Total Global SKU analyzed", total),
    ("", ""),
    ("Confidence: HIGH", conf_counts.get("HIGH", 0)),
    ("Confidence: MEDIUM", conf_counts.get("MEDIUM", 0)),
    ("Confidence: LOW", conf_counts.get("LOW", 0)),
    ("Confidence: NONE", conf_counts.get("NONE", 0)),
    ("Confidence: BUSINESS_CONFIRMED", conf_counts.get("BUSINESS_CONFIRMED", 0)),
    ("", ""),
    ("Legacy evidence (LEGACY_CONVERSION_CANDIDATE)", issue_counts.get("LEGACY_CONVERSION_CANDIDATE", 0)),
    ("Name-heuristic evidence (NAME_DERIVED_CANDIDATE)", issue_counts.get("NAME_DERIVED_CANDIDATE", 0)),
    ("Price-ratio evidence (PRICE_RATIO_SUPPORTS_CONVERSION)", issue_counts.get("PRICE_RATIO_SUPPORTS_CONVERSION", 0)),
    ("Unit label mismatch (UNIT_LABEL_MISMATCH)", issue_counts.get("UNIT_LABEL_MISMATCH", 0)),
    ("Conversion conflicts remaining (CONVERSION_CONFLICT)", issue_counts.get("CONVERSION_CONFLICT", 0)),
    ("Business-confirmed overrides applied (BUSINESS_CONFIRMED_OVERRIDE)", issue_counts.get("BUSINESS_CONFIRMED_OVERRIDE", 0)),
    ("Ambiguous package structure (PACKAGE_STRUCTURE_UNCLEAR)", issue_counts.get("PACKAGE_STRUCTURE_UNCLEAR", 0)),
    ("Global Master candidates (GLOBAL_MASTER_CANDIDATE)", issue_counts.get("GLOBAL_MASTER_CANDIDATE", 0)),
    ("Karang Tengah local-only inactive (KT_LOCAL_ONLY_INACTIVE)", issue_counts.get("KT_LOCAL_ONLY_INACTIVE", 0)),
    ("Duplicate source rows (DUPLICATE_SOURCE_ROW)", issue_counts.get("DUPLICATE_SOURCE_ROW", 0)),
    ("Identity conflicts / BLOCKED (IDENTITY_CONFLICT)", issue_counts.get("IDENTITY_CONFLICT", 0)),
    ("Movement reconciliation review (MOVEMENT_RECONCILIATION_REVIEW)", issue_counts.get("MOVEMENT_RECONCILIATION_REVIEW", 0)),
    ("No evidence at all (NO_EVIDENCE_FOUND)", issue_counts.get("NO_EVIDENCE_FOUND", 0)),
    ("", ""),
    ("Rows BLOCKED (identity conflict, no conversion work possible)", int((df_review["Review Status"] == "BLOCKED").sum())),
    ("Rows APPROVED (business-confirmed only)", int((df_review["Review Status"] == "APPROVED").sum())),
    ("Rows with Approved = YES", int((df_review["Approved"] == "YES").sum())),
    ("", ""),
    ("PRODUCTION POSTING THIS PHASE", "NONE — staging/analysis only. No opening, no historical replay, no FIFO postings, no cutover date set."),
]
df_summary = pd.DataFrame(summary_rows, columns=["Metric", "Value"])

# ---------------------------------------------------------------------
# BUSINESS CONFIRMED sheet -- the 3 overrides, full before/after detail
# ---------------------------------------------------------------------
bc_rows = []
for r in results:
    if r.get("conversion_source") != "BUSINESS_CONFIRMED":
        continue
    bc_rows.append({
        "SKU": r["sku"], "Item Name": r.get("item_name"),
        "Approved Base Unit": r.get("approved_base_unit"),
        "Approved Purchase Unit": r.get("approved_purchase_unit"),
        "Approved Purchase Conversion": r.get("approved_purchase_conversion"),
        "Unit Cost Base (KG)": r.get("unit_cost_base_kg"),
        "Unit Cost Base (GR equivalent)": r.get("unit_cost_base_gr_equivalent"),
        "Correction Note": r.get("correction_note"),
        "Prior Detector Issue Code": r.get("prior_detector_issue_code"),
        "Prior Detector Confidence": r.get("prior_detector_confidence"),
        "Prior Detector Review Status": r.get("prior_detector_review_status"),
        "Conversion Source": r.get("conversion_source"),
        "Price Source": r.get("price_source"),
        "Review Status": r.get("review_status"),
    })
df_business_confirmed = pd.DataFrame(bc_rows)

# ---------------------------------------------------------------------
# CONFLICTS sheet -- only what's still genuinely unresolved
# ---------------------------------------------------------------------
df_conflicts = df_review[df_review["Issue Code"].str.contains("CONVERSION_CONFLICT", na=False)].copy()

# ---------------------------------------------------------------------
# PRICE RATIO CHECK sheet
# ---------------------------------------------------------------------
price_rows = []
for r in results:
    ev = r.get("price_ratio_evidence")
    if not ev:
        continue
    price_rows.append({
        "SKU": r["sku"], "Item Name": r.get("item_name"),
        "Ratio": ev.get("ratio"),
        "Candidate Factor": ev.get("candidate_factor"),
        "Suspected Scale (label mismatch)": ev.get("suspected_scale"),
        "Source A": ev.get("source_a"), "Unit A": ev.get("unit_a"), "Price A": ev.get("price_a"),
        "Source B": ev.get("source_b"), "Unit B": ev.get("unit_b"), "Price B": ev.get("price_b"),
        "Issue Code": r.get("issue_code"),
        "Review Status": r.get("review_status"),
    })
df_price = pd.DataFrame(price_rows)

# ---------------------------------------------------------------------
# NAME HEURISTICS sheet
# ---------------------------------------------------------------------
name_rows = []
for r in results:
    ev = r.get("product_name_evidence")
    if not ev:
        continue
    name_rows.append({
        "SKU": r["sku"], "Item Name": r.get("item_name"),
        "Matched Pattern": ev.get("raw"),
        "Total Qty": ev.get("total_qty"),
        "Unit": ev.get("unit"),
        "Ambiguous Structure (count x size)": ev.get("ambiguous_structure"),
        "Issue Code": r.get("issue_code"),
    })
df_name = pd.DataFrame(name_rows)

# ---------------------------------------------------------------------
# LEGACY CONVERSIONS sheet
# ---------------------------------------------------------------------
legacy_rows = []
for r in results:
    ev = r.get("legacy_evidence")
    if not ev:
        continue
    legacy_rows.append({
        "SKU": r["sku"], "Item Name": r.get("item_name"),
        "Legacy Kode Bahan": ev.get("legacy_kode_bahan"),
        "Name Match Score": ev.get("match_score"),
        "Legacy Kemasan Beli (purchase unit)": ev.get("legacy_kemasan_beli"),
        "Legacy Isi Kemasan (purchase conversion)": ev.get("legacy_isi_kemasan"),
        "Legacy Satuan Kemasan (middle unit)": ev.get("legacy_satuan_kemasan"),
        "Legacy Isi Dasar (base-content factor)": ev.get("legacy_isi_dasar"),
        "Legacy Satuan Dasar HPP (base unit)": ev.get("legacy_satuan_dasar_hpp"),
        "NOTE": "LEGACY EVIDENCE ONLY — never authoritative on its own, superseded by BUSINESS_CONFIRMED where present.",
    })
df_legacy = pd.DataFrame(legacy_rows)

# ---------------------------------------------------------------------
# BLOCKED SKU sheet
# ---------------------------------------------------------------------
df_blocked = df_review[df_review["Review Status"] == "BLOCKED"].copy()

# ---------------------------------------------------------------------
# TRANSACTION EVIDENCE sheet
# ---------------------------------------------------------------------
txn_rows = []
for r in results:
    scm_ev = r.get("scm_transaction_evidence")
    cb_ev = r.get("cibadak_transaction_evidence")
    if not scm_ev and not cb_ev:
        continue
    txn_rows.append({
        "SKU": r["sku"], "Item Name": r.get("item_name"),
        "SCM Unit": (scm_ev or {}).get("unit"), "SCM Price": (scm_ev or {}).get("price"),
        "SCM Qty In": (scm_ev or {}).get("qty_in"), "SCM Qty Out": (scm_ev or {}).get("qty_out"),
        "Cibadak Unit": (cb_ev or {}).get("unit"), "Cibadak Price": (cb_ev or {}).get("price"),
        "Cibadak Qty In": (cb_ev or {}).get("qty_in"), "Cibadak Qty Out": (cb_ev or {}).get("qty_out"),
        "Issue Code": r.get("issue_code"),
    })
df_txn = pd.DataFrame(txn_rows)

# ---------------------------------------------------------------------
# WAREHOUSE UNIT COMPARISON (v2) -- regenerated from v2 records, using the
# approved purchase conversion where a business-confirmed override exists.
# ---------------------------------------------------------------------
comparison_rows = []
for r in results:
    if len(r["warehouses_present"]) < 2:
        continue
    scm_unit = r["gudang_besar_unit"]
    transit_unit = r["cibadak_unit"] or r["karangtengah_unit"]
    legacy_conv = r["legacy_purchase_conversion"]
    derived_conv = r.get("approved_purchase_conversion") or r["purchase_conversion_candidate"]
    try:
        conv_value = float(legacy_conv) if legacy_conv not in (None, "") else (
            float(derived_conv) if derived_conv not in (None, "") else None
        )
    except (TypeError, ValueError):
        conv_value = None
    if r.get("conversion_source") == "BUSINESS_CONFIRMED" and r.get("approved_purchase_conversion"):
        conv_value = float(r["approved_purchase_conversion"])

    if scm_unit and transit_unit and scm_unit != transit_unit:
        pattern = "BASE_UNIT_MISMATCH"
    elif r.get("conversion_source") == "BUSINESS_CONFIRMED" and conv_value and conv_value > 1:
        pattern = "SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CONFIRMED"
    elif conv_value and conv_value > 1:
        pattern = "SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CANDIDATE"
    elif conv_value == 1:
        pattern = "NO_PACKAGING_LAYER"
    else:
        pattern = "NO_PACKAGING_EVIDENCE"

    comparison_rows.append({
        "SKU": r["sku"], "Item Name": r["item_name"],
        "SCM Unit": scm_unit, "Transit Unit": transit_unit,
        "Legacy Conversion": legacy_conv,
        "Derived Conversion": derived_conv,
        "Pattern": pattern,
        "Conversion Source": r.get("conversion_source") or "DETECTOR",
        "Evidence": r["issue_code"],
        "Status": r["review_status"],
    })
df_wh_comparison = pd.DataFrame(comparison_rows)
df_wh_comparison.to_csv(CSV_PATH, index=False)
print(f"Wrote {CSV_PATH} ({len(df_wh_comparison)} rows)")

# ---------------------------------------------------------------------
# INSTRUCTIONS sheet
# ---------------------------------------------------------------------
instructions_text = [
    "PHASE G-DATA 1B.1 — UNIT CONVERSION RECONSTRUCTION (v2, WITH BUSINESS-CONFIRMED OVERRIDES)",
    "",
    "This workbook is still a STAGING/REVIEW artifact. Nothing here has been",
    "posted to production. No opening stock, no historical transaction replay,",
    "no FIFO batches, and no cutover date have been created from this data.",
    "",
    "What changed from v1 (unit_conversion_review.xlsx):",
    "- 3 SKUs (999208, 999209, 140539) now carry an explicit business-owner",
    "  confirmation, recorded in migration/scripts/business_confirmed_overrides.json",
    "  and applied by apply_business_confirmed_overrides.py. Their Review Status",
    "  is APPROVED and Confidence shows BUSINESS_CONFIRMED (a distinct value from",
    "  the detector's HIGH/MEDIUM/LOW/NONE scale, so a business confirmation is",
    "  never confused with an auto-derived confidence score).",
    "- 999208 / 999209: approved conversion is 1 PCS = 15 KG (Approved Purchase",
    "  Unit=PCS, Approved Purchase Conversion=15, Approved Base Unit=KG). This",
    "  explains the ~15x price ratio found earlier; it is no longer flagged",
    "  PRICE_RATIO_SUPPORTS_CONVERSION or CONVERSION_CONFLICT.",
    "- 140539: this was NEVER a packaging conversion. It is a unit-price-BASIS",
    "  mismatch: Rp305.000/KG and Rp305/GR are the same real price, expressed",
    "  in two different units. No purchase_conversion factor is derived from",
    "  it. Canonical unit_cost_base is recorded both ways (KG=305000, GR",
    "  equivalent=305) so downstream systems store the value consistent with",
    "  whichever unit they actually use as the global base unit.",
    "- These confirmations apply ONLY to these 3 exact SKUs. 140541 shows a",
    "  similar ~136x price-ratio pattern but has NOT been confirmed by the",
    "  business and is intentionally left as an open CONVERSION_CONFLICT --",
    "  no assumption was generalized from 999208/999209/140539 to it.",
    "- Legacy evidence for all 3 SKUs is UNCHANGED and still visible in the",
    "  LEGACY CONVERSIONS sheet and the Legacy Evidence column -- it is kept",
    "  for audit, superseded (not deleted) by the business confirmation.",
    "",
    "How to read CONVERSION REVIEW (unchanged from v1 otherwise):",
    "- Confidence HIGH requires 3+ independent evidence sources (name heuristic,",
    "  price ratio, legacy isi_dasar) mutually agreeing on the same factor.",
    "- Confidence MEDIUM = exactly 2 independent sources agree.",
    "- Confidence LOW = exactly 1 source (most rows: legacy evidence alone).",
    "- Confidence NONE = no usable evidence found at all.",
    "- Confidence BUSINESS_CONFIRMED = an explicit owner/admin confirmation",
    "  overrides the detector entirely for that SKU (see BUSINESS CONFIRMED sheet).",
    "- Every other row's Approved column is still blank -- nothing outside",
    "  these 3 SKUs has been approved.",
    "",
    "Sheets in this workbook:",
    "  SUMMARY                  - headline counts",
    "  BUSINESS CONFIRMED       - the 3 overrides, before/after detail",
    "  CONVERSION REVIEW        - one row per Global SKU, full evidence trail",
    "  CONFLICTS                - rows where evidence sources STILL disagree",
    "  PRICE RATIO CHECK        - all price-ratio detector output",
    "  NAME HEURISTICS          - all @<qty><unit>-style name parses",
    "  LEGACY CONVERSIONS       - all dbinventory.xlsx fuzzy-matched evidence",
    "  BLOCKED SKU              - identity conflicts blocking all further work",
    "  TRANSACTION EVIDENCE     - In/Out SCM + Cibadak transaction data per SKU",
    "  WAREHOUSE UNIT COMPARISON- cross-warehouse base-unit + packaging pattern",
    "",
    "STOP: still no opening import, no historical replay, no cutover from this",
    "phase. Only these 3 SKUs are approved; everything else still needs review.",
]
df_instructions = pd.DataFrame({"INSTRUCTIONS": instructions_text})

with pd.ExcelWriter(XLSX_PATH, engine="openpyxl") as writer:
    df_instructions.to_excel(writer, sheet_name="INSTRUCTIONS", index=False)
    df_summary.to_excel(writer, sheet_name="SUMMARY", index=False)
    df_business_confirmed.to_excel(writer, sheet_name="BUSINESS CONFIRMED", index=False)
    df_review.to_excel(writer, sheet_name="CONVERSION REVIEW", index=False)
    df_conflicts.to_excel(writer, sheet_name="CONFLICTS", index=False)
    df_price.to_excel(writer, sheet_name="PRICE RATIO CHECK", index=False)
    df_name.to_excel(writer, sheet_name="NAME HEURISTICS", index=False)
    df_legacy.to_excel(writer, sheet_name="LEGACY CONVERSIONS", index=False)
    df_blocked.to_excel(writer, sheet_name="BLOCKED SKU", index=False)
    df_txn.to_excel(writer, sheet_name="TRANSACTION EVIDENCE", index=False)
    df_wh_comparison.to_excel(writer, sheet_name="WAREHOUSE UNIT COMPARISON", index=False)

    for sheet_name, frame in [
        ("INSTRUCTIONS", df_instructions), ("SUMMARY", df_summary),
        ("BUSINESS CONFIRMED", df_business_confirmed),
        ("CONVERSION REVIEW", df_review), ("CONFLICTS", df_conflicts),
        ("PRICE RATIO CHECK", df_price), ("NAME HEURISTICS", df_name),
        ("LEGACY CONVERSIONS", df_legacy), ("BLOCKED SKU", df_blocked),
        ("TRANSACTION EVIDENCE", df_txn), ("WAREHOUSE UNIT COMPARISON", df_wh_comparison),
    ]:
        ws = writer.sheets[sheet_name]
        for i, col in enumerate(frame.columns, start=1):
            width = min(max(12, int(frame[col].astype(str).str.len().clip(upper=60).max() if len(frame) else 12) + 2), 60)
            ws.column_dimensions[ws.cell(row=1, column=i).column_letter].width = width

print(f"Wrote {XLSX_PATH}")
print(f"Sheets: INSTRUCTIONS, SUMMARY, BUSINESS CONFIRMED({len(df_business_confirmed)}), "
      f"CONVERSION REVIEW({len(df_review)}), CONFLICTS({len(df_conflicts)}), "
      f"PRICE RATIO CHECK({len(df_price)}), NAME HEURISTICS({len(df_name)}), LEGACY CONVERSIONS({len(df_legacy)}), "
      f"BLOCKED SKU({len(df_blocked)}), TRANSACTION EVIDENCE({len(df_txn)}), WAREHOUSE UNIT COMPARISON({len(df_wh_comparison)})")
