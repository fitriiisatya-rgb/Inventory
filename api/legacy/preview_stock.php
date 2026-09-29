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
if (!is_array($rows)) {
    Response::error("Body harus berisi { rows: [...] } — hasil parse JSON export legacy.", 422);
}
if (count($rows) === 0) {
    Response::error('File legacy tidak berisi baris data.', 422);
}
if (count($rows) > 20000) {
    Response::error('Terlalu banyak baris (>20000) untuk satu kali preview. Pecah file menjadi beberapa bagian.', 422);
}

$service = new LegacyMigrationService(Database::pdo());
try {
    Response::json($service->previewStock($rows));
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}
