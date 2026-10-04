<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Stock IN / OUT V2) — public/assets/js/transaction-history.js
 *
 * History Transaksi → transaction detail drawer: for an OUT that was issued through the Stock OUT
 * sheet (it carries a Delivery Order), add "Cetak DO" / "Cetak Invoice" buttons that re-print the
 * saved documents (selling prices only; the Invoice button needs TRANSACTION_OUT_CREATE or
 * DISTRIBUTION_VIEW). One anchor, exactly once:
 *     if (actionsRow.children.length) body.appendChild(actionsRow);
 * is replaced by the block below + the same line extended with `|| isOutDoc`. Nothing else in the
 * file changes; the extra lookup is a GET and any failure is swallowed (the detail itself is unaffected).
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, it is not already
 * patched, and the anchor is found exactly once. Dry-run by default; --apply backs up
 * (<file>.pre-tx2-backup), writes atomically, re-verifies, records <file>.tx2-patch.json.
 *
 * Usage:
 *   php scripts/patch_tx2_transaction_history_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_tx2_transaction_history_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-tx2-backup');
define('JP_META_SUFFIX', '.tx2-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_tx2_transaction_history_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);
jp_refuse_if_contains($source, ['isOutDoc', '/stock-out/by-transaction']);

$new = <<<'JSCODE'
                    // STOCK OUT V2 — an OUT that was issued through the Stock OUT sheet has a Delivery Order
                    // and an Invoice: offer to re-print them (selling prices only — see StockOutDocumentService).
                    const isOutDoc = d.transaction_type === 'OUT' && !!d.reference_no && typeof TxKit !== 'undefined';
                    if (isOutDoc) {
                        TxKit.api('GET', `/stock-out/by-transaction/${transactionId}`).then((doc) => {
                            if (!doc) return;
                            const doBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', 'data-testid': 'hist-print-do' }, '🖨 Cetak DO');
                            doBtn.addEventListener('click', () => StockOutSheet.openDocuments(doc.do_id, 1, doc));
                            actionsRow.appendChild(doBtn);
                            if (doc.invoice_id && (Auth.hasPermission('TRANSACTION_OUT_CREATE') || Auth.hasPermission('DISTRIBUTION_VIEW'))) {
                                const invBtn = UI.el('button', { class: 'btn btn-secondary btn-sm', 'data-testid': 'hist-print-invoice' }, '🖨 Cetak Invoice');
                                invBtn.addEventListener('click', () => StockOutSheet.openDocuments(doc.do_id, 0, doc));
                                actionsRow.appendChild(invBtn);
                            }
                        }).catch(() => { /* reprint is a convenience; the transaction detail itself is unaffected */ });
                    }
                    if (actionsRow.children.length || isOutDoc) body.appendChild(actionsRow);

JSCODE;

$patched = jp_replace_exactly_once($source,
    "                    if (actionsRow.children.length) body.appendChild(actionsRow);\n",
    $new,
    'actionsRow append line');
jp_finish($opts['path'], $source, $patched, $opts['apply']);
