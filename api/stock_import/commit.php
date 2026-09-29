<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'stock_import.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$batchId = (int) ($input['batch_id'] ?? 0);
if ($batchId <= 0) {
    Response::error('batch_id wajib diisi.', 422);
}

try {
    $service = new StockImportService(Database::pdo());
    $result = $service->commit($batchId, $user['id']);
    Response::json($result);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}
