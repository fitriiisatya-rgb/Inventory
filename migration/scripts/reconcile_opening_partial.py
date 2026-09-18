#!/usr/bin/env python3
"""
PHASE G-DATA 3 (partial) — HISTORICAL opening + IN - OUT reconciliation for
SCM/Gudang Besar and Cibadak, validated/normalized against Global Master v6
(v5 + the "all YUPI items use GR" business-confirmed base-unit override).
Karang Tengah has no IN/OUT file yet, so it is validated/normalized but NOT
reconstructed -- kept as WAITING_MOVEMENT_DATA.

This is validation/reconciliation ONLY:
- NO production posting, NO FIFO batches, NO cutover.
- The 3 stok_awal files are treated as authoritative HISTORICAL opening
  (1 Sept 2026), not a production opening import.
- Business-confirmed conversions (999208, 999209, 140539, 140550, 140551)
  are used to NORMALIZE quantities/costs to the Global Base Unit -- never
  modified.
- Known theoretical-negative SKUs are flagged, never auto-corrected.

Inputs:
  migration/workspace/raw/final_opening_round1_2026-09-17/
    stok_awal_september_{gudang_besar,cibadak,karangtengah}.xlsx
    In Out SCM 01-15 Sept 2026.xlsx
    In Out Cibadak 01-15 Sept 2026.xlsx
  migration/workspace/normalized/unit_conversion_candidates_real_v6.json (Global Master v6)

Output:
  migration/workspace/reports/reconciliation_01_15_sep_partial.xlsx
"""
import json

import pandas as pd

BASE = "/home/user/Inventory/migration/workspace/raw/final_opening_round1_2026-09-17"
V5_PATH = "/home/user/Inventory/migration/workspace/normalized/unit_conversion_candidates_real_v6.json"
OUT_PATH = "/home/user/Inventory/migration/workspace/reports/reconciliation_01_15_sep_partial.xlsx"

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
    "pail": "PAIL", "jar": "JAR", "toples": "JAR", "set": "SET",
    "sheet": "SHEET", "lembar": "SHEET", "meter": "METER", "mtr": "METER", "m": "METER",
    "batang": "BATANG",
}


def normalize_unit(raw):
    if raw is None or (isinstance(raw, float) and pd.isna(raw)):
        return None
    key = str(raw).strip().lower()
    if key == "":
        return None
    return UNIT_ALIASES.get(key, key.upper())


def normalize_code(x):
    if x is None or (pd.api.types.is_scalar(x) and pd.isna(x)):
        return None
    if isinstance(x, float):
        return str(int(x)) if x.is_integer() else str(x)
    s = str(x).strip()
    if s.endswith(".0"):
        try:
            return str(int(float(s)))
        except ValueError:
            pass
    return s if s != "" else None


# ---------------------------------------------------------------------
# Global Master v6
# ---------------------------------------------------------------------
v5 = json.load(open(V5_PATH, encoding="utf-8"))
master = {r["sku"]: r for r in v5}
print(f"Global Master v6: {len(master)} SKU")

# Known, verified business-confirmed conversions (do NOT modify) -- used
# only for NORMALIZATION here, exactly as approved in Phase G-DATA 1B.1/1B.3.
BUSINESS_CONFIRMED_PURCHASE = {  # sku -> (purchase_unit, factor_to_base)
    "999208": ("PCS", 15.0),
    "999209": ("PCS", 15.0),
}
# Canonical PHYSICAL-quantity conversions (Phase G-DATA 1B.1) -- these need
# NO business confirmation, unlike a PCS<->weight packaging factor: 1 KG is
# always 1000 GR and 1 LTR is always 1000 ML, universally, so this is a
# general normalization rule, not a per-SKU guess.
CANONICAL_DIMENSION = {
    "KG": ("WEIGHT", 1000.0, "GR"), "GR": ("WEIGHT", 1.0, "GR"),
    "LTR": ("VOLUME", 1000.0, "ML"), "ML": ("VOLUME", 1.0, "ML"),
}


