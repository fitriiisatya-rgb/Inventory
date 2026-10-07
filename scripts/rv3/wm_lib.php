<?php
declare(strict_types=1);

/**
 * WAREHOUSE MODEL (SCM = MAIN · CIBADAK = TRANSIT · KARANG_TENGAH = TRANSIT) — library for scripts/rv3/warehouse_model.php.
 *
 * warehouses.warehouse_type is descriptive metadata (schema: "Purely descriptive metadata — never changes FIFO or transfer behavior"). Before any change this library SCANS THE INSTALLED
 * CODE (services/*.php, public/index.php, public/assets/js/*.js — read only) and lists every place that reads the type; the correction is refused when a reader outside the known
 * display / input list is found. The write itself (one row, one column) proves INSIDE its own transaction that nothing else moved: ledger / FIFO fingerprint, transfers, Stock Opname
 * sessions and the Reports V3 totals of every warehouse + company-wide are identical before and after.
 */

use App\Services\AuditService;
use App\Services\Database;
use App\Services\MovementReportV3Service;

const WM_CONFIRM = 'WAREHOUSE_TYPE_ROLLBACK';
/** code => the type the business says the warehouse has */
const WM_INTENDED = ['SCM' => 'MAIN', 'CIBADAK' => 'TRANSIT', 'KARANG_TENGAH' => 'TRANSIT'];
/** files that READ warehouse_type and are known to be display / master-data input only */
const WM_KNOWN_READERS = [
    'services/MasterRecordService.php' => 'validates the type typed into "Tambah Gudang" (input)',
    'services/ImportSimpleMasterService.php' => 'validates / inserts the type of an imported warehouse row (input)',
    'services/ImportTemplateService.php' => 'import template text (input)',
    'services/WarehouseReportService.php' => 'returns the type as a column of the warehouse report (display)',
    'services/InventoryHppReportService.php' => 'warehouseBreakdown() returns the type with each panel (display label)',
    'public/assets/js/master-warehouses.js' => 'Master Gudang table column / create form (display + input)',
    'public/assets/js/report-hpp.js' => 'HPP panel subtitle text "Stok transit & distribusi" vs "Stok utama bahan baku & material" (display text)',
];

final class WmException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $exitCode = 11)
    {
        parent::__construct($message);
    }
}

/** @return array{readers:list<array{file:string,line:int,text:string,known:?string}>,unknown:list<string>} */
function wm_usage_scan(string $appRoot): array
{
    $files = [];
    foreach (rv3_glob($appRoot . '/services/*.php') as $f) {
        $files[] = $f;
    }
    foreach (rv3_glob($appRoot . '/public/assets/js/*.js') as $f) {
        $files[] = $f;
    }
    if (is_file($appRoot . '/public/index.php')) {
        $files[] = $appRoot . '/public/index.php';
    }
    $readers = [];
    $unknown = [];
    foreach ($files as $f) {
        $rel = ltrim(substr($f, strlen(rtrim($appRoot, '/'))), '/');
        $lines = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $l) {
            $t = trim($l);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
                continue;   // comments are not code
            }
            $hit = str_contains($l, 'warehouse_type') || preg_match('/[\'"]TRANSIT[\'"]/', $l) === 1;
            if (!$hit) {
                continue;
            }
            $known = WM_KNOWN_READERS[$rel] ?? null;
            $readers[] = ['file' => $rel, 'line' => $i + 1, 'text' => mb_substr($t, 0, 140), 'known' => $known];
            if ($known === null) {
                $unknown[] = "{$rel}:" . ($i + 1);
            }
        }
    }
    return ['readers' => $readers, 'unknown' => $unknown];
}

