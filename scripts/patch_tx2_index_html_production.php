<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Stock IN / OUT V2) — public/index.html
 *
 * Script/cache tags only:
 *   - transactions.js: its tag moves to the fresh token 20261010-tx2 and the two NEW modules are
 *     loaded right after it (stock-in-sheet.js, stock-out-sheet.js);
 *   - transaction-history.js and app.css: ?v= moves to 20261010-tx2 (both changed).
 * Each tag is matched with ANY current token (production may be on the Jejak / Dashboard
 * tokens) but must match EXACTLY ONCE. No other tag is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, neither the new
 * token nor the new modules are already referenced, and every anchor matches exactly once.
 * Dry-run by default; --apply backs up (<file>.pre-tx2-backup), writes atomically, re-verifies,
 * records <file>.tx2-patch.json for rollback_tx2_production.php.
 *
 * Usage:
 *   php scripts/patch_tx2_index_html_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_tx2_index_html_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-tx2-backup');
define('JP_META_SUFFIX', '.tx2-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_tx2_index_html_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

const TX2_TOKEN = '20261010-tx2';
jp_refuse_if_contains($source, [TX2_TOKEN, 'stock-in-sheet.js', 'stock-out-sheet.js']);

$patched = jp_regex_replace_exactly_once($source,
    '#<script src="assets/js/transactions\.js\?v=[A-Za-z0-9._-]+"></script>#',
    static fn (array $m): string => '<script src="assets/js/transactions.js?v=' . TX2_TOKEN . '"></script>' . "\n"
        . '<script src="assets/js/stock-in-sheet.js?v=' . TX2_TOKEN . '"></script>' . "\n"
        . '<script src="assets/js/stock-out-sheet.js?v=' . TX2_TOKEN . '"></script>',
    'transactions.js <script> tag');
$patched = jp_regex_replace_exactly_once($patched,
    '#(<script src="assets/js/transaction-history\.js\?v=)[A-Za-z0-9._-]+("></script>)#',
    static fn (array $m): string => $m[1] . TX2_TOKEN . $m[2],
    'transaction-history.js <script> tag');
$patched = jp_regex_replace_exactly_once($patched,
    '#(<link rel="stylesheet" href="assets/css/app\.css\?v=)[A-Za-z0-9._-]+(">)#',
    static fn (array $m): string => $m[1] . TX2_TOKEN . $m[2],
    'app.css <link> tag');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
