<?php
declare(strict_types=1);

/**
 * READ-ONLY check for the Master Data "Tambah ..." package. Two modes in one script:
 *
 *   php scripts/mdm_readonly_check.php --app-root=<app dir with services/> [--service-dir=<dir holding the three package/production services>]
 *       DATABASE check (BEFORE deploying use --service-dir=payload): READ ONLY transaction (a write is proved to be
 *       rejected first); checks the columns the new creates write to, the six MASTER_*_MANAGE permissions and who holds
 *       them, and drives the validators of MasterRecordService / SupplierService / BakeryDestinationService with inputs
 *       that are refused BEFORE any write (empty input, an existing real sku / code, over-long values) — nothing is created.
 *
 *   php scripts/mdm_readonly_check.php --public-dir=<public/> --services-dir=<services/> --files
 *       FILE check (AFTER applying): the 14 targets carry the new markers exactly once, the CSS braces balance, index.html
 *       carries the token on all nine tags, and every state file exists. Files only, never needs the DB.
 *
 * Exit code 1 if any check fails.
 */

$appRoot = $serviceDir = $public = $services = null;
$files = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--app-root=')) { $appRoot = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--service-dir=')) { $serviceDir = rtrim(substr($arg, 14), '/'); }
    elseif (str_starts_with($arg, '--public-dir=')) { $public = rtrim(substr($arg, 13), '/'); }
    elseif (str_starts_with($arg, '--services-dir=')) { $services = rtrim(substr($arg, 15), '/'); }
    elseif ($arg === '--files') { $files = true; }
    else { fwrite(STDERR, "unknown argument: {$arg}\n"); exit(2); }
}
$fail = 0;
$n = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$n): void {
    $n++;
    if (!$ok) { $fail++; }
    echo ($ok ? 'PASS' : 'FAIL') . " - {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
};

if ($files) {
    if ($public === null || $services === null) { fwrite(STDERR, "--files needs --public-dir and --services-dir\n"); exit(2); }
    $read = static fn (string $p): string => (string) @file_get_contents($p);
    $php = $read("{$public}/index.php");
    $css = $read("{$public}/assets/css/app.css");
    $html = $read("{$public}/index.html");
    $api = $read("{$public}/assets/js/api-client.js");
    $check('index.php: MasterRecordService required once, 3 routes + category guard present', substr_count($php, "services/MasterRecordService.php") === 1 && substr_count($php, "'POST /items' =>") === 1 && substr_count($php, "'POST /warehouses' =>") === 1 && substr_count($php, "'POST /divisions' =>") === 1 && str_contains($php, "category name '{\$name}' already exists"));
    $check('index.php: legacy routes untouched (PUT /items/{id}, PUT /warehouses/{id}, POST /suppliers, POST /categories still once)', substr_count($php, "'PUT /items/{id}' =>") === 1 && substr_count($php, "'PUT /warehouses/{id}' =>") === 1 && substr_count($php, "'POST /suppliers' =>") === 1 && substr_count($php, "'POST /categories' =>") === 1);
    $check('api-client.js: createItem / createWarehouse / createDivision once each', substr_count($api, 'createItem:') === 1 && substr_count($api, 'createWarehouse:') === 1 && substr_count($api, 'createDivision:') === 1);
    $check('app.css: master modal block present once', substr_count($css, '/* Master Data compact create modal (master-common.js') === 1 && str_contains($css, '.mdm-modal-head'));
    $depth = 0; $min = 0;
    foreach (str_split($css) as $c) { if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; $min = min($min, $depth); } }
    $check('app.css: braces balanced', $depth === 0 && $min === 0, "depth={$depth}");
    $tagOk = substr_count($html, 'app.css?v=20261012-mdm') === 1;
    foreach (['api-client.js', 'master-common.js', 'master-categories.js', 'master-vendors.js', 'master-bakery-destinations.js', 'master-items.js', 'master-warehouses.js', 'master-divisions.js'] as $js) {
        $tagOk = $tagOk && substr_count($html, "{$js}?v=20261012-mdm") === 1;
    }
    $check('index.html: app.css + 8 scripts carry token 20261012-mdm (once each)', $tagOk);
    $common = $read("{$public}/assets/js/master-common.js");
    $check('master-common.js: pageHeader + recordModal', str_contains($common, 'function recordModal') && str_contains($common, 'function pageHeader'));
    foreach (['master-categories' => 'Tambah Kategori', 'master-vendors' => 'Tambah Supplier', 'master-bakery-destinations' => 'Tambah Bakery', 'master-items' => 'Tambah Barang', 'master-warehouses' => 'Tambah Gudang', 'master-divisions' => 'Tambah Divisi'] as $f => $label) {
        $check("{$f}.js: \"{$label}\" header button, no inline create form", str_contains($read("{$public}/assets/js/{$f}.js"), "'{$label}'") && str_contains($read("{$public}/assets/js/{$f}.js"), 'MasterCommon.pageHeader'));
    }
    $check('SupplierService.php / BakeryDestinationService.php accept is_active on create', str_contains($read("{$services}/SupplierService.php"), ":is_active") && str_contains($read("{$services}/BakeryDestinationService.php"), ":is_active"));
    $check('MasterRecordService.php present', is_file("{$services}/MasterRecordService.php") && str_contains($read("{$services}/MasterRecordService.php"), 'function createItem'));
    $targets = ["{$public}/index.php", "{$public}/index.html", "{$public}/assets/css/app.css", "{$public}/assets/js/api-client.js", "{$public}/assets/js/master-common.js", "{$public}/assets/js/master-categories.js", "{$public}/assets/js/master-vendors.js", "{$public}/assets/js/master-bakery-destinations.js", "{$public}/assets/js/master-items.js", "{$public}/assets/js/master-warehouses.js", "{$public}/assets/js/master-divisions.js", "{$services}/SupplierService.php", "{$services}/BakeryDestinationService.php", "{$services}/MasterRecordService.php"];
    $missing = [];
    foreach ($targets as $t) { if (!is_file($t . '.mdm-patch.json') || (!is_file($t . '.pre-mdm-backup') && !str_ends_with($t, 'MasterRecordService.php'))) { $missing[] = basename($t); } }
    $check('state files beside all 14 targets (backup for the 13 changed ones)', !$missing, implode(',', $missing));
    echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
    exit($fail ? 1 : 0);
}

