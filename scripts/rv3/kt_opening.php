<?php
declare(strict_types=1);

/**
 * Karang Tengah OPENING BALANCE — controlled cutover CLI.
 *
 *   php kt_opening.php preview --app-root=<app> --source=<xlsx> [--out=<dir outside the app>] [--package-dir=<package>] [--warehouse-code=KARANG_TENGAH]
 *       READ ONLY (the whole run is one READ ONLY transaction that is rolled back; MySQL itself rejects a write — proved first). Maps all rows, prints the summary,
 *       the blocker list and the PREVIEW SHA256; --out writes karang_mapping_all_rows.csv / karang_blockers.csv / karang_summary.json.
 *   php kt_opening.php verify  --app-root=<app> --source=<xlsx> [--package-dir=<package>]
 *       READ ONLY post-write reconciliation (ledger + report classification).
 *   php kt_opening.php post    --app-root=<app> --source=<xlsx> --preview-sha=<sha256 of the reviewed preview> --actor=<SUPERADMIN username> [--yes]
 *       WITHOUT --yes it only repeats the preview and exits 10 "NOT APPLIED". With --yes: ONE transaction, refused while a blocker exists, refused when already posted
 *       (OPENING_BALANCE_ALREADY_POSTED, exit 12), refused when the preview changed (exit 13), verified inside the transaction (exit 16 = rolled back).
 *   php kt_opening.php rollback --app-root=<app> --source=<xlsx> [--yes --confirm=KARANG_TENGAH_SO_20261001]
 *       LAST RESORT (the primary rollback is the database backup taken right before the post). Lists exactly what it would remove; with --yes + --confirm it removes ONLY the rows
 *       this reference created, and only while every layer is untouched (no consumption, no later movement, no report-locked period).
 *
 * Exit codes: 0 ok · 1 verification failed · 2 usage · 3 abort · 10 NOT APPLIED · 11 blocked · 12 already posted · 13 preview mismatch · 14 invalid actor · 15 old FifoService · 16 post-verify failed.
 * Never touches Stock Opname sessions, existing FIFO layers, other warehouses, purchases, transfers, or the effective-date table.
 */

require_once __DIR__ . '/rv3_bootstrap.php';
require_once __DIR__ . '/kt_opening_lib.php';

$payload = rv3_bootstrap_args($argv);
// the mode is the first argument (preview|post|verify|rollback) or --mode=<mode> (when the read-only validator runs the script in-process)
$mode = isset($argv[1]) && !str_starts_with($argv[1], '--') ? $argv[1] : '';
$opt = ['mode' => null, 'app-root' => null, 'source' => null, 'out' => null, 'warehouse-code' => KT_WAREHOUSE_CODE, 'preview-sha' => null, 'actor' => null, 'confirm' => null];
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
if (!in_array($mode, ['preview', 'verify', 'post', 'rollback'], true) || $opt['app-root'] === null || !is_dir($opt['app-root'] . '/services') || $opt['source'] === null) {
    fwrite(STDERR, "usage: php kt_opening.php preview|verify|post|rollback --app-root=<dir with services/> --source=<Hasil_SO xlsx> [--out=<dir>] [--package-dir=<package>] [--preview-sha=… --actor=… --yes]\n");
    rv3_script_exit(2);
}
$appRoot = rtrim($opt['app-root'], '/');
rv3_bootstrap_load($appRoot, $payload, ['App\\Services\\Database', 'App\\Services\\FifoService', 'App\\Services\\XlsxReaderService', 'App\\Services\\AuditService']);

use App\Services\AuditService;
use App\Services\Database;

$say = static function (string $l): void {
    echo $l . "\n";
};
try {
    $source = kt_read_source($opt['source']);
} catch (Throwable $e) {
    fwrite(STDERR, 'ABORT: ' . $e->getMessage() . "\n");
    rv3_script_exit(3);
}
$pdo = Database::connection();

