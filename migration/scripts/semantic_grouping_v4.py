#!/usr/bin/env python3
"""
PHASE G-DATA 1B.2 — SEMANTIC UNIT & ADMIN DECISION GROUPING.

Does NOT repeat Phase 1B.1 (the physical KG<->GR / LTR<->ML normalization
fix is already correct and unchanged in v3, commit 4755ea0). This script
loads that v3 output as a read-only baseline and adds a further, separate
semantic layer:

1. A corrected product-name parser: composite "qty+unit x qty" /
   "qty+unit @ qty+unit" patterns, a known-unit allowlist (so a random
   trailing word like "Spesial"/"Ori"/"Talas" is never treated as a unit),
   and 3+-level patterns are left as NAME_STRUCTURE_REVIEW rather than
   mis-parsed.
2. A classification of what a parsed name value actually MEANS relative
   to the SKU's base unit: a trivial restatement of the base unit itself,
   a content-weight-per-inventory-unit note (e.g. "1 PCS = 425 GR net"),
   a content-qty-per-inventory-unit note (e.g. "1 PACK = 100 PCS"), a
   real same-unit candidate, or a cross-count-unit ambiguity (SET vs PCS
   etc) that needs a human business decision, never an auto-declared
   conversion.
3. Grouping of the remaining genuine ambiguities into a small number of
   recurring semantic decision groups (e.g. one "is SET the same as PCS
   for Topper Ultah products" question covering 17 SKUs) instead of 46
   individual questions, plus a short list of truly unique cases.

Baseline: migration/workspace/normalized/unit_conversion_candidates_real_v3.json
Outputs:
  migration/workspace/normalized/unit_conversion_candidates_real_v4.json
  migration/workspace/normalized/warehouse_unit_comparison_v4.csv
  migration/workspace/normalized/admin_decision_groups.json (raw group data)

NO production posting happens here -- staging/analysis only.
"""
import json
import re

OUT_DIR = "/home/user/Inventory/migration/workspace/normalized"
BASELINE_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v3.json"
OUT_JSON_PATH = f"{OUT_DIR}/unit_conversion_candidates_real_v4.json"
OUT_CSV_PATH = f"{OUT_DIR}/warehouse_unit_comparison_v4.csv"
OUT_GROUPS_PATH = f"{OUT_DIR}/admin_decision_groups.json"

RATIO_TOLERANCE = 0.05

# ---------------------------------------------------------------------
# Unit dictionary -- same canonical set as the v3 detector, PLUS one
# real discovered unit from this round's scan ("batang" = stick/rod,
# used for candles: "Lilin Spiral @1x12 batang"). Extending the
# dictionary with units actually observed in real data is the same
# principle the project has followed since Phase G2.1 -- this is not a
# per-SKU hardcode, it's a general vocabulary addition.
# ---------------------------------------------------------------------
CANONICAL_UNITS = {
    "GR", "KG", "ML", "LTR", "PCS", "BOX", "KARTON", "KARUNG", "LUSIN", "PACK", "ROLL",
    "PAIL", "JAR", "SET", "SHEET", "METER", "BATANG",
}
UNIT_ALIASES = {
    "gr": "GR", "gram": "GR", "grams": "GR", "g": "GR",
    "kg": "KG", "kilogram": "KG", "kilo": "KG",
    "ml": "ML", "mililiter": "ML", "milliliter": "ML",
    "ltr": "LTR", "liter": "LTR", "litre": "LTR", "l": "LTR", "lt": "LTR",
    "pcs": "PCS", "piece": "PCS", "pieces": "PCS", "pc": "PCS", "buah": "PCS", "biji": "PCS", "unit": "PCS",
    "box": "BOX", "kotak": "BOX", "dus": "BOX",
    "karton": "KARTON", "carton": "KARTON", "ctn": "KARTON",
    "karung": "KARUNG", "sack": "KARUNG", "sak": "KARUNG",
    "lusin": "LUSIN", "dozen": "LUSIN",
    "pack": "PACK", "pak": "PACK", "bungkus": "PACK",
    "roll": "ROLL", "gulung": "ROLL",
    "pail": "PAIL", "pail(s)": "PAIL",
    "jar": "JAR", "toples": "JAR",
    "set": "SET",
    "sheet": "SHEET", "lembar": "SHEET",
    "meter": "METER", "mtr": "METER", "m": "METER",
    "batang": "BATANG",
}
# Weight/volume are the only two DIMENSIONS with a hardcoded canonical
# conversion (Phase 1B.1). Everything else is a "count" style unit --
# comparable to another count unit only via a human business decision.
DIMENSION_OF = {"KG": "WEIGHT", "GR": "WEIGHT", "LTR": "VOLUME", "ML": "VOLUME"}
CANONICAL_DIMENSION = {
    "KG": ("WEIGHT", 1000.0, "GR"), "GR": ("WEIGHT", 1.0, "GR"),
    "LTR": ("VOLUME", 1000.0, "ML"), "ML": ("VOLUME", 1.0, "ML"),
}