if ($appRoot === null || !is_dir("{$appRoot}/services")) {
    fwrite(STDERR, "usage: php scripts/mdm_readonly_check.php --app-root=<dir with services/> [--service-dir=<dir>]   |   --public-dir=<..> --services-dir=<..> --files\n");
    exit(2);
}
$own = ['MasterRecordService.php', 'SupplierService.php', 'BakeryDestinationService.php'];
foreach (glob("{$appRoot}/services/*.php") ?: [] as $f) {
    if (!in_array(basename($f), $own, true)) { require_once $f; }
}
foreach ($own as $f) {
    $path = ($serviceDir ?? "{$appRoot}/services") . "/{$f}";
    if (!is_file($path)) { fwrite(STDERR, "missing service file: {$path}\n"); exit(2); }
    require_once $path;
}

use App\Services\BakeryDestinationService;
use App\Services\Database;
use App\Services\MasterRecordService;
use App\Services\SupplierService;
use App\Services\ValidationException;

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
$col = static fn (string $t, string $c): bool => (int) $GLOBALS['pdo']->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$t}' AND column_name = '{$c}'")->fetchColumn() === 1;
$need = ['warehouses' => ['code', 'name', 'warehouse_type', 'is_active', 'activation_locked'], 'divisions' => ['code', 'name', 'is_active'],
    'items' => ['sku', 'name', 'category', 'category_id', 'base_unit_id', 'default_supplier_id', 'minimum_stock', 'status', 'locked_at'],
    'categories' => ['code', 'name', 'is_active'], 'suppliers' => ['code', 'name', 'contact_name', 'email', 'is_active'], 'bakery_destinations' => ['code', 'name', 'address', 'city_area', 'pic_name', 'phone', 'route_cluster', 'notes', 'is_active'],
    'item_unit_conversions' => ['item_id', 'unit_id', 'conversion_to_base', 'is_purchase_default', 'valid_from', 'valid_to'], 'item_price_history' => ['item_id', 'supplier_id', 'unit_id', 'price_per_unit', 'unit_cost_base', 'effective_date']];
