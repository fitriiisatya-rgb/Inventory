#!/usr/bin/env python3
"""
PHASE G-DATA 1B.2 — builds unit_conversion_review_v4.xlsx from
unit_conversion_candidates_real_v4.json (v3 baseline + semantic-grouping
layer from semantic_grouping_v4.py). Same sheet shape as v3, plus new
Semantic Tag / Content Info columns and a dedicated MOVEMENT REVIEW sheet
kept separate from CONFLICTS per Phase 1B.2 item 13.

Pure report generation -- no production data is touched or posted.
"""
import json

import pandas as pd

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
RESULTS_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v4.json"
XLSX_PATH = f"{OUT_DIR}/unit_conversion_review_v4.xlsx"

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
        "SCM Transaction Evidence": evidence_str(scm_ev, "unit", "price", "qty_in", "qty_out"),
        "Cibadak Transaction Evidence": evidence_str(cb_ev, "unit", "price", "qty_in", "qty_out"),
        "Product Name Evidence": evidence_str(name_ev, "raw", "raw_qty", "raw_unit", "normalized_qty", "normalized_unit", "parse_kind"),
        "Price Ratio Evidence": evidence_str(price_ev, "ratio", "candidate_factor", "suspected_scale"),
        "Legacy Evidence": evidence_str(legacy_ev, "legacy_kode_bahan", "legacy_kemasan_beli", "legacy_isi_kemasan", "legacy_satuan_kemasan", "legacy_isi_dasar", "legacy_satuan_dasar_hpp"),
        "Evidence Count": g(r, "evidence_count"),
        "Confidence": g(r, "confidence") or "NONE",
        "Name Semantic Class": g(r, "name_semantic_class"),
        "Semantic Tag / Decision Group": g(r, "semantic_tag"),
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
    ("CONVERSION_CONFLICT remaining (after semantic grouping)", issue_counts.get("CONVERSION_CONFLICT", 0)),
    ("CROSS_COUNT_UNIT (grouped, needs admin decision)", issue_counts.get("CROSS_COUNT_UNIT", 0)),
    ("CONTENT_WEIGHT_PER_INVENTORY_UNIT (resolved, informational)", issue_counts.get("CONTENT_WEIGHT_PER_INVENTORY_UNIT", 0)),
    ("CONTENT_QTY_PER_INVENTORY_UNIT (resolved, informational)", issue_counts.get("CONTENT_QTY_PER_INVENTORY_UNIT", 0)),
    ("COMPOSITE_PACKAGE_AGREEMENT (resolved, name+legacy agree)", issue_counts.get("COMPOSITE_PACKAGE_AGREEMENT", 0)),
    ("NAME_TRIVIAL_BASE_UNIT (resolved, self-referential name)", issue_counts.get("NAME_TRIVIAL_BASE_UNIT", 0)),
    ("PRODUCT_VARIANT_TEXT (resolved, not a unit)", issue_counts.get("PRODUCT_VARIANT_TEXT", 0)),
    ("NAME_STRUCTURE_REVIEW (name too ambiguous, excluded)", issue_counts.get("NAME_STRUCTURE_REVIEW", 0)),
    ("MOVEMENT_RECONCILIATION_REVIEW (kept separate)", issue_counts.get("MOVEMENT_RECONCILIATION_REVIEW", 0)),
    ("Identity resolved via GB authority", issue_counts.get("IDENTITY_RESOLVED_VIA_GB_AUTHORITY", 0)),
    ("Global Master candidates", issue_counts.get("GLOBAL_MASTER_CANDIDATE", 0)),
    ("", ""),
    ("Rows BLOCKED", int((df_review["Review Status"] == "BLOCKED").sum())),
    ("Rows APPROVED (business-confirmed)", int((df_review["Review Status"] == "APPROVED").sum())),
    ("", ""),
    ("PRODUCTION POSTING THIS PHASE", "NONE — staging/analysis only."),
]
df_summary = pd.DataFrame(summary_rows, columns=["Metric", "Value"])

df_conflicts = df_review[df_review["Issue Code"].str.contains("CONVERSION_CONFLICT", na=False)].copy()
df_grouped = df_review[df_review["Issue Code"].str.contains("CROSS_COUNT_UNIT", na=False)].copy()
df_movement = df_review[df_review["Issue Code"].str.contains("MOVEMENT_RECONCILIATION_REVIEW", na=False)].copy()
df_content = df_review[df_review["Issue Code"].str.contains("CONTENT_WEIGHT_PER_INVENTORY_UNIT|CONTENT_QTY_PER_INVENTORY_UNIT", na=False, regex=True)].copy()

instructions_text = [
    "PHASE G-DATA 1B.2 — SEMANTIC UNIT & ADMIN DECISION GROUPING",
    "",
    "Baseline: unit_conversion_candidates_real_v3.json (commit 4755ea0),",
    "unchanged. This round adds a semantic classification layer on top --",
    "it does not repeat the physical-unit (KG/GR, LTR/ML) normalization fix.",
    "",
    "The 46 CONVERSION_CONFLICT rows from v3 are NOT presented here as 46",
    "individual questions. Each was reclassified:",
    "  - 15 resolved automatically (parser fix, content-vs-base reclassification,",
    "    composite-package matches, or recognizing text that was never a unit)",
    "  - 27 consolidated into 6 recurring semantic decision groups -- see",
    "    admin_decision_groups.xlsx",
    "  - 4 remain genuinely unique, individual questions -- see CONFLICTS sheet",
    "",
    "See unit_conversion_summary_v4.md and admin_decision_groups.xlsx for the",
    "full breakdown and the actual admin questions.",
    "",
    "STOP: still no opening import, no historical replay, no cutover.",
]
df_instructions = pd.DataFrame({"INSTRUCTIONS": instructions_text})

with pd.ExcelWriter(XLSX_PATH, engine="openpyxl") as writer:
    df_instructions.to_excel(writer, sheet_name="INSTRUCTIONS", index=False)
    df_summary.to_excel(writer, sheet_name="SUMMARY", index=False)
    df_review.to_excel(writer, sheet_name="CONVERSION REVIEW", index=False)
    df_conflicts.to_excel(writer, sheet_name="CONVERSION_CONFLICT rows", index=False)
    df_grouped.to_excel(writer, sheet_name="GROUPED (see admin xlsx)", index=False)
    df_content.to_excel(writer, sheet_name="CONTENT DESCRIPTORS", index=False)
    df_movement.to_excel(writer, sheet_name="MOVEMENT REVIEW", index=False)

    for sheet_name, frame in [
        ("INSTRUCTIONS", df_instructions), ("SUMMARY", df_summary),
        ("CONVERSION REVIEW", df_review), ("CONVERSION_CONFLICT rows", df_conflicts),
        ("GROUPED (see admin xlsx)", df_grouped), ("CONTENT DESCRIPTORS", df_content),
        ("MOVEMENT REVIEW", df_movement),
    ]:
        ws = writer.sheets[sheet_name]
        for i, col in enumerate(frame.columns, start=1):
            width = min(max(12, int(frame[col].astype(str).str.len().clip(upper=60).max() if len(frame) else 12) + 2), 60)
            ws.column_dimensions[ws.cell(row=1, column=i).column_letter].width = width

print(f"Wrote {XLSX_PATH}")
print(f"Sheets: INSTRUCTIONS, SUMMARY, CONVERSION REVIEW({len(df_review)}), "
      f"CONVERSION_CONFLICT rows({len(df_conflicts)}), GROUPED({len(df_grouped)}), "
      f"CONTENT DESCRIPTORS({len(df_content)}), MOVEMENT REVIEW({len(df_movement)})")