/** @return list<array<string,mixed>> */
function wm_state(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query('SELECT id, code, name, warehouse_type, is_active, activation_locked FROM warehouses ORDER BY id')->fetchAll() as $w) {
        $id = (int) $w['id'];
        $tx = $pdo->query("SELECT COUNT(*), COALESCE(MAX(transaction_date),'—') FROM inventory_transactions WHERE warehouse_id = {$id}")->fetch(PDO::FETCH_NUM);
        $val = $pdo->query("SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches WHERE warehouse_id = {$id}")->fetchColumn();
        $trf = $pdo->query("SELECT COUNT(*) FROM warehouse_transfers WHERE from_warehouse_id = {$id} OR to_warehouse_id = {$id}")->fetchColumn();
        $so = $pdo->query("SELECT COUNT(*) FROM stock_opname_sessions WHERE warehouse_id = {$id}")->fetchColumn();
        $want = WM_INTENDED[$w['code']] ?? null;
        $rows[] = ['id' => $id, 'code' => $w['code'], 'name' => $w['name'], 'type' => $w['warehouse_type'], 'intended' => $want, 'is_active' => (int) $w['is_active'], 'locked' => (int) $w['activation_locked'],
            'ok' => $want === null ? null : $want === $w['warehouse_type'], 'transactions' => (int) $tx[0], 'last_tx' => $tx[1], 'fifo_value' => round((float) $val, 4), 'transfers' => (int) $trf, 'so_sessions' => (int) $so];
    }
    return $rows;
}

/** every number that must be identical before and after a type change */
function wm_fingerprint(PDO $pdo, bool $withReports = true): array
{
    $f = [
        'transactions' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn(),
        'lines' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_transaction_lines')->fetchColumn(),
        'batches' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_batches')->fetchColumn(),
        'qty' => (string) $pdo->query('SELECT COALESCE(SUM(qty_base),0) FROM inventory_batches')->fetchColumn(),
        'value' => (string) $pdo->query('SELECT COALESCE(SUM(qty_base * unit_cost_base),0) FROM inventory_batches')->fetchColumn(),
        'allocations' => (int) $pdo->query('SELECT COUNT(*) FROM fifo_allocations')->fetchColumn(),
        'ledger_digest' => (string) $pdo->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, transaction_type, transaction_date, warehouse_id, status) ORDER BY id)) FROM inventory_transactions")->fetchColumn(),
        'transfers' => (string) $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', id, from_warehouse_id, to_warehouse_id, status) ORDER BY id)),'')) FROM warehouse_transfers")->fetchColumn(),
        'so_sessions' => (string) $pdo->query("SELECT CONCAT(COUNT(*), ':', COALESCE(MD5(GROUP_CONCAT(CONCAT_WS('|', id, warehouse_id, status, session_date) ORDER BY id)),'')) FROM stock_opname_sessions")->fetchColumn(),
    ];
    if ($withReports && class_exists(MovementReportV3Service::class)) {
        $end = date('Y-m-d');
        $start = date('Y-m-01', strtotime('-2 months', strtotime($end)));
        $scopes = ['ALL' => null];
        foreach ($pdo->query('SELECT id, code FROM warehouses ORDER BY id')->fetchAll() as $w) {
            $scopes[$w['code']] = (int) $w['id'];
        }
        $rep = [];
        foreach ($scopes as $label => $id) {
            $t = MovementReportV3Service::overview($pdo, $start, $end, $id)['split_totals'];
            $rep[$label] = array_map(static fn ($k) => round((float) $t[$k], 4), ['opening', 'in', 'out', 'tin', 'tout', 'adjustment', 'closing']);
        }
        $f['reports_v3'] = $rep;
        $f['reports_period'] = "{$start}..{$end}";
    }
    return $f;
}

