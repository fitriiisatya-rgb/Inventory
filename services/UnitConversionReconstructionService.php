<?php
declare(strict_types=1);

namespace App\Services;

/**
 * PHASE G-DATA 1B — unit conversion reconstruction. Every function here is
 * a DETECTOR that produces a candidate + evidence, never a corrector.
 * Nothing in this class writes a "final" value anywhere — the caller
 * (scripts/reconstruct_unit_conversions.php) persists results to the
 * review-only `unit_conversion_candidates` table, and a human approves
 * from there. See G11 in docs/PHASE_G10_ANOMALY_RULES.md: no silent fix,
 * ever — this class is the concrete implementation of that principle for
 * unit/price reconstruction specifically.
 */
final class UnitConversionReconstructionService
{
    /** Ratios within this fraction of an integer are considered "supports a candidate factor". */
    private const RATIO_TOLERANCE = 0.05;
    /** Ratios near these values, for a same-claimed-unit comparison, suggest a mislabeled unit rather than real packaging. */
    private const SCALE_CONSTANTS = [10.0, 100.0, 1000.0, 10000.0, 100000.0];

    /**
     * Extracts a quantity+unit heuristic from an item name, e.g.
     * "Tepung ABC @25Kg" -> ['raw' => '@25Kg', 'total_qty' => 25.0, 'unit' => 'KG', 'ambiguous_structure' => false]
     * "Chocolate @12x1Kg" -> ['raw' => '@12x1Kg', 'total_qty' => 12.0, 'unit' => 'KG', 'ambiguous_structure' => true]
     * (ambiguous_structure=true means "12 x 1KG" could be 12 packs of 1KG in
     * a box, or a single 12KG unit — this method reports the TOTAL only and
     * flags that the pack/box/carton structure itself is not determinable
     * from the name alone; never guesses which of Purchase/Middle unit that
     * structure maps to.)
     */
    public static function extractNameHeuristic(string $itemName): ?array
    {
        // "@12x1Kg" / "@12 x 1 Kg" style — count x unit-size.
        if (preg_match('/@\s*(\d+(?:[.,]\d+)?)\s*[xX]\s*(\d+(?:[.,]\d+)?)\s*([A-Za-z]+)/', $itemName, $m)) {
            $count = self::toFloat($m[1]);
            $size = self::toFloat($m[2]);
            return [
                'raw' => $m[0],
                'total_qty' => round($count * $size, 6),
                'unit' => strtoupper($m[3]),
                'ambiguous_structure' => true,
            ];
        }
        // "@25Kg" / "@25 Kg" style — single quantity+unit.
        if (preg_match('/@\s*(\d+(?:[.,]\d+)?)\s*([A-Za-z]+)/', $itemName, $m)) {
            return [
                'raw' => $m[0],
                'total_qty' => self::toFloat($m[1]),
                'unit' => strtoupper($m[2]),
                'ambiguous_structure' => false,
            ];
        }
        return null;
    }

    private static function toFloat(string $s): float
    {
        return (float) str_replace(',', '.', $s);
    }

    /**
     * Compares price-per-unit across two or more source records for the
     * SAME sku and looks for a ratio that supports a packaging conversion.
     * @param array $priceRecords each: ['source' => string, 'unit' => string, 'price_per_unit' => float]
     * @return array{0: ?array, 1: ?array} [price_ratio_evidence, unit_label_mismatch_evidence] — either may be null
     */
    public static function detectPriceRatio(array $priceRecords): array
    {
        $priceEvidence = null;
        $mismatchEvidence = null;

        for ($i = 0; $i < count($priceRecords); $i++) {
            for ($j = $i + 1; $j < count($priceRecords); $j++) {
                $a = $priceRecords[$i];
                $b = $priceRecords[$j];
                if ((float) $a['price_per_unit'] <= 0 || (float) $b['price_per_unit'] <= 0) {
                    continue;
                }
                $ratio = (float) $a['price_per_unit'] / (float) $b['price_per_unit'];
                if ($ratio < 1) {
                    continue; // only evaluate the >=1 direction; the pair is symmetric
                }

                $sameUnit = strtoupper(trim($a['unit'])) === strtoupper(trim($b['unit']));

                if ($sameUnit) {
                    foreach (self::SCALE_CONSTANTS as $scale) {
                        if (abs($ratio - $scale) / $scale <= self::RATIO_TOLERANCE) {
                            $mismatchEvidence = [
                                'ratio' => round($ratio, 2), 'suspected_scale' => $scale,
                                'price_a' => $a['price_per_unit'], 'unit_a' => $a['unit'], 'source_a' => $a['source'],
                                'price_b' => $b['price_per_unit'], 'unit_b' => $b['unit'], 'source_b' => $b['source'],
                            ];
                        }
                    }
                    continue;
                }

                $nearestInt = round($ratio);
                if ($nearestInt >= 2 && $nearestInt <= 200 && abs($ratio - $nearestInt) / $nearestInt <= self::RATIO_TOLERANCE) {
                    $priceEvidence = [
                        'ratio' => round($ratio, 4), 'candidate_factor' => (int) $nearestInt,
                        'price_a' => $a['price_per_unit'], 'unit_a' => $a['unit'], 'source_a' => $a['source'],
                        'price_b' => $b['price_per_unit'], 'unit_b' => $b['unit'], 'source_b' => $b['source'],
                    ];
                }
            }
        }

        return [$priceEvidence, $mismatchEvidence];
    }

