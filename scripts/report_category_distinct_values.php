<?php
declare(strict_types=1);

/**
 * PHASE V2 3c — Step A of the category backfill strategy
 * (docs/PHASE_V2_TECHNICAL_DESIGN.md Section 3). Read-only: makes no
 * changes, safe to run against production at any time.
 *
 * Lists every distinct value currently in items.category, with:
 *  - item_count per raw value
 *  - a normalized_key (lowercased, trimmed, punctuation/whitespace
 *    collapsed) so near-duplicates ("Bahan Kue" / "bahan kue " /
 *    "Bahan-Kue") are grouped for human review — NEVER auto-merged
 *  - is_blank for NULL/empty values
 *
 * Output: storage/reports/category_distinct_values_<timestamp>.csv
 * The owner reviews this file and returns an approved mapping
 * (raw_value -> category_code/category_name, blanks/merges resolved
 * explicitly) for scripts/backfill_item_categories.php to consume.
 *
 * This script never invents or guesses a category grouping on its own —
 * normalized_key is a REVIEW AID, not a decision. Same discipline as the
 * project's existing PCS<->weight packaging-conversion rule: business
 * classification is never guessed.
 *
 * Usage: php scripts/report_category_distinct_values.php
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

function normalizeKey(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $normalized = mb_strtolower(trim($value));
    $normalized = preg_replace('/[\s\-_,.\/]+/u', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

$pdo = Database::connection();

$stmt = $pdo->query(
    "SELECT category, COUNT(*) AS item_count
     FROM items
     GROUP BY category
     ORDER BY item_count DESC, category ASC"
);
$rows = $stmt->fetchAll();

$groups = [];
foreach ($rows as $row) {
    $raw = $row['category'];
    $isBlank = $raw === null || trim((string) $raw) === '';
    $key = $isBlank ? '__BLANK__' : normalizeKey($raw);
    $groups[$key][] = ['raw_value' => $raw, 'item_count' => (int) $row['item_count'], 'is_blank' => $isBlank];
}

$outDir = __DIR__ . '/../storage/reports';
@mkdir($outDir, 0775, true);
$outPath = $outDir . '/category_distinct_values_' . date('Ymd_His') . '.csv';

$fh = fopen($outPath, 'w');
fputcsv($fh, ['group_id', 'raw_value', 'normalized_key', 'item_count', 'is_blank', 'group_has_multiple_raw_values']);

$groupId = 0;
$totalRaw = 0;
$blankCount = 0;
$multiVariantGroups = 0;
foreach ($groups as $key => $variants) {
    $groupId++;
    $groupHasMultiple = count($variants) > 1 ? 1 : 0;
    if ($groupHasMultiple) {
        $multiVariantGroups++;
    }
    foreach ($variants as $variant) {
        $totalRaw++;
        if ($variant['is_blank']) {
            $blankCount++;
        }
        fputcsv($fh, [
            $groupId,
            $variant['raw_value'] ?? '(NULL)',
            $key,
            $variant['item_count'],
            $variant['is_blank'] ? 1 : 0,
            $groupHasMultiple,
        ]);
    }
}
fclose($fh);

echo "Report written to: {$outPath}\n\n";
echo "Distinct raw category values: {$totalRaw}\n";
echo "Normalized groups: {$groupId}\n";
echo "Groups with more than one raw-value variant (near-duplicate candidates): {$multiVariantGroups}\n";
echo "Blank/NULL category rows: {$blankCount}\n\n";

if ($multiVariantGroups > 0) {
    echo "Near-duplicate candidates (review these merge decisions manually):\n";
    $groupId = 0;
    foreach ($groups as $key => $variants) {
        $groupId++;
        if (count($variants) > 1) {
            $values = implode(' | ', array_map(fn ($v) => ($v['raw_value'] ?? '(NULL)') . " (n={$v['item_count']})", $variants));
            echo "  group {$groupId} [{$key}]: {$values}\n";
        }
    }
    echo "\n";
}

echo "Next step: review the CSV, decide category_code/category_name per group\n";
echo "(and whether/how to map blank rows), then hand the mapping to\n";
echo "scripts/backfill_item_categories.php.\n";
