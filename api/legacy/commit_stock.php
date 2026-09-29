<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'stock_import.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true);
$rows = $input['rows'] ?? null;
$locationMap = $input['location_map'] ?? null;
$sourceFile = trim((string) ($input['source_file'] ?? 'legacy_stock.json'));

if (!is_array($rows) || count($rows) === 0) {
    Response::error("Body harus berisi { rows: [...] } — hasil parse JSON export legacy.", 422);
}
if (!is_array($locationMap) || count($locationMap) === 0) {
    Response::error('location_map wajib diisi — setiap lokasi legacy yang muncul pada data harus dipetakan ke lokasi baru sebelum commit.', 422);
}
foreach ($locationMap as $k => $v) {
    if (!is_int($v) && !ctype_digit((string) $v)) {
        Response::error("location_map['{$k}'] harus berupa location_id (angka).", 422);
    }
    $locationMap[$k] = (int) $v;
}

$service = new LegacyMigrationService(Database::pdo());
try {
    Response::json(['data' => $service->commitStock($rows, $locationMap, $user['id'], $sourceFile)]);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}