def normalize_unit(raw):
    if raw is None:
        return None
    key = str(raw).strip().lower()
    if key == "":
        return None
    if key in UNIT_ALIASES:
        return UNIT_ALIASES[key]
    return key.upper()


def is_known_unit(unit):
    return unit in CANONICAL_UNITS


def normalize_physical_qty(qty, unit):
    if qty is None or unit is None:
        return qty, unit, "UNKNOWN_UNIT"
    if unit in CANONICAL_DIMENSION:
        _, factor, ref_unit = CANONICAL_DIMENSION[unit]
        return round(qty * factor, 6), ref_unit, ("SAME_UNIT" if unit == ref_unit else "CONVERTED")
    return qty, unit, "SAME_UNIT"


NUM = r"\d+(?:[.,]\d+)?"


def _f(s):
    return float(s.replace(",", "."))


# Longest-first alternation of every known unit token so "GR" wins over
# a generic [A-Za-z]+ grab that would otherwise swallow a following "X".
_UNIT_TOKENS = sorted(set(list(UNIT_ALIASES.keys()) + [u.lower() for u in CANONICAL_UNITS]), key=len, reverse=True)
_UNIT_ALT = "|".join(re.escape(u) for u in _UNIT_TOKENS)

# P1: "<qty><KNOWNUNIT> x <qty>" with NO unit after the second number --
#     e.g. "500GRX20" (Chefmate), "1kg x 10" (Kacang cincang), and
#     "500GR X 24 GREENTEA" (a flavour/variant NAME may still follow the
#     count -- only a KNOWN UNIT word immediately after the count blocks
#     the match; (?!\d) stops the greedy \d+ from silently backtracking
#     into the middle of the second number, e.g. "24" -> "2").
RE_WEIGHT_THEN_COUNT = re.compile(
    rf"({NUM})\s*({_UNIT_ALT})\s*[xX]\s*({NUM})(?!\d)(?!\s*(?:{_UNIT_ALT}))", re.IGNORECASE
)
# P2: "<qty><KNOWNUNIT> @ <qty><KNOWNUNIT>" -- e.g. "8kg@3pcs".
RE_WEIGHT_AT_COUNT = re.compile(
    rf"({NUM})\s*({_UNIT_ALT})\s*@\s*({NUM})\s*({_UNIT_ALT})", re.IGNORECASE
)
# P3 (existing "count x size unit", ambiguous by construction, unchanged
#     from v1-v3): "@ <qty> x <qty> <unit>" e.g. "@12x1Kg".
RE_MULTI_AMBIG = re.compile(rf"@\s*({NUM})\s*[xX]\s*({NUM})\s*([A-Za-z]+)")
# P4: plain "@ <qty> <word>" (SINGLE) -- validated against the known-unit
#     list AFTER matching, not baked into the regex.
RE_SINGLE = re.compile(rf"@\s*({NUM})\s*([A-Za-z]+)")
# 3+-level composite guard: two or more REAL multiplier separators (an
# x/X sitting between two digits, e.g. "1x12x2") -- NOT any x/X
# character anywhere (that would false-positive on ordinary words
# containing the letter x, e.g. "TULIP BORDEUX 2x2.5Kg").
RE_MULTI_X_COUNT = re.compile(r"\d\s*[xX]\s*\d")


