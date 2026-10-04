<?php
declare(strict_types=1);

/**
 * Master Data "Tambah ..." (create) — backend, through the real HTTP API on a real MariaDB.
 *
 *   A. POST /items      — row + identity conversion + purchase conversion + Harga Beli (item_price_history) in one
 *                         transaction, audit rows, validation (sku unique/required, category/supplier/unit must exist,
 *                         inactive refused, conversion pair, negative price), supplier is never auto-created,
 *                         failed creation leaves NOTHING behind (rollback), a freshly created item can be posted by Stock IN's engine
 *   B. POST /warehouses — code/name/type/status, duplicate code, type rule, activation_locked stays 0, audit
 *   C. POST /divisions  — code/name/status, duplicate code
 *   D. POST /suppliers, /bakery-destinations, /categories (existing routes) — new optional is_active, length/format
 *                         guards, category duplicate NAME refused; created active bakery is selectable by Stock OUT's engine
 *   E. permissions      — VIEWER and STOCK are refused on every create (403) even with a valid body; no row is written
 *
 * Usage: php tests/master_data_create_test.php
 */

require_once __DIR__ . '/lib/dashboard_fixture.php';

use App\Services\Database;

$results = [];
function check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    $results[] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

$pdo = Database::connection();
$fx = dashboard_build_fixture($pdo);

$port = 8900 + random_int(4000, 4400);
$proc = proc_open(sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../public')), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', sys_get_temp_dir() . '/md_create_server.log', 'w']], $pipes, __DIR__ . '/..');
$base = "http://127.0.0.1:{$port}/api";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100_000);
    $ch = curl_init("{$base}/auth/me");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
    $ready = curl_exec($ch) !== false;
    curl_close($ch);
}
function http(string $method, string $url, ?array $body = null, ?string $jar = null, ?string $csrf = null): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($csrf) { $h[] = "X-CSRF-Token: {$csrf}"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h]);
    if ($jar) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $st, 'body' => json_decode((string) $raw, true) ?: []];
}
function login(string $base, array $c): array
{
    $jar = tempnam(sys_get_temp_dir(), 'md_');
    $r = http('POST', "{$base}/auth/login", ['username' => $c['username'], 'password' => $c['password']], $jar);
    return ['jar' => $jar, 'csrf' => $r['body']['data']['csrf_token'] ?? '', 'status' => $r['status']];
}
$post = static fn (array $s, string $path, array $b): array => http('POST', "{$GLOBALS['base']}{$path}", $b, $s['jar'], $s['csrf']);
$one = static fn (string $sql, array $args = []) => (function () use ($sql, $args) { $st = $GLOBALS['pdo']->prepare($sql); $st->execute($args); return $st->fetchColumn(); })();
$msg = static fn (array $r): string => (string) ($r['body']['error']['message'] ?? '');

