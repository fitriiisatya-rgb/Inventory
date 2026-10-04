<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Master Data "Tambah ...") — public/index.php
 *
 * Three edits, nothing else:
 *   1. +1 require_once (services/MasterRecordService.php) right after the MasterDataSafetyService require;
 *   2. +3 routes (POST /items, POST /warehouses, POST /divisions — payload/mdm_index_php_routes.txt) inserted
 *      immediately before the 'PUT /warehouses/{id}' route;
 *   3. the POST /categories duplicate check: the exact audited block (payload/mdm_category_old.txt) is replaced
 *      by the block that also refuses a duplicate NAME and honours is_active (payload/mdm_category_new.txt).
 * Every anchor must match EXACTLY ONCE; the three payload files must hash to the tested values.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the payload hashes match, the file
 * does not already reference MasterRecordService, and every anchor matches once. Dry-run by default; --apply backs up
 * (<file>.pre-mdm-backup), writes atomically, re-verifies, records <file>.mdm-patch.json for rollback_mdm_production.php.
 *
 * Usage:
 *   php scripts/patch_mdm_index_php_production.php <index.php> <routes.txt> <cat_old.txt> <cat_new.txt> \
 *       --expect-sha256=<h> --expect-routes-sha256=<h> --expect-catold-sha256=<h> --expect-catnew-sha256=<h> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-mdm-backup');
define('JP_META_SUFFIX', '.mdm-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_mdm_index_php_production.php <index.php> <routes.txt> <cat_old.txt> <cat_new.txt> --expect-sha256=<h> --expect-routes-sha256=<h> --expect-catold-sha256=<h> --expect-catnew-sha256=<h> [--apply]';
$apply = false;
$e = ['sha256' => null, 'routes-sha256' => null, 'catold-sha256' => null, 'catnew-sha256' => null];
$pos = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    $matched = false;
    foreach (array_keys($e) as $k) {
        if (str_starts_with($arg, "--expect-{$k}=")) {
            $e[$k] = strtolower(substr($arg, strlen("--expect-{$k}=")));
            $matched = true;
        }
    }
    if (!$matched) {
        if (str_starts_with($arg, '--')) {
            jp_fail("unknown argument: {$arg}\nusage: {$usage}");
        }
        $pos[] = $arg;
    }
}
$hex = static fn (?string $v): bool => $v !== null && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
if (count($pos) !== 4 || !$hex($e['sha256']) || !$hex($e['routes-sha256']) || !$hex($e['catold-sha256']) || !$hex($e['catnew-sha256'])) {
    jp_fail("usage: {$usage}");
}
[$path, $routesPath, $catOldPath, $catNewPath] = $pos;

$source = jp_read($path);
jp_assert_preimage($source, $e['sha256']);
$payload = [];
foreach (['routes' => $routesPath, 'catold' => $catOldPath, 'catnew' => $catNewPath] as $k => $p) {
    $payload[$k] = jp_read($p);
    $h = hash('sha256', $payload[$k]);
    if (!hash_equals($e["{$k}-sha256"], $h)) {
        jp_fail("{$k} payload SHA256 mismatch — refusing. expected=" . $e["{$k}-sha256"] . " actual={$h}");
    }
}
echo "OK — routes / category old / category new payload hashes match.\n";
jp_refuse_if_contains($source, ['MasterRecordService', "'POST /items'", "'POST /warehouses'", "'POST /divisions'"]);

$require = "require_once __DIR__ . '/../services/MasterDataSafetyService.php';\n";
$patched = jp_replace_exactly_once($source, $require, $require . "require_once __DIR__ . '/../services/MasterRecordService.php';\n", 'require_once MasterDataSafetyService.php');
$anchor = "    'PUT /warehouses/{id}' => function (array \$params) use (\$pdo, \$input) {\n";
$patched = jp_replace_exactly_once($patched, $anchor, $payload['routes'] . $anchor, "'PUT /warehouses/{id}' route");
$patched = jp_replace_exactly_once($patched, $payload['catold'], $payload['catnew'], 'POST /categories duplicate-check block (exact audited text)');
jp_finish($path, $source, $patched, $apply);
