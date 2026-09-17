#!/usr/bin/env python3
"""
PHASE G-DATA 1B.2 — builds admin_decision_groups.xlsx from
admin_decision_groups.json (produced by semantic_grouping_v4.py).

Sheets: SUMMARY, DECISION GROUPS, AFFECTED SKUS, UNIQUE CASES, INSTRUCTIONS.
Business-readable choices only -- no database-column-level questions.

Pure report generation -- no production data is touched or posted.
"""
import json

import pandas as pd

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
GROUPS_PATH = f"{OUT_DIR}/admin_decision_groups.json"
XLSX_PATH = f"{OUT_DIR}/admin_decision_groups.xlsx"

data = json.load(open(GROUPS_PATH, encoding="utf-8"))
decision_groups = data["decision_groups"]
unique_cases = data["unique_cases"]
movement_review_skus = data["movement_review_skus"]
resolved = data["resolved_count_by_reason"]

# ---------------------------------------------------------------------
# DECISION GROUPS sheet
# ---------------------------------------------------------------------
dg_rows = [{
    "Decision Group ID": g["group_id"],
    "Business Question": g["business_question"],
    "Affected SKU Count": g["affected_sku_count"],
    "Representative SKU": g["representative_sku"],
    "Representative Item": g["representative_item"],
    "Current Global Base": g["current_global_base"],
    "Name Evidence": g["name_evidence"],
    "Legacy Evidence": g["legacy_evidence"],
    "Transaction Evidence": g["transaction_evidence"],
    "Possible Interpretation": g["possible_interpretation"],
    "Recommended Choices": g["recommended_choices"],
    "Admin Decision": g["admin_decision"],
    "Admin Notes": g["admin_notes"],
} for g in decision_groups]
df_groups = pd.DataFrame(dg_rows)

# ---------------------------------------------------------------------
# AFFECTED SKUS sheet -- one row per SKU, tagged with its group
# ---------------------------------------------------------------------
affected_rows = []
for g in decision_groups:
    for sku, item in zip(g["affected_skus"], g["affected_items"]):
        affected_rows.append({
            "Decision Group ID": g["group_id"], "SKU": sku, "Item Name": item,
            "Admin Decision Applies From Group": "YES -- follows the group's Admin Decision once filled in",
        })
df_affected = pd.DataFrame(affected_rows)

# ---------------------------------------------------------------------
# UNIQUE CASES sheet -- cannot be grouped, each needs its own answer
# ---------------------------------------------------------------------
uc_rows = [{
    "SKU": u["sku"], "Name": u["item_name"], "Current Base": u["current_base"],
    "Legacy": u["legacy"], "Name Evidence": u["name_evidence"],
    "Transaction Evidence": u["transaction_evidence"], "Exact Question": u["exact_question"],
    "Admin Decision": "", "Admin Notes": "",
} for u in unique_cases]
df_unique = pd.DataFrame(uc_rows)

# ---------------------------------------------------------------------
# SUMMARY sheet
# ---------------------------------------------------------------------
total_grouped = sum(g["affected_sku_count"] for g in decision_groups)
summary_rows = [
    ("Starting CONVERSION_CONFLICT count (v3, physical-normalization only)", 46),
    ("", ""),
    ("Resolved automatically (no admin decision needed)", sum(resolved.values())),
]
for reason, count in sorted(resolved.items(), key=lambda kv: -kv[1]):
    summary_rows.append((f"  - {reason}", count))
summary_rows += [
    ("", ""),
    ("Consolidated into admin decision groups", total_grouped),
    ("Number of decision groups", len(decision_groups)),
]
for g in decision_groups:
    summary_rows.append((f"  - {g['group_id']}", g["affected_sku_count"]))
summary_rows += [
    ("", ""),
    ("Remaining unique (individual) questions", len(unique_cases)),
    ("", ""),
    ("Total SKUs requiring SOME admin answer", total_grouped + len(unique_cases)),
    ("Movement reconciliation rows (kept SEPARATE, not a conversion question)", len(movement_review_skus)),
    ("", ""),
    ("PRODUCTION POSTING THIS PHASE", "NONE — staging/analysis only."),
]
df_summary = pd.DataFrame(summary_rows, columns=["Metric", "Value"])

# ---------------------------------------------------------------------
# INSTRUCTIONS sheet
# ---------------------------------------------------------------------
instructions_text = [
    "ADMIN DECISION GROUPS — Phase G-DATA 1B.2",
    "",
    "This workbook turns 46 raw unit-conversion conflicts into a SMALL number",
    "of business questions instead of 46 individual ones.",
    "",
    "HOW TO ANSWER:",
    "1. Open the DECISION GROUPS sheet. Each row is ONE business question",
    "   covering several SKUs at once (see AFFECTED SKUS for the full list",
    "   per group).",
    "2. Fill in the 'Admin Decision' column with your chosen letter (A/B/C/D",
    "   from 'Recommended Choices', or your own answer in 'Admin Notes').",
    "3. A group's decision applies to ALL its affected SKUs ONLY if you",
    "   confirm the rule holds for the whole group. If it's true for most",
    "   SKUs but not all, say so in 'Admin Notes' and list the exceptions --",
    "   those will be split out and handled individually.",
    "4. UNIQUE CASES sheet: these could not be grouped (each has its own",
    "   specific numbers/conflict). Please answer each one individually.",
    "5. Movement-reconciliation rows are NOT unit-conversion questions --",
    "   they are opening+IN-OUT quantity discrepancies (timing/rounding/",
    "   missing movement). They are listed separately in the MOVEMENT",
    "   REVIEW sheet of unit_conversion_review_v4.xlsx and do not need an",
    "   answer here.",
    "",
    "These are all staging/analysis questions. Nothing is posted to",
    "production until you answer and a human re-applies your decisions in",
    "a following phase. No opening import, no historical replay, no",
    "cutover happens from this workbook alone.",
]
df_instructions = pd.DataFrame({"INSTRUCTIONS": instructions_text})

with pd.ExcelWriter(XLSX_PATH, engine="openpyxl") as writer:
    df_instructions.to_excel(writer, sheet_name="INSTRUCTIONS", index=False)
    df_summary.to_excel(writer, sheet_name="SUMMARY", index=False)
    df_groups.to_excel(writer, sheet_name="DECISION GROUPS", index=False)
    df_affected.to_excel(writer, sheet_name="AFFECTED SKUS", index=False)
    df_unique.to_excel(writer, sheet_name="UNIQUE CASES", index=False)

    for sheet_name, frame in [
        ("INSTRUCTIONS", df_instructions), ("SUMMARY", df_summary),
        ("DECISION GROUPS", df_groups), ("AFFECTED SKUS", df_affected),
        ("UNIQUE CASES", df_unique),
    ]:
        ws = writer.sheets[sheet_name]
        for i, col in enumerate(frame.columns, start=1):
            width = min(max(14, int(frame[col].astype(str).str.len().clip(upper=70).max() if len(frame) else 14) + 2), 70)
            ws.column_dimensions[ws.cell(row=1, column=i).column_letter].width = width

print(f"Wrote {XLSX_PATH}")
print(f"Sheets: INSTRUCTIONS, SUMMARY, DECISION GROUPS({len(df_groups)}), "
      f"AFFECTED SKUS({len(df_affected)}), UNIQUE CASES({len(df_unique)})")