def canonical_convert(qty, from_unit, to_unit):
    """Converts qty between two units in the SAME physical dimension (both
    WEIGHT or both VOLUME). Returns None if not both known/same-dimension --
    never guesses across dimensions (e.g. GR -> PCS)."""
    if from_unit not in CANONICAL_DIMENSION or to_unit not in CANONICAL_DIMENSION:
        return None
    dim_from, factor_from, _ = CANONICAL_DIMENSION[from_unit]
    dim_to, factor_to, _ = CANONICAL_DIMENSION[to_unit]
    if dim_from != dim_to:
        return None
    return qty * factor_from / factor_to


# 140539: NOT a packaging conversion -- a unit-price-BASIS mismatch (business
# confirmed: Rp305,000/KG = Rp305/GR, same real price). Canonical cost is
# fixed; any opening row for this SKU is normalized by VALUE PRESERVATION
# (qty_base = raw_qty*raw_price / canonical_cost_base) rather than assuming
# either the raw qty or raw price unit label is correct on its own.
BUSINESS_CONFIRMED_PRICE_BASIS = {"140539": 305000.0}  # canonical Rp per KG (approved base unit)


def load_stok_awal(path):
    df = pd.read_excel(path, sheet_name="Stok Awal")
    df["Kode Barang"] = df["Kode Barang"].apply(normalize_code)
    return df


def load_inout_scm(path):
    df = pd.read_excel(path, sheet_name="Detail Per Barang", header=2)
    df["Kode"] = df["Kode"].apply(normalize_code)
    return df


def load_inout_cb(path):
    df = pd.read_excel(path, sheet_name="Cibadak", header=2)
    df["Kode Barang"] = df["Kode Barang"].apply(normalize_code)
    return df


gb_open = load_stok_awal(f"{BASE}/stok_awal_september_gudang_besar.xlsx")
cb_open = load_stok_awal(f"{BASE}/stok_awal_september_cibadak.xlsx")
kt_open = load_stok_awal(f"{BASE}/stok_awal_september_karangtengah.xlsx")
scm_inout = load_inout_scm(f"{BASE}/In Out SCM 01-15 Sept 2026.xlsx")
cb_inout = load_inout_cb(f"{BASE}/In Out Cibadak 01-15 Sept 2026.xlsx")

print(f"GB opening: {len(gb_open)} rows | CB opening: {len(cb_open)} rows | KT opening: {len(kt_open)} rows")
print(f"SCM in/out: {len(scm_inout)} rows | CB in/out: {len(cb_inout)} rows")

# Known, previously-verified theoretical-negative SKUs (per user instruction) --
# flagged explicitly below, never auto-corrected regardless of what the
# recomputation finds.
KNOWN_NEGATIVE = {
    ("CIBADAK", "555410"): -250.0,
    ("CIBADAK", "800401"): -162.0,
    ("CIBADAK", "400201"): -466.5,
    ("SCM", "100304"): -0.5,
    ("SCM", "777419"): -0.5,
}