def parse_product_name(item_name):
    """Returns a dict describing what was found in the name, one of:
    - {'kind': 'composite', 'qty': float, 'unit': str, 'raw': str, 'parts': [...]}
    - {'kind': 'single', 'qty': float, 'unit': str, 'raw': str}
    - {'kind': 'ambiguous_multi', 'raw': str}          -- old "count x size unit" form
    - {'kind': 'structure_review', 'raw': str}          -- 3+ level, too ambiguous
    - {'kind': 'variant_text', 'raw': str, 'word': str} -- trailing word is not a unit
    - None                                              -- nothing matched at all
    """
    if not item_name:
        return None
    at_idx = item_name.find("@")
    if at_idx < 0:
        # Every v1-v3 pattern required an explicit "@" marker before
        # treating any number in the name as packaging/quantity info --
        # preserved here so a bare dimension string like "24X15X29" or a
        # coincidental "2x2.5Kg" with no "@" is never scanned at all.
        return None
    tail = item_name[at_idx:]

    # 3+ level composite guard: bail to NAME_STRUCTURE_REVIEW rather than
    # mis-parse (e.g. "1x12x2 set", "10X12X20Gr", "6X12X60Ml").
    at_window = tail[:40]
    if len(RE_MULTI_X_COUNT.findall(at_window)) >= 2:
        return {"kind": "structure_review", "raw": at_window.strip()}

    m = RE_WEIGHT_AT_COUNT.search(item_name)
    if m:
        q1, u1_raw, q2, u2_raw = m.groups()
        u1, u2 = normalize_unit(u1_raw), normalize_unit(u2_raw)
        d1, d2 = DIMENSION_OF.get(u1), DIMENSION_OF.get(u2)
        # Only treat as a genuine composite when exactly one side is a
        # weight/volume "content" unit and the other is a plain count.
        if (d1 in ("WEIGHT", "VOLUME")) != (d2 in ("WEIGHT", "VOLUME")):
            if d1 in ("WEIGHT", "VOLUME"):
                content_qty, content_unit, count_qty, count_unit = _f(q1), u1, _f(q2), u2
            else:
                content_qty, content_unit, count_qty, count_unit = _f(q2), u2, _f(q1), u1
            return {
                "kind": "composite", "qty": round(content_qty * count_qty, 6), "unit": content_unit,
                "raw": m.group(0), "parts": [(_f(q1), u1), (_f(q2), u2)],
            }

    m = RE_WEIGHT_THEN_COUNT.search(tail)
    if m:
        q1, u1_raw, q2 = m.groups()
        u1 = normalize_unit(u1_raw)
        return {
            "kind": "composite", "qty": round(_f(q1) * _f(q2), 6), "unit": u1,
            "raw": m.group(0), "parts": [(_f(q1), u1), (_f(q2), None)],
        }

    m = RE_MULTI_AMBIG.search(tail)
    if m:
        q1, q2, u_raw = m.groups()
        u = normalize_unit(u_raw)
        if is_known_unit(u):
            return {"kind": "ambiguous_multi", "raw": m.group(0), "count": _f(q1), "size": _f(q2), "unit": u}
        return {"kind": "structure_review", "raw": m.group(0)}

    m = RE_SINGLE.search(tail)
    if m:
        q, u_raw = m.groups()
        u = normalize_unit(u_raw)
        if is_known_unit(u):
            return {"kind": "single", "qty": _f(q), "unit": u, "raw": m.group(0)}
        return {"kind": "variant_text", "raw": m.group(0), "word": u_raw.strip()}

    return None


