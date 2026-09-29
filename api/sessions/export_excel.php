<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

$user = Auth::requireLogin();
Permissions::require($user['role'], 'report.export');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$sessionId = (int) ($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    Response::error('session_id wajib diisi.', 422);
}

$pdo = Database::pdo();
$locks = new ItemLockService($pdo, (int) $GLOBALS['SO_CONFIG']['app']['lock_ttl_seconds']);
$recon = new ReconciliationService($pdo, $locks);
$fin = new FinalizationService($pdo, $recon, $locks);
$report = new SessionReportService($pdo, $recon, $fin);

$sessionStmt = $pdo->prepare('SELECT session_no FROM stock_opname_sessions WHERE id = ? LIMIT 1');
$sessionStmt->execute([$sessionId]);
$sessionNo = $sessionStmt->fetchColumn();
if (!$sessionNo) {
    Response::error('Session tidak ditemukan.', 404);
}

try {
    $bytes = $report->buildWorkbook($sessionId)->build();
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 500);
}

Audit::log($user['id'], 'SESSION_EXPORT_EXCEL', 'stock_opname_sessions', $sessionId, null, ['filename' => $sessionNo . '.xlsx']);

$filename = 'StokOpname_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $sessionNo) . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
echo $bytes;