def normalize_opening_row(sku, raw_unit_str, raw_qty, raw_price):
    """Returns (qty_base, cost_base, base_unit, normalization_note, needs_review)."""
    m = master.get(sku)
    if m is None:
        return raw_qty, raw_price, normalize_unit(raw_unit_str), "UNKNOWN_SKU: not in Global Master v6 -- values NOT normalized", True

    base_unit = m.get("approved_base_unit") or m.get("global_base_unit_candidate")
    raw_unit = normalize_unit(raw_unit_str)

    if sku in BUSINESS_CONFIRMED_PRICE_BASIS:
        canonical_cost = BUSINESS_CONFIRMED_PRICE_BASIS[sku]
        if raw_qty in (None, 0) or pd.isna(raw_qty):
            return 0.0, canonical_cost, base_unit, "BUSINESS_CONFIRMED price-basis SKU, zero qty", False
        total_value = float(raw_qty) * float(raw_price)
        qty_base = round(total_value / canonical_cost, 6)
        return qty_base, canonical_cost, base_unit, (
            f"BUSINESS_CONFIRMED price-basis normalization: raw {raw_qty}{raw_unit}@Rp{raw_price} "
            f"-> value-preserved qty_base={qty_base}{base_unit} @ canonical Rp{canonical_cost}/{base_unit}"
        ), False

    if sku in BUSINESS_CONFIRMED_PURCHASE:
        purchase_unit, factor = BUSINESS_CONFIRMED_PURCHASE[sku]
        if raw_unit == purchase_unit:
            qty_base = round(float(raw_qty) * factor, 6)
            cost_base = round(float(raw_price) / factor, 4)
            return qty_base, cost_base, base_unit, (
                f"BUSINESS_CONFIRMED conversion applied: {raw_qty} {purchase_unit} x {factor} = {qty_base} {base_unit}"
            ), False
        if raw_unit == base_unit:
            return float(raw_qty), float(raw_price), base_unit, "already in base unit, no conversion needed", False
        return raw_qty, raw_price, base_unit, f"unit '{raw_unit}' matches neither base ({base_unit}) nor the approved purchase unit ({purchase_unit}) -- NOT normalized", True

    if raw_unit == base_unit or base_unit is None:
        return float(raw_qty), float(raw_price), base_unit or raw_unit, "already in base unit", False

    converted_qty = canonical_convert(float(raw_qty), raw_unit, base_unit)
    if converted_qty is not None:
        # Same physical quantity, different (always-valid) unit label -- cost
        # per base unit scales by the inverse of the quantity factor so total
        # value is preserved exactly.
        converted_cost = canonical_convert(float(raw_price), base_unit, raw_unit)
        return round(converted_qty, 6), round(converted_cost, 4), base_unit, (
            f"canonical physical-unit conversion (no business confirmation needed): "
            f"{raw_qty} {raw_unit} -> {round(converted_qty, 6)} {base_unit}"
        ), False

    return raw_qty, raw_price, base_unit, f"unit mismatch: raw='{raw_unit}' vs Global Base='{base_unit}' and no approved conversion or valid physical-unit conversion exists -- NOT normalized (do not guess)", True


def process_opening(df, warehouse_label):
    rows = []
    for _, r in df.iterrows():
        sku = r["Kode Barang"]
        if sku is None:
            continue
        raw_qty = r["Jumlah Stok Awal"]
        raw_price = r["Harga per Satuan (Rp)"]
        raw_unit = r["Satuan Dasar (referensi)"]
        qty_base, cost_base, base_unit, note, needs_review = normalize_opening_row(
            sku, raw_unit, raw_qty if pd.notna(raw_qty) else 0.0, raw_price if pd.notna(raw_price) else 0.0
        )
        m = master.get(sku)
        rows.append({
            "sku": sku,
            "item_name": (m or {}).get("item_name") or r.get("Nama Barang (referensi)"),
            "warehouse": warehouse_label,
            "opening_qty_base": qty_base,
            "unit_cost_base": cost_base,
            "base_unit": base_unit,
            "raw_qty": raw_qty, "raw_unit": normalize_unit(raw_unit), "raw_price": raw_price,
            "normalization_note": note,
            "needs_review": needs_review,
            "in_master": m is not None,
            "category": (m or {}).get("category_candidate"),
        })
    return rows


gb_rows = process_opening(gb_open, "SCM")
cb_rows = process_opening(cb_open, "CIBADAK")
kt_rows = process_opening(kt_open, "KARANG_TENGAH")

print(f"Rows needing review (unknown SKU or unresolved unit mismatch): "
      f"SCM={sum(r['needs_review'] for r in gb_rows)} CIBADAK={sum(r['needs_review'] for r in cb_rows)} "
      f"KT={sum(r['needs_review'] for r in kt_rows)}")

