<?php
declare(strict_types=1);

/**
 * STABILIZATION — proves the patched markers are present in the ACTIVE
 * production-target file (public/assets/js/stock-opname-v2163eod.js),
 * NOT the dev-branch public/assets/js/stock-opname.js (production does
 * not load that file). Run this AFTER applying
 * scripts/patch_stock_opname_v2163eod_production.php to a real copy of
 * production's file.
 *
 * Usage:
 *   php tests/verify_production_patch_markers_test.php <path-to-stock-opname-v2163eod.js>
 * Defaults to public/assets/js/stock-opname-v2163eod.js if no path is
 * given; if that default does not exist in this sandbox (expected —
 * this file is production-only and was never committed to this repo),
 * the test SKIPS with exit 0 rather than failing, and says so.
 */

$path = $argv[1] ?? __DIR__ . '/../public/assets/js/stock-opname-v2163eod.js';

if (!is_file($path)) {
    echo "SKIP — {$path} not found in this sandbox (expected: production's stock-opname-v2163eod.js was never committed here). Run this against a real copy of that file.\n";
    exit(0);
}

$source = file_get_contents($path);
$results = [];
function check(string $name, bool $pass): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}\n";
}

check('file is the v2163eod production target, not stock-opname.js', basename($path) === 'stock-opname-v2163eod.js');
check('renderGeneration token present', str_contains($source, 'renderGeneration'));
check('loadForWarehouse() captures myGeneration', (bool) preg_match('/function\s+loadForWarehouse[\s\S]{0,200}myGeneration/', $source));
check('renderSession() captures myGeneration + stale()', (bool) preg_match('/function\s+renderSession\s*\(\s*sessionId\s*\)[\s\S]{0,300}stale\s*=/', $source));
check('a stale() check exists after the session fetch', str_contains($source, 'if (stale()) return'));
check('postOpname() still calls the EXACT existing API contract', str_contains($source, 'InvApi.postOpname(currentSessionId, overrides)'));
check('postOpname() shows a "Memposting…" loading label', str_contains($source, 'Memposting'));
check('postOpname() has a defensive re-entry guard', str_contains($source, 'btn.disabled) return'));

$failed = count(array_filter($results, fn ($r) => !$r));
echo "\n" . (count($results) - $failed) . ' / ' . count($results) . " PASSED\n";
exit($failed > 0 ? 1 : 0);