def classify_name_value(qty, unit, base_unit):
    """Decides what a (qty, unit) name value MEANS relative to the SKU's
    base unit -- never assumes it's automatically a base-unit conversion
    candidate. Returns one of:
      REAL_CANDIDATE               -- genuinely comparable to legacy/base
      NAME_TRIVIAL_BASE_UNIT       -- "@1 <base unit>", restates the base, no info
      CONTENT_WEIGHT_PER_INVENTORY_UNIT -- base is a count unit, name gives a weight/volume
      CONTENT_QTY_PER_INVENTORY_UNIT    -- base is weight/volume, name gives a count
      CROSS_COUNT_UNIT              -- both are count-type units but differ (SET vs PCS etc)
      CROSS_DIMENSION_AMBIGUOUS     -- one is WEIGHT, other is VOLUME (no valid conversion)
    """
    name_dim = DIMENSION_OF.get(unit, "COUNT")
    base_dim = DIMENSION_OF.get(base_unit, "COUNT") if base_unit else "COUNT"
    if base_unit and unit == base_unit and abs(qty - 1.0) < 1e-9:
        return "NAME_TRIVIAL_BASE_UNIT"
    if base_dim == "COUNT" and name_dim in ("WEIGHT", "VOLUME"):
        return "CONTENT_WEIGHT_PER_INVENTORY_UNIT"
    if base_dim in ("WEIGHT", "VOLUME") and name_dim == "COUNT":
        return "CONTENT_QTY_PER_INVENTORY_UNIT"
    if base_dim == "COUNT" and name_dim == "COUNT" and unit != base_unit:
        return "CROSS_COUNT_UNIT"
    if {"WEIGHT", "VOLUME"} == {base_dim, name_dim} and base_dim != name_dim:
        return "CROSS_DIMENSION_AMBIGUOUS"
    return "REAL_CANDIDATE"


# ---------------------------------------------------------------------
# Load v3 baseline (read-only) and re-derive name evidence + confidence
# ---------------------------------------------------------------------
results = json.load(open(BASELINE_PATH, encoding="utf-8"))
print(f"Loaded {len(results)} SKU from v3 baseline ({BASELINE_PATH})")

semantic_group_members = {}   # group_id -> [sku, ...]
unique_case_skus = []
resolved_count_by_reason = {}

