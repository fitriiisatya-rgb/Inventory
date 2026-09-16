<?php
declare(strict_types=1);

/**
 * PHASE G9 — data quality report for a staged (not necessarily committed)
 * import batch. Read-only: never touches import_batches/import_rows/
 * stock_openings status, never changes ERROR/WARNING/VALID classification
 * — it only summarizes what staging already decided, plus a handful of
 * independent analysis categories (abnormal cost, duplicate opening,
 * unknown supplier/division, duplicate legacy id) that the underlying
 * importers don't tag today (G6/G7 detailed validation is out of this
 * batch's scope — this script adds visibility without changing what
 * blocks a commit).
 *
 * Usage:
 *   php scripts/generate_import_quality_report.php <type> <id>
 *   type: MASTER_ITEM | SUPPLIER | DIVISION | WAREHOUSE | OPENING_STOCK | HISTORICAL_TRANSACTION
 *   id:   import_batches.id (for the generic types) or stock_openings.id (for OPENING_STOCK)
 *
 * Writes both a .json and a .md report to migration/workspace/reports/.
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/generate_import_quality_report.php <type> <id>\n");
    fwrite(STDERR, "type: MASTER_ITEM | SUPPLIER | DIVISION | WAREHOUSE | OPENING_STOCK | HISTORICAL_TRANSACTION\n");
    exit(1);
}

[$script, $type, $idArg] = $argv;
$type = strtoupper($type);
$id = (int) $idArg;
$pdo = Database::connection();

$report = match ($type) {
    'MASTER_ITEM', 'SUPPLIER', 'DIVISION', 'WAREHOUSE' => reportForGenericBatch($pdo, $type, $id, fn () => []),
    'HISTORICAL_TRANSACTION' => reportForHistorical($pdo, $id),
    'OPENING_STOCK' => reportForOpening($pdo, $id),
    default => null,
};

if ($report === null) {
    fwrite(STDERR, "Unknown type '{$type}'.\n");
    exit(1);
}

$outDir = __DIR__ . '/../migration/workspace/reports';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$stamp = date('Ymd_His');
$base = "{$outDir}/{$type}_{$id}_{$stamp}";

file_put_contents("{$base}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents("{$base}.md", renderMarkdown($type, $id, $report));

echo "Report written:\n  {$base}.json\n  {$base}.md\n\n";
echo renderMarkdown($type, $id, $report);

// ---------------------------------------------------------------------

function reportForGenericBatch(PDO $pdo, string $type, int $batchId, callable $extraCategorizer): array
{
    $batch = $pdo->prepare('SELECT * FROM import_batches WHERE id = :id AND import_type = :type');
    $batch->execute(['id' => $batchId, 'type' => $type]);
    $batch = $batch->fetch();
    if (!$batch) {
        fwrite(STDERR, "Import batch #{$batchId} of type {$type} not found.\n");
        exit(1);
    }

    $rows = $pdo->prepare('SELECT * FROM import_rows WHERE import_batch_id = :id');
    $rows->execute(['id' => $batchId]);
    $rows = $rows->fetchAll();

    $counts = ['total_rows' => count($rows), 'valid' => 0, 'warning' => 0, 'error' => 0];
    $categories = array_fill_keys(['duplicate_sku', 'duplicate_barcode', 'invalid_unit', 'invalid_conversion'], 0);
    $extra = $extraCategorizer();
    foreach ($extra as $key => $_) {
        $categories[$key] = 0;
    }

    foreach ($rows as $row) {
        $counts[strtolower($row['row_status'])]++;
        $messages = strtolower((string) $row['messages']);
        if (str_contains($messages, 'duplicate sku')) {
            $categories['duplicate_sku']++;
        }
        if (str_contains($messages, 'barcode')) {
            $categories['duplicate_barcode']++;
        }
        if (str_contains($messages, 'not a recognized unit')) {
            $categories['invalid_unit']++;
        }
        if (str_contains($messages, 'conversion')) {
            $categories['invalid_conversion']++;
        }
        if (array_key_exists('unknown_sku', $categories) && str_contains($messages, 'unknown sku')) {
            $categories['unknown_sku']++;
        }
        if (array_key_exists('unknown_warehouse', $categories) && str_contains($messages, 'unknown warehouse_code')) {
            $categories['unknown_warehouse']++;
        }
    }

    return [
        'import_type' => $type, 'import_batch_id' => $batchId, 'file_name' => $batch['file_name'],
        'status' => $batch['status'], 'counts' => $counts, 'categories' => $categories,
    ];
}

function reportForHistorical(PDO $pdo, int $batchId): array
{
    $base = reportForGenericBatch($pdo, 'HISTORICAL_TRANSACTION', $batchId, fn () => [
        'unknown_sku' => 0, 'unknown_warehouse' => 0,
    ]);

    // Independent analysis (G7 detail is out of this batch's scope — this
    // never changes what the importer accepted/rejected, only reports on it).
    $rows = $pdo->prepare('SELECT raw_data FROM import_rows WHERE import_batch_id = :id');
    $rows->execute(['id' => $batchId]);
    $rows = $rows->fetchAll();

    $unknownSupplier = 0;
    $unknownDivision = 0;
    $referenceSeen = [];
    $duplicateLegacyId = 0;

    foreach ($rows as $row) {
        $data = json_decode((string) $row['raw_data'], true) ?: [];
        if (!empty($data['supplier_code'])) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE code = :c');
            $exists->execute(['c' => $data['supplier_code']]);
            if ((int) $exists->fetchColumn() === 0) {
                $unknownSupplier++;
            }
        }
        if (!empty($data['division_code'])) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM divisions WHERE code = :c');
            $exists->execute(['c' => $data['division_code']]);
            if ((int) $exists->fetchColumn() === 0) {
                $unknownDivision++;
            }
        }
        $legacyId = $data['original_legacy_id'] ?? $data['reference_no'] ?? null;
        if ($legacyId !== null && $legacyId !== '') {
            if (isset($referenceSeen[$legacyId])) {
                $duplicateLegacyId++;
            }
            $referenceSeen[$legacyId] = true;
        }
    }

    $base['categories']['unknown_supplier'] = $unknownSupplier;
    $base['categories']['unknown_division'] = $unknownDivision;
    $base['categories']['duplicate_legacy_id'] = $duplicateLegacyId;

    return $base;
}

function reportForOpening(PDO $pdo, int $openingId): array
{
    $opening = $pdo->prepare('SELECT * FROM stock_openings WHERE id = :id');
    $opening->execute(['id' => $openingId]);
    $opening = $opening->fetch();
    if (!$opening) {
        fwrite(STDERR, "Stock opening #{$openingId} not found.\n");
        exit(1);
    }

    $lines = $pdo->prepare('SELECT * FROM stock_opening_lines WHERE stock_opening_id = :id');
    $lines->execute(['id' => $openingId]);
    $lines = $lines->fetchAll();

    $counts = ['total_rows' => count($lines), 'valid' => 0, 'warning' => 0, 'error' => 0];
    $categories = ['negative_qty' => 0, 'zero_cost' => 0, 'abnormal_cost' => 0, 'abnormal_qty' => 0, 'duplicate_opening' => 0];

    // Reference cost/qty per item (median of this file's own positive lines) for anomaly detection.
    $costsByItem = [];
    $qtysByItem = [];
    foreach ($lines as $line) {
        if ((float) $line['qty_base'] > 0) {
            $qtysByItem[(int) $line['item_id']][] = (float) $line['qty_base'];
            if ((float) $line['unit_cost_base'] > 0) {
                $costsByItem[(int) $line['item_id']][] = (float) $line['unit_cost_base'];
            }
        }
    }
    $median = function (array $values): float {
        sort($values);
        $n = count($values);
        return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    };
    $medianByItem = array_map($median, $costsByItem);
    $medianQtyByItem = array_map($median, $qtysByItem);

    $seenCombo = [];
    foreach ($lines as $line) {
        $counts[strtolower($line['row_status'])]++;

        if ((float) $line['qty_base'] < 0) {
            $categories['negative_qty']++;
        }
        if ((float) $line['qty_base'] > 0 && (float) $line['unit_cost_base'] == 0.0) {
            $categories['zero_cost']++;
        }
        $itemId = (int) $line['item_id'];
        if (isset($medianByItem[$itemId]) && $medianByItem[$itemId] > 0) {
            $ratio = (float) $line['unit_cost_base'] / $medianByItem[$itemId];
            if ($ratio > 5 || ($ratio > 0 && $ratio < 0.2)) {
                $categories['abnormal_cost']++;
            }
        }
        // QTY_ABNORMAL (G10): same 5x/0.2x band as cost, applied to quantity
        // within this same file — flags a review-worthy outlier, never
        // auto-corrected (G11).
        if (isset($medianQtyByItem[$itemId]) && $medianQtyByItem[$itemId] > 0 && (float) $line['qty_base'] > 0) {
            $qtyRatio = (float) $line['qty_base'] / $medianQtyByItem[$itemId];
            if ($qtyRatio > 5 || $qtyRatio < 0.2) {
                $categories['abnormal_qty']++;
            }
        }
        $combo = $line['warehouse_id'] . ':' . $line['item_id'] . ':' . ($line['batch_reference'] ?? '');
        if (isset($seenCombo[$combo])) {
            $categories['duplicate_opening']++;
        }
        $seenCombo[$combo] = true;
    }

    return [
        'import_type' => 'OPENING_STOCK', 'stock_opening_id' => $openingId,
        'cutoff_date' => $opening['cutoff_date'], 'status' => $opening['status'],
        'control_total_value' => $opening['control_total_value'],
        'counts' => $counts, 'categories' => $categories,
    ];
}

function renderMarkdown(string $type, int $id, array $report): string
{
    $lines = ["# Data Quality Report — {$type} #{$id}", '', "Generated: " . date('Y-m-d H:i:s') . " UTC", ''];
    $lines[] = '## Counts';
    $lines[] = '';
    foreach ($report['counts'] as $k => $v) {
        $lines[] = "- **" . ucwords(str_replace('_', ' ', $k)) . ":** {$v}";
    }
    $lines[] = '';
    $lines[] = '## Categories';
    $lines[] = '';
    foreach ($report['categories'] as $k => $v) {
        $lines[] = "- **" . ucwords(str_replace('_', ' ', $k)) . ":** {$v}";
    }
    $lines[] = '';
    return implode("\n", $lines) . "\n";
}