# ---------------------------------------------------------------------
# IN/OUT normalization (SCM + Cibadak only)
# ---------------------------------------------------------------------
def normalize_inout(sku, raw_unit_str, qty_in, qty_out):
    m = master.get(sku)
    base_unit = (m or {}).get("approved_base_unit") or (m or {}).get("global_base_unit_candidate")
    raw_unit = normalize_unit(raw_unit_str)
    if sku in BUSINESS_CONFIRMED_PURCHASE and raw_unit == BUSINESS_CONFIRMED_PURCHASE[sku][0]:
        factor = BUSINESS_CONFIRMED_PURCHASE[sku][1]
        return round(qty_in * factor, 6), round(qty_out * factor, 6), base_unit, True
    if raw_unit != base_unit and base_unit is not None:
        conv_in = canonical_convert(qty_in, raw_unit, base_unit)
        conv_out = canonical_convert(qty_out, raw_unit, base_unit)
        if conv_in is not None:
            return round(conv_in, 6), round(conv_out, 6), base_unit, True
    # SCM/Cibadak transaction units were already confirmed (Phase G-DATA 1B)
    # to match the base unit for the whole real catalog -- pass through.
    return qty_in, qty_out, base_unit or raw_unit, (raw_unit == base_unit or base_unit is None)


scm_inout_by_sku = {}
for _, r in scm_inout.iterrows():
    sku = r["Kode"]
    if sku is None:
        continue
    qin = float(r["Qty Penerimaan"]) if pd.notna(r["Qty Penerimaan"]) else 0.0
    qout = float(r["Qty Total Pengambilan"]) if pd.notna(r["Qty Total Pengambilan"]) else 0.0
    qin_b, qout_b, unit, ok = normalize_inout(sku, r["Satuan"], qin, qout)
    scm_inout_by_sku[sku] = {"in": qin_b, "out": qout_b, "unit_ok": ok}

cb_inout_by_sku = {}
for _, r in cb_inout.iterrows():
    sku = r["Kode Barang"]
    if sku is None:
        continue
    qin = float(r["Total Penerimaan"]) if pd.notna(r["Total Penerimaan"]) else 0.0
    qout = float(r["Total Pengeluaran"]) if pd.notna(r["Total Pengeluaran"]) else 0.0
    qin_b, qout_b, unit, ok = normalize_inout(sku, r["Satuan"], qin, qout)
    cb_inout_by_sku[sku] = {"in": qin_b, "out": qout_b, "unit_ok": ok}


def reconstruct(rows, inout_by_sku, warehouse_label):
    out = []
    for r in rows:
        io = inout_by_sku.get(r["sku"], {"in": 0.0, "out": 0.0, "unit_ok": True})
        ending = round(r["opening_qty_base"] + io["in"] - io["out"], 6)
        value = round(ending * r["unit_cost_base"], 4)
        key = (warehouse_label, r["sku"])
        if key in KNOWN_NEGATIVE:
            status = "MOVEMENT_RECONCILIATION_REVIEW"
        elif r["needs_review"]:
            status = "UNIT_OR_IDENTITY_REVIEW_REQUIRED"
        elif ending < 0:
            status = "THEORETICAL_NEGATIVE_ENDING"
        elif ending == 0:
            status = "ZERO"
        else:
            status = "OK"
        out.append({
            "SKU": r["sku"], "Item Name": r["item_name"], "Base Unit": r["base_unit"],
            "Opening 1 Sep": r["opening_qty_base"], "IN 1-15": io["in"], "OUT 1-15": io["out"],
            "Calculated Ending 15 Sep": ending, "Unit Cost": r["unit_cost_base"], "Calculated Value": value,
            "Status": status, "Normalization Note": r["normalization_note"],
            "In Global Master v6": r["in_master"], "Category": r["category"],
        })
    return out


