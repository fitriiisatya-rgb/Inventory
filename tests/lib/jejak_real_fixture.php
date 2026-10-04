<?php
declare(strict_types=1);

/**
 * Shared fixture for the Jejak Stock Opname tests: builds TWO real POSTED
 * sessions through the application's own services, one per counting model,
 * mirroring the two production sessions (SCM-like FINDINGS_V1 and
 * CIBADAK-like LEGACY_DUAL_COUNT):
 *
 *   'legacy'   LEGACY_DUAL_COUNT, driven through start -> assign team ->
 *              submitCount -> recount -> excludeUncounted -> finalize ->
 *              post, so its stock_adjustments are REAL (FIFO cost) and
 *              differ from "variance x session HPP" on the two-batch item.
 *   'findings' FINDINGS_V1, driven through claimItem/submitFinding with a
 *              multi-counter team, a voided finding, an item with no EOD
 *              baseline, and a post-count movement; marked POSTED by
 *              direct UPDATE (Checkpoint B does not exist in this codebase
 *              — same technique as tests/inventory_v2_16_5_*).
 *
 * Every expected number returned in 'expect' is hand-computed here from the
 * fixture inputs, independent of StockOpnameJejakService.
 */

require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/Exceptions.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/UnitConversionService.php';
require_once __DIR__ . '/../../services/UnitNormalizationService.php';
require_once __DIR__ . '/../../services/PriceAnomalyService.php';
require_once __DIR__ . '/../../services/CostNormalizationService.php';
require_once __DIR__ . '/../../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../../services/IdempotencyService.php';
require_once __DIR__ . '/../../services/InventoryService.php';
require_once __DIR__ . '/../../services/FifoService.php';
require_once __DIR__ . '/../../services/PeriodLockService.php';
require_once __DIR__ . '/../../services/WarehouseLockService.php';
require_once __DIR__ . '/../../services/WarehouseGuardService.php';
require_once __DIR__ . '/../../services/StockAdjustmentService.php';
require_once __DIR__ . '/../../services/NumberingService.php';
require_once __DIR__ . '/../../services/StockOpnameService.php';
require_once __DIR__ . '/../../services/StockOpnamePhotoService.php';
require_once __DIR__ . '/../../services/XlsxReaderService.php';
require_once __DIR__ . '/../../services/ExcelWriterService.php';
require_once __DIR__ . '/../../services/StockOpnameReferenceImportService.php';
require_once __DIR__ . '/../../services/StockOpnameBookStockService.php';
require_once __DIR__ . '/../../services/StockOpnameJejakService.php';
require_once __DIR__ . '/../../services/AuthService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\UnitConversionService;
use App\Services\StockOpnameService;
use App\Services\StockOpnamePhotoService;
use App\Services\StockOpnameBookStockService;

function jf_uid(string $p): string { return $p . '-' . bin2hex(random_bytes(3)); }

function jf_user(PDO $pdo, string $tag, int $roleId, ?int $warehouseId = null): array
{
    $u = jf_uid($tag);
    $pass = 'Jf' . bin2hex(random_bytes(4)) . '!1';
    $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role_id, warehouse_id, is_active) VALUES (:u,:h,:n,:r,:w,1)')
        ->execute(['u' => $u, 'h' => password_hash($pass, PASSWORD_BCRYPT), 'n' => $u, 'r' => $roleId, 'w' => $warehouseId]);
    return ['id' => (int) $pdo->lastInsertId(), 'username' => $u, 'password' => $pass];
}

function jf_item(PDO $pdo, int $unitId, string $tag, int $categoryId): array
{
    $sku = jf_uid($tag);
    $pdo->prepare('INSERT INTO items (sku, name, base_unit_id, category_id, minimum_stock, status) VALUES (:sku,:name,:unit,:cat,0,:status)')
        ->execute(['sku' => $sku, 'name' => "Item {$sku}", 'unit' => $unitId, 'cat' => $categoryId, 'status' => 'ACTIVE']);
    $id = (int) $pdo->lastInsertId();
    UnitConversionService::openNewVersion($pdo, $id, $unitId, 1.0, '2020-01-01 00:00:00', null, 'base identity');
    return ['id' => $id, 'sku' => $sku, 'name' => "Item {$sku}"];
}

