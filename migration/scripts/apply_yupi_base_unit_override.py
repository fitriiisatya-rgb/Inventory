#!/usr/bin/env python3
"""
PHASE G-DATA 3 — applies the "all YUPI items use GR" business-confirmed
base-unit override on top of the v5 baseline (unchanged, read-only input).
Same layered-override pattern as Phase 1B.1/1B.3: never edits the detector
or semantic-grouping code, only adds an explicit, auditable layer on top.

Scans the full 1,165-SKU Global Master v5 for item_name containing "YUPI"
(case-insensitive) -- this is NOT hardcoded to the 2 known SKUs; if the
real catalog ever gains another YUPI item, this script picks it up
automatically and reports it. Wherever legacy/master data recorded PCS for
a matched item, that is treated as an old mislabel of the same GR-based
item -- NO PCS->GR conversion factor is invented (Section 3/4 of the
instruction).

Output: migration/workspace/normalized/unit_conversion_candidates_real_v6.json
"""
import json

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
BASELINE_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v5.json"
OVERRIDES_PATH = "/home/user/Inventory/migration/scripts/business_confirmed_overrides_yupi.json"
OUT_JSON_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v6.json"

results = json.load(open(BASELINE_PATH, encoding="utf-8"))
overrides = json.load(open(OVERRIDES_PATH, encoding="utf-8"))
overrides = {k: v for k, v in overrides.items() if not k.startswith("_")}

# ---------------------------------------------------------------------
# Scan step: find every SKU whose item_name contains "YUPI" -- confirms
# the override file's scope is complete and nothing YUPI-named is missed.
# ---------------------------------------------------------------------
yupi_skus = [r["sku"] for r in results if "YUPI" in (r.get("item_name") or "").upper()]
print(f"Scanned 1,165 Global Master v5 SKU for 'YUPI' in item_name: {len(yupi_skus)} match(es) -> {yupi_skus}")

missing_from_override = [s for s in yupi_skus if s not in overrides]
extra_in_override = [s for s in overrides if s not in yupi_skus]
if missing_from_override:
    print(f"WARNING: {len(missing_from_override)} YUPI SKU found in Global Master but NOT in the override file: {missing_from_override}")
if extra_in_override:
    print(f"WARNING: override file names SKU(s) not currently matching 'YUPI' in Global Master: {extra_in_override}")

applied = []
non_yupi_touched = []

for r in results:
    sku = r["sku"]
    if sku not in overrides:
        continue
    if "YUPI" not in (r.get("item_name") or "").upper():
        non_yupi_touched.append(sku)  # safety net -- should never happen
        continue

    ov = overrides[sku]
    r["prior_base_unit_before_yupi_override"] = {
        "gudang_besar_unit": r.get("gudang_besar_unit"),
        "cibadak_unit": r.get("cibadak_unit"),
        "karangtengah_unit": r.get("karangtengah_unit"),
        "global_base_unit_candidate": r.get("global_base_unit_candidate"),
        "approved_base_unit": r.get("approved_base_unit"),
        "issue_code": r.get("issue_code"),
        "review_status": r.get("review_status"),
        "confidence": r.get("confidence"),
    }

    new_base = ov["approved_base_unit"]
    # Relabel every unit field that previously said PCS (or was empty) to
    # the confirmed base unit -- this is a LABEL correction, not a
    # packaging conversion: no approved_purchase_unit/conversion is set.
    if r.get("gudang_besar_unit") in (None, "PCS"):
        r["gudang_besar_unit"] = new_base
    if r.get("cibadak_unit") in (None, "PCS"):
        r["cibadak_unit"] = new_base if r.get("cibadak_unit") == "PCS" else r.get("cibadak_unit")
    if r.get("karangtengah_unit") in (None, "PCS"):
        r["karangtengah_unit"] = new_base if r.get("karangtengah_unit") == "PCS" else r.get("karangtengah_unit")
    r["global_base_unit_candidate"] = new_base
    r["approved_base_unit"] = new_base
    r["approved_purchase_unit"] = None
    r["approved_purchase_conversion"] = None

    r["conversion_source"] = ov["conversion_source"]
    r["review_status"] = ov["review_status"]
    r["confidence"] = "BUSINESS_CONFIRMED"
    r["approved"] = "YES"

    remaining_issues = [c for c in (r.get("issue_code") or "").split(",") if c and c not in (
        "NO_EVIDENCE_FOUND", "UNIT_REVIEW_REQUIRED", "BASE_UNIT_REVIEW_REQUIRED",
    )]
    remaining_issues.append("BUSINESS_CONFIRMED_OVERRIDE")
    r["issue_code"] = ",".join(dict.fromkeys(remaining_issues))

    note = ov["correction_note"]
    r["correction_note"] = f"{r['correction_note']}; {note}" if r.get("correction_note") else note
    r["admin_source_answer"] = "SEMUA PRODUK YUPI menggunakan satuan dasar GR (GRAM)."

    applied.append(sku)

if non_yupi_touched:
    raise SystemExit(f"REFUSING TO WRITE: override file named non-YUPI SKU(s), which must never happen: {non_yupi_touched}")

print(f"Applied YUPI base-unit override to {len(applied)} SKU: {applied}")

# ---------------------------------------------------------------------
# Safety net BEFORE writing: confirm no other SKU changed at all.
# ---------------------------------------------------------------------
baseline_by_sku = {r["sku"]: r for r in json.load(open(BASELINE_PATH, encoding="utf-8"))}
changed_non_yupi = [r["sku"] for r in results if r["sku"] not in overrides and r != baseline_by_sku[r["sku"]]]
if changed_non_yupi:
    raise SystemExit(f"REFUSING TO WRITE: non-YUPI SKU(s) were unexpectedly modified: {changed_non_yupi}")
print(f"Verified: all {len(results) - len(applied)} non-YUPI SKU are byte-identical to v5 (checked before writing).")

with open(OUT_JSON_PATH, "w", encoding="utf-8") as f:
    json.dump(results, f, ensure_ascii=False, indent=2, default=str)
print(f"Wrote {OUT_JSON_PATH}")