    /**
     * Flags an identity conflict when the same SKU code carries meaningfully
     * different item names across sources — normalization only strips case/
     * whitespace/punctuation, never treats a genuinely different product
     * name as "close enough".
     * @param array $namesBySource ['SOURCE_LABEL' => 'item name', ...]
     * @return array|null ['names' => $namesBySource] if conflicting, else null
     */
    public static function detectIdentityConflict(array $namesBySource): ?array
    {
        $normalized = array_map(
            fn ($n) => strtoupper(preg_replace('/[^A-Z0-9]+/i', ' ', trim($n))),
            $namesBySource
        );
        $unique = array_unique($normalized);
        if (count($unique) <= 1) {
            return null;
        }
        return ['names' => $namesBySource];
    }

    /**
     * Flags a conflict when two independently-derived conversion factors for
     * the same unit disagree beyond rounding.
     */
    public static function detectConversionConflict(?float $factorA, ?string $labelA, ?float $factorB, ?string $labelB): ?array
    {
        if ($factorA === null || $factorB === null) {
            return null;
        }
        if (abs($factorA - $factorB) / max($factorA, $factorB) > self::RATIO_TOLERANCE) {
            return ['factor_a' => $factorA, 'label_a' => $labelA, 'factor_b' => $factorB, 'label_b' => $labelB];
        }
        return null;
    }

    /**
     * Assembles the full candidate record for one SKU from whatever
     * evidence is available. This is the ONLY place that decides
     * confidence/issue_code/review_status/approved — every value it writes
     * traces back to an explicit rule above, never an inferred "best guess".
     *
     * @param array $input {
     *   sku, item_name_by_source: [source => name], legacy: ?array{base_unit,mid_unit,mid_conversion,purchase_unit,purchase_conversion,source_field,legacy_source},
     *   name_for_heuristic: ?string, price_records: array, display_units: [source => unit],
     *   is_global_master_candidate: bool, is_duplicate_source: bool, duplicate_note: ?string,
     * }
     */
    public static function buildCandidateRecord(array $input): array
    {
        $issues = [];
        $confidence = null;
        $reviewStatus = 'PENDING';
        $legacyEvidence = null;
        $nameEvidence = null;
        $priceEvidence = null;
        $mismatchEvidence = null;

        // ---- Identity conflict (blocks everything else) ----
        $identityConflict = null;
        if (!empty($input['item_name_by_source']) && count($input['item_name_by_source']) > 1) {
            $identityConflict = self::detectIdentityConflict($input['item_name_by_source']);
        }
        if ($identityConflict !== null) {
            $issues[] = 'IDENTITY_CONFLICT';
            $reviewStatus = 'BLOCKED';
        }

        // ---- Duplicate source (same real item under >1 SKU code at one location) ----
        if (!empty($input['is_duplicate_source'])) {
            $issues[] = 'DUPLICATE_SOURCE';
        }

        // ---- Global Master candidate (new SKU, no existing master row) ----
        if (!empty($input['is_global_master_candidate'])) {
            $issues[] = 'GLOBAL_MASTER_CANDIDATE';
        }

        // ---- Legacy extraction (never auto-approved regardless of source) ----
        if (!empty($input['legacy'])) {
            $legacyEvidence = [[
                'legacy_source' => $input['legacy']['legacy_source'] ?? null,
                'source_field' => $input['legacy']['source_field'] ?? null,
                'legacy_value' => $input['legacy'],
            ]];
            $issues[] = 'LEGACY_CONVERSION_CANDIDATE';
            $confidence = $confidence ?? 'MEDIUM'; // "multiple consistent legacy records" tier — single record here, so capped MEDIUM not HIGH
        }

        // ---- Name-derived heuristic ----
        if (!empty($input['name_for_heuristic'])) {
            $nameEvidence = self::extractNameHeuristic($input['name_for_heuristic']);
            if ($nameEvidence !== null) {
                $issues[] = 'NAME_DERIVED_CANDIDATE';
                if ($nameEvidence['ambiguous_structure']) {
                    $issues[] = 'PACKAGE_STRUCTURE_UNCLEAR';
                }
                $confidence = $confidence ?? 'LOW';
            }
        }

        // ---- Price ratio cross-check ----
        if (!empty($input['price_records']) && count($input['price_records']) >= 2) {
            [$priceEvidence, $mismatchEvidence] = self::detectPriceRatio($input['price_records']);
            if ($mismatchEvidence !== null) {
                $issues[] = 'UNIT_LABEL_MISMATCH';
            } elseif ($priceEvidence !== null) {
                $issues[] = 'PRICE_RATIO_SUPPORTS_CONVERSION';
                $confidence = $confidence ?? 'MEDIUM';
            }
        }

        // ---- Conversion conflict: legacy vs name-derived disagree ----
        if ($legacyEvidence !== null && $nameEvidence !== null && !empty($input['legacy']['purchase_conversion'])) {
            $conflict = self::detectConversionConflict(
                (float) $input['legacy']['purchase_conversion'], $input['legacy']['purchase_unit'] ?? null,
                $nameEvidence['total_qty'] ?? null, $nameEvidence['unit'] ?? null
            );
            if ($conflict !== null) {
                $issues[] = 'CONVERSION_CONFLICT';
            }
        }

        if (empty($issues) && $identityConflict === null) {
            // Nothing at all found for this SKU — needs manual sourcing, not a rule failure.
            $issues[] = 'NO_EVIDENCE_FOUND';
            $reviewStatus = $reviewStatus === 'BLOCKED' ? $reviewStatus : 'PENDING';
        }

        // Base unit ambiguity (Section 2): flagged separately, never resolved automatically here.
        if (!empty($input['base_unit_ambiguous'])) {
            $issues[] = 'BASE_UNIT_REVIEW_REQUIRED';
        }

        // ---- Approval: per Section 17, YES only with zero conflicts, consistent
        // math, strong evidence, and no identity issue. None of the rules above
        // ever set this — it is left BLANK unconditionally in this engine's
        // output; a human sets it after review by updating the DB row directly. ----

        return [
            'sku' => $input['sku'],
            'item_name' => self::firstNonEmpty($input['item_name_by_source'] ?? []),
            'current_source' => $identityConflict !== null ? 'MULTIPLE (CONFLICTING)' : self::firstKey($input['item_name_by_source'] ?? []),
            'global_base_unit_candidate' => $input['global_base_unit_candidate'] ?? null,
            'legacy_base_unit' => $input['legacy']['base_unit'] ?? null,
            'middle_unit_candidate' => $input['legacy']['mid_unit'] ?? null,
            'middle_conversion_to_base' => $input['legacy']['mid_conversion'] ?? null,
            'purchase_unit_candidate' => $input['legacy']['purchase_unit'] ?? ($nameEvidence['unit'] ?? null),
            'purchase_conversion_to_base' => $input['legacy']['purchase_conversion'] ?? ($nameEvidence['total_qty'] ?? null),
            'gudang_besar_display_unit' => $input['display_units']['GUDANG_BESAR'] ?? null,
            'cibadak_display_unit' => $input['display_units']['CIBADAK'] ?? null,
            'karangtengah_display_unit' => $input['display_units']['KARANG_TENGAH'] ?? null,
            'name_derived_candidate' => $nameEvidence ? "{$nameEvidence['total_qty']} {$nameEvidence['unit']} from \"{$nameEvidence['raw']}\"" : null,
            'price_ratio_evidence' => $priceEvidence ?? $mismatchEvidence,
            'legacy_evidence' => $legacyEvidence,
            'confidence' => $confidence,
            'issue_code' => implode(',', array_unique($issues)),
            'issue_detail' => self::buildIssueDetail($identityConflict, $mismatchEvidence, $input),
            'review_status' => $reviewStatus,
            'approved_base_unit' => null, 'approved_middle_unit' => null, 'approved_middle_conversion' => null,
            'approved_purchase_unit' => null, 'approved_purchase_conversion' => null,
            'approved' => null, 'correction_note' => $input['duplicate_note'] ?? null,
        ];
    }