function jf_in(PDO $pdo, int $itemId, int $unitId, int $whId, float $qty, float $price, int $by, string $date): void
{
    Database::transaction(fn (PDO $tx) => FifoService::postIn($tx, [
        'transaction_uuid' => jf_uid('jf-in'), 'item_id' => $itemId, 'warehouse_id' => $whId,
        'input_qty' => $qty, 'input_unit_id' => $unitId, 'unit_price_input' => $price,
        'transaction_date' => $date, 'created_by' => $by, 'username' => 'jf', 'transaction_type' => 'OPENING',
    ]));
}

function jf_photo(): string
{
    $img = imagecreatetruecolor(6, 6);
    imagefill($img, 0, 0, imagecolorallocate($img, 40, 90, 200));
    $path = tempnam(sys_get_temp_dir(), 'jfphoto') . '.jpg';
    imagejpeg($img, $path, 90);
    imagedestroy($img);
    return $path;
}

/** @param array<string,float> $conditionQtys e.g. ['DAMAGED'=>5.0] */
function jf_finding(PDO $pdo, int $sessionId, string $role, int $itemId, int $userId, int $unitId, float $good, array $conditionQtys, string $countedAt, ?string $notes = null): void
{
    $claim = Database::transaction(fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, $role, $userId, $itemId));
    $conditions = [
        'GOOD' => [['unit_id' => $unitId, 'qty' => $good]],
        'DAMAGED' => [['unit_id' => $unitId, 'qty' => 0]], 'EXPIRED' => [['unit_id' => $unitId, 'qty' => 0]], 'DEADSTOCK' => [['unit_id' => $unitId, 'qty' => 0]],
    ];
    $photos = ['DAMAGED' => [], 'EXPIRED' => [], 'DEADSTOCK' => []];
    foreach ($conditionQtys as $type => $qty) {
        $conditions[$type] = [['unit_id' => $unitId, 'qty' => $qty]];
        $upload = StockOpnamePhotoService::upload($pdo, $sessionId, $role, $itemId, $type, $userId, $claim['claim_token'], jf_photo());
        $photos[$type] = [$upload['token']];
    }
    Database::transaction(fn (PDO $tx) => StockOpnameService::submitFinding($tx, $sessionId, $role, $itemId, $conditions, $notes, $userId, $claim['claim_token'], $photos, $countedAt));
}

function jf_csv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'jfbase_') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
    return $path;
}

/**
 * @return array{legacy:array<string,mixed>, findings:array<string,mixed>, admin:array<string,mixed>, viewer:array<string,mixed>, outsider:array<string,mixed>}
 */
