<?php
declare(strict_types=1);

/**
 * READ-ONLY prediction of the October opening after the period cutoff AND the Karang Tengah opening (see of_lib.php). One READ ONLY transaction, a write is proved to be rejected first.
 *
 *   php october_forecast.php --app-root=<app> --source=<Hasil_SO xlsx> --sessions=11,12 --cutoff=2026-09-30 [--package-dir=<package>]
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/kt_opening_lib.php';
require_once __DIR__ . '/pc_lib.php';
require_once __DIR__ . '/of_lib.php';

$payload = rv3_bootstrap_args($argv);
$opt = ['app-root' => null, 'source' => null, 'sessions' => '11,12', 'cutoff' => '2026-09-30'];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: {$a}\n");
        rv3_script_exit(2);
    }
}
if ($opt['app-root'] === null || !is_dir($opt['app-root'] . '/services') || $opt['source'] === null) {
    fwrite(STDERR, "usage: php october_forecast.php --app-root=<dir with services/> --source=<xlsx> [--sessions=11,12] [--cutoff=2026-09-30] [--package-dir=<package>]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\InventoryEffectiveDateService', 'App\\Services\\MovementReportV3Service', 'App\\Services\\XlsxReaderService']);

use App\Services\Database;

$sessions = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $opt['sessions'])), static fn (int $i) => $i > 0)));
$pdo = Database::connection();
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION');
try {
    $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
    $pdo->exec('ROLLBACK');
    fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
    rv3_script_exit(3);
} catch (PDOException $e) {
    // expected
}
try {
    $pc = pc_plan($pdo, $sessions, (string) $opt['cutoff']);
    $kt = kt_plan($pdo, kt_read_source((string) $opt['source']));
    $f = of_forecast($pdo, $pc, $kt);
} catch (PcException $e) {
    fwrite(STDERR, "{$e->codeName}: {$e->getMessage()}\n");
    rv3_script_exit($e->exitCode);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
}
foreach (of_lines($f) as $l) {
    echo $l . "\n";
}
if ($pc['blocked'] || $kt['blocked']) {
    echo "\nCATATAN: " . ($pc['blocked'] ? 'period cutoff DIBLOKIR (' . count($pc['blockers']) . ' blocker) ' : '') . ($kt['blocked'] ? 'opening Karang DIBLOKIR (' . count($kt['blockers']) . ' blocker) ' : '') . "— prediksi ini hanya berlaku bila blocker tersebut sudah selesai.\n";
}
$bad = count(array_filter($f['invariants'], static fn ($i) => !$i[1]));
rv3_script_exit($bad === 0 ? 0 : 1);
