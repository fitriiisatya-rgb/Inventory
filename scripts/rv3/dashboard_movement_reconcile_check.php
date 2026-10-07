<?php
declare(strict_types=1);

/**
 * READ-ONLY reconciliation of the dashboard "Ringkasan Pergerakan Stok" against Laporan Pergerakan Stok (Reports v3) on the REAL database.
 *
 *   php dashboard_movement_reconcile_check.php --app-root=/path/to/app [--package-dir=<package>] [--label=BEFORE|AFTER]
 *                                             [--periods=today,month,last7] [--custom=YYYY-MM-DD:YYYY-MM-DD]
 *
 * It NEVER writes: the connection is switched to SET SESSION TRANSACTION READ ONLY and the whole run happens inside one READ ONLY transaction that is rolled back at the end —
 * MySQL itself rejects any write attempt (proved first). Without --package-dir it loads the INSTALLED dashboard service (BEFORE the apply: the old one); with --package-dir the
 * package's payload copy is substituted in memory (AFTER). The reference is always the application's MovementReportV3Service.
 *
 * For the whole company and every active warehouse, for every period (today / month / last 7 days / custom):
 *   - prints the dashboard figures in one normalised line next to the report's (the before / after reconciliation table)
 *   - NEW structure: every component == the report's; opening + IN − OUT + Transfer IN − Transfer OUT + Adjustment = closing; company-wide Transfer IN − OUT = in-transit (0 once
 *     received); Adjustment named by ledger type sums exactly to Adjustment (no plug); no generic "Pergerakan lain"; drill-down rows == cards; company == Σ warehouses
 *   - OLD structure (before the fix): shows what "Pergerakan lain" actually contained (transfers + adjustments + …) and whether opening / IN / OUT / closing already equal the report's
 * Exit code 1 if a check of the NEW structure fails (the OLD structure is informational: it prints "LEGACY").
 */

require_once __DIR__ . '/rv3_bootstrap.php';

$payload = rv3_bootstrap_args($argv);            // --package-dir=<package>: validate the PACKAGE's dashboard service before it is installed (AFTER); without it: the installed one (BEFORE)
$appRoot = null;
$label = 'RUN';
$periods = ['today', 'month', 'last7'];
$custom = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) {
        $appRoot = rtrim(substr($arg, 11), '/');
    } elseif (str_starts_with($arg, '--label=')) {
        $label = substr($arg, 8);
    } elseif (str_starts_with($arg, '--periods=')) {
        $periods = array_values(array_filter(explode(',', substr($arg, 10))));
    } elseif (str_starts_with($arg, '--custom=')) {
        $custom = substr($arg, 9);
    } else {
        fwrite(STDERR, "unknown argument: {$arg}\n");
        rv3_script_exit(2);
    }
}
if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php dashboard_movement_reconcile_check.php --app-root=<dir with services/> [--package-dir=<package>] [--label=BEFORE|AFTER] [--periods=today,month,last7] [--custom=a:b]\n");
    rv3_script_exit(2);
}
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\MovementReportV3Service', 'App\\Services\\DashboardInventoryService']);

use App\Services\DashboardInventoryService;
use App\Services\Database;
use App\Services\MovementReportV3Service;

$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
    $pdo->exec('ROLLBACK');
    rv3_script_exit(3);
} catch (PDOException $e) {
    echo "read-only guard verified: a write attempt is rejected by the server ({$e->getCode()}).\n";
}
echo "dashboard service under test: " . (new ReflectionClass(DashboardInventoryService::class))->getFileName() . "  [{$label}]\n";
echo "reference report service    : " . (new ReflectionClass(MovementReportV3Service::class))->getFileName() . "\n";

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

$today = date('Y-m-d');
$ranges = [];
foreach ($periods as $p) {
    $ranges[] = match ($p) {
        'today' => ['today', null, null, $today, $today],
        'month' => ['month', null, null, date('Y-m-01'), $today],
        'last7' => ['custom', date('Y-m-d', strtotime('-6 days')), $today, date('Y-m-d', strtotime('-6 days')), $today],
        default => null,
    };
}
if ($custom !== null && preg_match('/^(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$/', $custom, $cm)) {
    $ranges[] = ['custom', $cm[1], $cm[2], $cm[1], $cm[2]];
}
$ranges = array_values(array_filter($ranges));

