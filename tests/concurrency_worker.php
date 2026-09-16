<?php
declare(strict_types=1);

/**
 * Single OUT attempt in its OWN process with its OWN PDO connection —
 * launched twice in parallel by concurrency_test.sh to create genuine
 * concurrent contention on the same FIFO batch row.
 *
 * argv: item_id warehouse_id qty created_by
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/FifoService.php';

use App\Services\Database;
use App\Services\FifoService;
use App\Services\InsufficientStockException;

[$script, $itemId, $warehouseId, $qty, $createdBy] = $argv;

// Force a brand-new connection in THIS process (Database::connection() is a
// per-process static singleton, so two separate `php` invocations already
// get two separate MySQL connections/sessions — exactly what real
// concurrent API requests would look like).
$pdo = Database::connection();
$kgUnitId = (int) $pdo->query("SELECT id FROM units WHERE code='KG'")->fetchColumn();

try {
    $result = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, [
        'transaction_uuid' => 'conc-out-' . getmypid() . '-' . bin2hex(random_bytes(4)),
        'item_id' => (int) $itemId, 'warehouse_id' => (int) $warehouseId,
        'input_qty' => (float) $qty, 'input_unit_id' => $kgUnitId,
        'transaction_date' => '2026-09-02 08:00:00', 'created_by' => (int) $createdBy,
    ]));
    echo "SUCCESS pid=" . getmypid() . " total_cost={$result['total_cost']}\n";
} catch (InsufficientStockException $e) {
    echo "REJECTED pid=" . getmypid() . " " . $e->getMessage() . "\n";
} catch (Throwable $e) {
    echo "ERROR pid=" . getmypid() . " " . get_class($e) . ": " . $e->getMessage() . "\n";
}
