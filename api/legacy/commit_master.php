<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'master.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}
Csrf::requireValid();

$input = json_decode(file_get_contents('php://input'), true);
$rows = $input['rows'] ?? null;
$sourceFile = trim((string) ($input['source_file'] ?? 'legacy_master.json'));
if (!is_array($rows) || count($rows) === 0) {
    Response::error("Body harus berisi { rows: [...] } — hasil parse JSON export legacy.", 422);
}

$service = new LegacyMigrationService(Database::pdo());
try {
    Response::json(['data' => $service->commitMaster($rows, $user['id'], $sourceFile)]);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}
