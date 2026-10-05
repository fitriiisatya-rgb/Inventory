<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Laporan Pembelian redesign) — public/assets/js/api-client.js
 *
 * Adds five one-line client methods (purchaseV2Overview, purchaseV2Invoices, purchaseV2Items, purchaseV2InvoiceDetail, purchaseV2ExportUrl) directly after the existing
 * purchaseBySupplierExportUrl line. That line must match EXACTLY ONCE; nothing else in the file is touched.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256 and the new methods are absent.
 * Dry-run by default; --apply backs up (<file>.pre-pur-backup), writes atomically, re-verifies, records <file>.pur-patch.json.
 *
 * Usage: php scripts/patch_pur_api_client_production.php <path> --expect-sha256=<hash> [--apply]
 */

define('JP_BACKUP_SUFFIX', '.pre-pur-backup');
define('JP_META_SUFFIX', '.pur-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_pur_api_client_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ['purchaseV2Overview:', 'purchaseV2Invoices:', 'purchaseV2Items:', 'purchaseV2InvoiceDetail:', 'purchaseV2ExportUrl:']);

$line = "        purchaseBySupplierExportUrl: (params) => `/api/reports/purchase/by-supplier\${qs(Object.assign({}, params, { format: 'csv' }))}`,\n";
$patched = jp_replace_exactly_once($source, $line, $line
    . "        purchaseV2Overview: (params) => request('GET', `/reports/purchase-v2/overview\${qs(params)}`),\n"
    . "        purchaseV2Invoices: (params) => request('GET', `/reports/purchase-v2/invoices\${qs(params)}`),\n"
    . "        purchaseV2Items: (params) => request('GET', `/reports/purchase-v2/items\${qs(params)}`),\n"
    . "        purchaseV2InvoiceDetail: (params) => request('GET', `/reports/purchase-v2/invoice-detail\${qs(params)}`),\n"
    . "        purchaseV2ExportUrl: (params) => `/api/reports/purchase-v2/export\${qs(params)}`,\n", 'purchaseBySupplierExportUrl line');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
