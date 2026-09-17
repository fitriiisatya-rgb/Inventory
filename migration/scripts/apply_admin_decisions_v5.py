#!/usr/bin/env python3
"""
PHASE G-DATA 1B.3 — apply the owner/admin's answers to
admin_decision_groups.xlsx (Phase G-DATA 1B.2) on top of the v4 baseline.

Same layered pattern as apply_business_confirmed_overrides.py in Phase
1B.1: the detector + semantic-grouping output (v4) is never modified in
place. This is a separate, auditable override layer read from
admin_decisions_v5.json (the owner's answers, recorded verbatim in
source_answer fields).

Covers all 31 SKU that needed an admin answer (27 across 6 groups + 4
unique cases) -- every one of them was answered, so after this layer
CONVERSION_CONFLICT should be fully resolved for this catalog.

NO production posting happens here. Output is still staging/analysis
only: unit_conversion_candidates_real_v5.json.
"""
import json

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
BASELINE_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v4.json"
DECISIONS_PATH = "/home/user/Inventory/migration/scripts/admin_decisions_v5.json"
OUT_JSON_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v5.json"

results = json.load(open(BASELINE_PATH, encoding="utf-8"))
decisions = json.load(open(DECISIONS_PATH, encoding="utf-8"))
by_sku = {r["sku"]: r for r in results}

applied = []


def apply_override(sku, resolution_text, source_answer, **fields):
    r = by_sku.get(sku)
    if r is None:
        print(f"WARNING: SKU {sku} not found in baseline, skipping")
        return
    r["prior_semantic_issue_code"] = r["issue_code"]
    r["prior_semantic_confidence"] = r["confidence"]
    r["prior_semantic_review_status"] = r["review_status"]

    remaining_issues = [
        c for c in r["issue_code"].split(",")
        if c and c not in ("CONVERSION_CONFLICT", "CROSS_COUNT_UNIT", "SAME_UNIT_DIFFERENT_FACTOR",
                            "SAME_UNIT_LEGACY_DEFAULT_VS_NAME_COUNT", "CROSS_DIMENSION_AMBIGUOUS")
    ]
    remaining_issues.append("ADMIN_DECISION_CONFIRMED")
    r["issue_code"] = ",".join(dict.fromkeys(remaining_issues))

    r["conversion_source"] = "BUSINESS_CONFIRMED"
    r["review_status"] = "APPROVED"
    r["confidence"] = "BUSINESS_CONFIRMED"
    r["approved"] = "YES"

    if "approved_base_unit" in fields:
        r["approved_base_unit"] = fields["approved_base_unit"]
    if "approved_purchase_unit" in fields:
        r["approved_purchase_unit"] = fields["approved_purchase_unit"]
    if "approved_purchase_conversion" in fields:
        r["approved_purchase_conversion"] = fields["approved_purchase_conversion"]
    if "content_qty" in fields:
        r["content_info"] = {
            "content_qty": fields["content_qty"], "content_unit": fields.get("content_unit"),
            "content_per_inventory_unit": True, "inventory_unit": r.get("global_base_unit_candidate"),
        }

    note = f"Owner confirmed via admin_decision_groups.xlsx: \"{source_answer}\" -- {resolution_text}"
    r["correction_note"] = f"{r['correction_note']}; {note}" if r.get("correction_note") else note
    r["admin_source_answer"] = source_answer
    applied.append(sku)


for group_id, group in decisions["group_answers"].items():
    if "skus" in group and isinstance(group["skus"], list):
        for sku in group["skus"]:
            apply_override(sku, group["resolution"], group["source_answer"],
                            approved_base_unit=group["approved_base_unit"],
                            approved_purchase_unit=group["approved_purchase_unit"],
                            approved_purchase_conversion=group["approved_purchase_conversion"])
    elif "per_sku" in group:
        for sku, fields in group["per_sku"].items():
            apply_override(sku, group["resolution"], group["source_answer"], **fields)
    elif "skus" in group and isinstance(group["skus"], dict):
        for sku, fields in group["skus"].items():
            apply_override(sku, group["resolution"], group["source_answer"], **fields)

for sku, entry in decisions["unique_case_answers"].items():
    fields = {k: v for k, v in entry.items() if k not in ("item_name", "source_answer", "resolution", "content_note")}
    apply_override(sku, entry["resolution"], entry["source_answer"], **fields)

print(f"Applied admin decisions to {len(applied)} SKU")
print(sorted(applied))

with open(OUT_JSON_PATH, "w", encoding="utf-8") as f:
    json.dump(results, f, ensure_ascii=False, indent=2, default=str)
print(f"Wrote {OUT_JSON_PATH}")

remaining_conflicts = [r["sku"] for r in results if "CONVERSION_CONFLICT" in r["issue_code"]]
remaining_cross_count = [r["sku"] for r in results if "CROSS_COUNT_UNIT" in r["issue_code"]]
print(f"Remaining CONVERSION_CONFLICT after admin decisions: {len(remaining_conflicts)} -> {remaining_conflicts}")
print(f"Remaining CROSS_COUNT_UNIT (ungrouped/unanswered): {len(remaining_cross_count)} -> {remaining_cross_count}")
