<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Stock Opname audit redesign) — public/assets/js/api-client.js
 *
 * Adds four one-line client methods (opnameAuditSessions, opnameAuditItems, opnameAuditItemDetail, opnameAuditExportUrl) directly after the existing
 * stockOpnameReportPrintUrl line. That line must match EXACTLY ONCE; nothing else in the file is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256 and the new methods are absent.
 * Dry-run by default; --apply backs up (<file>.pre-soa-backup), writes atomically, re-verifies, records <file>.soa-patch.json.
 *
 * Usage: php scripts/patch_soa_api_client_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-soa-backup');
define('JP_META_SUFFIX', '.soa-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_soa_api_client_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ['opnameAuditSessions:', 'opnameAuditItems:', 'opnameAuditItemDetail:', 'opnameAuditExportUrl:']);

$line = "        stockOpnameReportPrintUrl: (id) => `/api/stock-opname-reports/\${id}/print`,\n";
$patched = jp_replace_exactly_once($source, $line, $line
    . "        opnameAuditSessions: (params) => request('GET', `/reports/opname-audit/sessions\${qs(params)}`),\n"
    . "        opnameAuditItems: (params) => request('GET', `/reports/opname-audit/items\${qs(params)}`),\n"
    . "        opnameAuditItemDetail: (params) => request('GET', `/reports/opname-audit/item-detail\${qs(params)}`),\n"
    . "        opnameAuditExportUrl: (params) => `/api/reports/opname-audit/export\${qs(params)}`,\n", 'stockOpnameReportPrintUrl line');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
