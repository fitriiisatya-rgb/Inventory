<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Master Data "Tambah ...") — public/assets/js/api-client.js
 *
 * Adds three one-line client methods (createItem, createWarehouse, createDivision) directly after the existing
 * createCategory line. That line must match EXACTLY ONCE; nothing else in the file is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256 and the new methods are absent.
 * Dry-run by default; --apply backs up (<file>.pre-mdm-backup), writes atomically, re-verifies, records <file>.mdm-patch.json.
 *
 * Usage: php scripts/patch_mdm_api_client_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-mdm-backup');
define('JP_META_SUFFIX', '.mdm-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_mdm_api_client_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ['createItem:', 'createWarehouse:', 'createDivision:']);

$line = "        createCategory: (payload) => request('POST', '/categories', payload),\n";
$patched = jp_replace_exactly_once($source, $line, $line
    . "        createItem: (payload) => request('POST', '/items', payload),\n"
    . "        createWarehouse: (payload) => request('POST', '/warehouses', payload),\n"
    . "        createDivision: (payload) => request('POST', '/divisions', payload),\n", 'createCategory line');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