try {
    check('server ready', $ready);
    $admin = login($base, $fx['admin']);
    $viewer = login($base, $fx['viewer']);
    $stockA = login($base, $fx['stockA']);
    check('logins', $admin['status'] === 200 && $viewer['status'] === 200 && $stockA['status'] === 200);

    $catId = (int) $pdo->query("SELECT id FROM categories WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $supId = (int) $pdo->query("SELECT id FROM suppliers WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($supId === 0) {
        $r = $post($admin, '/suppliers', ['code' => 'MD-SUP0', 'name' => 'MD Supplier Seed']);
        $supId = (int) ($r['body']['data']['supplier_id'] ?? 0);
    }
    $kg = (int) $pdo->query("SELECT id FROM units WHERE code = 'KG'")->fetchColumn();
    $gr = (int) $pdo->query("SELECT id FROM units WHERE code = 'GR'")->fetchColumn();
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code = 'PCS'")->fetchColumn();
    $karung = (int) $pdo->query("SELECT id FROM units WHERE code = 'KARUNG'")->fetchColumn();
    check('fixtures: category, supplier and units exist', $catId > 0 && $supId > 0 && $kg > 0 && $gr > 0 && $pcs > 0 && $karung > 0);

    // ===================== A. POST /items
    echo "\n===== POST /items =====\n";
    $auditBefore = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $r = $post($admin, '/items', ['sku' => 'MD-ITEM-1', 'name' => 'Tepung MD', 'category_id' => $catId, 'default_supplier_id' => $supId, 'base_unit_id' => $kg,
        'purchase_unit_id' => $karung, 'purchase_conversion' => 25, 'price' => 300000]);
    $itemId = (int) ($r['body']['data']['item_id'] ?? 0);
    check('create with category + supplier + base unit + purchase unit/conversion + price -> 200 + item_id', $r['status'] === 200 && $itemId > 0, $r['status'] . ' ' . $msg($r));
    $it = $pdo->query("SELECT * FROM items WHERE id = {$itemId}")->fetch();
    check('item row: sku/name/category/supplier/base unit/status ACTIVE/minimum 0/not locked', $it && $it['sku'] === 'MD-ITEM-1' && $it['name'] === 'Tepung MD' && (int) $it['category_id'] === $catId && (int) $it['default_supplier_id'] === $supId && (int) $it['base_unit_id'] === $kg && $it['status'] === 'ACTIVE' && (float) $it['minimum_stock'] === 0.0 && $it['locked_at'] === null);
    $convs = $pdo->query("SELECT unit_id, conversion_to_base, is_purchase_default, valid_to FROM item_unit_conversions WHERE item_id = {$itemId} ORDER BY unit_id")->fetchAll();
    $byUnit = [];
    foreach ($convs as $c) { $byUnit[(int) $c['unit_id']] = $c; }
    check('conversions: base identity 1 + purchase 25 (purchase default), both open', count($convs) === 2 && (float) $byUnit[$kg]['conversion_to_base'] === 1.0 && (float) $byUnit[$karung]['conversion_to_base'] === 25.0 && (int) $byUnit[$karung]['is_purchase_default'] === 1 && $byUnit[$kg]['valid_to'] === null && $byUnit[$karung]['valid_to'] === null);
    $ph = $pdo->query("SELECT * FROM item_price_history WHERE item_id = {$itemId}")->fetchAll();
    check('Harga Beli recorded in item_price_history on the PURCHASE unit: 300.000 / karung -> 12.000 per kg (append-only, supplier kept)', count($ph) === 1 && (int) $ph[0]['unit_id'] === $karung && (float) $ph[0]['price_per_unit'] === 300000.0 && (float) $ph[0]['unit_cost_base'] === 12000.0 && (int) $ph[0]['supplier_id'] === $supId);
    $aud = $pdo->query("SELECT action_code FROM audit_logs WHERE entity_type = 'items' AND entity_id = {$itemId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    check('audit: ITEM_CREATE + ITEM_PRICE_UPDATE', in_array('ITEM_CREATE', $aud, true) && in_array('ITEM_PRICE_UPDATE', $aud, true), implode(',', $aud));
    check('GET /items/{id}/units lists base + purchase unit with the reference price (existing price system)', (function () use ($base, $admin, $itemId, $karung) {
        $u = http('GET', "{$base}/items/{$itemId}/units", null, $admin['jar'], $admin['csrf'])['body']['data'] ?? [];
        foreach ($u as $row) { if ((int) $row['id'] === $karung) { return (float) $row['reference_price'] === 300000.0; } }
        return false;
    })());

    $r = $post($admin, '/items', ['sku' => 'MD-ITEM-2', 'name' => 'Gula MD', 'category_id' => $catId, 'base_unit_id' => $pcs]);
    $item2 = (int) ($r['body']['data']['item_id'] ?? 0);
    check('minimal create (no supplier / purchase unit / price) works; price stays blank (no history row), identity conversion only', $r['status'] === 200 && (int) $one("SELECT COUNT(*) FROM item_price_history WHERE item_id = ?", [$item2]) === 0 && (int) $one("SELECT COUNT(*) FROM item_unit_conversions WHERE item_id = ?", [$item2]) === 1);
    $r = $post($admin, '/items', ['sku' => 'MD-ITEM-3', 'name' => 'Free MD', 'category_id' => $catId, 'base_unit_id' => $pcs, 'price' => 0, 'status' => 'INACTIVE']);
    check('price 0 is allowed (free item) and INACTIVE status is honoured', $r['status'] === 200 && (int) $one("SELECT COUNT(*) FROM item_price_history WHERE item_id = ? AND price_per_unit = 0", [(int) $r['body']['data']['item_id']]) === 1 && $one("SELECT status FROM items WHERE sku = 'MD-ITEM-3'") === 'INACTIVE');

    $n0 = (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
    $bad = [
        'duplicate sku (case-insensitive)' => [['sku' => 'md-item-1', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg], '/sku .* already exists/'],
        'blank sku' => [['sku' => '', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg], '/sku is required/'],
        'sku with space' => [['sku' => 'A B', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg], '/sku must not contain spaces/'],
        'blank name' => [['sku' => 'MD-X1', 'name' => '  ', 'category_id' => $catId, 'base_unit_id' => $kg], '/name is required/'],
        'missing category' => [['sku' => 'MD-X2', 'name' => 'x', 'base_unit_id' => $kg], '/category_id is required/'],
        'unknown category' => [['sku' => 'MD-X3', 'name' => 'x', 'category_id' => 999999, 'base_unit_id' => $kg], '/category_id 999999 does not exist/'],
        'unknown supplier (never auto-created)' => [['sku' => 'MD-X4', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'default_supplier_id' => 999999], '/default_supplier_id 999999 does not exist/'],
        'missing base unit' => [['sku' => 'MD-X5', 'name' => 'x', 'category_id' => $catId], '/base_unit_id is required/'],
        'unknown base unit' => [['sku' => 'MD-X6', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => 999999], '/base_unit_id 999999 is not a recognized unit/'],
        'purchase unit == base unit' => [['sku' => 'MD-X7', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'purchase_unit_id' => $kg, 'purchase_conversion' => 2], '/purchase_unit_id must differ/'],
        'purchase unit without factor' => [['sku' => 'MD-X8', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'purchase_unit_id' => $karung], '/purchase_conversion must be a number > 0/'],
        'zero factor' => [['sku' => 'MD-X9', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'purchase_unit_id' => $karung, 'purchase_conversion' => 0], '/purchase_conversion must be a number > 0/'],
        'factor without unit' => [['sku' => 'MD-Y1', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'purchase_conversion' => 5], '/purchase_unit_id is required/'],
        'negative price' => [['sku' => 'MD-Y2', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'price' => -1], '/price cannot be negative/'],
        'non-numeric price' => [['sku' => 'MD-Y3', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'price' => 'abc'], '/price must be numeric/'],
        'bad status' => [['sku' => 'MD-Y4', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg, 'status' => 'DELETED'], '/status must be ACTIVE or INACTIVE/'],
    ];
    foreach ($bad as $label => [$body, $re]) {
        $r = $post($admin, '/items', $body);
        check("rejected (422): {$label}", $r['status'] === 422 && preg_match($re, $msg($r)) === 1, $r['status'] . ' ' . $msg($r));
    }
    $inactiveCat = (int) ($post($admin, '/categories', ['code' => 'MD-CAT-OFF', 'name' => 'MD Cat Off', 'is_active' => false])['body']['data']['category_id'] ?? 0);
    $r = $post($admin, '/items', ['sku' => 'MD-Z1', 'name' => 'x', 'category_id' => $inactiveCat, 'base_unit_id' => $kg]);
    check('inactive category refused for a new item', $r['status'] === 422 && str_contains($msg($r), 'is inactive'), $msg($r));
    check('none of the rejected creates left an item behind (count unchanged)', (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn() === $n0);
    // multiple errors reported together
    $r = $post($admin, '/items', ['sku' => '', 'name' => '', 'base_unit_id' => 0]);
    check('several field errors come back together (sku, name, category, base unit)', $r['status'] === 422 && substr_count($msg($r), ';') >= 3, $msg($r));
    // rollback: a conversion failure after the INSERT must remove the item
    $pdo->exec("INSERT INTO units (code, name) VALUES ('MDX','md x')");
    $r = $post($admin, '/items', ['sku' => 'MD-RB', 'name' => 'Rollback', 'category_id' => $catId, 'base_unit_id' => (int) $pdo->lastInsertId(), 'purchase_unit_id' => $karung, 'purchase_conversion' => 1e30]);
    check('a failure after the INSERT rolls the whole creation back (no orphan item / conversion / price)', $r['status'] !== 200 && (int) $one("SELECT COUNT(*) FROM items WHERE sku = 'MD-RB'") === 0);
    // the new item is usable by the real posting engine
    $wh = (int) $pdo->query("SELECT id FROM warehouses WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $rin = $post($admin, '/stock-in', ['transaction_uuid' => 'md-in-' . bin2hex(random_bytes(6)), 'warehouse_id' => $wh, 'transaction_date' => date('Y-m-d'), 'reference_no' => 'MD-IN-1',
        'lines' => [['item_id' => $item2, 'input_unit_id' => $pcs, 'input_qty' => 5, 'unit_price_input' => 1000, 'ppn_rate' => 0]]]);
    check('the freshly created item can be received by the existing Stock IN engine (conversion approved)', $rin['status'] === 200, $rin['status'] . ' ' . $msg($rin));

    // ===================== B. POST /warehouses
    echo "\n===== POST /warehouses =====\n";
    $r = $post($admin, '/warehouses', ['code' => 'MD-GD1', 'name' => 'Gudang MD', 'warehouse_type' => 'MAIN', 'is_active' => true]);
    $whId = (int) ($r['body']['data']['warehouse_id'] ?? 0);
    $w = $pdo->query("SELECT * FROM warehouses WHERE id = {$whId}")->fetch();
    check('create warehouse -> row with code/name/type MAIN/active, activation_locked 0', $r['status'] === 200 && $w && $w['code'] === 'MD-GD1' && $w['name'] === 'Gudang MD' && $w['warehouse_type'] === 'MAIN' && (int) $w['is_active'] === 1 && (int) $w['activation_locked'] === 0);
    $r2 = $post($admin, '/warehouses', ['code' => 'MD-GD2', 'name' => 'Transit MD', 'warehouse_type' => 'TRANSIT', 'is_active' => false]);
    check('TRANSIT type + inactive status honoured', $r2['status'] === 200 && $one("SELECT warehouse_type FROM warehouses WHERE code = 'MD-GD2'") === 'TRANSIT' && (int) $one("SELECT is_active FROM warehouses WHERE code = 'MD-GD2'") === 0);
    check('audit WAREHOUSE_CREATE written', (int) $one("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'WAREHOUSE_CREATE' AND entity_id = ?", [$whId]) === 1);
    $r = $post($admin, '/warehouses', ['code' => 'md-gd1', 'name' => 'Dup', 'warehouse_type' => 'MAIN']);
    check('duplicate warehouse code (case-insensitive) -> 422', $r['status'] === 422 && str_contains($msg($r), 'already exists'), $msg($r));
    $r = $post($admin, '/warehouses', ['code' => 'MD-GD3', 'name' => 'X', 'warehouse_type' => 'VIRTUAL']);
    check('unknown warehouse type -> 422', $r['status'] === 422 && str_contains($msg($r), 'warehouse_type'), $msg($r));
    $r = $post($admin, '/warehouses', ['code' => 'bad code!', 'name' => 'X', 'warehouse_type' => 'MAIN']);
    check('code with illegal characters -> 422', $r['status'] === 422 && str_contains($msg($r), 'code may only contain'), $msg($r));
    $r = $post($admin, '/warehouses', ['code' => '', 'name' => '', 'warehouse_type' => 'MAIN']);
    check('blank code and name -> 422 (both reported)', $r['status'] === 422 && str_contains($msg($r), 'code is required') && str_contains($msg($r), 'name is required'), $msg($r));
    check('a new warehouse creates no item / stock rows (Master Barang stays centralised)', (int) $one("SELECT COUNT(*) FROM inventory_batches WHERE warehouse_id = ?", [$whId]) === 0);

    // ===================== C. POST /divisions
    echo "\n===== POST /divisions =====\n";
    $r = $post($admin, '/divisions', ['code' => 'MD-DIV1', 'name' => 'Divisi MD']);
    $divId = (int) ($r['body']['data']['division_id'] ?? 0);
    check('create division -> row (active by default) + audit DIVISION_CREATE', $r['status'] === 200 && (int) $one("SELECT is_active FROM divisions WHERE id = ?", [$divId]) === 1 && (int) $one("SELECT COUNT(*) FROM audit_logs WHERE action_code = 'DIVISION_CREATE' AND entity_id = ?", [$divId]) === 1);
    $r = $post($admin, '/divisions', ['code' => 'MD-DIV2', 'name' => 'Divisi Off', 'is_active' => false]);
    check('inactive division honoured', $r['status'] === 200 && (int) $one("SELECT is_active FROM divisions WHERE code = 'MD-DIV2'") === 0);
    $r = $post($admin, '/divisions', ['code' => 'MD-DIV1', 'name' => 'Dup']);
    check('duplicate division code -> 422', $r['status'] === 422 && str_contains($msg($r), 'already exists'), $msg($r));
    $r = $post($admin, '/divisions', ['code' => 'MD-DIV3', 'name' => '']);
    check('blank division name -> 422', $r['status'] === 422 && str_contains($msg($r), 'name is required'), $msg($r));

    // ===================== D. existing create routes
    echo "\n===== suppliers / bakery / categories =====\n";
    $r = $post($admin, '/suppliers', ['code' => 'MD-SUP1', 'name' => 'PT MD', 'contact_name' => 'Budi', 'phone' => '0812', 'email' => 'a@b.co', 'address' => 'Jl. MD 1', 'is_active' => false]);
    check('supplier created with all fields; is_active=false honoured (new optional field)', $r['status'] === 200 && (int) $one("SELECT is_active FROM suppliers WHERE code = 'MD-SUP1'") === 0 && $one("SELECT email FROM suppliers WHERE code = 'MD-SUP1'") === 'a@b.co');
    $r = $post($admin, '/suppliers', ['code' => 'MD-SUP2', 'name' => 'PT MD 2']);
    check('supplier default is active (previous behaviour unchanged)', $r['status'] === 200 && (int) $one("SELECT is_active FROM suppliers WHERE code = 'MD-SUP2'") === 1);
    $r = $post($admin, '/suppliers', ['code' => 'md-sup1', 'name' => 'Dup']);
    check('duplicate supplier code -> 422', $r['status'] === 422 && str_contains($msg($r), 'already exists'), $msg($r));
    $r = $post($admin, '/suppliers', ['code' => 'MD-SUP3', 'name' => 'X', 'email' => 'not-an-email']);
    check('invalid supplier email -> 422', $r['status'] === 422 && str_contains($msg($r), 'email'), $msg($r));
    $r = $post($admin, '/suppliers', ['code' => str_repeat('S', 31), 'name' => 'X']);
    check('over-long supplier code -> 422 (not a database error)', $r['status'] === 422 && str_contains($msg($r), 'code must be at most 30'), $msg($r));
    $r = $post($admin, '/suppliers', ['code' => 'MD-SUP4', 'name' => '']);
    check('blank supplier name -> 4xx', $r['status'] === 422, $r['status'] . ' ' . $msg($r));

    $r = $post($admin, '/bakery-destinations', ['code' => 'SUDIRMAN-MD', 'name' => 'Bakery Sudirman MD', 'address' => 'Jl. Jend Sudirman No. 36 Sukabumi.', 'city_area' => 'Sukabumi', 'is_active' => true]);
    $bkId = (int) ($r['body']['data']['bakery_destination_id'] ?? 0);
    check('bakery destination created (code/name/address/area/status)', $r['status'] === 200 && $one("SELECT city_area FROM bakery_destinations WHERE id = ?", [$bkId]) === 'Sukabumi' && (int) $one("SELECT is_active FROM bakery_destinations WHERE id = ?", [$bkId]) === 1);
    $r = $post($admin, '/bakery-destinations', ['code' => 'BK-OFF-MD', 'name' => 'Bakery Off', 'is_active' => false]);
    check('inactive bakery honoured', $r['status'] === 200 && (int) $one("SELECT is_active FROM bakery_destinations WHERE code = 'BK-OFF-MD'") === 0);
    $r = $post($admin, '/bakery-destinations', ['code' => 'sudirman-md', 'name' => 'Dup']);
    check('duplicate bakery code -> 422', $r['status'] === 422 && str_contains($msg($r), 'already exists'), $msg($r));
    check('the new ACTIVE bakery is returned by GET /bakery-destinations (what Stock OUT lists) and the inactive one is flagged inactive', (function () use ($base, $admin, $bkId) {
        $rows = http('GET', "{$base}/bakery-destinations", null, $admin['jar'], $admin['csrf'])['body']['data'] ?? [];
        $a = array_values(array_filter($rows, fn ($x) => (int) $x['id'] === $bkId));
        $o = array_values(array_filter($rows, fn ($x) => $x['code'] === 'BK-OFF-MD'));
        return count($a) === 1 && (int) $a[0]['is_active'] === 1 && count($o) === 1 && (int) $o[0]['is_active'] === 0;
    })());
    $q = http('POST', "{$base}/stock-out/quote", ['warehouse_id' => $wh, 'bakery_destination_id' => $bkId, 'transaction_date' => date('Y-m-d'), 'lines' => []], $admin['jar'], $admin['csrf']);
    check('Stock OUT V2 accepts the new bakery as destination (quote resolves it; no "bakery not found")', !str_contains(json_encode($q['body']), 'bakery destination') || !str_contains(strtolower(json_encode($q['body'])), 'not found'), json_encode($q['body']));

    $r = $post($admin, '/categories', ['code' => 'MD-KTG1', 'name' => 'Kategori MD']);
    check('category created (active by default)', $r['status'] === 200 && (int) $one("SELECT is_active FROM categories WHERE code = 'MD-KTG1'") === 1);
    $r = $post($admin, '/categories', ['code' => 'MD-KTG2', 'name' => 'Kategori MD']);
    check('duplicate category NAME (same case) -> 422', $r['status'] === 422 && str_contains($msg($r), "category name 'Kategori MD' already exists"), $msg($r));
    $r = $post($admin, '/categories', ['code' => 'MD-KTG3', 'name' => 'KATEGORI md']);
    check('duplicate category name differing only by case -> 422', $r['status'] === 422 && str_contains($msg($r), 'already exists'), $msg($r));
    $r = $post($admin, '/categories', ['code' => 'md-ktg1', 'name' => 'Lain']);
    check('duplicate category code -> 422', $r['status'] === 422 && str_contains($msg($r), 'category code'), $msg($r));
    $r = $post($admin, '/categories', ['code' => 'MD-KTG4', 'name' => 'Kategori Off', 'is_active' => false]);
    check('inactive category honoured', $r['status'] === 200 && (int) $one("SELECT is_active FROM categories WHERE code = 'MD-KTG4'") === 0);
    $newCat = (int) $one("SELECT id FROM categories WHERE code = 'MD-KTG1'");
    $r = $post($admin, '/items', ['sku' => 'MD-ITEM-C', 'name' => 'Item kategori baru', 'category_id' => $newCat, 'base_unit_id' => $pcs]);
    check('a new ACTIVE category is immediately usable by Master Barang create', $r['status'] === 200);

    // ===================== E. permissions
    echo "\n===== permissions =====\n";
    $before = [];
    foreach (['items', 'warehouses', 'divisions', 'suppliers', 'bakery_destinations', 'categories'] as $t) { $before[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    $bodies = [
        '/items' => ['sku' => 'NOPE-1', 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $kg],
        '/warehouses' => ['code' => 'NOPE-W', 'name' => 'x', 'warehouse_type' => 'MAIN'],
        '/divisions' => ['code' => 'NOPE-D', 'name' => 'x'],
        '/suppliers' => ['code' => 'NOPE-S', 'name' => 'x'],
        '/bakery-destinations' => ['code' => 'NOPE-B', 'name' => 'x'],
        '/categories' => ['code' => 'NOPE-C', 'name' => 'x'],
    ];
    foreach (['VIEWER' => $viewer, 'STOCK' => $stockA] as $roleName => $sess) {
        $allForbidden = true;
        foreach ($bodies as $path => $b) {
            $r = $post($sess, $path, $b);
            if ($r['status'] !== 403 || ($r['body']['error']['code'] ?? '') !== 'FORBIDDEN') { $allForbidden = false; echo "  {$roleName} POST {$path} -> {$r['status']}\n"; }
        }
        check("{$roleName}: POST on all six master create endpoints is refused (403 FORBIDDEN) with a valid body", $allForbidden);
    }
    $after = [];
    foreach ($before as $t => $_) { $after[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
    check('no row was written by the refused requests', $before === $after);
    $anon = http('POST', "{$base}/items", $bodies['/items']);
    check('unauthenticated POST /items -> 401', $anon['status'] === 401, (string) $anon['status']);
    $noCsrf = http('POST', "{$base}/items", $bodies['/items'], $admin['jar'], null);
    check('POST without the CSRF token -> 403 CSRF_INVALID', $noCsrf['status'] === 403 && ($noCsrf['body']['error']['code'] ?? '') === 'CSRF_INVALID');
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
}

$failed = count(array_filter($results, fn ($x) => !$x));
echo "\n" . (count($results) - $failed) . ' / ' . count($results) . ' PASSED' . ($failed ? " — {$failed} FAILED" : '') . "\n";
exit($failed ? 1 : 0);
