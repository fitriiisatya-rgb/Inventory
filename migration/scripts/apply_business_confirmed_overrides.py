#!/usr/bin/env python3
"""
PHASE G-DATA 1B.1 — apply business-confirmed overrides on top of the
detector-only output (unit_conversion_candidates_real.json).

This is a SEPARATE, explicit layer on top of the pure rule engine
(reconstruct_unit_conversions_real.py), never mixed into it, so the
detector's output always stays reproducible/auditable from raw data
alone. Overrides only apply to the exact SKUs a human (business
owner/admin) explicitly confirmed in business_confirmed_overrides.json
-- no generalization to other SKUs with a similar pattern.

NO production posting happens here. Output is still staging/analysis
only: unit_conversion_candidates_real_v3.json (Phase G-DATA 1B.1's
normalization-engine fix regenerated the detector output, so this
overrides layer is re-applied on top of the corrected v3 detector run;
the v2 file from the pre-normalization-fix round is left as historical
record and not overwritten).
"""
import json
import sys

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
BASE_RESULTS_PATH = f"{OUT_DIR}/unit_conversion_candidates_real.json"
OVERRIDES_PATH = "/home/user/Inventory/migration/scripts/business_confirmed_overrides.json"
VERSION_SUFFIX = sys.argv[1] if len(sys.argv) > 1 else "v3"
V2_RESULTS_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_{VERSION_SUFFIX}.json"

results = json.load(open(BASE_RESULTS_PATH, encoding="utf-8"))
overrides = json.load(open(OVERRIDES_PATH, encoding="utf-8"))
overrides = {k: v for k, v in overrides.items() if not k.startswith("_")}

applied = []
for r in results:
    sku = r["sku"]
    if sku not in overrides:
        continue
    ov = overrides[sku]

    r["prior_detector_issue_code"] = r["issue_code"]
    r["prior_detector_confidence"] = r["confidence"]
    r["prior_detector_review_status"] = r["review_status"]

    remaining_issues = [
        code for code in r["issue_code"].split(",")
        if code and code not in ov.get("resolves_issue_codes", [])
    ]
    remaining_issues.append("BUSINESS_CONFIRMED_OVERRIDE")
    r["issue_code"] = ",".join(dict.fromkeys(remaining_issues))

    r["conversion_source"] = ov.get("conversion_source")
    if "price_source" in ov:
        r["price_source"] = ov["price_source"]
    r["review_status"] = ov.get("review_status", r["review_status"])
    r["confidence"] = "BUSINESS_CONFIRMED"
    r["approved"] = "YES"

    if ov.get("approved_base_unit"):
        r["approved_base_unit"] = ov["approved_base_unit"]
    if ov.get("approved_purchase_unit"):
        r["approved_purchase_unit"] = ov["approved_purchase_unit"]
    if ov.get("approved_purchase_conversion") is not None:
        r["approved_purchase_conversion"] = ov["approved_purchase_conversion"]
    if ov.get("unit_cost_base_kg") is not None:
        r["unit_cost_base_kg"] = ov["unit_cost_base_kg"]
    if ov.get("unit_cost_base_gr_equivalent") is not None:
        r["unit_cost_base_gr_equivalent"] = ov["unit_cost_base_gr_equivalent"]

    note = ov.get("correction_note", "")
    r["correction_note"] = (
        f"{r['correction_note']}; {note}" if r.get("correction_note") else note
    )
    r["issue_detail"] = (
        f"{r['issue_detail']} [BUSINESS_CONFIRMED override applied: {note} "
        f"Prior detector-only state: issue_code={r['prior_detector_issue_code']}, "
        f"confidence={r['prior_detector_confidence']}, "
        f"review_status={r['prior_detector_review_status']} -- legacy evidence "
        f"retained below for audit, superseded by this business confirmation.]"
    ).strip()

    applied.append(sku)

with open(V2_RESULTS_PATH, "w", encoding="utf-8") as f:
    json.dump(results, f, ensure_ascii=False, indent=2, default=str)

print(f"Applied business-confirmed overrides to {len(applied)} SKU: {applied}")
print(f"Wrote {V2_RESULTS_PATH}")

remaining_conflicts = [r["sku"] for r in results if "CONVERSION_CONFLICT" in r["issue_code"]]
remaining_mismatches = [r["sku"] for r in results if "UNIT_LABEL_MISMATCH" in r["issue_code"]]
print(f"Remaining CONVERSION_CONFLICT after overrides: {len(remaining_conflicts)} -> {remaining_conflicts}")
print(f"Remaining UNIT_LABEL_MISMATCH after overrides: {len(remaining_mismatches)} -> {remaining_mismatches}")