/** @return array<string,mixed> */
function wm_plan(PDO $pdo, string $appRoot, string $code = 'CIBADAK', string $to = 'TRANSIT'): array
{
    $state = wm_state($pdo);
    $scan = wm_usage_scan($appRoot);
    $target = null;
    foreach ($state as $r) {
        if ($r['code'] === $code) {
            $target = $r;
        }
    }
    $blockers = [];
    if ($target === null) {
        $blockers[] = "gudang {$code} tidak ditemukan (tidak dibuat otomatis)";
    } elseif ($target['type'] === $to) {
        $blockers = [];
    }
    if ($scan['unknown'] !== []) {
        $blockers[] = 'warehouse_type dibaca oleh kode di luar daftar tampilan / input yang dikenal: ' . implode(', ', array_slice($scan['unknown'], 0, 6)) . ' — tinjau dulu';
    }
    $action = $target === null ? 'NONE' : ($target['type'] === $to ? 'ALREADY' : 'CHANGE');
    $plan = ['code' => $code, 'to' => $to, 'warehouse' => $target, 'action' => $action, 'state' => $state, 'scan' => $scan, 'blockers' => $blockers, 'blocked' => $blockers !== []];
    $plan['sha'] = hash('sha256', json_encode([$code, $to, $target['id'] ?? null, $target['type'] ?? null, $scan['unknown']]));
    return $plan;
}

/** @return list<string> */
function wm_lines(array $plan): array
{
    $o = ['=== MODEL GUDANG — CHECK (READ ONLY) ===', 'Model akhir yang dimaksud: SCM = MAIN · CIBADAK = TRANSIT · KARANG_TENGAH = TRANSIT', ''];
    $o[] = sprintf('%-4s %-15s %-26s %-8s %-9s %-4s %-6s %-14s %6s %18s %5s %4s', 'id', 'kode', 'nama', 'tipe', 'seharusnya', 'akt', 'kunci', 'status', 'tx', 'nilai FIFO', 'trf', 'SO');
    foreach ($plan['state'] as $r) {
        $o[] = sprintf('%-4d %-15s %-26s %-8s %-9s %-4d %-6d %-14s %6d %18s %5d %4d', $r['id'], $r['code'], mb_substr($r['name'], 0, 26), $r['type'], $r['intended'] ?? '—', $r['is_active'], $r['locked'],
            $r['ok'] === null ? 'tidak dinilai' : ($r['ok'] ? 'OK' : 'TIDAK SESUAI'), $r['transactions'], number_format($r['fifo_value'], 2, ',', '.'), $r['transfers'], $r['so_sessions']);
    }
    $have = array_column($plan['state'], 'code');
    foreach (array_keys(WM_INTENDED) as $c) {
        if (!in_array($c, $have, true)) {
            $o[] = "PERINGATAN: kode gudang {$c} tidak ada di produksi — periksa tabel di atas; koreksi hanya untuk kode yang cocok (opsi --warehouse-code=<kode CIBADAK>)";
        }
    }
    $o[] = '';
    $o[] = 'PEMBACA warehouse_type DI KODE TERPASANG (dipindai baca-saja; komentar diabaikan):';
    foreach ($plan['scan']['readers'] as $r) {
        $o[] = sprintf('  %-48s :%-5d %-9s %s', $r['file'], $r['line'], $r['known'] === null ? 'TINJAU!' : 'dikenal', $r['known'] ?? $r['text']);
    }
    $o[] = $plan['scan']['unknown'] === [] ? 'KESIMPULAN: tipe gudang hanya dibaca oleh validasi input master dan label tampilan. TIDAK dipakai oleh validasi transaksi, aturan transfer, Stock IN/OUT, Stock Opname, izin, FIFO, atau angka laporan (Movement / Dashboard / Valuasi / HPP).' : 'KESIMPULAN: ADA pembaca di luar daftar yang dikenal — koreksi DITOLAK sampai ditinjau.';
    $o[] = '';
    $t = $plan['warehouse'];
    if ($t === null) {
        $o[] = "KOREKSI: gudang {$plan['code']} tidak ditemukan.";
    } elseif ($plan['action'] === 'ALREADY') {
        $o[] = "KOREKSI: {$plan['code']} sudah bertipe {$plan['to']} — tidak ada yang perlu dilakukan.";
    } else {
        $o[] = "KOREKSI: UPDATE warehouses SET warehouse_type = '{$plan['to']}' WHERE id = {$t['id']} AND warehouse_type = '{$t['type']}'   ({$plan['code']}: {$t['type']} → {$plan['to']}; satu baris, satu kolom)";
        $o[] = 'DAMPAK: Master Gudang menampilkan TRANSIT; panel gudang pada halaman HPP memakai subjudul "Stok transit & distribusi" (bukan "Stok utama bahan baku & material"). Tidak ada perubahan pada stok, ledger, FIFO, transaksi historis, transfer, sesi SO, atau total laporan — dibuktikan di dalam transaksi saat penulisan (sidik jari sebelum == sesudah).';
    }
    $o[] = 'PLAN SHA256 : ' . $plan['sha'];
    $o[] = 'STATUS      : ' . ($plan['blocked'] ? 'DIBLOKIR — ' . implode('; ', $plan['blockers']) : ($plan['action'] === 'CHANGE' ? 'SIAP (belum ada yang ditulis)' : 'tidak ada perubahan'));
    return $o;
}

