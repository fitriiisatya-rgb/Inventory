<?php
declare(strict_types=1);

/**
 * Data-driven unit conversion, modeled exactly on the legacy
 * index_2.php semantics (confirmed against its quoted midToBase()):
 *
 *   buy_content = base units per 1 buy unit
 *   mid_content = MID units per 1 buy unit
 *   mid_to_base (derived, never stored) = buy_content / mid_content
 *
 * Example — Keju: 1 Karton = 20 Kg, 1 Karton = 20.000 Gr
 *   buy_content=20000  mid_content=20  => mid_to_base = 1000 Gr per Kg
 */
final class UnitConversion
{
    /**
     * Which input levels should be rendered for this item, in order.
     * No dropdown — the UI renders exactly one field per returned level.
     *
     * @return array<int, array{level: string, unit: string}>
     */
    public static function levels(array $item): array
    {
        $buyUnit  = trim((string) $item['buy_unit']);
        $midUnit  = $item['mid_unit'] !== null ? trim((string) $item['mid_unit']) : null;
        $baseUnit = trim((string) $item['base_unit']);

        if (Validation::sameUnit($buyUnit, $baseUnit)) {
            // 1-level item (e.g. Telur): buy_unit === base_unit.
            return [['level' => 'base', 'unit' => $baseUnit]];
        }

        $levels = [['level' => 'buy', 'unit' => $buyUnit]];
        if ($midUnit !== null && $midUnit !== '') {
            $levels[] = ['level' => 'mid', 'unit' => $midUnit];
        }
        $levels[] = ['level' => 'base', 'unit' => $baseUnit];
        return $levels;
    }

    /** buy_content / mid_content, or null when the item has no mid level. */
    public static function midToBase(array $item): ?float
    {
        $midContent = $item['mid_content'] ?? null;
        if ($midContent === null || (float) $midContent <= 0) {
            return null;
        }
        return (float) $item['buy_content'] / (float) $midContent;
    }

    /**
     * Normalize a raw multi-level input into a single base-unit quantity.
     * Callers pass 0 for any level the item doesn't have (levels() tells
     * them which levels exist) — the formula is safe either way because
     * a 0 * factor term contributes nothing.
     */
    public static function normalize(array $item, float $buyQty, float $midQty, float $baseQty): float
    {
        $buyUnit  = trim((string) $item['buy_unit']);
        $baseUnit = trim((string) $item['base_unit']);

        $total = $baseQty;

        $midToBase = self::midToBase($item);
        if ($midToBase !== null) {
            $total += $midQty * $midToBase;
        }

        if (!Validation::sameUnit($buyUnit, $baseUnit)) {
            $total += $buyQty * (float) $item['buy_content'];
        }

        return $total;
    }

    /**
     * Validate a proposed Master Barang conversion configuration.
     * Errors block saving; warnings surface to the user but don't block.
     *
     * @return array<int, array{severity: string, field: string, message: string}>
     */
    public static function validateConversion(
        string $buyUnit,
        $buyContent,
        ?string $midUnit,
        $midContent,
        string $baseUnit
    ): array {
        $issues = [];
        $buyUnit  = trim($buyUnit);
        $baseUnit = trim($baseUnit);
        $midUnit  = $midUnit !== null ? trim($midUnit) : null;
        if ($midUnit === '') {
            $midUnit = null;
        }

        if ($buyUnit === '') {
            $issues[] = ['severity' => 'error', 'field' => 'buy_unit', 'message' => 'Buy unit wajib diisi.'];
        }
        if ($baseUnit === '') {
            $issues[] = ['severity' => 'error', 'field' => 'base_unit', 'message' => 'Base unit wajib diisi.'];
        }
        if (!is_numeric($buyContent) || (float) $buyContent <= 0) {
            $issues[] = ['severity' => 'error', 'field' => 'buy_content', 'message' => 'Buy content harus berupa angka lebih besar dari 0.'];
        }

        if ($buyUnit === '' || $baseUnit === '' || !is_numeric($buyContent) || (float) $buyContent <= 0) {
            // Can't reason about the rest without valid basics.
            return $issues;
        }

        $buyContent = (float) $buyContent;
        $buyEqualsBase = Validation::sameUnit($buyUnit, $baseUnit);

        if ($buyEqualsBase) {
            if ($midUnit !== null) {
                $issues[] = [
                    'severity' => 'error', 'field' => 'mid_unit',
                    'message' => 'Item dengan buy_unit sama dengan base_unit (1 level) tidak boleh memiliki mid_unit.',
                ];
            }
            if (abs($buyContent - 1.0) > 0.0001) {
                $issues[] = [
                    'severity' => 'error', 'field' => 'buy_content',
                    'message' => 'buy_content harus bernilai 1 ketika buy_unit sama dengan base_unit.',
                ];
            }
            return $issues;
        }

        if ($midUnit === null) {
            return $issues; // valid 2-level item (buy/base only)
        }

        if (!is_numeric($midContent) || (float) $midContent <= 0) {
            $issues[] = [
                'severity' => 'error', 'field' => 'mid_content',
                'message' => 'mid_content harus berupa angka lebih besar dari 0 ketika mid_unit diisi.',
            ];
            return $issues;
        }
        $midContent = (float) $midContent;

        if (Validation::sameUnit($midUnit, $baseUnit)) {
            $issues[] = [
                'severity' => 'error', 'field' => 'mid_unit',
                'message' => 'mid_unit tidak boleh sama dengan base_unit — hapus mid_unit jika item hanya 2 level.',
            ];
            return $issues;
        }
        if (Validation::sameUnit($midUnit, $buyUnit)) {
            $issues[] = [
                'severity' => 'error', 'field' => 'mid_unit',
                'message' => 'mid_unit tidak boleh sama dengan buy_unit.',
            ];
            return $issues;
        }

        if ($midContent > $buyContent) {
            $issues[] = [
                'severity' => 'warning', 'field' => 'mid_content',
                'message' => "Jumlah mid unit per buy unit ({$midContent}) melebihi buy_content ({$buyContent}) — periksa kemungkinan buy_content dan mid_content tertukar.",
            ];
        }

        $midToBase = $buyContent / $midContent;
        if ($midToBase < 1) {
            $issues[] = [
                'severity' => 'warning', 'field' => 'mid_content',
                'message' => "Hasil konversi mid→base kurang dari 1 ({$midToBase}) — periksa kembali nilai buy_content/mid_content.",
            ];
        }

        return $issues;
    }

    public static function hasBlockingErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return true;
            }
        }
        return false;
    }
}