$warehouses = $pdo->query('SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$scopes = [['id' => null, 'label' => 'SEMUA GUDANG']];
foreach ($warehouses as $w) {
    $scopes[] = ['id' => (int) $w['id'], 'label' => "{$w['code']} — {$w['name']}"];
}

foreach ($ranges as [$period, $from, $to, $start, $end]) {
    echo "\n=== period: {$period} ({$start} .. {$end}) ===\n";
    $byScope = [];
    foreach ($scopes as $sc) {
        echo "\n-- {$sc['label']}\n";
        try {
            $ov = DashboardInventoryService::overview($pdo, $sc['id'], $period, $from, $to);
            $ref = MovementReportV3Service::overview($pdo, $ov['period']['start_date'], $ov['period']['end_date'], $sc['id']);
        } catch (Throwable $e) {
            $check('overview() builds', false, $e->getMessage());
            continue;
        }
        $m = $ov['movement'];
        $r = $ref['split_totals'];
        $legacy = array_key_exists('other_movements', $m);
        echo '  REPORT V3   : Awal ' . $rp((float) $r['opening']) . ' | IN ' . $rp((float) $r['in']) . ' | OUT ' . $rp((float) $r['out']) . ' | Transfer IN ' . $rp((float) $r['tin']) . ' | Transfer OUT ' . $rp((float) $r['tout'])
            . ' | Adjustment ' . $rp((float) $r['adjustment']) . ' | Akhir ' . $rp((float) $r['closing']) . ' | selisih ' . $rp((float) $r['difference']) . "\n";
        if ($legacy) {
            $o = $m['other_movements'];
            echo "  DASHBOARD [{$label}] (LEGACY structure): Awal " . $rp((float) $m['opening_stock']['value']) . ' | Pembelian ' . $rp((float) $m['purchase_in']['value']) . ' | Keluar ' . $rp((float) $m['stock_out']['value'])
                . ' | Pergerakan lain ' . $rp((float) $o['net']) . ' | Akhir ' . $rp((float) $m['closing_stock']['value']) . "\n";
            foreach ($o['items'] as $it) {
                if ($it['tx_count'] > 0 || abs((float) $it['value']) > 0.005) {
                    echo '      "Pergerakan lain" contains: ' . $it['label'] . ' = ' . $rp((float) $it['value']) . ' (' . $it['tx_count'] . " transaksi)\n";
                }
            }
            $check('LEGACY: opening / IN / OUT / closing equal the report (informational)', $near((float) $m['opening_stock']['value'], (float) $r['opening']) && $near((float) $m['purchase_in']['value'], (float) $r['in']) && $near((float) $m['stock_out']['value'], (float) $r['out']) && $near((float) $m['closing_stock']['value'], (float) $r['closing']));
            echo '  LEGACY: "Pergerakan lain" ' . $rp((float) $o['net']) . ' = report Transfer IN − OUT + Adjustment ' . $rp((float) $r['tin'] - (float) $r['tout'] + (float) $r['adjustment']) . ($near((float) $o['net'], (float) $r['tin'] - (float) $r['tout'] + (float) $r['adjustment']) ? '  (same ledger, only lumped together)' : '  (DIFFERENT)') . "\n";
            $failures -= 0;
            continue;
        }
        $c = $m['components'];
        $byScope[$sc['id'] ?? 'all'] = $c;
        echo "  DASHBOARD [{$label}]: Awal " . $rp((float) $c['opening']) . ' | Stock IN ' . $rp((float) $c['in']) . ' | Stock OUT ' . $rp((float) $c['out']) . ' | Transfer IN ' . $rp((float) $c['transfer_in']) . ' | Transfer OUT ' . $rp((float) $c['transfer_out'])
            . ' | Adjustment ' . $rp((float) $c['adjustment']) . ' | Akhir ' . $rp((float) $c['closing']) . "\n";
        foreach ($m['adjustment_breakdown']['items'] as $it) {
            echo '      Adjustment = ' . $it['label'] . ' ' . $rp((float) $it['value']) . ' (' . $it['tx_count'] . " transaksi)\n";
        }
        $check('every component == Laporan Pergerakan Stok (Reports v3)', $near((float) $c['opening'], (float) $r['opening']) && $near((float) $c['in'], (float) $r['in']) && $near((float) $c['out'], (float) $r['out']) && $near((float) $c['transfer_in'], (float) $r['tin'])
            && $near((float) $c['transfer_out'], (float) $r['tout']) && $near((float) $c['adjustment'], (float) $r['adjustment']) && $near((float) $c['closing'], (float) $r['closing']));
        $check('opening + IN − OUT + Transfer IN − Transfer OUT + Adjustment = closing', $near((float) $c['opening'] + (float) $c['in'] - (float) $c['out'] + (float) $c['transfer_in'] - (float) $c['transfer_out'] + (float) $c['adjustment'], (float) $c['closing']), 'diff=' . $m['reconciliation']['identity_diff']);
        $check('Adjustment named by ledger type sums exactly to Adjustment (no plug)', $near((float) $m['adjustment_breakdown']['net'], (float) $c['adjustment']), 'explained_diff=' . $m['adjustment_breakdown']['explained_diff']);
        $check('no generic "Pergerakan lain" in the payload', !array_key_exists('other_movements', $m));
        if ($sc['id'] === null) {
            $net = (float) $c['transfer_in'] - (float) $c['transfer_out'];
            echo '  company-wide Transfer IN − Transfer OUT = ' . $rp($net) . ($near($net, 0.0) ? '  (nets to zero)' : '  (value still in transit: a transfer dispatched but not yet received)') . "\n";
            $check('company-wide transfer net is reported honestly (0 or the in-transit value, flagged)', $m['reconciliation']['transfer_net_zero'] === $near($net, 0.0));
        }
        if ($m['reconciliation']['batch_ok'] !== null) {
            $check('Stok Akhir == current batch valuation', (bool) $m['reconciliation']['batch_ok'], $rp((float) $m['closing_stock']['value']) . ' vs ' . $rp((float) $m['reconciliation']['batch_on_hand']));
        }
        $drill = ['opening_stock' => (float) $c['opening'], 'stock_in' => (float) $c['in'], 'stock_out' => (float) $c['out'], 'adjustment' => (float) $c['adjustment'], 'closing_stock' => (float) $c['closing']];
        foreach ($drill as $t => $card) {
            $sum = 0.0;
            $rows = 0;
            $page = 1;
            do {
                $d = DashboardInventoryService::detail($pdo, $t, $sc['id'], $period, $from, $to, ['page' => $page, 'per_page' => 100]);
                foreach ($d['rows'] as $row) {
                    $sum += (float) $row['value'];
                    $rows++;
                }
                $pages = (int) $d['pagination']['total_pages'];
                $page++;
            } while ($page <= $pages && $page <= 500);
            $check("{$t}: SUM of {$rows} drill-down rows == card", $near($sum, $card), $rp($sum) . ' vs ' . $rp($card));
        }
    }
    if (isset($byScope['all'])) {
        echo "\n-- company-wide == sum of warehouses (no double count)\n";
        foreach (['opening', 'in', 'out', 'transfer_in', 'transfer_out', 'adjustment', 'closing'] as $k) {
            $sum = 0.0;
            foreach ($warehouses as $w) {
                $sum += (float) ($byScope[(int) $w['id']][$k] ?? 0);
            }
            $check("{$k}: company == sum over warehouses", $near($sum, (float) $byScope['all'][$k]), $rp($sum) . ' vs ' . $rp((float) $byScope['all'][$k]));
        }
    }
}
$pdo->exec('ROLLBACK');
echo "\n" . ($checks - $failures) . " / {$checks} checks passed" . ($failures ? " — {$failures} FAILED" : '') . "\n";
rv3_script_exit($failures ? 1 : 0);
