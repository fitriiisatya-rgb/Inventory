<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Stock Opname audit redesign) — public/index.php
 *
 * Three insertions, nothing else is touched (GET /reports/opname, /reports/opname/{id}, /reports/opname/{id}/jejak and every Stock Opname route stay as they are):
 *   1. +1 require_once (services/StockOpnameAuditReportService.php) right after the StockOpnameJejakService require;
 *   2. three request helpers (inv_soa_filters / inv_soa_session_ids / inv_soa_line_filters — payload/soa_index_php_helper.txt) immediately before the
 *      docblock of inv_so_resolve_warehouse_scope() (which they call, so a STOCK user / warehouse-scoped ADMIN can never widen the scope);
 *   3. five READ-ONLY GET routes (payload/soa_index_php_routes.txt: sessions, items, item-detail, photo/{id}, export)
 *      immediately before the 'GET /reports/opname' route.
 * Every anchor must match EXACTLY ONCE; the two payload files must hash to the tested values.
 *
 * TWO supported states (anything else is refused, nothing written):
 *   CLEAN                       none of the report's pieces present → the three insertions below;
 *   V2 BACKEND ALREADY APPLIED  require + helpers + routes present exactly once, byte-identical to the payloads → index.php is left untouched and only a
 *                               verification state file is recorded (the SOA V2 package's own state files are never read or modified).
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, the payload hashes match, and (CLEAN) every anchor matches once.
 * Dry-run by default; --apply backs up (<file>.pre-soa3-backup), writes atomically, re-verifies, records <file>.soa3-patch.json
 * for rollback_soa3_production.php.
 *
 * Usage:
 *   php scripts/patch_soa3_index_php_production.php <index.php> <helper.txt> <routes.txt> \
 *       --expect-sha256=<h> --expect-helper-sha256=<h> --expect-routes-sha256=<h> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-soa3-backup');
define('JP_META_SUFFIX', '.soa3-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$usage = 'php scripts/patch_soa3_index_php_production.php <index.php> <helper.txt> <routes.txt> --expect-sha256=<h> --expect-helper-sha256=<h> --expect-routes-sha256=<h> [--apply]';
$apply = false;
$e = ['sha256' => null, 'helper-sha256' => null, 'routes-sha256' => null];
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
if (count($pos) !== 3 || !$hex($e['sha256']) || !$hex($e['helper-sha256']) || !$hex($e['routes-sha256'])) {
    jp_fail("usage: {$usage}");
}
[$path, $helperPath, $routesPath] = $pos;

$source = jp_read($path);
jp_assert_preimage($source, $e['sha256']);
$payload = [];
foreach (['helper' => $helperPath, 'routes' => $routesPath] as $k => $p) {
    $payload[$k] = jp_read($p);
    $h = hash('sha256', $payload[$k]);
    if (!hash_equals($e["{$k}-sha256"], $h)) {
        jp_fail("{$k} payload SHA256 mismatch — refusing. expected=" . $e["{$k}-sha256"] . " actual={$h}");
    }
}
echo "OK — helper / routes payload hashes match.\n";
// ---- state: CLEAN (nothing of the report is in index.php) or V2 BACKEND ALREADY APPLIED (the SOA V2 package's backend step: require + helpers + routes — byte-identical payloads) ----
$requireLine = "require_once __DIR__ . '/../services/StockOpnameAuditReportService.php';\n";
$hasRequire = substr_count($source, $requireLine);
$hasHelper = substr_count($source, $payload['helper']);
$hasRoutes = substr_count($source, $payload['routes']);
$anyMarker = $hasRequire + $hasHelper + $hasRoutes + substr_count($source, 'inv_soa_filters') + substr_count($source, "'GET /reports/opname-audit/");
if ($anyMarker === 0) {
    echo "STATE: CLEAN — index.php has none of the report's pieces; they will be inserted.\n";
} elseif ($hasRequire === 1 && $hasHelper === 1 && $hasRoutes === 1 && substr_count($source, 'function inv_soa_filters(') === 1 && substr_count($source, "'GET /reports/opname-audit/sessions'") === 1) {
    echo "STATE: V2 BACKEND ALREADY APPLIED — the require, the three helpers and the five routes are present exactly once and byte-identical to this package's payloads.\n";
    echo "       index.php is NOT modified by this package (nothing to add); a verification state file is recorded so the rollback knows index.php was not ours to touch.\n";
    if (!$apply) {
        echo "\nDRY RUN ONLY — nothing was written. Re-run with --apply to record the verification.\n";
        exit(0);
    }
    $meta = jp_meta_path($path);
    if (file_exists($meta)) {
        jp_fail("{$meta} already exists — refusing to apply over a previous run. Roll back first.");
    }
    file_put_contents($meta, json_encode(['target' => basename($path), 'kind' => 'verified_existing', 'postimage_sha256' => hash('sha256', $source), 'verified_at' => gmdate('c')], JSON_PRETTY_PRINT) . "\n");
    echo "\nVERIFIED {$path} (unchanged). SHA256: " . hash('sha256', $source) . "\n";
    exit(0);
} else {
    jp_fail("index.php carries only PART of the report backend (require={$hasRequire}, helpers={$hasHelper}, routes={$hasRoutes}) or a different version — refusing to guess; nothing was written.");
}

$require = "require_once __DIR__ . '/../services/StockOpnameJejakService.php';\n";
$patched = jp_replace_exactly_once($source, $require, $require . $requireLine, 'require_once StockOpnameJejakService.php');
$docAnchor = "/**\n * PHASE V2.14.9.1 — same rule as inv_require_so_warehouse_scope(), but for\n";
$patched = jp_replace_exactly_once($patched, $docAnchor, $payload['helper'] . $docAnchor, 'inv_so_resolve_warehouse_scope docblock');
$routeAnchor = "    'GET /reports/opname' => function () use (\$pdo, \$query) {\n";
$patched = jp_replace_exactly_once($patched, $routeAnchor, $payload['routes'] . $routeAnchor, "'GET /reports/opname' route");
jp_finish($path, $source, $patched, $apply);