for r in results:
    if r.get("conversion_source") == "BUSINESS_CONFIRMED":
        continue  # never touch a business-confirmed row

    item_name = r.get("item_name")
    base_unit = r.get("global_base_unit_candidate")
    parsed = parse_product_name(item_name)

    new_name_evidence = None
    name_class = None
    content_info = None

    if parsed is None:
        pass
    elif parsed["kind"] == "structure_review":
        r_issue_extra = "NAME_STRUCTURE_REVIEW"
    elif parsed["kind"] == "variant_text":
        r_issue_extra = "PRODUCT_VARIANT_TEXT"
    elif parsed["kind"] == "ambiguous_multi":
        # Same "count x size unit" ambiguous form the v1-v3 engine already
        # excluded from candidate_factors -- unchanged behaviour, just
        # re-derived with the known-unit-validated regex.
        new_name_evidence = {
            "raw": parsed["raw"], "total_qty": round(parsed["count"] * parsed["size"], 6),
            "unit": parsed["unit"], "ambiguous_structure": True,
        }
        name_class = "AMBIGUOUS_MULTI"
    elif parsed["kind"] in ("composite", "single"):
        qty, unit = parsed["qty"], parsed["unit"]
        name_class = classify_name_value(qty, unit, base_unit)
        norm_qty, norm_unit, norm_status = normalize_physical_qty(qty, unit)
        new_name_evidence = {
            "raw": parsed["raw"], "total_qty": qty, "unit": unit, "ambiguous_structure": False,
            "raw_qty": qty, "raw_unit": unit, "normalized_qty": norm_qty, "normalized_unit": norm_unit,
            "normalization_status": norm_status, "parse_kind": parsed["kind"],
            "composite_parts": parsed.get("parts"),
        }
        if name_class == "CONTENT_WEIGHT_PER_INVENTORY_UNIT":
            content_info = {"content_qty": qty, "content_unit": unit, "content_per_inventory_unit": True,
                             "inventory_unit": base_unit}
        elif name_class == "CONTENT_QTY_PER_INVENTORY_UNIT":
            content_info = {"content_qty": qty, "content_unit": unit, "content_per_inventory_unit": True,
                             "inventory_unit": base_unit}

    # ---- Re-run legacy evidence (unchanged from v3) + candidate clustering ----
    legacy_evidence = r.get("legacy_evidence")
    price_evidence = r.get("price_ratio_evidence") if r.get("price_ratio_evidence") and "candidate_factor" in (r.get("price_ratio_evidence") or {}) else None

    candidate_factors = []
    if new_name_evidence and name_class == "REAL_CANDIDATE":
        candidate_factors.append(("NAME_HEURISTIC", new_name_evidence["normalized_qty"], new_name_evidence["normalized_unit"]))
    if price_evidence and "normalized_qty" in price_evidence:
        candidate_factors.append(("PRICE_RATIO", price_evidence["normalized_qty"], price_evidence["normalized_unit"]))
    if legacy_evidence and legacy_evidence.get("legacy_isi_dasar_normalized_qty") not in (None, ""):
        candidate_factors.append(("LEGACY", legacy_evidence["legacy_isi_dasar_normalized_qty"], legacy_evidence["legacy_isi_dasar_normalized_unit"]))

    evidence_count = 0
    conflict = False
    if candidate_factors:
        clusters = []
        for src, v, u in candidate_factors:
            placed = False
            for cluster in clusters:
                ref_v, ref_u = cluster[0][1], cluster[0][2]
                if u is not None and ref_u is not None and u == ref_u and ref_v not in (None, 0) and abs(v - ref_v) / abs(ref_v) <= RATIO_TOLERANCE:
                    cluster.append((src, v, u))
                    placed = True
                    break
            if not placed:
                clusters.append([(src, v, u)])
        clusters.sort(key=len, reverse=True)
        evidence_count = len(clusters[0])
        conflict = len(clusters) > 1

    # ---- Semantic classification of the outcome ----
    semantic_tag = None
    sku = r["sku"]
    if parsed is not None and parsed["kind"] == "structure_review":
        semantic_tag = "NAME_STRUCTURE_REVIEW"
        # A name too structurally ambiguous to parse is only a decision
        # point if something ELSE (legacy/price) still disagrees once the
        # name is excluded from evidence -- otherwise it's just informational.
        if conflict:
            unique_case_skus.append(sku)
        else:
            resolved_count_by_reason[semantic_tag] = resolved_count_by_reason.get(semantic_tag, 0) + 1
    elif parsed is not None and parsed["kind"] == "variant_text":
        semantic_tag = "PRODUCT_VARIANT_TEXT"
        resolved_count_by_reason["PRODUCT_VARIANT_TEXT"] = resolved_count_by_reason.get("PRODUCT_VARIANT_TEXT", 0) + 1
    elif name_class == "NAME_TRIVIAL_BASE_UNIT":
        semantic_tag = "NAME_TRIVIAL_BASE_UNIT"
        resolved_count_by_reason[semantic_tag] = resolved_count_by_reason.get(semantic_tag, 0) + 1
    elif name_class == "CONTENT_WEIGHT_PER_INVENTORY_UNIT":
        semantic_tag = "CONTENT_WEIGHT_PER_INVENTORY_UNIT"
        resolved_count_by_reason[semantic_tag] = resolved_count_by_reason.get(semantic_tag, 0) + 1
    elif name_class == "CONTENT_QTY_PER_INVENTORY_UNIT":
        semantic_tag = "CONTENT_QTY_PER_INVENTORY_UNIT"
        resolved_count_by_reason[semantic_tag] = resolved_count_by_reason.get(semantic_tag, 0) + 1
    elif name_class == "CROSS_DIMENSION_AMBIGUOUS":
        semantic_tag = "CROSS_DIMENSION_AMBIGUOUS"
        if conflict or "CONVERSION_CONFLICT" in r["issue_code"]:
            unique_case_skus.append(sku)
    elif name_class == "CROSS_COUNT_UNIT":
        semantic_tag = "CROSS_COUNT_UNIT"
        # Group by (name_unit, base_unit, product-family keyword) -- the
        # Topper Ultah family gets its own named group per the explicit
        # business question; everything else groups by unit pair.
        name_unit = new_name_evidence["unit"]
        other_unit = name_unit if base_unit == "PCS" else base_unit if name_unit == "PCS" else None
        if other_unit == "SET" and "topper" in (item_name or "").lower():
            group_id = "TOPPER_SET_VS_PCS"
        elif other_unit in ("SET", "PACK", "SHEET", "ROLL"):
            group_id = f"{other_unit}_VS_PCS"
        else:
            # Neither side is PCS, or an unnamed pair -- name it in a
            # stable (alphabetical) order so the same pair never gets two
            # different group ids depending on which SKU is seen first.
            a, b = sorted([name_unit, base_unit])
            group_id = f"{a}_VS_{b}"
        semantic_group_members.setdefault(group_id, []).append(sku)
    elif conflict and evidence_count == 1:
        # Same normalized unit family among the top candidates, but a
        # genuine magnitude disagreement remains after all reclassification.
        # Split further: legacy shows a TRIVIAL default (1) vs a real
        # name-derived count -> that's a recurring, groupable pattern.
        # Otherwise (both non-trivial) -> a specific unique case.
        legacy_isi = legacy_evidence.get("legacy_isi_dasar") if legacy_evidence else None
        legacy_unit_matches_name = (
            new_name_evidence is not None
            and legacy_evidence is not None
            and legacy_evidence.get("legacy_isi_dasar_normalized_unit") == new_name_evidence.get("normalized_unit")
        )
        if legacy_unit_matches_name and legacy_isi is not None and abs(float(legacy_isi) - 1.0) < 1e-9 and new_name_evidence and new_name_evidence["normalized_qty"] not in (None,) and new_name_evidence["normalized_qty"] > 1:
            semantic_tag = "SAME_UNIT_LEGACY_DEFAULT_VS_NAME_COUNT"
            semantic_group_members.setdefault(semantic_tag, []).append(sku)
        else:
            semantic_tag = "SAME_UNIT_DIFFERENT_FACTOR"
            unique_case_skus.append(sku)
    elif parsed is not None and parsed["kind"] == "composite" and not conflict and evidence_count >= 2:
        semantic_tag = "COMPOSITE_PACKAGE_AGREEMENT"
        resolved_count_by_reason[semantic_tag] = resolved_count_by_reason.get(semantic_tag, 0) + 1

    # ---- Write results back onto the record ----
    r["product_name_evidence"] = new_name_evidence
    r["name_semantic_class"] = name_class
    r["semantic_tag"] = semantic_tag
    r["content_info"] = content_info
    r["evidence_count"] = evidence_count

    issues = [c for c in r["issue_code"].split(",") if c and c not in ("CONVERSION_CONFLICT", "NAME_DERIVED_CANDIDATE", "PACKAGE_STRUCTURE_UNCLEAR")]
    if new_name_evidence is not None:
        issues.append("NAME_DERIVED_CANDIDATE")
    if parsed is not None and parsed["kind"] == "ambiguous_multi":
        issues.append("PACKAGE_STRUCTURE_UNCLEAR")
    if semantic_tag:
        issues.append(semantic_tag)
    if conflict:
        issues.append("CONVERSION_CONFLICT")
    r["issue_code"] = ",".join(dict.fromkeys(issues))

    if r.get("review_status") != "BLOCKED":
        if evidence_count >= 3:
            r["confidence"] = "HIGH"
        elif evidence_count == 2:
            r["confidence"] = "MEDIUM"
        elif evidence_count == 1:
            r["confidence"] = "LOW"
        else:
            r["confidence"] = None
            if not issues:
                r["issue_code"] = "NO_EVIDENCE_FOUND"