scm_result = reconstruct(gb_rows, scm_inout_by_sku, "SCM")
cb_result = reconstruct(cb_rows, cb_inout_by_sku, "CIBADAK")

# ---------------------------------------------------------------------
# Verify the 5 known negative-theoretical SKUs match the user's stated values
# ---------------------------------------------------------------------
print("\n--- Verifying 5 known theoretical-negative SKUs ---")
all_results = {("SCM", r["SKU"]): r for r in scm_result}
all_results.update({("CIBADAK", r["SKU"]): r for r in cb_result})
for (wh, sku), expected in KNOWN_NEGATIVE.items():
    row = all_results.get((wh, sku))
    if row is None:
        print(f"  MISSING {wh} {sku} (expected {expected})")
        continue
    match = abs(row["Calculated Ending 15 Sep"] - expected) < 0.01
    print(f"  {wh} {sku}: calculated={row['Calculated Ending 15 Sep']} expected={expected} -> {'MATCH' if match else 'MISMATCH'}")

# ---------------------------------------------------------------------
# KARANG_TENGAH_PENDING -- opening only, validated/normalized, NO movement
# ---------------------------------------------------------------------
kt_result = []
for r in kt_rows:
    kt_result.append({
        "SKU": r["sku"], "Item Name": r["item_name"], "Base Unit": r["base_unit"],
        "Opening 1 Sep": r["opening_qty_base"], "IN 1-15": None, "OUT 1-15": None,
        "Calculated Ending 15 Sep": None, "Unit Cost": r["unit_cost_base"],
        "Calculated Value": round(r["opening_qty_base"] * r["unit_cost_base"], 4) if r["opening_qty_base"] is not None else None,
        "Status": "WAITING_MOVEMENT_DATA",
        "Normalization Note": r["normalization_note"], "In Global Master v6": r["in_master"], "Category": r["category"],
    })

# ---------------------------------------------------------------------
# MOVEMENT_REVIEW sheet -- the 5 known flagged SKUs, full detail
# ---------------------------------------------------------------------
movement_review_rows = []
for (wh, sku), expected in KNOWN_NEGATIVE.items():
    row = all_results.get((wh, sku))
    if row is None:
        continue
    movement_review_rows.append({
        "Warehouse": wh, "SKU": sku, "Item Name": row["Item Name"], "Base Unit": row["Base Unit"],
        "Opening 1 Sep": row["Opening 1 Sep"], "IN 1-15": row["IN 1-15"], "OUT 1-15": row["OUT 1-15"],
        "Calculated Ending 15 Sep": row["Calculated Ending 15 Sep"],
        "Expected (per instruction)": expected,
        "Matches Expected": abs(row["Calculated Ending 15 Sep"] - expected) < 0.01,
        "Status": "FLAGGED — DO NOT AUTO-FIX",
        "Notes": "Historical evidence only. Final verified opening (once supplied) is authoritative; this arithmetic is never adjusted to match it.",
    })

# ---------------------------------------------------------------------
# SUMMARY sheet
# ---------------------------------------------------------------------
def count_status(rows, status):
    return sum(1 for r in rows if r["Status"] == status)


