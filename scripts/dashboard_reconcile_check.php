<?php
declare(strict_types=1);

/**
 * READ-ONLY reconciliation of the Main Dashboard against the real database —
 * run it on the host that holds the database, with the application's normal
 * .env/config, BEFORE and AFTER deploying the Dashboard package.
 *
 *   php scripts/dashboard_reconcile_check.php --app-root=/path/to/app [--service=/path/to/DashboardInventoryService.php] [--periods=today,month]
 *
 * It NEVER writes: the connection is switched to SET SESSION TRANSACTION READ
 * ONLY and the whole run happens inside one READ ONLY transaction that is rolled
 * back at the end — MySQL itself rejects any write attempt (proved first).
 * --service defaults to <app-root>/services/DashboardInventoryService.php
 * (point it at the package copy to reconcile BEFORE deploying the backend).
 *
 * For the whole company and for every active warehouse, for each period:
 *   - the ledger identity  Awal + Pembelian - Keluar +/- Pergerakan lain = Akhir  holds
 *   - when the period ends today, Stok Akhir equals the current batch valuation
 *   - for each of the four cards, the SUM of every drill-down row (all pages) equals the card
 *   - company-wide Awal / Pembelian / Keluar / Akhir equal the SUM over the warehouses
 *     (no double counting). Exit code 1 if any check fails.
 */

$appRoot = null;
$servicePath = null;
$periods = ['today', 'month'];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) {
        $appRoot = rtrim(substr($arg, 11), '/');
    } elseif (str_starts_with($arg, '--service=')) {
        $servicePath = substr($arg, 10);
    } elseif (str_starts_with($arg, '--periods=')) {
        $periods = array_values(array_filter(explode(',', substr($arg, 10))));
    } else {
        fwrite(STDERR, "unknown argument: {$arg}\n");
        exit(2);
    }
}
if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php scripts/dashboard_reconcile_check.php --app-root=<dir with services/> [--service=<file>] [--periods=today,month]\n");
    exit(2);
}
$servicePath ??= "{$appRoot}/services/DashboardInventoryService.php";
if (!is_file($servicePath)) {
    fwrite(STDERR, "service file not found: {$servicePath}\n");
    exit(2);
}
foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) {
    if (basename($f) !== 'DashboardInventoryService.php') {
        require_once $f;
    }
}
require_once $servicePath;

use App\Services\DashboardInventoryService;
use App\Services\Database;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
    $pdo->exec('ROLLBACK');
    exit(3);
} catch (PDOException $e) {
    echo "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}

$failures = 0;
$checks = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures++;
    }
    echo '  ' . ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};
$near = static fn (float $a, float $b, float $eps = 0.02): bool => abs($a - $b) <= $eps;
$rp = static fn (float $v): string => 'Rp ' . number_format($v, 2, ',', '.');

$warehouses = $pdo->query('SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$types = ['opening_stock', 'purchase_in', 'stock_out', 'closing_stock'];

foreach ($periods as $period) {
    echo "\n=== period: {$period} ===\n";
    $scopes = [['id' => null, 'label' => 'SEMUA GUDANG']];
    foreach ($warehouses as $w) {
        $scopes[] = ['id' => (int) $w['id'], 'label' => "{$w['code']} — {$w['name']}"];
    }
    $byScope = [];
    foreach ($scopes as $sc) {
        echo "\n-- {$sc['label']}\n";
        try {
            $ov = DashboardInventoryService::overview($pdo, $sc['id'], $period, null, null);
        } catch (Throwable $e) {
            $check('overview() builds', false, $e->getMessage());
            continue;
        }
        $m = $ov['movement'];
        $byScope[$sc['id'] ?? 'all'] = $m;
        $check('ledger identity holds', (bool) $m['reconciliation']['ok'], 'diff=' . $m['reconciliation']['identity_diff']);
        if ($m['reconciliation']['batch_ok'] !== null) {
            $check('Stok Akhir == current batch valuation', (bool) $m['reconciliation']['batch_ok'], $rp((float) $m['closing_stock']['value']) . ' vs ' . $rp((float) $m['reconciliation']['batch_on_hand']));
        }
        foreach ($types as $t) {
            $sum = 0.0;
            $rows = 0;
            $page = 1;
            do {
                $d = DashboardInventoryService::detail($pdo, $t, $sc['id'], $period, null, null, ['page' => $page, 'per_page' => 100]);
                foreach ($d['rows'] as $r) {
                    $sum += (float) $r['value'];
                    $rows++;
                }
                $pages = (int) $d['pagination']['total_pages'];
                $page++;
            } while ($page <= $pages && $page <= 500);
            $check("{$t}: SUM of {$rows} drill-down rows == card", $near($sum, (float) $m[$t]['value']), $rp($sum) . ' vs ' . $rp((float) $m[$t]['value']));
        }
    }
    if (isset($byScope['all'])) {
        echo "\n-- company-wide == sum of warehouses (no double count)\n";
        foreach ($types as $t) {
            $sum = 0.0;
            foreach ($warehouses as $w) {
                $sum += (float) ($byScope[(int) $w['id']][$t]['value'] ?? 0);
            }
            $check("{$t}: company == sum over warehouses", $near($sum, (float) $byScope['all'][$t]['value']), $rp($sum) . ' vs ' . $rp((float) $byScope['all'][$t]['value']));
        }
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($checks - $failures) . " / {$checks} checks passed" . ($failures ? " — {$failures} FAILED" : '') . "\n";
exit($failures ? 1 : 0);
