<?php
declare(strict_types=1);

/**
 * PHASE V2 3c — Step B of the category backfill strategy
 * (docs/PHASE_V2_TECHNICAL_DESIGN.md Section 3). Idempotent, mapping-file
 * driven — never guesses a category grouping on its own.
 *
 * Input: a CSV with header `raw_value,category_code,category_name`,
 * approved by the owner from the report produced by
 * scripts/report_category_distinct_values.php. `raw_value` must match
 * items.category exactly (case-sensitive); use the literal token
 * `(NULL)` for the blank/NULL-category group (the report emits this same
 * token for those rows). Multiple raw_value rows may map to the same
 * category_code — that IS the merge decision, made explicitly by the
 * owner in the mapping file, never inferred here.
 *
 * Safety:
 *  - refuses to run if any raw_value currently in items.category is NOT
 *    covered by the mapping file, unless --allow-partial is passed
 *    (partial runs leave those items' category_id untouched, never guess)
 *  - items.category (the original free text) is NEVER modified or cleared
 *  - idempotent: re-running with the same mapping is a no-op; re-running
 *    with an extended mapping only affects the newly-covered raw values
 *
 * Usage:
 *   php scripts/backfill_item_categories.php path/to/approved_mapping.csv
 *   php scripts/backfill_item_categories.php path/to/approved_mapping.csv --allow-partial
 *   php scripts/backfill_item_categories.php path/to/approved_mapping.csv --dry-run
 */

require_once __DIR__ . '/../services/Database.php';

use App\Services\Database;

$args = array_slice($argv, 1);
$mappingPath = null;
$allowPartial = false;
$dryRun = false;
foreach ($args as $arg) {
    if ($arg === '--allow-partial') {
        $allowPartial = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($mappingPath === null) {
        $mappingPath = $arg;
    }
}

if ($mappingPath === null || !file_exists($mappingPath)) {
    fwrite(STDERR, "Usage: php scripts/backfill_item_categories.php <mapping.csv> [--allow-partial] [--dry-run]\n");
    fwrite(STDERR, "Mapping file not found: " . ($mappingPath ?? '(none given)') . "\n");
    exit(1);
}

$fh = fopen($mappingPath, 'r');
$header = fgetcsv($fh);
if ($header === false || array_map('strtolower', $header) !== ['raw_value', 'category_code', 'category_name']) {
    fwrite(STDERR, "Mapping file must have header: raw_value,category_code,category_name\n");
    exit(1);
}

/** @var array<string, array{code:string,name:string}> $mapping raw_value => target category */
$mapping = [];
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 3 || trim($row[0]) === '') {
        continue;
    }
    [$rawValue, $code, $name] = $row;
    $mapping[$rawValue] = ['code' => trim($code), 'name' => trim($name)];
}
fclose($fh);

echo 'Loaded ' . count($mapping) . " mapping rows from {$mappingPath}\n";

$pdo = Database::connection();

// ---- completeness precheck ----
// NULL and '' both collapse to the single "(NULL)" bucket the mapping file
// uses (matching backfill's own WHERE category IS NULL OR TRIM(category)='' ),
// so their counts are combined into one missing-entry rather than reported twice.
$distinctStmt = $pdo->query('SELECT category, COUNT(*) AS c FROM items GROUP BY category');
$missingCounts = [];
foreach ($distinctStmt->fetchAll() as $row) {
    $rawValue = $row['category'] === null || trim((string) $row['category']) === '' ? '(NULL)' : $row['category'];
    if (!isset($mapping[$rawValue])) {
        $missingCounts[$rawValue] = ($missingCounts[$rawValue] ?? 0) + (int) $row['c'];
    }
}
$missing = [];
foreach ($missingCounts as $rawValue => $count) {
    $missing[] = "{$rawValue} (n={$count})";
}

if ($missing && !$allowPartial) {
    fwrite(STDERR, "REFUSING TO RUN — the following items.category values are not covered by the mapping file:\n");
    foreach ($missing as $m) {
        fwrite(STDERR, " - {$m}\n");
    }
    fwrite(STDERR, "\nEither add these to the mapping, or pass --allow-partial to intentionally leave them\n");
    fwrite(STDERR, "uncategorized (category_id stays NULL) for now.\n");
    exit(1);
}
if ($missing && $allowPartial) {
    echo "--allow-partial set — the following will be left uncategorized this run:\n";
    foreach ($missing as $m) {
        echo " - {$m}\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "--dry-run: no changes will be made.\n\n";
}

$run = function (callable $fn) use ($pdo, $dryRun) {
    if ($dryRun) {
        return $fn($pdo, true);
    }
    return Database::transaction(fn (PDO $tx) => $fn($tx, false));
};

$summary = $run(function (PDO $pdo, bool $dryRun) use ($mapping) {
    $categoryIds = [];
    $categoriesCreated = 0;
    $categoriesReused = 0;
    foreach ($mapping as $target) {
        $code = $target['code'];
        if (isset($categoryIds[$code])) {
            continue;
        }
        $existing = $pdo->prepare('SELECT id FROM categories WHERE code = :c');
        $existing->execute(['c' => $code]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $categoryIds[$code] = (int) $id;
            $categoriesReused++;
            if (!$dryRun) {
                $pdo->prepare('UPDATE categories SET name = :n WHERE id = :id')->execute(['n' => $target['name'], 'id' => $id]);
            }
            continue;
        }
        if ($dryRun) {
            $categoryIds[$code] = -1; // placeholder, no real id yet
        } else {
            $pdo->prepare('INSERT INTO categories (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => $target['name']]);
            $categoryIds[$code] = (int) $pdo->lastInsertId();
        }
        $categoriesCreated++;
    }

    $itemsUpdated = 0;
    foreach ($mapping as $rawValue => $target) {
        $categoryId = $categoryIds[$target['code']];
        if ($rawValue === '(NULL)') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE category IS NULL OR TRIM(category) = \'\'');
            $stmt->execute();
            $count = (int) $stmt->fetchColumn();
            if (!$dryRun && $count > 0) {
                $pdo->prepare('UPDATE items SET category_id = :cat WHERE category IS NULL OR TRIM(category) = \'\'')
                    ->execute(['cat' => $categoryId]);
            }
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM items WHERE category = :raw');
            $stmt->execute(['raw' => $rawValue]);
            $count = (int) $stmt->fetchColumn();
            if (!$dryRun && $count > 0) {
                $pdo->prepare('UPDATE items SET category_id = :cat WHERE category = :raw')
                    ->execute(['cat' => $categoryId, 'raw' => $rawValue]);
            }
        }
        $itemsUpdated += $count;
    }

    return ['categories_created' => $categoriesCreated, 'categories_reused' => $categoriesReused, 'items_updated' => $itemsUpdated];
});

echo "Categories created: {$summary['categories_created']}\n";
echo "Categories reused (already existed, name refreshed): {$summary['categories_reused']}\n";
echo "Item rows with category_id set/refreshed: {$summary['items_updated']}\n";

$stillNull = (int) $pdo->query('SELECT COUNT(*) FROM items WHERE category_id IS NULL')->fetchColumn();
echo "Items remaining with category_id NULL (uncovered or intentionally blank): {$stillNull}\n";

echo "items.category (the original free text) is never written by this script — only category_id.\n";
echo "\nDone.\n";
