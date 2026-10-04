<?php
declare(strict_types=1);

/**
 * PRODUCTION PATCH (Stock IN / OUT V2) — public/index.php (API front controller)
 *
 * Adds three require_once lines (PurchaseInvoiceService, StockOutService,
 * StockOutDocumentService — installed separately) and the routes
 *   POST /stock-in/quote, POST /stock-in,
 *   GET  /stock-out/markup-defaults, POST /stock-out/quote, POST /stock-out/preview/{kind},
 *   POST /stock-out, GET /stock-out/recent, GET /stock-out/by-transaction/{txId},
 *   GET  /stock-out/{id}, GET /stock-out/{id}/print/do, GET /stock-out/{id}/print/invoice.
 * Two anchors, each required exactly once:
 *   1. the require_once of PurchaseCostingGateway.php -> the three new requires follow it;
 *   2. the existing "// PHASE V2.7 — read-only Cost Preview, called by the Transaksi Masuk"
 *      comment that opens GET /transactions/in/cost-preview -> the new routes are inserted
 *      immediately BEFORE that comment block.
 * No existing route, permission check or function is changed. POST /transactions/in and
 * POST /transactions/out stay exactly as they are (API compatible); the new routes use the
 * same inv_require_auth / inv_require_permission / inv_require_warehouse_scope guards.
 * Independent of whether the Dashboard / Jejak packages are applied.
 *
 * FAILS CLOSED: refuses unless the file's CURRENT SHA256 equals --expect-sha256, it is not
 * already patched, and every anchor is found exactly once. Dry-run by default; --apply backs
 * up (<file>.pre-tx2-backup), writes atomically, re-verifies and records
 * <file>.tx2-patch.json for rollback_tx2_production.php.
 *
 * Usage:
 *   php scripts/patch_tx2_index_php_production.php <path> --expect-sha256=<hash>          (dry run)
 *   php scripts/patch_tx2_index_php_production.php <path> --expect-sha256=<hash> --apply
 */

define('JP_BACKUP_SUFFIX', '.pre-tx2-backup');
define('JP_META_SUFFIX', '.tx2-patch.json');
require_once __DIR__ . '/lib/jejak_patch_common.php';

$opts = jp_parse_args($argv, 'php scripts/patch_tx2_index_php_production.php <path> --expect-sha256=<hash> [--apply]');
$source = jp_read($opts['path']);
jp_assert_preimage($source, $opts['expect']);

jp_refuse_if_contains($source, ['PurchaseInvoiceService', 'StockOutService', 'StockOutDocumentService', "'POST /stock-in", "'GET /stock-out"]);

$patched = jp_replace_exactly_once($source,
    "require_once __DIR__ . '/../services/PurchaseCostingGateway.php';\n",
    "require_once __DIR__ . '/../services/PurchaseCostingGateway.php';\n"
    . "require_once __DIR__ . '/../services/PurchaseInvoiceService.php';\n"
    . "require_once __DIR__ . '/../services/StockOutService.php';\n"
    . "require_once __DIR__ . '/../services/StockOutDocumentService.php';\n",
    'require_once PurchaseCostingGateway.php');

