#!/usr/bin/env python3
"""
PHASE G-DATA 1B.3 — builds unit_conversion_review_v5.xlsx and
warehouse_unit_comparison_v5.csv from unit_conversion_candidates_real_v5.json
(v4 baseline + the owner/admin's answers to admin_decision_groups.xlsx,
applied by apply_admin_decisions_v5.py).

Pure report generation -- no production data is touched or posted.
"""
import csv
import json

import pandas as pd

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
RESULTS_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v5.json"
XLSX_PATH = f"{OUT_DIR}/unit_conversion_review_v5.xlsx"
CSV_PATH = f"{OUT_DIR}/warehouse_unit_comparison_v5.csv"

results = json.load(open(RESULTS_PATH, encoding="utf-8"))


def g(r, key):
    v = r.get(key)
    return "" if v is None else v


def evidence_str(ev, *fields):
    if not ev:
        return ""
    return "; ".join(f"{f}={ev.get(f)}" for f in fields if ev.get(f) is not None)


def aliases_str(aliases):
    if not aliases:
        return ""
    return "; ".join(f"{wh}=\"{n}\"" for wh, n in aliases.items())


review_rows = []
for r in results:
    name_ev = r.get("product_name_evidence")
    price_ev = r.get("price_ratio_evidence")
    legacy_ev = r.get("legacy_evidence")
    scm_ev = r.get("scm_transaction_evidence")
    cb_ev = r.get("cibadak_transaction_evidence")
    ci = r.get("content_info")

    review_rows.append({
        "SKU": r["sku"],
        "Item Name (GB-authoritative)": g(r, "item_name"),
        "Identity Aliases (audit only)": aliases_str(r.get("identity_aliases")),
        "Category Candidate": g(r, "category_candidate"),
        "Gudang Besar Unit": g(r, "gudang_besar_unit"),
        "Cibadak Unit": g(r, "cibadak_unit"),
        "Karang Tengah Unit": g(r, "karangtengah_unit"),
        "Global Base Unit Candidate": g(r, "global_base_unit_candidate"),
        "Legacy Purchase Unit": g(r, "legacy_purchase_unit"),
        "Legacy Purchase Conversion": g(r, "legacy_purchase_conversion"),
        "Purchase Unit Candidate": g(r, "purchase_unit_candidate"),
        "Purchase Conversion Candidate": g(r, "purchase_conversion_candidate"),
        "Content Qty": g(ci, "content_qty") if ci else "",
        "Content Unit": g(ci, "content_unit") if ci else "",
        "Content Per Inventory Unit": g(ci, "inventory_unit") if ci else "",
        "Product Name Evidence": evidence_str(name_ev, "raw", "raw_qty", "raw_unit", "normalized_qty", "normalized_unit", "parse_kind"),
        "Price Ratio Evidence": evidence_str(price_ev, "ratio", "candidate_factor", "suspected_scale"),
        "Legacy Evidence": evidence_str(legacy_ev, "legacy_kode_bahan", "legacy_kemasan_beli", "legacy_isi_kemasan", "legacy_satuan_kemasan", "legacy_isi_dasar", "legacy_satuan_dasar_hpp"),
        "Evidence Count": g(r, "evidence_count"),
        "Confidence": g(r, "confidence") or "NONE",
        "Name Semantic Class": g(r, "name_semantic_class"),
        "Semantic Tag / Decision Group": g(r, "semantic_tag"),
        "Admin Source Answer": g(r, "admin_source_answer"),
        "Conversion Source": g(r, "conversion_source"),
        "Price Source": g(r, "price_source"),
        "Issue Code": g(r, "issue_code"),
        "Issue Detail": g(r, "issue_detail"),
        "Review Status": g(r, "review_status"),
        "Approved Base Unit": g(r, "approved_base_unit"),
        "Approved Purchase Unit": g(r, "approved_purchase_unit"),
        "Approved Purchase Conversion": g(r, "approved_purchase_conversion"),
        "Approved": g(r, "approved"),
        "Correction Note": g(r, "correction_note"),
    })
df_review = pd.DataFrame(review_rows)

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
    ("CONVERSION_CONFLICT remaining", issue_counts.get("CONVERSION_CONFLICT", 0)),
    ("CROSS_COUNT_UNIT remaining (ungrouped/unanswered)", issue_counts.get("CROSS_COUNT_UNIT", 0)),
    ("ADMIN_DECISION_CONFIRMED (this round's 31 SKU)", issue_counts.get("ADMIN_DECISION_CONFIRMED", 0)),
    ("BUSINESS_CONFIRMED_OVERRIDE (Phase 1B.1's 3 SKU)", issue_counts.get("BUSINESS_CONFIRMED_OVERRIDE", 0)),
    ("MOVEMENT_RECONCILIATION_REVIEW (kept separate, unresolved)", issue_counts.get("MOVEMENT_RECONCILIATION_REVIEW", 0)),
    ("", ""),
    ("Rows BLOCKED", int((df_review["Review Status"] == "BLOCKED").sum())),
    ("Rows APPROVED (business/admin-confirmed, all rounds)", int((df_review["Review Status"] == "APPROVED").sum())),
    ("", ""),
    ("PRODUCTION POSTING THIS PHASE", "NONE — staging/analysis only."),
]
df_summary = pd.DataFrame(summary_rows, columns=["Metric", "Value"])

