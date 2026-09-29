<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'stock_import.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$batchId = (int) ($_GET['batch_id'] ?? 0);
if ($batchId <= 0) {
    Response::error('batch_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$batchStmt = $pdo->prepare('SELECT * FROM stock_import_batches WHERE id = ? LIMIT 1');
$batchStmt->execute([$batchId]);
$batch = $batchStmt->fetch();
if (!$batch) {
    Response::error('Batch tidak ditemukan.', 404);
}

$rowsStmt = $pdo->prepare('SELECT * FROM stock_import_rows WHERE batch_id = ? ORDER BY row_no');
$rowsStmt->execute([$batchId]);

Response::json(['batch' => $batch, 'rows' => $rowsStmt->fetchAll()]);