/** Runs $fn inside one READ ONLY transaction (rolled back). The proof that a write is refused comes first. */
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

if ($mode === 'preview' || ($mode === 'post' && !$yes)) {
    $plan = $readOnly(static fn () => kt_plan($pdo, $source, (string) $opt['warehouse-code']));
    foreach (kt_report_lines($plan) as $l) {
        $say($l);
    }
    if ($opt['out'] !== null) {
        $real = realpath($opt['out']) ?: $opt['out'];
        if (str_starts_with(rtrim($real, '/') . '/', $appRoot . '/')) {
            fwrite(STDERR, "ABORT: --out must be OUTSIDE the application tree ({$appRoot}) — a read-only report never writes into it.\n");
            rv3_script_exit(3);
        }
        foreach (kt_write_outputs($plan, $opt['out']) as $f) {
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

if ($mode === 'verify') {
    $plan = $readOnly(static fn () => kt_plan($pdo, $source, (string) $opt['warehouse-code']));
    if ($plan['warehouse']['warehouse_id'] === null) {
        fwrite(STDERR, "ABORT: warehouse not found\n");
        rv3_script_exit(3);
    }
    $checks = $readOnly(static fn () => array_merge(kt_verify_ledger($pdo, $plan), kt_verify_reports($pdo, $plan)));
    $fail = 0;
    foreach ($checks as [$name, $ok, $detail]) {
        $say(($ok ? 'PASS' : 'FAIL') . " - {$name}  [{$detail}]");
        $fail += $ok ? 0 : 1;
    }
    $say($fail === 0 ? 'VERIFY OK' : "VERIFY FAILED ({$fail})");
    rv3_script_exit($fail === 0 ? 0 : 1);
}

if ($mode === 'post') {
    if ($opt['preview-sha'] === null || $opt['actor'] === null) {
        fwrite(STDERR, "post needs --preview-sha=<sha256 printed by preview> and --actor=<SUPERADMIN username>\n");
        rv3_script_exit(2);
    }
    $plan = $readOnly(static fn () => kt_plan($pdo, $source, (string) $opt['warehouse-code']));
    try {
        $res = kt_post($pdo, $plan, (string) $opt['actor'], (string) $opt['preview-sha']);
    } catch (KtOpeningException $e) {
        fwrite(STDERR, "{$e->codeName}: {$e->getMessage()}\n");
        rv3_script_exit($e->exitCode);
    }
    $say(sprintf('POSTED %d OPENING lines, value Rp %s, reference %s, effective %s', $res['lines'], kt_fmt($res['value_total'], 4), KT_REFERENCE, KT_EFFECTIVE_DATE));
    $say('Now run: kt_opening.php verify …');
    rv3_script_exit(0);
}

// ---- rollback (last resort)
$plan = $readOnly(static fn () => kt_plan($pdo, $source, (string) $opt['warehouse-code']));
$whId = $plan['warehouse']['warehouse_id'];
if ($whId === null) {
    fwrite(STDERR, "ABORT: warehouse not found\n");
    rv3_script_exit(3);
}
$rows = $readOnly(static function () use ($pdo, $whId) {
    $st = $pdo->prepare("SELECT t.id AS tx, l.id AS line, b.id AS batch, l.item_id, b.qty_base, b.original_qty_base,
                                (SELECT COUNT(*) FROM fifo_allocations a WHERE a.batch_id = b.id) AS alloc
                           FROM inventory_transactions t JOIN inventory_transaction_lines l ON l.transaction_id = t.id LEFT JOIN inventory_batches b ON b.id = l.created_batch_id
                          WHERE t.reference_no = :r AND t.transaction_type = 'OPENING' AND t.warehouse_id = :w");
    $st->execute(['r' => KT_REFERENCE, 'w' => $whId]);
    return $st->fetchAll();
});
$problems = [];
$other = $readOnly(static function () use ($pdo, $whId) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE warehouse_id = :w AND NOT (transaction_type = 'OPENING' AND reference_no = :r)");
    $q->execute(['w' => $whId, 'r' => KT_REFERENCE]);
    return (int) $q->fetchColumn();
});
if ($rows === []) {
    $say('nothing to roll back: no OPENING rows with reference ' . KT_REFERENCE);
    rv3_script_exit(0);
}
if ($other > 0) {
    $problems[] = "{$other} later transaction(s) exist in the warehouse";
}
foreach ($rows as $r) {
    if ($r['batch'] === null || abs((float) $r['qty_base'] - (float) $r['original_qty_base']) > 1e-9 || (int) $r['alloc'] > 0) {
        $problems[] = "layer of item {$r['item_id']} was consumed or is missing";
    }
}
if ($readOnly(static fn () => $plan['warehouse']['period_locked'])) {
    $problems[] = 'the period of the effective date is LOCKED';
}
$say(sprintf('ROLLBACK PLAN: %d transactions / lines / FIFO layers of %s in warehouse id %s (items.locked_at is restored only where this load set it).', count($rows), KT_REFERENCE, $whId));
if ($problems) {
    foreach (array_unique($problems) as $p) {
        $say("BLOCKED: {$p}");
    }
    $say('Restore the database backup taken before the post instead.');
    rv3_script_exit(11);
}
if (!$yes || $opt['confirm'] !== KT_REFERENCE) {
    $say('NOT APPLIED — add  --yes --confirm=' . KT_REFERENCE . '  to execute. Prefer restoring the pre-post database backup.');
    rv3_script_exit(10);
}
$res = Database::transaction(static function (PDO $tx) use ($rows, $whId) {
    $hdr = $tx->prepare("SELECT after_data FROM audit_logs WHERE action_code = 'KARANG_OPENING_POST' AND reason = :r ORDER BY id DESC LIMIT 1");
    $hdr->execute(['r' => KT_REFERENCE]);
    $after = json_decode((string) $hdr->fetchColumn(), true) ?: [];
    $unlock = array_map('intval', $after['items_unlocked_before'] ?? []);
    $txIds = array_map(static fn ($r) => (int) $r['tx'], $rows);
    $lineIds = array_map(static fn ($r) => (int) $r['line'], $rows);
    $batchIds = array_values(array_filter(array_map(static fn ($r) => $r['batch'] === null ? 0 : (int) $r['batch'], $rows)));
    $in = static fn (array $ids) => implode(',', $ids);
    $n = [];
    $n['price_history'] = $tx->exec('DELETE FROM item_price_history WHERE source_transaction_line_id IN (' . $in($lineIds) . ')');
    $n['lines'] = $tx->exec('DELETE FROM inventory_transaction_lines WHERE id IN (' . $in($lineIds) . ')');
    $n['batches'] = $batchIds ? $tx->exec('DELETE FROM inventory_batches WHERE id IN (' . $in($batchIds) . ')') : 0;
    $n['transactions'] = $tx->exec('DELETE FROM inventory_transactions WHERE id IN (' . $in($txIds) . ')');
    $relock = 0;
    foreach ($unlock as $itemId) {
        $c = $tx->prepare('SELECT COUNT(*) FROM inventory_transaction_lines WHERE item_id = :i');
        $c->execute(['i' => $itemId]);
        if ((int) $c->fetchColumn() === 0) {
            $relock += $tx->exec('UPDATE items SET locked_at = NULL WHERE id = ' . $itemId);
        }
    }
    $n['items_unlocked'] = $relock;
    if ($n['transactions'] !== count($txIds) || $n['lines'] !== count($lineIds)) {
        throw new RuntimeException('rollback count mismatch — nothing was changed');
    }
    AuditService::log($tx, null, 'kt_opening_cli', 'KARANG_OPENING_ROLLBACK', 'warehouses', $whId, null, $n, KT_REFERENCE);
    return $n;
});
$say('ROLLED BACK: ' . json_encode($res));
rv3_script_exit(0);