df_admin_confirmed = df_review[df_review["Issue Code"].str.contains("ADMIN_DECISION_CONFIRMED", na=False)].copy()
df_conflicts = df_review[df_review["Issue Code"].str.contains("CONVERSION_CONFLICT", na=False)].copy()
df_movement = df_review[df_review["Issue Code"].str.contains("MOVEMENT_RECONCILIATION_REVIEW", na=False)].copy()

instructions_text = [
    "PHASE G-DATA 1B.3 — ADMIN DECISIONS APPLIED",
    "",
    "Baseline: unit_conversion_candidates_real_v4.json (Phase 1B.2 semantic",
    "grouping), unchanged. This round applies the owner/admin's answers to",
    "admin_decision_groups.xlsx as a separate, auditable override layer",
    "(migration/scripts/admin_decisions_v5.json,",
    "migration/scripts/apply_admin_decisions_v5.py) -- the detector and",
    "semantic-grouping code are not modified.",
    "",
    "All 31 SKU that needed an admin answer (27 across 6 decision groups +",
    "4 unique cases) were answered and are now Review Status = APPROVED,",
    "Confidence = BUSINESS_CONFIRMED. CONVERSION_CONFLICT is fully resolved",
    "for this catalog (0 remaining).",
    "",
    "The ADMIN CONFIRMED sheet lists all 31 rows with the owner's original",
    "answer text (Admin Source Answer column) preserved for audit next to",
    "the derived Approved Base/Purchase Unit/Conversion values.",
    "",
    "Still unresolved (out of scope for admin_decision_groups.xlsx):",
    "  - 8 MOVEMENT_RECONCILIATION_REVIEW rows (opening+IN-OUT discrepancies,",
    "    a different question from unit conversion, kept in its own sheet).",
    "  - 7 Global Master candidates still need a category for 1 SKU (33515).",
    "  - 2 duplicate-source SKUs (900240/900251) -- informational only, no",
    "    admin question needed (differ only in spelling/capitalization).",
    "",
    "STOP: still no opening import, no historical replay, no cutover.",
]
df_instructions = pd.DataFrame({"INSTRUCTIONS": instructions_text})

with pd.ExcelWriter(XLSX_PATH, engine="openpyxl") as writer:
    df_instructions.to_excel(writer, sheet_name="INSTRUCTIONS", index=False)
    df_summary.to_excel(writer, sheet_name="SUMMARY", index=False)
    df_admin_confirmed.to_excel(writer, sheet_name="ADMIN CONFIRMED", index=False)
    df_review.to_excel(writer, sheet_name="CONVERSION REVIEW", index=False)
    df_conflicts.to_excel(writer, sheet_name="CONVERSION_CONFLICT rows", index=False)
    df_movement.to_excel(writer, sheet_name="MOVEMENT REVIEW", index=False)

    for sheet_name, frame in [
        ("INSTRUCTIONS", df_instructions), ("SUMMARY", df_summary),
        ("ADMIN CONFIRMED", df_admin_confirmed),
        ("CONVERSION REVIEW", df_review), ("CONVERSION_CONFLICT rows", df_conflicts),
        ("MOVEMENT REVIEW", df_movement),
    ]:
        ws = writer.sheets[sheet_name]
        for i, col in enumerate(frame.columns, start=1):
            width = min(max(12, int(frame[col].astype(str).str.len().clip(upper=60).max() if len(frame) else 12) + 2), 60)
            ws.column_dimensions[ws.cell(row=1, column=i).column_letter].width = width

print(f"Wrote {XLSX_PATH}")
print(f"Sheets: INSTRUCTIONS, SUMMARY, ADMIN CONFIRMED({len(df_admin_confirmed)}), "
      f"CONVERSION REVIEW({len(df_review)}), CONVERSION_CONFLICT rows({len(df_conflicts)}), "
      f"MOVEMENT REVIEW({len(df_movement)})")

# ---------------------------------------------------------------------
# warehouse_unit_comparison_v5.csv
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
        "Legacy Conversion": legacy_conv, "Derived Conversion": derived_conv,
        "Pattern": pattern, "Conversion Source": r.get("conversion_source") or "DETECTOR",
        "Evidence": r["issue_code"], "Status": r["review_status"],
    })

with open(CSV_PATH, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=list(comparison_rows[0].keys()))
    writer.writeheader()
    writer.writerows(comparison_rows)
print(f"Wrote {CSV_PATH} ({len(comparison_rows)} rows)")
