<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'stock_import.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$locationId = (int) ($_POST['location_id'] ?? 0);
if ($locationId <= 0) {
    Response::error('location_id wajib diisi.', 422);
}
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    Response::error('File CSV wajib diupload.', 422);
}

$file = $_FILES['file'];

// Never trust the client extension/declared type — sniff the real content.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
if (!in_array($mime, $allowedMimes, true)) {
    Response::error("Tipe file tidak didukung ({$mime}). Upload file CSV.", 422);
}

try {
    $service = new StockImportService(Database::pdo());
    $result = $service->previewCsv($locationId, $file['tmp_name'], basename($file['name']), $user['id']);
    Response::json($result);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}