$routes = <<<'PHPCODE'
    // STOCK IN V2 — multi-line purchase sheet (per-item PPN + discount,
    // one invoice discount, shipping). `quote` is read-only arithmetic
    // (server = single source of truth for every number shown); `post` runs
    // the unmodified PurchaseCostingGateway + FifoService::postIn() once per
    // item inside ONE database transaction. See PurchaseInvoiceService.
    'POST /stock-in/quote' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $quote = \App\Services\PurchaseInvoiceService::quote($pdo, $input);
        unset($quote['_costing']);
        inv_ok($quote, 'OK');
    },

    'POST /stock-in' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $result = Database::transaction(fn (PDO $tx) => \App\Services\PurchaseInvoiceService::post($tx, $input, (int) $user['id'], (string) $user['username']));
        inv_ok($result, 'Transaction posted');
    },

    // STOCK OUT V2 — table-first issue to a bakery destination that also
    // creates the Delivery Order + Invoice (see StockOutService). quote /
    // markup-defaults / preview are read-only; POST /stock-out posts the real
    // FIFO OUT per item + DO + Invoice atomically. Documents are re-printable.
    'GET /stock-out/markup-defaults' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        $ids = array_filter(explode(',', (string) ($query['category_ids'] ?? '')), 'strlen');
        inv_ok(\App\Services\StockOutService::markupDefaults($pdo, $ids), 'OK');
    },

    'POST /stock-out/quote' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        inv_ok(\App\Services\StockOutService::quote($pdo, $input), 'OK');
    },

    'POST /stock-out/preview/{kind}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        if (!in_array($params['kind'], ['do', 'invoice'], true)) {
            inv_error(404, 'NOT_FOUND', 'unknown preview kind');
        }
        $quote = \App\Services\StockOutService::quote($pdo, $input);
        if (!$quote['valid']) {
            throw new ValidationException($quote['errors']);
        }
        inv_html($params['kind'] === 'do'
            ? \App\Services\StockOutDocumentService::renderDo(\App\Services\StockOutDocumentService::doModelFromQuote($quote, $input, (string) $user['username']))
            : \App\Services\StockOutDocumentService::renderInvoice(\App\Services\StockOutDocumentService::invoiceModelFromQuote($quote, $input)));
    },

    'POST /stock-out' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $result = Database::transaction(fn (PDO $tx) => \App\Services\StockOutService::post($tx, $input, (int) $user['id'], (string) $user['username']));
        inv_ok($result, 'Transaction posted');
    },

    'GET /stock-out/recent' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $scope = ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null) ? (int) $user['warehouse_id'] : null;
        inv_ok(\App\Services\StockOutService::recent($pdo, $scope, (int) ($query['limit'] ?? 30)), 'OK');
    },

    'GET /stock-out/by-transaction/{txId}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $s = \App\Services\StockOutService::byTransaction($pdo, (int) $params['txId']);
        if ($s !== null) { inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']); }
        inv_ok($s, 'OK');
    },

    'GET /stock-out/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $s = \App\Services\StockOutService::summary($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']);
        inv_ok($s, 'OK');
    },

    // DO: any inventory viewer in scope. Invoice (selling prices): only roles that may create a Stock OUT or view Distribusi.
    'GET /stock-out/{id}/print/do' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $s = \App\Services\StockOutService::summary($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']);
        inv_html(\App\Services\StockOutDocumentService::renderDo(\App\Services\StockOutDocumentService::doModel($pdo, (int) $params['id'])));
    },

    'GET /stock-out/{id}/print/invoice' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if (!AuthService::hasPermission($pdo, $user['role_code'], 'TRANSACTION_OUT_CREATE') && !AuthService::hasPermission($pdo, $user['role_code'], 'DISTRIBUTION_VIEW')) {
            inv_error(403, 'FORBIDDEN', 'Invoice hanya dapat dilihat oleh pengguna Stock OUT / Distribusi.');
        }
        $s = \App\Services\StockOutService::summary($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']);
        if (empty($s['invoice_id'])) {
            inv_error(404, 'NOT_FOUND', 'delivery order has no invoice');
        }
        inv_html(\App\Services\StockOutDocumentService::renderInvoice(\App\Services\StockOutDocumentService::invoiceModel($pdo, (int) $s['invoice_id'])));
    },


PHPCODE;

$routeAnchor = "    // PHASE V2.7 — read-only Cost Preview, called by the Transaksi Masuk\n";
if (substr_count($patched, $routeAnchor) !== 1) {
    jp_fail("anchor 'PHASE V2.7 read-only Cost Preview' comment matched " . substr_count($patched, $routeAnchor) . ' times (expected exactly 1) — nothing was written.');
}
echo "OK — anchor 'PHASE V2.7 read-only Cost Preview' comment found exactly once.\n";
$patched = str_replace($routeAnchor, $routes . $routeAnchor, $patched);

jp_finish($opts['path'], $source, $patched, $opts['apply']);