    private static function firstNonEmpty(array $arr): ?string
    {
        foreach ($arr as $v) {
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    private static function firstKey(array $arr): ?string
    {
        $keys = array_keys($arr);
        return $keys[0] ?? null;
    }

    private static function buildIssueDetail(?array $identityConflict, ?array $mismatchEvidence, array $input): string
    {
        $parts = [];
        if ($identityConflict !== null) {
            $namesText = implode(' vs ', array_map(fn ($src, $name) => "{$src}=\"{$name}\"", array_keys($identityConflict['names']), $identityConflict['names']));
            $parts[] = "Identity conflict: {$namesText} — BLOCKED until SKU identity is resolved.";
        }
        if ($mismatchEvidence !== null) {
            $parts[] = "Price ratio {$mismatchEvidence['ratio']}x between {$mismatchEvidence['source_a']} (Rp{$mismatchEvidence['price_a']}/{$mismatchEvidence['unit_a']}) and {$mismatchEvidence['source_b']} (Rp{$mismatchEvidence['price_b']}/{$mismatchEvidence['unit_b']}) is close to a {$mismatchEvidence['suspected_scale']}x unit-scale constant — possible mislabeled unit, not necessarily a real price/packaging anomaly. Do not auto-correct.";
        }
        if (!empty($input['is_global_master_candidate'])) {
            $parts[] = 'Stock-only item at one warehouse — proposed as a new Global Master item using identity/name/unit from stock data; category left blank pending review.';
        }
        if (!empty($input['is_duplicate_source'])) {
            $parts[] = $input['duplicate_note'] ?? 'Appears to duplicate another SKU at the same source — retain one identity, discrepancy logged.';
        }
        return implode(' ', $parts);
    }
}