summary_rows = [
    ("Reconciliation window", "1 Sept 2026 (opening) through 15 Sept 2026 (IN/OUT)"),
    ("Warehouses reconstructed (Opening + IN - OUT)", "SCM, CIBADAK"),
    ("Warehouses pending (opening validated only, no movement data)", "KARANG_TENGAH"),
    ("", ""),
    ("SCM: total SKU", len(scm_result)),
    ("SCM: OK", count_status(scm_result, "OK")),
    ("SCM: ZERO", count_status(scm_result, "ZERO")),
    ("SCM: THEORETICAL_NEGATIVE_ENDING (unflagged)", count_status(scm_result, "THEORETICAL_NEGATIVE_ENDING")),
    ("SCM: MOVEMENT_RECONCILIATION_REVIEW (known-flagged)", count_status(scm_result, "MOVEMENT_RECONCILIATION_REVIEW")),
    ("SCM: UNIT_OR_IDENTITY_REVIEW_REQUIRED", count_status(scm_result, "UNIT_OR_IDENTITY_REVIEW_REQUIRED")),
    ("", ""),
    ("CIBADAK: total SKU", len(cb_result)),
    ("CIBADAK: OK", count_status(cb_result, "OK")),
    ("CIBADAK: ZERO", count_status(cb_result, "ZERO")),
    ("CIBADAK: THEORETICAL_NEGATIVE_ENDING (unflagged)", count_status(cb_result, "THEORETICAL_NEGATIVE_ENDING")),
    ("CIBADAK: MOVEMENT_RECONCILIATION_REVIEW (known-flagged)", count_status(cb_result, "MOVEMENT_RECONCILIATION_REVIEW")),
    ("CIBADAK: UNIT_OR_IDENTITY_REVIEW_REQUIRED", count_status(cb_result, "UNIT_OR_IDENTITY_REVIEW_REQUIRED")),
    ("", ""),
    ("KARANG_TENGAH: total SKU (opening only, WAITING_MOVEMENT_DATA)", len(kt_result)),
    ("", ""),
    ("Business-confirmed conversions used for normalization (unchanged)", "999208 (1 PCS=15KG), 999209 (1 PCS=15KG), 140539 (price-basis KG/GR)"),
    ("Known theoretical-negative SKUs flagged (never auto-fixed)", len(movement_review_rows)),
    ("All 5 match the instruction's stated values?", all(m["Matches Expected"] for m in movement_review_rows)),
    ("", ""),
    ("PRODUCTION POSTING THIS ROUND", "NONE — historical validation/reconciliation only. No FIFO batches, no cutover."),
    ("Next step", "Awaiting 'In Out Karang Tengah 01-15 Sept 2026' file to reconstruct KT and produce a company-wide reconciliation."),
]

# ---------------------------------------------------------------------
# Write workbook
# ---------------------------------------------------------------------
df_summary = pd.DataFrame(summary_rows, columns=["Metric", "Value"])
df_scm = pd.DataFrame(scm_result)
df_cb = pd.DataFrame(cb_result)
df_kt = pd.DataFrame(kt_result)
df_movement = pd.DataFrame(movement_review_rows)

with pd.ExcelWriter(OUT_PATH, engine="openpyxl") as writer:
    df_summary.to_excel(writer, sheet_name="SUMMARY", index=False)
    df_scm.to_excel(writer, sheet_name="SCM", index=False)
    df_cb.to_excel(writer, sheet_name="CIBADAK", index=False)
    df_kt.to_excel(writer, sheet_name="KARANG_TENGAH_PENDING", index=False)
    df_movement.to_excel(writer, sheet_name="MOVEMENT_REVIEW", index=False)

    for sheet_name, frame in [
        ("SUMMARY", df_summary), ("SCM", df_scm), ("CIBADAK", df_cb),
        ("KARANG_TENGAH_PENDING", df_kt), ("MOVEMENT_REVIEW", df_movement),
    ]:
        ws = writer.sheets[sheet_name]
        for i, col in enumerate(frame.columns, start=1):
            if len(frame):
                max_len = frame[col].astype(str).str.len().clip(upper=60).max()
                max_len = 12 if pd.isna(max_len) else int(max_len)
            else:
                max_len = 12
            width = min(max(12, max_len + 2), 60)
            ws.column_dimensions[ws.cell(row=1, column=i).column_letter].width = width

print(f"\nWrote {OUT_PATH}")
print(f"Sheets: SUMMARY, SCM({len(df_scm)}), CIBADAK({len(df_cb)}), KARANG_TENGAH_PENDING({len(df_kt)}), MOVEMENT_REVIEW({len(df_movement)})")