/** @return array<string,mixed> */
function wm_apply(PDO $pdo, array $plan, string $actorUsername, string $planSha): array
{
    if ($plan['blocked']) {
        throw new WmException('BLOCKED', implode('; ', $plan['blockers']), 11);
    }
    if ($plan['action'] === 'ALREADY') {
        return ['changed' => 0, 'note' => 'already ' . $plan['to']];
    }
    if ($plan['action'] !== 'CHANGE') {
        throw new WmException('NOT_FOUND', "warehouse {$plan['code']} not found", 11);
    }
    if (!hash_equals($plan['sha'], $planSha)) {
        throw new WmException('PLAN_MISMATCH', "plan sha256 mismatch: current {$plan['sha']}, you passed {$planSha} — re-run check and review again", 13);
    }
    $u = $pdo->prepare('SELECT u.id, u.username, u.is_active, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = :u');
    $u->execute(['u' => $actorUsername]);
    $actor = $u->fetch();
    if (!$actor || (int) $actor['is_active'] !== 1 || $actor['role_code'] !== 'SUPERADMIN') {
        throw new WmException('ACTOR_INVALID', '--actor must be an ACTIVE SUPERADMIN user', 14);
    }
    $t = $plan['warehouse'];
    return Database::transaction(function (PDO $tx) use ($plan, $t, $actor) {
        $before = wm_fingerprint($tx);
        $n = $tx->prepare('UPDATE warehouses SET warehouse_type = :to WHERE id = :id AND warehouse_type = :from');
        $n->execute(['to' => $plan['to'], 'id' => $t['id'], 'from' => $t['type']]);
        if ($n->rowCount() !== 1) {
            throw new WmException('ROW_COUNT', 'exactly one warehouse row must change — nothing was changed', 16);
        }
        $after = wm_fingerprint($tx);
        if ($before !== $after) {
            throw new WmException('POST_VERIFY_FAILED', 'the type change moved the ledger / FIFO / transfers / opname / report totals — rolled back: ' . json_encode([$before, $after]), 16);
        }
        AuditService::log($tx, (int) $actor['id'], (string) $actor['username'], 'WAREHOUSE_TYPE_CORRECT', 'warehouses', (int) $t['id'], ['warehouse_type' => $t['type']], ['warehouse_type' => $plan['to'], 'code' => $t['code'], 'fingerprint' => $after], 'warehouse model correction');
        return ['changed' => 1, 'fingerprint' => $after];
    });
}

/** @return list<array{0:string,1:bool,2:string}> */
function wm_verify(PDO $pdo): array
{
    $out = [];
    foreach (wm_state($pdo) as $r) {
        if ($r['ok'] !== null) {
            $out[] = ["{$r['code']} is {$r['intended']}", $r['ok'], "type={$r['type']}"];
        }
    }
    return $out;
}