print(f"Re-derived name evidence + confidence for {len(results)} SKU")

with open(OUT_JSON_PATH, "w", encoding="utf-8") as f:
    json.dump(results, f, ensure_ascii=False, indent=2, default=str)
print(f"Wrote {OUT_JSON_PATH}")

# ---------------------------------------------------------------------
# warehouse_unit_comparison_v4.csv (same shape as v3)
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
        "Pattern": pattern, "Semantic Tag": r.get("semantic_tag") or "",
        "Conversion Source": r.get("conversion_source") or "DETECTOR",
        "Evidence": r["issue_code"], "Status": r["review_status"],
    })

import csv
with open(OUT_CSV_PATH, "w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=list(comparison_rows[0].keys()))
    writer.writeheader()
    writer.writerows(comparison_rows)
print(f"Wrote {OUT_CSV_PATH} ({len(comparison_rows)} rows)")

# ---------------------------------------------------------------------
# Admin decision groups + unique cases
# ---------------------------------------------------------------------
GROUP_QUESTIONS = {
    "TOPPER_SET_VS_PCS": {
        "question": "Untuk produk Topper Ultah @12 set, apakah satu kemasan berisi 12 SET dan dalam sistem lama 1 SET memang dicatat sebagai 1 PCS?",
        "choices": ["A: 1 SET = 1 PCS (pakai konversi ini untuk seluruh family)", "B: SET dan PCS berbeda (butuh faktor konversi lain)", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    },
    "SET_VS_PCS": {
        "question": "Untuk produk berikut, apakah SET dan PCS memang unit yang sama (1 SET = 1 PCS) pada sistem ini?",
        "choices": ["A: 1 SET = 1 PCS", "B: SET dan PCS berbeda", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    },
    "PACK_VS_PCS": {
        "question": "Untuk produk berikut, apakah jumlah PACK pada nama produk adalah jumlah PCS riil per unit inventory, atau hanya istilah kemasan yang tidak memengaruhi hitungan stok (stok tetap dihitung per PCS)?",
        "choices": ["A: 1 PACK = jumlah PCS sesuai nama (pakai sebagai faktor konversi)", "B: PACK hanya istilah kemasan; stok tetap per PCS (tidak ada konversi)", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    },
    "SHEET_VS_PCS": {
        "question": "Untuk produk kertas berikut, apakah 1 SHEET memang sama dengan 1 PCS pada sistem ini?",
        "choices": ["A: 1 SHEET = 1 PCS", "B: SHEET dan PCS berbeda", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    },
    "ROLL_VS_PCS": {
        "question": "Untuk produk berikut, apakah 1 ROLL memang sama dengan 1 PCS pada sistem ini?",
        "choices": ["A: 1 ROLL = 1 PCS", "B: ROLL dan PCS berbeda", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    },
    "SAME_UNIT_LEGACY_DEFAULT_VS_NAME_COUNT": {
        "question": "Untuk produk PCS berikut, sistem lama mencatat faktor kemasan = 1 (tidak ada konversi), tetapi nama produk menyiratkan jumlah isi per kemasan yang berbeda. Apakah kami boleh memakai jumlah dari nama produk sebagai faktor konversi resmi?",
        "choices": ["A: Ya, pakai jumlah dari nama produk", "B: Tidak, faktor lama (=1) yang benar", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    },
}


def build_group_row(group_id, skus):
    rows = [r for r in results if r["sku"] in skus]
    rep = rows[0]
    q = GROUP_QUESTIONS.get(group_id, {
        "question": f"Untuk produk berikut, bagaimana hubungan unit yang tepat ({group_id})?",
        "choices": ["A: Unit sama (gunakan sebagai konversi)", "B: Unit berbeda (butuh faktor lain)", "C: Data lama salah; gunakan unit lain", "D: Perlu cek fisik"],
    })
    return {
        "group_id": group_id,
        "business_question": q["question"],
        "affected_sku_count": len(rows),
        "representative_sku": rep["sku"],
        "representative_item": rep["item_name"],
        "current_global_base": rep.get("global_base_unit_candidate"),
        "name_evidence": (rep.get("product_name_evidence") or {}).get("raw"),
        "legacy_evidence": f"{(rep.get('legacy_evidence') or {}).get('legacy_isi_dasar')} {(rep.get('legacy_evidence') or {}).get('legacy_satuan_dasar_hpp')}",
        "transaction_evidence": (rep.get("scm_transaction_evidence") or {}).get("unit"),
        "possible_interpretation": "; ".join(sorted({f"{x['sku']}: name={((x.get('product_name_evidence') or {}).get('raw'))} legacy={(x.get('legacy_evidence') or {}).get('legacy_isi_dasar')}{(x.get('legacy_evidence') or {}).get('legacy_satuan_dasar_hpp')}" for x in rows[:5]})),
        "recommended_choices": " | ".join(q["choices"]),
        "admin_decision": "",
        "admin_notes": "",
        "affected_skus": [x["sku"] for x in rows],
        "affected_items": [x["item_name"] for x in rows],
    }


decision_groups = [build_group_row(gid, skus) for gid, skus in semantic_group_members.items()]
decision_groups.sort(key=lambda g: -g["affected_sku_count"])

unique_cases = []
for sku in unique_case_skus:
    r = next(x for x in results if x["sku"] == sku)
    ne = r.get("product_name_evidence")
    le = r.get("legacy_evidence")
    unique_cases.append({
        "sku": r["sku"], "item_name": r["item_name"],
        "current_base": r.get("global_base_unit_candidate"),
        "legacy": f"{le.get('legacy_isi_dasar') if le else ''} {le.get('legacy_satuan_dasar_hpp') if le else ''}",
        "name_evidence": ne.get("raw") if ne else (r.get("issue_detail") or "")[:120],
        "transaction_evidence": (r.get("scm_transaction_evidence") or {}).get("unit"),
        "exact_question": f"SKU {r['sku']} ({r['item_name']}): evidence disagrees ({r.get('semantic_tag')}) -- please confirm the correct base/packaging conversion.",
        "semantic_tag": r.get("semantic_tag"),
    })

movement_review_rows = [r["sku"] for r in results if "MOVEMENT_RECONCILIATION_REVIEW" in r["issue_code"]]

with open(OUT_GROUPS_PATH, "w", encoding="utf-8") as f:
    json.dump({
        "decision_groups": decision_groups,
        "unique_cases": unique_cases,
        "movement_review_skus": movement_review_rows,
        "resolved_count_by_reason": resolved_count_by_reason,
    }, f, ensure_ascii=False, indent=2, default=str)
print(f"Wrote {OUT_GROUPS_PATH}")
print(f"Decision groups: {len(decision_groups)} covering {sum(g['affected_sku_count'] for g in decision_groups)} SKU")
print(f"Unique cases: {len(unique_cases)}")
print(f"Resolved automatically: {resolved_count_by_reason}")
print(f"Movement review (kept separate): {len(movement_review_rows)}")

remaining_conflicts = [r["sku"] for r in results if "CONVERSION_CONFLICT" in r["issue_code"]]
print(f"CONVERSION_CONFLICT remaining after semantic grouping: {len(remaining_conflicts)} -> {remaining_conflicts}")
