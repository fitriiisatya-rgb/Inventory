# Unit Conversion Reconstruction — Summary Report v2 (Phase G-DATA 1B.1)

This is a **delta report on top of** `unit_conversion_summary.md` (the
full real-catalog run, 1,165 SKU). It applies **3 explicit business-owner
confirmations** received directly from the owner/admin, layered onto the
detector-only output by a separate, auditable step
(`migration/scripts/apply_business_confirmed_overrides.py` +
`business_confirmed_overrides.json`) — the detector itself
(`reconstruct_unit_conversions_real.py`) was **not modified**, so its
output stays reproducible from raw data alone.

**Still no production posting.** No opening stock, no historical replay,
no FIFO batches, no cutover date. Staging/analysis only.

---

## Overrides applied

| SKU | Item | Confirmed conversion | Type |
|---|---|---|---|
| 999208 | PREMIX MENTEGA BASIC | 1 PCS = 15 KG | Packaging conversion |
| 999209 | PREMIX MENTEGA ROTI | 1 PCS = 15 KG | Packaging conversion |
| 140539 | MUTIARA PUTIH 8 MM | Rp305.000/KG = Rp305/GR (same price, two bases) | Unit-price-basis normalization (NOT a packaging conversion) |

For 999208/999209: `approved_base_unit=KG`, `approved_purchase_unit=PCS`,
`approved_purchase_conversion=15.0`, `review_status=APPROVED`,
`confidence=BUSINESS_CONFIRMED`, `conversion_source=BUSINESS_CONFIRMED`.
The ~15× price ratio found earlier by the detector is now explained,
not a conflict — `PRICE_RATIO_SUPPORTS_CONVERSION` and
`CONVERSION_CONFLICT` are both cleared for these two SKUs.

For 140539: `approved_base_unit=KG` (unchanged — this was never a
packaging question), `unit_cost_base_kg=305000`,
`unit_cost_base_gr_equivalent=305`, `review_status=APPROVED`,
`price_source=BUSINESS_CONFIRMED`. **No `purchase_conversion` factor of
1000 was derived** — per the owner's explicit instruction, this is a
KG↔GR price-basis fix, not a packaging layer. `UNIT_LABEL_MISMATCH` is
cleared.

Legacy evidence for all three SKUs is **kept, not deleted** (still
visible in LEGACY CONVERSIONS and the Legacy Evidence column) — it is
superseded by the business confirmation where it disagreed (both
999208/999209's legacy `Isi Dasar=1.0` and 140539's legacy `Isi
Dasar=1.0` are now understood as the legacy system's own
"no-conversion" assumption, which the owner has now corrected/clarified).

**Scope discipline confirmed:** this override applies to exactly these 3
SKU codes and no others. **140541** ("CREAMFILL CLASSIC BANANA") shows a
structurally similar ~136× price-ratio-vs-legacy conflict but was **not**
part of this confirmation — it remains an open `CONVERSION_CONFLICT`,
unresolved, per the explicit instruction not to generalize the 15×/basis
findings to any other SKU.

## Updated distribution (v2 vs v1)

| Metric | v1 (detector only) | v2 (with overrides) |
|---|---|---|
| Confidence HIGH | 0 | 0 |
| Confidence MEDIUM | 133 | 133 |
| Confidence LOW | 1,008 | 1,005 |
| Confidence NONE | 24 | 24 |
| Confidence BUSINESS_CONFIRMED | — | 3 |
| Review status APPROVED | 0 | 3 |
| Review status BLOCKED | 5 | 5 (unchanged — identity conflicts unrelated to this confirmation) |
| `PRICE_RATIO_SUPPORTS_CONVERSION` | 3 | 1 (140541 only) |
| `UNIT_LABEL_MISMATCH` | 1 | 0 |
| **`CONVERSION_CONFLICT`** | **38** | **36** |

## CONVERSION_CONFLICT remaining after normalization + overrides: **36**

Full list (SKU codes): 100201, 100309, 100310, 100503, 110301, 110414,
110415, 110416, 111507, 111508, 140508, **140541**, 140710, 140808,
150108, 150110, 150121, 200132, 300215, 300216, 333201, 333202, 333203,
333204, 333404, 333502, 400104, 400107, 400602, 444803, 555911, 600107,
666201, 700113, 777211, 900225.

All 36 are still the same root cause as before: a name-heuristic-derived
quantity (or, for 140541, a price-ratio-derived factor) disagrees with
the legacy `Isi Dasar` value for that SKU — i.e. today's product naming
or pricing implies a packaging factor that the legacy system's own data
says doesn't exist (factor=1). None of these has a business confirmation
yet; none should be auto-resolved by analogy to 999208/999209/140539.

## Files regenerated

- `migration/workspace/normalized/unit_conversion_review_v2.xlsx` — 11
  sheets: INSTRUCTIONS, SUMMARY, **BUSINESS CONFIRMED** (new, 3 rows),
  CONVERSION REVIEW (1,165, +Conversion Source/Price Source/Unit Cost Base
  columns), CONFLICTS (36, down from 38), PRICE RATIO CHECK (4),
  NAME HEURISTICS (374), LEGACY CONVERSIONS (1,144), BLOCKED SKU (5),
  TRANSACTION EVIDENCE (1,007), WAREHOUSE UNIT COMPARISON (1,002).
- `migration/workspace/normalized/warehouse_unit_comparison_v2.csv` —
  1,002 rows; 999208/999209 now show `Pattern =
  SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CONFIRMED` (factor 15, confirmed)
  instead of the generic candidate pattern; 465 other rows remain
  `..._CANDIDATE` (still unconfirmed).
- `migration/workspace/normalized/unit_conversion_candidates_real_v2.json`
  — full record set, each overridden record also carries
  `prior_detector_issue_code` / `prior_detector_confidence` /
  `prior_detector_review_status` so the pre-override detector state is
  never lost.
- `migration/workspace/reports/unit_conversion_summary_v2.md` — this file.

## Still pending (unchanged from v1, per §16 of the original report)

- 5 identity-blocked SKUs (111712, 111905, 111906, 111920, 800405)
- 140541's own ~136× conflict (explicitly not covered by this confirmation)
- 7 Global Master candidates needing a category
- 2 duplicate-source SKUs (900240, 900251) needing a canonical name
- The remaining 36 CONVERSION_CONFLICT rows
- 8 MOVEMENT_RECONCILIATION_REVIEW rows
- 465 SCM_LARGE_UNIT_TO_TRANSIT_BASE_UNIT_CANDIDATE rows still awaiting
  their own business confirmation
- 1,005 LOW-confidence rows (single-source legacy evidence only)

## Regression

No backend/PHP code was touched by this override step (Python analysis
tooling only) — the 82/82 MySQL regression result from the base run
still applies unchanged.

## Confirmation

**NO production opening, NO historical replay, NO cutover** was
performed. This remains staging/analysis only.

---

**STOP** (unchanged): do not proceed to opening import or cutover.
Everything outside the 3 now-approved SKUs still needs human review.