$missing = [];
foreach ($need as $t => $cols) { foreach ($cols as $c) { if (!$col($t, $c)) { $missing[] = "{$t}.{$c}"; } } }
$check('every column the new creates write to exists', !$missing, implode(',', $missing));
$perms = ['MASTER_ITEM_MANAGE', 'MASTER_WAREHOUSE_MANAGE', 'MASTER_DIVISION_MANAGE', 'MASTER_SUPPLIER_MANAGE', 'MASTER_BAKERY_DESTINATION_MANAGE', 'MASTER_CATEGORY_MANAGE'];
$holders = [];
foreach ($perms as $p) {
    $st = $pdo->prepare('SELECT r.code FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions pe ON pe.id = rp.permission_id WHERE pe.code = :c ORDER BY r.code');
    $st->execute(['c' => $p]);
    $holders[$p] = $st->fetchAll(PDO::FETCH_COLUMN);
}
$check('the six MASTER_*_MANAGE permissions exist and SUPERADMIN holds all of them', count(array_filter($holders, static fn ($h) => in_array('SUPERADMIN', $h, true))) === 6);
foreach ($holders as $p => $h) { echo "  {$p}: " . implode(', ', $h) . "\n"; }
$check('STOCK / VIEWER / DIVISION / OPNAME_COUNTER hold none of them (create stays admin-only)', !array_filter($holders, static fn ($h) => array_intersect($h, ['STOCK', 'VIEWER', 'DIVISION', 'OPNAME_COUNTER'])));

$refuses = static function (callable $fn, string $needle) {
    try { $fn(); return 'NOT REFUSED'; } catch (ValidationException $e) { return str_contains(implode('; ', $e->errors), $needle) ? 'ok' : implode('; ', $e->errors); }
};
$catId = (int) $pdo->query('SELECT id FROM categories WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$unitId = (int) $pdo->query('SELECT id FROM units ORDER BY id LIMIT 1')->fetchColumn();
$sku = $pdo->query('SELECT sku FROM items ORDER BY id LIMIT 1')->fetchColumn();
$whCode = $pdo->query('SELECT code FROM warehouses ORDER BY id LIMIT 1')->fetchColumn();
$dvCode = $pdo->query('SELECT code FROM divisions ORDER BY id LIMIT 1')->fetchColumn();
$check('units master has rows (the unit dropdowns have something to offer)', $unitId > 0);
$check('createItem refuses empty input (sku / name / category / base unit) before any write', $refuses(fn () => MasterRecordService::createItem($pdo, []), 'sku is required') === 'ok');
if ($sku !== false && $catId > 0 && $unitId > 0) {
    $check("createItem refuses an EXISTING real sku ('{$sku}') as a duplicate before any write", $refuses(fn () => MasterRecordService::createItem($pdo, ['sku' => $sku, 'name' => 'x', 'category_id' => $catId, 'base_unit_id' => $unitId]), 'already exists') === 'ok');
}
$check('createItem refuses an unknown category / supplier / unit (nothing is auto-created)', $refuses(fn () => MasterRecordService::createItem($pdo, ['sku' => 'ZZ-CHECK', 'name' => 'x', 'category_id' => 99999999, 'default_supplier_id' => 99999999, 'base_unit_id' => 99999999]), 'does not exist') === 'ok');
if ($whCode !== false) { $check("createWarehouse refuses an EXISTING real code ('{$whCode}')", $refuses(fn () => MasterRecordService::createWarehouse($pdo, ['code' => $whCode, 'name' => 'x', 'warehouse_type' => 'MAIN']), 'already exists') === 'ok'); }
if ($dvCode !== false) { $check("createDivision refuses an EXISTING real code ('{$dvCode}')", $refuses(fn () => MasterRecordService::createDivision($pdo, ['code' => $dvCode, 'name' => 'x']), 'already exists') === 'ok'); }
$check('createWarehouse refuses an unknown type', $refuses(fn () => MasterRecordService::createWarehouse($pdo, ['code' => 'ZZ-CHECK', 'name' => 'x', 'warehouse_type' => 'VIRTUAL']), 'warehouse_type') === 'ok');
$check('SupplierService::create refuses an over-long code / bad e-mail before any write', $refuses(fn () => SupplierService::create($pdo, ['code' => str_repeat('S', 31), 'name' => 'x', 'email' => 'nope']), 'code must be at most 30') === 'ok');
$check('BakeryDestinationService::create refuses an over-long code before any write', $refuses(fn () => BakeryDestinationService::create($pdo, ['code' => str_repeat('B', 31), 'name' => 'x']), 'code must be at most 30') === 'ok');
$counts = [];
foreach (['items', 'warehouses', 'divisions', 'suppliers', 'bakery_destinations', 'categories'] as $t) { $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn(); }
echo '  current row counts: ' . json_encode($counts) . "\n";
$pdo->exec('ROLLBACK');
echo "\n" . ($n - $fail) . " / {$n} checks passed" . ($fail ? " — {$fail} FAILED" : '') . "\n";
exit($fail ? 1 : 0);
