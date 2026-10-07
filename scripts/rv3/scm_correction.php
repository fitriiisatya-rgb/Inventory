<?php
declare(strict_types=1);

/**
 * SCM OPENING STOCK CORRECTION — "Keterangan 2" of Perbandingan_Stok_SCM_vs_SO is the final business truth (see scm_corr_lib.php).
 *
 *   php scm_correction.php interpret --app-root=<app> --source=<xlsx> --out=<dir>
 *       workbook only (no database read): the interpretation of every Keterangan 2 (types, explicit qty, nominal, condition, mapping) → scm_keterangan2_interpretation.csv
 *   php scm_correction.php preview   --app-root=<app> --source=<xlsx> --out=<dir outside the app> [--overrides=<csv>] [--warehouse-code=SCM] [--package-dir=<package>]
 *       READ ONLY (one READ ONLY transaction, the server is proved to refuse a write first). Corrected SCM opening dataset, FIFO safety, current-stock reconciliation, blockers, the ten
 *       output files and the PREVIEW SHA256. Exit 0 = no blocker · 11 = blocked.
 *   php scm_correction.php verify    --app-root=<app> [--warehouse-code=SCM]       READ ONLY post-write reconciliation (from the audit records of the correction)
 *   php scm_correction.php post      --app-root=<app> --source=<xlsx> --preview-sha=<sha256> --actor=<SUPERADMIN> [--overrides=<csv>] [--yes]
 *       NOT ISSUED YET. Without --yes it repeats the preview and exits 10 "NOT APPLIED". With --yes: ONE transaction, bound to the reviewed preview, refused while any blocker exists,
 *       idempotent (exit 12 SCM_OPENING_CORRECTION_ALREADY_POSTED), verified inside the transaction (exit 16 = rolled back).
 *
 * Overrides (--overrides): a CSV a person approved (source_row,field,value,approved_by,note) that settles what the text cannot: item_sku · final_qty_base · final_value · hpp_basis · unit_factor ·
 * condition · accept_revaluation. Rows without approved_by are ignored. The digest is part of the preview SHA256.
 * Exit codes: 0 ok · 1 verification failed · 2 usage · 3 abort · 10 NOT APPLIED · 11 blocked · 12 already posted · 13 preview mismatch · 14 invalid actor · 16 post-verify failed.
 * It never edits Stock Opname sessions / inputs / timestamps, historical transactions, original audit rows, FIFO layers that were consumed, or master data.
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/kt_opening_lib.php';
require_once __DIR__ . '/kt_resolve_lib.php';
require_once __DIR__ . '/scm_corr_lib.php';

$payload = rv3_bootstrap_args($argv);
$mode = isset($argv[1]) && !str_starts_with($argv[1], '--') ? $argv[1] : '';
$opt = ['mode' => null, 'app-root' => null, 'source' => null, 'out' => null, 'overrides' => null, 'warehouse-code' => SC_WAREHOUSE_CODE, 'preview-sha' => null, 'actor' => null];
$yes = false;
foreach (array_slice($argv, $mode === '' ? 1 : 2) as $a) {
    if ($a === '--yes') {
        $yes = true;
    } elseif (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m) && array_key_exists($m[1], $opt)) {
        $opt[$m[1]] = $m[2];
    } else {
        fwrite(STDERR, "unknown argument: {$a}\n");
        rv3_script_exit(2);
    }
}
if ($mode === '' && $opt['mode'] !== null) {
    $mode = (string) $opt['mode'];
}
if (!in_array($mode, ['interpret', 'preview', 'verify', 'post'], true) || $opt['app-root'] === null || !is_dir($opt['app-root'] . '/services') || ($mode !== 'verify' && $opt['source'] === null) || (in_array($mode, ['interpret', 'preview'], true) && $opt['out'] === null)) {
    fwrite(STDERR, "usage: php scm_correction.php interpret|preview|verify|post --app-root=<dir with services/> --source=<Perbandingan xlsx> --out=<dir> [--overrides=<csv>] [--package-dir=<package>] [--preview-sha=… --actor=… --yes]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\XlsxReaderService', 'App\\Services\\StockAdjustmentService', 'App\\Services\\AuditService', 'App\\Services\\InventoryEffectiveDateService', 'App\\Services\\InventoryHppReportService']);

use App\Services\Database;

$say = static function (string $l): void {
    echo $l . "\n";
};
$checkOut = static function () use ($opt, $appRoot): void {
    $real = realpath((string) $opt['out']) ?: (string) $opt['out'];
    if (str_starts_with(rtrim($real, '/') . '/', $appRoot . '/')) {
        fwrite(STDERR, "ABORT: --out must be OUTSIDE the application tree ({$appRoot}) — a read-only preview never writes into it.\n");
        rv3_script_exit(3);
    }
};
try {
    $wb = $opt['source'] !== null ? sc_read_workbook((string) $opt['source']) : null;
    $ov = sc_load_overrides($opt['overrides']);
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    rv3_script_exit(3);
}

if ($mode === 'interpret') {
    $checkOut();
    $with = array_filter($wb['rows'], static fn ($r) => $r['ket2'] !== '');
    $say(sprintf('Workbook %s sha256=%s — %d baris Perbandingan, %d dengan Keterangan 2', $wb['file'], $wb['sha256'], count($wb['rows']), count($with)));
    foreach (sc_write_interpretation($wb, (string) $opt['out']) as $f) {
        $say("wrote {$f}");
    }
    rv3_script_exit(0);
}

$pdo = Database::connection();
$readOnly = static function (callable $fn) use ($pdo) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION');
    try {
        $pdo->exec('UPDATE items SET id = id WHERE 1 = 0');
        $pdo->exec('ROLLBACK');
        fwrite(STDERR, "ABORT: the connection accepted a write inside the READ ONLY transaction — refusing to continue.\n");
        rv3_script_exit(3);
    } catch (PDOException $e) {
        // expected: the server refused the write
    }
    try {
        return $fn();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    }
};

try {
    if ($mode === 'verify') {
        $checks = $readOnly(static fn () => sc_verify($pdo, (string) $opt['warehouse-code']));
        $fail = 0;
        foreach ($checks as [$name, $ok, $detail]) {
            $say(($ok ? 'PASS' : 'FAIL') . " - {$name}  [{$detail}]");
            $fail += $ok ? 0 : 1;
        }
        $say($fail === 0 ? 'VERIFY OK' : "VERIFY FAILED ({$fail})");
        rv3_script_exit($fail === 0 ? 0 : 1);
    }
    if ($mode === 'preview' || ($mode === 'post' && !$yes)) {
        $plan = $readOnly(static fn () => sc_plan($pdo, $wb, $ov, (string) $opt['warehouse-code']));
        foreach (sc_report_lines($plan) as $l) {
            $say($l);
        }
        if ($opt['out'] !== null) {
            $checkOut();
            foreach (sc_write_outputs($plan, $wb, (string) $opt['out']) as $f) {
                $say("wrote {$f}");
            }
        }
        if ($mode === 'post') {
            $say('');
            $say('NOT APPLIED — post was started without --yes; nothing was written.');
            rv3_script_exit(10);
        }
        rv3_script_exit($plan['blocked'] ? 11 : 0);
    }
    // post --yes
    if ($opt['preview-sha'] === null || $opt['actor'] === null) {
        fwrite(STDERR, "post needs --preview-sha=<sha256 printed by preview> and --actor=<SUPERADMIN username>\n");
        rv3_script_exit(2);
    }
    $plan = $readOnly(static fn () => sc_plan($pdo, $wb, $ov, (string) $opt['warehouse-code']));
    $res = sc_post($pdo, $plan, (string) $opt['actor'], (string) $opt['preview-sha']);
    $say(sprintf('POSTED %d item correction(s), %d transaction(s), Δ value Rp %s, reference %s, effective %s (transaction date %s)', $res['items'], $res['transactions'], number_format((float) $res['delta_value'], 4, '.', ''), SC_REFERENCE, SC_EFFECTIVE_DATE, SC_TX_INSTANT));
    $say('Now run: scm_correction.php verify …');
    rv3_script_exit(0);
} catch (ScException $e) {
    fwrite(STDERR, "{$e->codeName}: {$e->getMessage()}\n");
    rv3_script_exit($e->exitCode);
}