function jejak_build_fixture(PDO $pdo): array
{
    $superRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='SUPERADMIN'")->fetchColumn();
    $viewerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='VIEWER'")->fetchColumn();
    $stockRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='STOCK'")->fetchColumn();
    $kg = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

    $tag = bin2hex(random_bytes(2));
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'Gudang CIBADAK (fixture)', 1)")->execute(['c' => "JFL{$tag}"]);
    $whL = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'Gudang SCM (fixture)', 1)")->execute(['c' => "JFF{$tag}"]);
    $whF = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO warehouses (code, name, is_active) VALUES (:c, 'Gudang Lain (fixture)', 1)")->execute(['c' => "JFO{$tag}"]);
    $whOther = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => jf_uid('JF-CA'), 'n' => 'JF Roti']);
    $catA = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, 1)')->execute(['c' => jf_uid('JF-CB'), 'n' => 'JF Bahan']);
    $catB = (int) $pdo->lastInsertId();

    $admin = jf_user($pdo, 'jfadmin', $superRoleId);
    $viewer = jf_user($pdo, 'jfviewer', $viewerRoleId);
    $outsider = jf_user($pdo, 'jfstockother', $stockRoleId, $whOther);
    $userA = jf_user($pdo, 'jfA', $superRoleId);
    $userB = jf_user($pdo, 'jfB', $superRoleId);
    $userC = jf_user($pdo, 'jfC', $superRoleId);
    $userD = jf_user($pdo, 'jfD', $superRoleId);

    // =========================================================== LEGACY
    $L1 = jf_item($pdo, $kg, 'JFL1', $catA); // two batches 50@1000 + 50@1400 (avg 1200), counted 95 -> -5, rusak 5
    $L2 = jf_item($pdo, $kg, 'JFL2', $catA); // 50@2000 counted 55 -> +5
    $L3 = jf_item($pdo, $kg, 'JFL3', $catB); // 30@500 counted 30, deadstock 30, variance 0
    $L4 = jf_item($pdo, $kg, 'JFL4', $catB); // 20@1500, P1 18 / P2 22 -> recount 19 -> -1
    $L5 = jf_item($pdo, $kg, 'JFL5', $catB); // 7@100 never counted -> excluded
    $L6 = jf_item($pdo, $kg, 'JFL6', $catB); // 10@100 counted 10/10 (P1 userB, P2 userD)
    jf_in($pdo, $L1['id'], $kg, $whL, 50, 1000, $admin['id'], '2026-01-01 08:00:00');
    jf_in($pdo, $L1['id'], $kg, $whL, 50, 1400, $admin['id'], '2026-01-02 08:00:00');
    jf_in($pdo, $L2['id'], $kg, $whL, 50, 2000, $admin['id'], '2026-01-01 08:00:00');
    jf_in($pdo, $L3['id'], $kg, $whL, 30, 500, $admin['id'], '2026-01-01 08:00:00');
    jf_in($pdo, $L4['id'], $kg, $whL, 20, 1500, $admin['id'], '2026-01-01 08:00:00');
    jf_in($pdo, $L5['id'], $kg, $whL, 7, 100, $admin['id'], '2026-01-01 08:00:00');
    jf_in($pdo, $L6['id'], $kg, $whL, 10, 100, $admin['id'], '2026-01-01 08:00:00');

    $sidL = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whL, $admin['id'], [$L1['id'], $L2['id'], $L3['id'], $L4['id'], $L5['id'], $L6['id']], 'LEGACY_DUAL_COUNT'));
    Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sidL, 'p1', [$userA['id'], $userB['id']], $admin['id']));
    Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sidL, 'p2', [$userC['id'], $userD['id']], $admin['id']));
    $pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-30', 'id' => $sidL]);

    $sc = static fn (string $role, array $item, float $q, int $uid, array $cond = []) =>
        Database::transaction(fn (PDO $tx) => StockOpnameService::submitCount($tx, $sidL, $role, $item['id'], $q, $uid, $cond));
    $sc('p1', $L1, 95.0, $userA['id'], ['rusak_qty' => 5.0, 'expired_qty' => 0, 'deadstock_qty' => 0, 'notes' => 'pecah saat dus jatuh']);
    $sc('p2', $L1, 95.0, $userC['id'], ['rusak_qty' => 5.0, 'expired_qty' => 0, 'deadstock_qty' => 0]);
    $sc('p1', $L2, 55.0, $userA['id']);
    $sc('p2', $L2, 55.0, $userC['id']);
    $sc('p1', $L3, 30.0, $userA['id'], ['rusak_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 30.0, 'notes' => 'tidak laku 6 bulan']);
    $sc('p2', $L3, 30.0, $userC['id'], ['rusak_qty' => 0, 'expired_qty' => 0, 'deadstock_qty' => 30.0]);
    $sc('p1', $L4, 18.0, $userB['id']);
    $sc('p2', $L4, 22.0, $userC['id']);
    Database::transaction(fn (PDO $tx) => StockOpnameService::recount($tx, $sidL, $L4['id'], 19.0, 'supervisor recount', $admin['id'], ['final_rusak_qty' => 0, 'final_expired_qty' => 0, 'final_deadstock_qty' => 0]));
    $sc('p1', $L6, 10.0, $userB['id']);
    $sc('p2', $L6, 10.0, $userD['id']);
    Database::transaction(fn (PDO $tx) => StockOpnameService::excludeUncounted($tx, $sidL, $L5['id'], 'fixture — barang tidak ditemukan', $admin['id']));
    Database::transaction(fn (PDO $tx) => StockOpnameService::finalize($tx, $sidL, $admin['id']));
    Database::transaction(fn (PDO $tx) => StockOpnameService::post($tx, $sidL, $admin['id']));

    // ========================================================= FINDINGS_V1
    $F = [];
    foreach (['pos', 'neg', 'zero', 'excl', 'rusak', 'dead', 'mix', 'move', 'nobase'] as $k) {
        $F[$k] = jf_item($pdo, $kg, 'JFF' . strtoupper($k), $k === 'rusak' || $k === 'dead' || $k === 'mix' || $k === 'move' ? $catB : $catA);
    }
    $prices = ['pos' => 100, 'neg' => 1000, 'zero' => 500, 'excl' => 200, 'rusak' => 300, 'dead' => 600, 'mix' => 700, 'move' => 800, 'nobase' => 250];
    foreach ($F as $k => $item) {
        jf_in($pdo, $item['id'], $kg, $whF, 999, (float) $prices[$k], $admin['id'], '2026-09-01 08:00:00');
    }
    $sidF = Database::transaction(fn (PDO $tx) => StockOpnameService::start($tx, $whF, $admin['id'], array_column($F, 'id'), 'FINDINGS_V1'));
    $pdo->prepare('UPDATE stock_opname_sessions SET session_date = :d WHERE id = :id')->execute(['d' => '2026-09-30', 'id' => $sidF]);
    $f1a = jf_user($pdo, 'jfF1a', $superRoleId);
    $f1b = jf_user($pdo, 'jfF1b', $superRoleId);
    $f1c = jf_user($pdo, 'jfF1c', $superRoleId); // only ever writes a VOIDED finding
    $f2a = jf_user($pdo, 'jfF2a', $superRoleId);
    Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sidF, 'p1', [$f1a['id'], $f1b['id'], $f1c['id']], $admin['id']));
    Database::transaction(fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sidF, 'p2', [$f2a['id']], $admin['id']));

    $base = [['Kode Barang', 'Nama Barang', 'Satuan', 'Stok Akhir']];
    $book = ['pos' => 50, 'neg' => 100, 'zero' => 30, 'excl' => 10, 'rusak' => 40, 'dead' => 15, 'mix' => 100, 'move' => 100];
    foreach ($book as $k => $q) {
        $base[] = [$F[$k]['sku'], $F[$k]['name'], 'KG', (string) $q];
    }
    $coverage = ['inout_through' => '2026-09-29 23:59:59', 'scaling_through' => '2026-09-29 23:59:59', 'adjustment_through' => '2026-09-29 23:59:59'];
    Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $sidF, jf_csv($base), 'baseline.csv', $admin['id'], $coverage));

    $at = '2026-09-29 12:00:00';
    // pos: a mistaken P1 finding by f1c (voided below), then f1b's real one (55).
    jf_finding($pdo, $sidF, 'p1', $F['pos']['id'], $f1c['id'], $kg, 3.0, [], $at);
    $voidId = (int) $pdo->query("SELECT id FROM stock_opname_findings WHERE session_id = {$sidF} AND counter_user_id = {$f1c['id']}")->fetchColumn();
    Database::transaction(fn (PDO $tx) => StockOpnameService::voidFinding($tx, $sidF, $voidId, 'salah input', $admin['id']));
    jf_finding($pdo, $sidF, 'p1', $F['pos']['id'], $f1b['id'], $kg, 55.0, [], $at);
    jf_finding($pdo, $sidF, 'p1', $F['neg']['id'], $f1a['id'], $kg, 95.0, [], $at);
    jf_finding($pdo, $sidF, 'p1', $F['zero']['id'], $f1b['id'], $kg, 30.0, [], $at);
    jf_finding($pdo, $sidF, 'p1', $F['nobase']['id'], $f1a['id'], $kg, 12.0, [], $at);
    // two-team agreeing findings (resolve counted_qty_base / final_* conditions)
    jf_finding($pdo, $sidF, 'p1', $F['rusak']['id'], $f1a['id'], $kg, 38.0, ['DAMAGED' => 2.0], $at, 'kemasan sobek');
    jf_finding($pdo, $sidF, 'p2', $F['rusak']['id'], $f2a['id'], $kg, 38.0, ['DAMAGED' => 2.0], $at);
    jf_finding($pdo, $sidF, 'p1', $F['dead']['id'], $f1b['id'], $kg, 10.0, ['DEADSTOCK' => 5.0], $at);
    jf_finding($pdo, $sidF, 'p2', $F['dead']['id'], $f2a['id'], $kg, 10.0, ['DEADSTOCK' => 5.0], $at);
    jf_finding($pdo, $sidF, 'p1', $F['mix']['id'], $f1a['id'], $kg, 90.0, ['DAMAGED' => 5.0, 'EXPIRED' => 3.0, 'DEADSTOCK' => 2.0], $at);
    jf_finding($pdo, $sidF, 'p2', $F['mix']['id'], $f2a['id'], $kg, 90.0, ['DAMAGED' => 5.0, 'EXPIRED' => 3.0, 'DEADSTOCK' => 2.0], $at);
    jf_finding($pdo, $sidF, 'p1', $F['move']['id'], $f1b['id'], $kg, 90.0, ['DAMAGED' => 10.0], '2026-09-30 10:00:00');
    jf_finding($pdo, $sidF, 'p2', $F['move']['id'], $f2a['id'], $kg, 90.0, ['DAMAGED' => 10.0], '2026-09-30 10:00:00');
    Database::transaction(fn (PDO $tx) => StockOpnameBookStockService::recordSingleMovement($tx, $sidF, $F['move']['id'], 'IN', 5.0, '2026-09-30 15:00:00', null, 'fixture post-count movement', $admin['id']));
    Database::transaction(fn (PDO $tx) => StockOpnameService::excludeUncounted($tx, $sidF, $F['excl']['id'], 'fixture — tidak dihitung', $admin['id']));

    $pdo->prepare("UPDATE stock_opname_sessions SET status = 'POSTED', finalized_by = :by, finalized_at = '2026-10-01 08:00:00', posted_by = :by2, posted_at = '2026-10-01 09:00:00' WHERE id = :id")
        ->execute(['by' => $admin['id'], 'by2' => $admin['id'], 'id' => $sidF]);

    // ============================== hand-computed expectations (independent)
    $expect = [
        'legacy' => [
            // hpp = avg cost at session start; system 100 @ (50*1000+50*1400)/100
            'hpp' => ['L1' => 1200.0, 'L2' => 2000.0, 'L3' => 500.0, 'L4' => 1500.0, 'L5' => 100.0, 'L6' => 100.0],
            'system' => ['L1' => 100.0, 'L2' => 50.0, 'L3' => 30.0, 'L4' => 20.0, 'L5' => 7.0, 'L6' => 10.0],
            'final' => ['L1' => 95.0, 'L2' => 55.0, 'L3' => 30.0, 'L4' => 19.0, 'L5' => null, 'L6' => 10.0],
            'variance' => ['L1' => -5.0, 'L2' => 5.0, 'L3' => 0.0, 'L4' => -1.0, 'L5' => null, 'L6' => 0.0],
            'nilai_stok_sistem' => 100 * 1200 + 50 * 2000 + 30 * 500 + 20 * 1500 + 7 * 100 + 10 * 100,           // 266.700
            'nilai_final_count' => 95 * 1200 + 55 * 2000 + 30 * 500 + 19 * 1500 + 10 * 100,                       // 268.500 (L5 excluded: no final)
            'selisih_nominal' => -5 * 1200 + 5 * 2000 + -1 * 1500,                                                // 2.500
            'dead_stock' => 30 * 500,                                                                              // 15.000
            'rusak' => 5 * 1200,                                                                                   // 6.000
            // real posted adjustments: L1 OUT 5 from the OLDEST batch (1000), L2 IN 5 @ last cost 2000, L4 OUT 1 @ 1500
            'adjustment_bersih' => -5 * 1000 + 5 * 2000 + -1 * 1500,                                               // 3.500
        ],
        'findings' => [
            'hpp' => ['pos' => 100.0, 'neg' => 1000.0, 'zero' => 500.0, 'excl' => 200.0, 'rusak' => 300.0, 'dead' => 600.0, 'mix' => 700.0, 'move' => 800.0, 'nobase' => 250.0],
            // move: book 100 + the eligible post-count IN (+5, recorded before the EOD cutoff) = 105
            'system' => ['pos' => 50.0, 'neg' => 100.0, 'zero' => 30.0, 'excl' => 10.0, 'rusak' => 40.0, 'dead' => 15.0, 'mix' => 100.0, 'move' => 105.0, 'nobase' => null],
            'final' => ['pos' => 55.0, 'neg' => 95.0, 'zero' => 30.0, 'excl' => null, 'rusak' => 38.0, 'dead' => 10.0, 'mix' => 90.0, 'move' => 95.0, 'nobase' => 12.0],
            'variance' => ['pos' => 5.0, 'neg' => -5.0, 'zero' => 0.0, 'excl' => null, 'rusak' => -2.0, 'dead' => -5.0, 'mix' => -10.0, 'move' => -10.0, 'nobase' => null],
            'nilai_stok_sistem' => 50 * 100 + 100 * 1000 + 30 * 500 + 10 * 200 + 40 * 300 + 15 * 600 + 100 * 700 + 105 * 800,
            'nilai_final_count' => 55 * 100 + 95 * 1000 + 30 * 500 + 38 * 300 + 10 * 600 + 90 * 700 + 95 * 800 + 12 * 250, // nobase: counted 12 but no book stock yet
            'selisih_nominal' => 5 * 100 + -5 * 1000 + -2 * 300 + -5 * 600 + -10 * 700 + -10 * 800,
            'dead_stock' => 5 * 600 + 2 * 700,
            'rusak' => 2 * 300 + 5 * 700 + 10 * 800,
            'adjustment_bersih' => 0,
        ],
    ];

    return [
        'admin' => $admin, 'viewer' => $viewer, 'outsider' => $outsider,
        'legacy' => ['session_id' => $sidL, 'warehouse_id' => $whL, 'items' => ['L1' => $L1, 'L2' => $L2, 'L3' => $L3, 'L4' => $L4, 'L5' => $L5, 'L6' => $L6],
            'users' => ['A' => $userA, 'B' => $userB, 'C' => $userC, 'D' => $userD], 'expect' => $expect['legacy']],
        'findings' => ['session_id' => $sidF, 'warehouse_id' => $whF, 'items' => $F,
            'users' => ['f1a' => $f1a, 'f1b' => $f1b, 'f1c' => $f1c, 'f2a' => $f2a], 'expect' => $expect['findings']],
    ];
}
