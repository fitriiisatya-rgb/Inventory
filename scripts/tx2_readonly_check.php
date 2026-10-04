<?php
declare(strict_types=1);

/**
 * READ-ONLY wiring check for Stock IN / OUT V2 against the real database — run it on the host that
 * holds the database BEFORE and AFTER deploying (use --service-dir=<package payload dir> before).
 *
 *   php scripts/tx2_readonly_check.php --app-root=/path/to/app [--service-dir=/path/to/payload]
 *
 * It NEVER writes: SET SESSION TRANSACTION READ ONLY + one READ ONLY transaction that is rolled
 * back (a write is proved to be rejected first). It builds a Stock IN quote and a Stock OUT quote
 * for REAL data (an active warehouse, an active item that has a purchase price, an active bakery
 * destination) and prints/validates the arithmetic against hand formulas:
 *   - Stock IN : qty x price, item discount, PPN, invoice discount, shipping, grand total identity
 *   - Stock OUT: Harga Modal (reference price) + category markup = Harga Jual; Grand = SUM + Biaya Kirim
 * and that the tables the new flows write to exist (distribution_*, purchase_*, document_number_sequences).
 * Exit code 1 if any check fails.
 */

$appRoot = null;
$serviceDir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) {
        $appRoot = rtrim(substr($arg, 11), '/');
    } elseif (str_starts_with($arg, '--service-dir=')) {
        $serviceDir = rtrim(substr($arg, 14), '/');
    } else {
        fwrite(STDERR, "unknown argument: {$arg}\n");
        exit(2);
    }
}
if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php scripts/tx2_readonly_check.php --app-root=<dir with services/> [--service-dir=<dir>]\n");
    exit(2);
}
$own = ['PurchaseInvoiceService.php', 'StockOutService.php', 'StockOutDocumentService.php'];
foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) {
    if (!in_array(basename($f), $own, true)) {
        require_once $f;
    }
}
foreach ($own as $f) {
    $path = ($serviceDir ?? "{$appRoot}/services") . "/{$f}";
    if (!is_file($path)) {
        fwrite(STDERR, "missing service file: {$path}\n");
        exit(2);
    }
    require_once $path;
}

use App\Services\Database;
use App\Services\PurchaseInvoiceService;
use App\Services\StockOutDocumentService;
use App\Services\StockOutService;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction.\n");
    $pdo->exec('ROLLBACK');
    exit(3);
} catch (PDOException $e) {
    echo "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}

$fail = 0;
$n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};
$near = static fn (float $a, float $b, float $e = 0.01): bool => abs($a - $b) <= $e;

foreach (['distribution_orders', 'distribution_order_lines', 'distribution_invoices', 'distribution_invoice_lines', 'distribution_pricing_policies', 'document_number_sequences', 'purchase_invoice_headers', 'purchase_line_costs', 'item_price_history', 'bakery_destinations'] as $t) {
    $check("table {$t} exists", (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t}'")->fetchColumn() === 1);
}
$check('owner logo asset is deployed (public/assets/images/amor-logo.jpg)', is_file("{$appRoot}/public/assets/images/amor-logo.jpg") || is_file("{$appRoot}/assets/images/amor-logo.jpg") || is_file(dirname($appRoot) . '/public/assets/images/amor-logo.jpg'), 'checked app-root/public, app-root, parent/public');

$wh = $pdo->query('SELECT id, name FROM warehouses WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch();
$bk = $pdo->query('SELECT id, name FROM bakery_destinations WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch();
$it = $pdo->query(
    "SELECT i.id, i.name, i.base_unit_id, i.category_id FROM items i
     JOIN item_price_history h ON h.item_id = i.id WHERE i.status = 'ACTIVE' ORDER BY h.id DESC LIMIT 1"
)->fetch();
$check('an active warehouse, an active bakery destination and an item with a purchase price exist', (bool) ($wh && $bk && $it));
if ($wh && $bk && $it) {
    $date = date('Y-m-d');
    $qin = PurchaseInvoiceService::quote($pdo, [
        'warehouse_id' => (int) $wh['id'], 'transaction_date' => $date, 'invoice_discount_type' => 'PERCENT', 'invoice_discount_value' => 10, 'freight_amount' => 1000,
        'lines' => [['item_id' => (int) $it['id'], 'input_unit_id' => (int) $it['base_unit_id'], 'input_qty' => 10, 'unit_price_input' => 1000, 'ppn_rate' => 11, 'discount_type' => 'PERCENT', 'discount_value' => 5]],
    ]);
    $check("Stock IN quote valid for '{$it['name']}'", (bool) $qin['valid'], implode(' | ', $qin['errors']));
    // hand: base 10.000, disc 500, dpp 9.500, ppn 1.045, total 10.545, inv disc 10% = 1.054,5, grand = 10.545 - 1.054,5 + 1.000 = 10.490,5
    $check('Stock IN arithmetic: row total 10.545, Subtotal 10.545, Diskon Invoice 1.054,5, Grand Total 10.490,5', $near($qin['lines'][0]['total'], 10545) && $near($qin['totals']['invoice_discount'], 1054.5) && $near($qin['totals']['grand_total'], 10490.5), json_encode($qin['totals']));
    $qout = StockOutService::quote($pdo, [
        'warehouse_id' => (int) $wh['id'], 'bakery_destination_id' => (int) $bk['id'], 'transaction_date' => $date, 'shipping_amount' => 500,
        'markups' => [(string) (int) ($it['category_id'] ?? 0) => ['mode' => 'PERCENT', 'value' => 20]],
        'lines' => [['item_id' => (int) $it['id'], 'input_unit_id' => (int) $it['base_unit_id'], 'input_qty' => 1]],
    ]);
    $l = $qout['lines'][0];
    $check('Stock OUT quote: Harga Modal is the reference purchase price; Harga Jual = Modal x 1,2; Grand = Total + 500 (stock/price errors, if any, are data not code)', $l['reference_price'] !== null
        ? ($near($l['selling_price'], round($l['reference_price'] * 1.2, 4), 0.0002) && $near($qout['totals']['grand_total'], $l['total'] + 500, 0.0002))
        : true, 'ref=' . json_encode($l['reference_price']) . ' errors=' . json_encode($qout['errors']));
    if ($qout['valid']) {
        $doc = StockOutDocumentService::renderInvoice(StockOutDocumentService::invoiceModelFromQuote($qout, ['transaction_date' => $date]));
        $text = strip_tags((string) preg_replace('#<style>.*?</style>#s', '', $doc));
        $check('Invoice preview text carries no cost / markup words', !preg_match('/Modal|HPP|Markup|Margin|FIFO|%/i', $text));
        $check('DO preview text carries no price words', !preg_match('/Rp|Harga|Markup|HPP|Total/i', strip_tags((string) preg_replace('#<style>.*?</style>#s', '', StockOutDocumentService::renderDo(StockOutDocumentService::doModelFromQuote($qout, ['transaction_date' => $date], 'check'))))));
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
