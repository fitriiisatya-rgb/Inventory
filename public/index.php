<?php
declare(strict_types=1);

/**
 * Front controller for the internal REST API (Section 24 / PHASE D0.3
 * frozen contract — see docs/API_CONTRACT.md). Document root for the API
 * vhost/subpath must point here; every request is routed through this
 * single script so response shape, CORS, auth, CSRF and error handling are
 * enforced in exactly one place.
 *
 * Response shape (frozen — do not change without updating API_CONTRACT.md):
 *   success: {"success":true,"data":{...},"message":"..."}
 *   error:   {"success":false,"error":{"code":"...","message":"..."}}
 * The frontend must branch on error.code, never on error.message text.
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/Exceptions.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UnitConversionService.php';
require_once __DIR__ . '/../services/UnitNormalizationService.php';
require_once __DIR__ . '/../services/PriceAnomalyService.php';
require_once __DIR__ . '/../services/IdempotencyService.php';
require_once __DIR__ . '/../services/MigrationNegativeStockService.php';
require_once __DIR__ . '/../services/StockPolicyService.php';
require_once __DIR__ . '/../services/StockReportService.php';
require_once __DIR__ . '/../services/TransactionHistoryService.php';
require_once __DIR__ . '/../services/SupplierService.php';
require_once __DIR__ . '/../services/BakeryDestinationService.php';
require_once __DIR__ . '/../services/MasterDataSafetyService.php';
require_once __DIR__ . '/../services/MasterRecordService.php';
require_once __DIR__ . '/../services/WarehouseReportService.php';
require_once __DIR__ . '/../services/WarehouseCutoverService.php';
require_once __DIR__ . '/../services/WarehouseCutoverImportService.php';
require_once __DIR__ . '/../services/TraceService.php';
require_once __DIR__ . '/../services/InventoryService.php';
require_once __DIR__ . '/../services/FifoService.php';
require_once __DIR__ . '/../services/PeriodLockService.php';
require_once __DIR__ . '/../services/WarehouseLockService.php';
require_once __DIR__ . '/../services/WarehouseGuardService.php';
require_once __DIR__ . '/../services/StockAdjustmentService.php';
require_once __DIR__ . '/../services/StockOpnameService.php';
require_once __DIR__ . '/../services/StockOpnamePhotoService.php';
require_once __DIR__ . '/../services/StockOpnameCounterAccountService.php';
require_once __DIR__ . '/../services/StockOpnameReferenceImportService.php';
require_once __DIR__ . '/../services/StockOpnameFinalExportService.php';
require_once __DIR__ . '/../services/StockOpnameBookStockService.php';
require_once __DIR__ . '/../services/StockOpnameMonthlyReportService.php';
require_once __DIR__ . '/../services/StockOpnameMonthlyReportPrintService.php';
require_once __DIR__ . '/../services/TransferService.php';
require_once __DIR__ . '/../services/ProductionService.php';
require_once __DIR__ . '/../services/BookClosingService.php';
require_once __DIR__ . '/../services/ReconciliationService.php';
require_once __DIR__ . '/../services/SystemHealthService.php';
require_once __DIR__ . '/../services/VoidService.php';
require_once __DIR__ . '/../services/XlsxReaderService.php';
require_once __DIR__ . '/../services/ImportMasterItemService.php';
require_once __DIR__ . '/../services/ImportSimpleMasterService.php';
require_once __DIR__ . '/../services/ImportOpeningStockService.php';
require_once __DIR__ . '/../services/ImportTemplateService.php';
require_once __DIR__ . '/../services/OpeningValidationService.php';
require_once __DIR__ . '/../services/CostNormalizationService.php';
require_once __DIR__ . '/../services/OpeningReconciliationService.php';
require_once __DIR__ . '/../services/MovementReconciliationReviewService.php';
require_once __DIR__ . '/../services/ImportHistoricalTransactionService.php';
require_once __DIR__ . '/../services/InventoryHppReportService.php';
require_once __DIR__ . '/../services/DashboardInventoryService.php';
require_once __DIR__ . '/../services/InventoryMovementReportService.php';
require_once __DIR__ . '/../services/MovementDailyReportService.php';
require_once __DIR__ . '/../services/InventoryReconciliationReportService.php';
require_once __DIR__ . '/../services/InventorySummaryReportService.php';
require_once __DIR__ . '/../services/TransferReportService.php';
require_once __DIR__ . '/../services/StockOpnameReportService.php';
require_once __DIR__ . '/../services/StockOpnameJejakService.php';
require_once __DIR__ . '/../services/StockOpnamePrintService.php';
require_once __DIR__ . '/../services/AdjustmentReportService.php';
require_once __DIR__ . '/../services/ExpiryReportService.php';
require_once __DIR__ . '/../services/SlowMovementReportService.php';
require_once __DIR__ . '/../services/ExcelWriterService.php';
require_once __DIR__ . '/../services/PurchaseCostingService.php';
require_once __DIR__ . '/../services/PurchaseCostingGateway.php';
require_once __DIR__ . '/../services/PurchaseInvoiceService.php';
require_once __DIR__ . '/../services/StockOutService.php';
require_once __DIR__ . '/../services/StockOutDocumentService.php';
require_once __DIR__ . '/../services/ImportLiveTransactionService.php';
require_once __DIR__ . '/../services/ImportStockPolicyService.php';
require_once __DIR__ . '/../services/ItemBarcodeService.php';
require_once __DIR__ . '/../services/ItemPriceService.php';
require_once __DIR__ . '/../services/NumberingService.php';
require_once __DIR__ . '/../services/DistributionOrderService.php';
require_once __DIR__ . '/../services/PricingPolicyService.php';
require_once __DIR__ . '/../services/DistributionInvoiceService.php';
require_once __DIR__ . '/../services/DistributionReportService.php';
require_once __DIR__ . '/../services/DistributionPrintService.php';

use App\Services\AuthService;
use App\Services\Database;
use App\Services\InsufficientStockException;
use App\Services\NegativeStockException;
use App\Services\PriceAnomalyException;
use App\Services\PeriodLockedException;
use App\Services\WarehouseLockedException;
use App\Services\WarehouseInactiveException;
use App\Services\WarehouseActivationLockedException;
use App\Services\WarehouseCutoverLockedException;
use App\Services\WarehouseGuardService;
use App\Services\CostRequiredException;
use App\Services\UnitConversionNotApprovedException;
use App\Services\NegativeMigrationStockRequiresAdjustmentException;
use App\Services\MigrationNegativeStockService;
use App\Services\StockPolicyService;
use App\Services\StockReportService;
use App\Services\TransactionHistoryService;
use App\Services\SupplierService;
use App\Services\BakeryDestinationService;
use App\Services\MasterDataSafetyService;
use App\Services\WarehouseReportService;
use App\Services\WarehouseCutoverService;
use App\Services\WarehouseCutoverImportService;
use App\Services\TraceService;
use App\Services\InventoryHppReportService;
use App\Services\ExcelWriterService;
use App\Services\AuditService;
use App\Services\RateLimitedException;
use App\Services\NotFoundException;
use App\Services\TransferAlreadyReceivedException;
use App\Services\TransferAlreadyCancelledException;
use App\Services\DuplicateRequestException;
use App\Services\ImportValidationException;
use App\Services\OpeningReconciliationService;
use App\Services\MovementReconciliationReviewService;
use App\Services\ValidationException;
use App\Services\CountingModeConflictException;
use App\Services\ClaimConflictException;
use App\Services\FindingsCheckpointBRequiredException;
use App\Services\ItemLockedException;
use App\Services\FifoService;
use App\Services\InventoryService;
use App\Services\TransferService;
use App\Services\StockOpnameService;
use App\Services\StockOpnamePhotoService;
use App\Services\StockOpnameCounterAccountService;
use App\Services\StockOpnameReferenceImportService;
use App\Services\StockOpnameFinalExportService;
use App\Services\StockOpnameBookStockService;
use App\Services\StockOpnameMonthlyReportService;
use App\Services\StockOpnameMonthlyReportPrintService;
use App\Services\StockAdjustmentService;
use App\Services\ProductionService;
use App\Services\BookClosingService;
use App\Services\ReconciliationService;
use App\Services\SystemHealthService;
use App\Services\VoidService;
use App\Services\ImportMasterItemService;
use App\Services\ImportSimpleMasterService;
use App\Services\ImportOpeningStockService;
use App\Services\ImportTemplateService;
use App\Services\ImportHistoricalTransactionService;
use App\Services\InventoryMovementReportService;
use App\Services\InventoryReconciliationReportService;
use App\Services\InventorySummaryReportService;
use App\Services\TransferReportService;
use App\Services\StockOpnameReportService;
use App\Services\StockOpnamePrintService;
use App\Services\AdjustmentReportService;
use App\Services\ExpiryReportService;
use App\Services\SlowMovementReportService;
use App\Services\PurchaseCostingService;
use App\Services\PurchaseCostingGateway;
use App\Services\UnitConversionService;
use App\Services\ImportLiveTransactionService;
use App\Services\ImportStockPolicyService;
use App\Services\ItemBarcodeService;
use App\Services\ItemPriceService;
use App\Services\DistributionOrderService;
use App\Services\PricingPolicyService;
use App\Services\DistributionInvoiceService;
use App\Services\DistributionReportService;
use App\Services\DistributionPrintService;

$config = require __DIR__ . '/../config/config.php';

// ---- error handling: never leak stack traces to a production client (Section: "error PHP tidak ditampilkan") ----
ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
error_reporting(E_ALL);
if (!is_dir(dirname($config['log']['path']))) {
    @mkdir(dirname($config['log']['path']), 0755, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $config['log']['path']);

/** Success envelope — frozen shape, see file docblock. */
function inv_ok(mixed $data, string $message = 'OK', int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** PHASE V2.11C — raw print-friendly HTML response (DO/Invoice print), same non-JSON convention as inv_export_csv(). */
function inv_html(string $html): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

/**
 * Error envelope — frozen shape, see file docblock. Frontend branches on
 * `code`, never on `message`. PHASE V2.5: `$details` is a purely additive,
 * optional extra key (e.g. `dependencies` for a blocked void/reversal) —
 * omitted entirely (not even present as null) for every pre-V2.5 caller, so
 * the frozen `{code, message}` shape is unchanged for every existing
 * consumer that doesn't pass it.
 */
function inv_error(int $httpStatus, string $code, string $message, ?array $details = null): never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error += $details;
    }
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * PHASE V2.6C — shared CSV export infrastructure, reused by every report's
 * export route instead of duplicating header/escaping/filename logic
 * per-report. Every caller already did its own filtering/warehouse-scope
 * resolution BEFORE building `$rows` — this function only ever writes
 * what it's handed, never fetches or filters on its own.
 *
 * - `$filenameParts` is joined with '_' and sanitized to [A-Za-z0-9_-]
 *   only (e.g. ['laporan-pembelian', 'SCM', '2026-09-01', '2026-09-30']
 *   -> laporan-pembelian_SCM_2026-09-01_2026-09-30.csv).
 * - A UTF-8 BOM is written first so Excel opens Rupiah/Indonesian text
 *   correctly without a manual "Import as UTF-8" step.
 * - `$escape` is always passed explicitly to fputcsv() — PHP 8.4
 *   deprecates relying on its implicit default (see the V2.6B fix to the
 *   pre-existing Laporan Stok export this same phase's regression run
 *   surfaced).
 * - Never mutates anything; the route calling this must already be a
 *   pure GET/read path.
 *
 * @param list<string> $filenameParts
 * @param list<string> $header
 * @param iterable<array> $rows
 * @param callable(array):list<scalar|null> $mapRow
 */
function inv_export_csv(array $filenameParts, array $header, iterable $rows, callable $mapRow): never
{
    $safeParts = array_map(static fn ($p) => preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $p), $filenameParts);
    $filename = implode('_', array_filter($safeParts, static fn ($p) => $p !== ''));
    if ($filename === '') {
        $filename = 'export';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel-friendly
    inv_csv_write_row($out, $header);
    foreach ($rows as $row) {
        inv_csv_write_row($out, $mapRow($row));
    }
    fclose($out);
    exit;
}

/**
 * PHASE V2.6 FINAL GATE — CSV/Excel formula-injection hardening, ONE
 * centralized helper for every CSV export in the app (inv_export_csv()
 * above, plus the one ad-hoc writer at GET /reports/movement/daily that
 * needs a second header block). Export-layer only: this never touches
 * what is stored or returned by the JSON API, only the bytes written
 * into the downloaded .csv file.
 *
 * A text cell whose value starts with '=', '+', '-' or '@' can be
 * interpreted by Excel/Sheets/LibreOffice as a formula when the CSV is
 * opened (e.g. a `reason`, `actor`, or item-name field set to
 * `=cmd|'/c calc'!A1` by whoever entered that data). The standard
 * mitigation is to prefix such a cell with a single quote so the
 * spreadsheet application treats it as literal text.
 *
 * This must never re-classify a genuine numeric business figure (a
 * negative variance like -1250.50, a signed value, a percentage) as
 * "formula-like" text merely because its string form starts with '-'
 * or '+' — is_numeric() covers both real PHP int/float values AND the
 * numeric strings PDO returns for DECIMAL columns, so those always pass
 * through untouched and unquoted.
 */
function inv_csv_safe_cell(mixed $value): mixed
{
    if (!is_string($value) || $value === '' || is_numeric($value)) {
        return $value;
    }
    if (preg_match('/^[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }
    return $value;
}

/** @param list<scalar|null> $row */
function inv_csv_write_row($out, array $row): void
{
    fputcsv($out, array_map('inv_csv_safe_cell', $row), ',', '"', '\\');
}

// ---- CORS: only the configured new-domain origins, never a silent open policy ----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['cors']['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

AuthService::bootSession($config['session']);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = preg_replace('#^/api#', '', rtrim($path, '/'));
$path = $path === '' ? '/' : $path;

$rawBody = file_get_contents('php://input') ?: '';
$input = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $input = $decoded;
    }
}
$query = $_GET;

set_exception_handler(function (Throwable $e) use ($path) {
    // A genuine unique-key race (two concurrent requests with the identical
    // idempotency key, both past the "already exists?" pre-check before
    // either committed) surfaces here as a raw PDO duplicate-entry error —
    // translate it instead of leaking a 500.
    if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
        $e = new DuplicateRequestException();
    }

    error_log('[unhandled] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    $importPrefixed = str_starts_with($path, '/import/');
    $map = [
        InsufficientStockException::class        => ['code' => 422, 'label' => 'INSUFFICIENT_STOCK'],
        NegativeStockException::class             => ['code' => 422, 'label' => 'NEGATIVE_STOCK'],
        PriceAnomalyException::class              => ['code' => 422, 'label' => 'PRICE_ANOMALY'],
        PeriodLockedException::class              => ['code' => 423, 'label' => 'PERIOD_LOCKED'],
        WarehouseLockedException::class           => ['code' => 423, 'label' => 'OPNAME_ACTIVE'],
        WarehouseInactiveException::class         => ['code' => 422, 'label' => 'WAREHOUSE_INACTIVE'],
        WarehouseActivationLockedException::class => ['code' => 422, 'label' => 'WAREHOUSE_ACTIVATION_LOCKED'],
        WarehouseCutoverLockedException::class    => ['code' => 422, 'label' => 'WAREHOUSE_CUTOVER_LOCKED'],
        CostRequiredException::class              => ['code' => 422, 'label' => 'COST_REQUIRED'],
        UnitConversionNotApprovedException::class => ['code' => 422, 'label' => 'UNIT_CONVERSION_NOT_APPROVED'],
        NegativeMigrationStockRequiresAdjustmentException::class => ['code' => 422, 'label' => 'NEGATIVE_MIGRATION_STOCK_REQUIRES_ADJUSTMENT'],
        RateLimitedException::class               => ['code' => 429, 'label' => 'RATE_LIMITED'],
        NotFoundException::class                  => ['code' => 404, 'label' => 'NOT_FOUND'],
        TransferAlreadyReceivedException::class   => ['code' => 409, 'label' => 'TRANSFER_ALREADY_RECEIVED'],
        TransferAlreadyCancelledException::class  => ['code' => 409, 'label' => 'TRANSFER_ALREADY_CANCELLED'],
        DuplicateRequestException::class          => ['code' => 409, 'label' => 'DUPLICATE_REQUEST'],
        ImportValidationException::class          => ['code' => 422, 'label' => 'IMPORT_VALIDATION_FAILED'],
        // PHASE V2.5 — transaction correction / void / transfer reversal.
        OpeningProtectedException::class                    => ['code' => 422, 'label' => 'OPENING_PROTECTED'],
        TransactionAlreadyVoidException::class              => ['code' => 409, 'label' => 'TRANSACTION_ALREADY_VOID'],
        TransferAlreadyReversedException::class             => ['code' => 409, 'label' => 'TRANSFER_ALREADY_REVERSED'],
        VoidHasDownstreamDependenciesException::class       => ['code' => 422, 'label' => 'VOID_HAS_DOWNSTREAM_DEPENDENCIES'],
        TransferReversalHasDownstreamDependenciesException::class => ['code' => 422, 'label' => 'TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES'],
        ValidationException::class                => ['code' => 422, 'label' => $importPrefixed ? 'IMPORT_VALIDATION_FAILED' : 'VALIDATION_ERROR'],
        // PHASE V2.14.10.1 — the two Stock Opname write-models must never
        // mix; a claim must be currently valid, not merely presented.
        CountingModeConflictException::class      => ['code' => 409, 'label' => 'COUNTING_MODE_CONFLICT'],
        ClaimConflictException::class              => ['code' => 409, 'label' => 'CLAIM_LOST'],
        FindingsCheckpointBRequiredException::class => ['code' => 409, 'label' => 'FINDINGS_V1_CHECKPOINT_B_REQUIRED'],
        // PHASE: Edit Barang — base_unit_id change is refused once an item
        // has posted transactions (UnitConversionService::changeBaseUnit()
        // already enforces this; this class existed before but had no
        // route exercising it, so it was never mapped to a friendly HTTP
        // code until Edit Barang became its first real caller).
        ItemLockedException::class                => ['code' => 422, 'label' => 'ITEM_LOCKED'],
    ];
    // PHASE V2.5: the two dependency exceptions carry a structured
    // `dependencies` list the frontend renders verbatim (spec: "Then list
    // dependency transaction IDs") — checked before the generic loop so the
    // extra key rides along without touching every other error path.
    if ($e instanceof VoidHasDownstreamDependenciesException) {
        inv_error(422, 'VOID_HAS_DOWNSTREAM_DEPENDENCIES', $e->getMessage(), ['dependencies' => $e->dependencies]);
    }
    if ($e instanceof TransferReversalHasDownstreamDependenciesException) {
        inv_error(422, 'TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES', $e->getMessage(), ['dependencies' => $e->dependencies]);
    }
    foreach ($map as $class => $info) {
        if ($e instanceof $class) {
            inv_error($info['code'], $info['label'], $e->getMessage());
        }
    }
    inv_error(500, 'INTERNAL_ERROR', 'Internal server error');
});

// ---- CSRF (Section: cookie session -> CSRF protection) ----
// Every mutating request from an authenticated session must carry the
// synchronizer token issued at login. Login itself is exempt (no session
// yet); GET/HEAD/OPTIONS are exempt (must stay side-effect-free).
if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && $path !== '/auth/login') {
    if (!empty($_SESSION['user_id'])) {
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!AuthService::verifyCsrf($csrfHeader)) {
            inv_error(403, 'CSRF_INVALID', 'Missing or invalid X-CSRF-Token header');
        }
    }
}

function inv_require_auth(bool $allowPasswordChangePending = false): array
{
    $user = AuthService::currentUser();
    if ($user === null) {
        inv_error(401, 'UNAUTHENTICATED', 'Not authenticated');
    }
    // PHASE G21: a provisioned account (temp generated password) can do
    // nothing except change its password until it does — enforced here so
    // every other route in this file inherits the block automatically,
    // without needing its own check.
    if (!$allowPasswordChangePending && !empty($user['must_change_password'])) {
        inv_error(403, 'PASSWORD_CHANGE_REQUIRED', 'Password must be changed before continuing');
    }
    return $user;
}

function inv_require_permission(PDO $pdo, array $user, string $permission): void
{
    if (!AuthService::hasPermission($pdo, $user['role_code'], $permission)) {
        inv_error(403, 'FORBIDDEN', "Missing permission: {$permission}");
    }
}

function inv_require_warehouse_scope(array $user, int $warehouseId): void
{
    try {
        AuthService::assertWarehouseScope($user, $warehouseId);
    } catch (ValidationException $e) {
        inv_error(403, 'FORBIDDEN', $e->getMessage());
    }
}

/**
 * PHASE V2.14.9.1 — Stock-Opname-SPECIFIC warehouse scope guard. Deliberately
 * NOT a change to AuthService::assertWarehouseScope() (that function is
 * shared by every other warehouse-scoped route in the app — transactions,
 * transfers, adjustments, reports — and widening it to also restrict ADMIN
 * would be a global authorization change nobody asked for here).
 *
 * "ADMIN Transit" business definition for Stock Opname only: the existing
 * ADMIN role, with users.warehouse_id set to a specific warehouse, is
 * restricted to operating on THAT warehouse's opname sessions only.
 * SUPERADMIN is always allowed (subject to whatever permission check the
 * route already performs). An ADMIN with warehouse_id = NULL keeps the
 * exact pre-V2.14.9.1 behavior (unscoped) — nothing changes for that
 * account. STOCK keeps its existing behavior via assertWarehouseScope().
 */
function inv_require_so_warehouse_scope(array $user, int $warehouseId): void
{
    if ($user['role_code'] === 'SUPERADMIN') {
        return;
    }
    if ($user['role_code'] === 'ADMIN' && $user['warehouse_id'] !== null) {
        if ((int) $user['warehouse_id'] !== $warehouseId) {
            inv_error(403, 'FORBIDDEN', "Stock Opname: this account is scoped to warehouse {$user['warehouse_id']}, not {$warehouseId}");
        }
        return;
    }
    // STOCK (and any other role) — unchanged, existing general guard.
    inv_require_warehouse_scope($user, $warehouseId);
}

/**
 * PHASE V2.14.9.1 — same rule as inv_require_so_warehouse_scope(), but for
 * a LIST-style Stock Opname route that has no single target warehouse to
 * validate against: an ADMIN scoped to a warehouse has their filter FORCED
 * to that warehouse (never silently shown another warehouse's sessions,
 * and never merely hidden client-side), exactly mirroring how STOCK is
 * already forced today. SUPERADMIN and an unscoped ADMIN see whatever
 * $requestedWarehouseId (or lack thereof) they asked for, unchanged.
 */
function inv_so_resolve_warehouse_scope(array $user, ?int $requestedWarehouseId): ?int
{
    if ($user['role_code'] === 'ADMIN' && $user['warehouse_id'] !== null) {
        return (int) $user['warehouse_id'];
    }
    if ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null) {
        return (int) $user['warehouse_id'];
    }
    return $requestedWarehouseId;
}

/**
 * PHASE V2.14.10 — session-scoped counter authorization. "Counter
 * authority comes from the SESSION ASSIGNMENT, not from promoting the
 * person's global role": an ACTIVE user explicitly assigned to P1/P2 for
 * THIS SPECIFIC session must be able to open it, claim/release a line,
 * and submit a count for their own role — regardless of whether their
 * global role holds STOCK_OPNAME_MANAGE at all, and regardless of the
 * normal warehouse-scope rule (a supervisor may deliberately assign a
 * counter across warehouses; the session's own warehouse is what they're
 * counting, not their own users.warehouse_id).
 *
 * This grant is checked FRESH on every call (never cached on
 * $_SESSION — the global role/permission set is untouched), is scoped to
 * exactly the $sessionId passed in, and never widens beyond whatever the
 * calling route does with the returned role — no other route, warehouse,
 * or Stock Opname session is reachable through it. A caller who instead
 * holds $permission globally (the pre-V2.14.10 SUPERADMIN/ADMIN/STOCK
 * path) is reported as 'privileged' and the caller is expected to apply
 * whatever additional check (e.g. inv_require_so_warehouse_scope) it
 * always has.
 *
 * @return array{mode:'privileged'}|array{mode:'counter', role:string}
 */
function inv_require_so_counter_or_permission(PDO $pdo, array $user, int $sessionId, string $permission): array
{
    try {
        $role = StockOpnameService::assertIsActiveCounter($pdo, $sessionId, (int) $user['id']);
        return ['mode' => 'counter', 'role' => $role];
    } catch (ValidationException $e) {
        // Not an assigned counter for this session — fall through to the
        // caller's own global-permission path.
    }
    if (!AuthService::hasPermission($pdo, $user['role_code'], $permission)) {
        inv_error(403, 'FORBIDDEN', "Missing permission: {$permission}");
    }
    return ['mode' => 'privileged'];
}

function inv_require_division_scope(array $user, ?int $divisionId): void
{
    try {
        AuthService::assertDivisionScope($user, $divisionId);
    } catch (ValidationException $e) {
        inv_error(403, 'FORBIDDEN', $e->getMessage());
    }
}

/**
 * Same "STOCK is always forced to their own warehouse, never a
 * company-wide rollup" pattern as GET /reports/stock and GET
 * /reports/transactions — centralized here since the HPP report has 5
 * routes that all need it identically.
 */
/**
 * Pergerakan Stok Harian (redesign): common query parsing. Returns [start, end, warehouse(scope-resolved), category, q, item].
 * The warehouse is resolved through inv_hpp_resolve_warehouse_scope(), so a warehouse-limited user can never widen the scope
 * by editing the query string.
 */
function inv_movement_params(array $user, array $query): array
{
    $start = (string) ($query['start_date'] ?? '');
    $end = (string) ($query['end_date'] ?? '');
    if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
        inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
    }
    $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
    $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
    $cat = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
    $q = isset($query['q']) && trim((string) $query['q']) !== '' ? trim((string) $query['q']) : null;
    $item = isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null;
    return [$start, $end, $warehouseId, $cat, $q, $item];
}

function inv_hpp_resolve_warehouse_scope(array $user, ?int $requestedWarehouseId): ?int
{
    if ($user['role_code'] === 'STOCK') {
        if (empty($user['warehouse_id'])) {
            inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
        }
        return (int) $user['warehouse_id'];
    }
    if ($requestedWarehouseId !== null) {
        inv_require_warehouse_scope($user, $requestedWarehouseId);
    }
    return $requestedWarehouseId;
}

/**
 * PHASE V2.6 FINAL GATE — audit_logs (and every entity_type it covers:
 * users, roles, categories, suppliers, system settings, ...) has no
 * reliable per-row warehouse column, and several entity_types have no
 * warehouse concept at all. That makes a per-event warehouse filter
 * unsafe to build honestly: it would either guess ownership (wrong by
 * construction for entity_types with no warehouse) or silently show a
 * mix of "resolved" and "unresolved" rows to a warehouse-scoped user.
 * Per the release-gate policy, the secure default for an unresolved case
 * is full denial, not a partial/best-effort filter — so this route
 * never even queries audit_logs for a warehouse-scoped role. Today only
 * STOCK carries a non-null warehouse_id (and STOCK doesn't hold
 * AUDIT_LOG_VIEW in the seeded permission set either — see
 * database/schema.sql — so this is redundant defense-in-depth against
 * that permission grant ever changing, applied identically to both
 * GET /reports/audit and GET /reports/audit/export).
 */
function inv_require_company_wide_audit_scope(array $user): void
{
    if (!empty($user['warehouse_id'])) {
        inv_error(403, 'FORBIDDEN', 'Audit Transaksi is a company-wide report and is not available to a warehouse-scoped role.');
    }
}

/**
 * PHASE V2.6C — interactive-period guard for Rekonsiliasi Arus Stok. Never
 * touches InventoryReconciliationReportService's own (already-validated)
 * math — this is a pure input-validation gate at the route boundary, same
 * layer as the existing start<=end check every report route already has.
 * 366 (not 365) so a genuine leap-year 12-month span is never rejected.
 */
function inv_require_reconciliation_range(string $start, string $end): void
{
    $days = (int) floor((strtotime($end) - strtotime($start)) / 86400) + 1;
    if ($days > 366) {
        inv_error(422, 'VALIDATION_ERROR', 'Rentang Rekonsiliasi maksimal 366 hari. Pilih periode yang lebih pendek.', ['max_days' => 366, 'requested_days' => $days]);
    }
}

/**
 * PHASE V2.7 — resolves the unit conversion factor exactly like
 * FifoService::postIn() will (same UnitConversionService lookup, same
 * transaction_date), then builds PurchaseCostingService's cost preview
 * for the CURRENT single-line-per-transaction Stock IN flow (see that
 * service's own architecture note — $lines always has exactly one
 * element here). Returns the full preview plus the equivalent
 * unit_price_input that FifoService::postIn() must be called with so its
 * own, completely unmodified unit_cost_base/subtotal formulas land
 * exactly on final_unit_cost_base/final_inventory_cost.
 */
// PHASE V2.8: the actual glue now lives in PurchaseCostingGateway (so
// ImportLiveTransactionService can reuse it too, outside of any HTTP
// request) — these two wrappers are kept so every existing call site
// below is untouched, and behave byte-for-byte as before.
function inv_purchase_costing_preview(PDO $pdo, array $input): array
{
    return PurchaseCostingGateway::preview($pdo, $input);
}

function inv_persist_purchase_costing(PDO $pdo, array $posted, int $createdBy, array $costing): void
{
    PurchaseCostingGateway::persist($pdo, $posted, $createdBy, $costing);
}

/**
 * PHASE V2.7.9 — enriches Laporan Pembelian rows (from
 * TransactionHistoryService::list(), unchanged) with the purchase costing
 * breakdown where it exists. A LEFT-JOIN-shaped lookup, never a required
 * one: any row whose transaction was never posted through the costed flow
 * (OPENING/TRANSFER_IN/historical/pre-V2.7 IN) simply gets
 * purchase_costing = null — TransactionHistoryService itself is never
 * modified, this only adds a field to the already-returned rows.
 */
function inv_enrich_purchase_costing(PDO $pdo, array $rows): array
{
    $lineIds = array_values(array_unique(array_column($rows, 'line_id')));
    if ($lineIds === []) {
        return $rows;
    }
    $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM purchase_line_costs WHERE transaction_line_id IN ({$placeholders})");
    $stmt->execute($lineIds);
    $byLineId = [];
    foreach ($stmt->fetchAll() as $c) {
        $byLineId[(int) $c['transaction_line_id']] = $c;
    }
    foreach ($rows as &$r) {
        $c = $byLineId[$r['line_id']] ?? null;
        $r['purchase_costing'] = $c === null ? null : [
            'gross_unit_price_input' => round((float) $c['gross_unit_price_input'], 4),
            'gross_amount' => round((float) $c['gross_amount'], 4),
            'line_discount_amount' => round((float) $c['line_discount_amount'], 4),
            'invoice_discount_allocated' => round((float) $c['invoice_discount_allocated'], 4),
            'net_purchase_before_tax' => round((float) $c['net_purchase_before_tax'], 4),
            'ppn_allocated' => round((float) $c['ppn_allocated'], 4),
            'freight_allocated' => round((float) $c['freight_allocated'], 4),
            'final_inventory_cost' => round((float) $c['final_inventory_cost'], 4),
        ];
    }
    unset($r);
    return $rows;
}

$pdo = Database::connection();

// ---- routing table ----
// Static routes are matched by exact "METHOD /path" key; parameterized
// routes are declared with {name} placeholders and matched by regex.
$routes = [
    'POST /auth/login' => function () use ($pdo, $input) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user = AuthService::attemptLogin($pdo, (string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''), $ip);
        if ($user === null) {
            inv_error(401, 'UNAUTHENTICATED', 'Invalid credentials');
        }
        inv_ok([
            'username' => $user['username'], 'role' => $user['role_code'],
            'must_change_password' => (bool) $user['must_change_password'],
            'csrf_token' => AuthService::csrfToken(),
            // PHASE V2.14.3 — read fresh from role_permissions on every
            // login, never cached in $_SESSION, so frontend visibility
            // (Auth.applyRoleVisibility()) always matches the backend's
            // own current authorization state for this role.
            'permissions' => AuthService::permissionsForRole($pdo, $user['role_code']),
        ], 'Logged in');
    },
    'POST /auth/logout' => function () {
        AuthService::logout();
        inv_ok(null, 'Logged out');
    },
    'GET /auth/me' => function () use ($pdo) {
        $user = AuthService::currentUser();
        if ($user) {
            $user['csrf_token'] = AuthService::csrfToken();
            // PHASE V2.14.3 — same as POST /auth/login: read fresh from the
            // DB on every call, never from session state, so a permission
            // grant/revoke is visible on the very next /auth/me without a
            // new login.
            $user['permissions'] = AuthService::permissionsForRole($pdo, $user['role_code']);
        }
        inv_ok($user, 'OK');
    },
    // PHASE G21: the one action allowed while must_change_password=1.
    'POST /auth/change-password' => function () use ($pdo, $input) {
        $user = inv_require_auth(true);
        AuthService::changePassword($pdo, (int) $user['id'], (string) ($input['current_password'] ?? ''), (string) ($input['new_password'] ?? ''));
        inv_ok(null, 'Password changed');
    },

    'GET /items' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT * FROM items ORDER BY name')->fetchAll(), 'OK');
    },
    'GET /warehouses' => function () use ($pdo) {
        $user = inv_require_auth();

        if ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null) {
            $stmt = $pdo->prepare(
                'SELECT * FROM warehouses WHERE id = :id AND is_active = 1 ORDER BY name'
            );
            $stmt->execute(['id' => (int) $user['warehouse_id']]);
            inv_ok($stmt->fetchAll(), 'OK');
        }

        // PHASE V2.13: this route is the sole source for Master.warehouses()
        // — the shared cache every OPERATIONAL warehouse dropdown across the
        // whole app reads from (Stock IN/OUT, Transfers, Opname, etc.). An
        // inactive warehouse (e.g. Karang Tengah pre-go-live) must never
        // appear here for ANY role, not only STOCK-scoped ones — this is a
        // generic rule, so the same is_active=1 filter this endpoint already
        // applied to STOCK users now applies unconditionally. The Master
        // Gudang admin screen intentionally does NOT use this route — it
        // reads GET /warehouses/report, which already supports listing
        // inactive rows (its own explicit "INACTIVE" status filter), so
        // admins can still see and manage an inactive warehouse there.
        inv_ok(
            $pdo->query('SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name')->fetchAll(),
            'OK'
        );
    },
    // PHASE V2.1: extended with optional search/active/sort/linked_item_count —
    // every existing caller passing no query params gets the exact same
    // unfiltered, name-ascending, full-column list as before.
    'GET /suppliers' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.name LIKE :q_n OR s.code LIKE :q_c OR s.contact_name LIKE :q_ct OR s.address LIKE :q_a OR s.email LIKE :q_e)';
            $bind['q_n'] = $bind['q_c'] = $bind['q_ct'] = $bind['q_a'] = $bind['q_e'] = '%' . $q . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 's.is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 's.is_active = 0';
        }
        $sortMap = ['name' => 's.name', 'newest' => 's.created_at', 'oldest' => 's.created_at', 'linked_items' => 'linked_item_count'];
        $sortKey = $sortMap[$query['sort'] ?? 'name'] ?? 's.name';
        $dir = strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' || ($query['sort'] ?? '') === 'oldest' ? 'DESC' : 'ASC';
        if (($query['sort'] ?? '') === 'newest') {
            $dir = 'DESC';
        }
        $sql = "
            SELECT s.*, COALESCE(li.cnt, 0) AS linked_item_count
            FROM suppliers s
            LEFT JOIN (SELECT default_supplier_id, COUNT(*) AS cnt FROM items WHERE default_supplier_id IS NOT NULL GROUP BY default_supplier_id) li
                ON li.default_supplier_id = s.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$sortKey} {$dir}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
    },
    // PHASE V2: Master Vendor/Supplier CRUD (audited: suppliers already
    // existed as an import-only table — this is the first write path).
    // Soft-delete only via PUT .../{id} with is_active:false — never DELETE.
    'POST /suppliers' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_SUPPLIER_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => SupplierService::create($tx, $input));
        inv_ok($result, 'Supplier created');
    },
    'PUT /suppliers/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_SUPPLIER_MANAGE');
        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => SupplierService::update($tx, (int) $params['id'], $input));
        inv_ok($result, 'Supplier updated');
    },
    'DELETE /suppliers/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_SUPPLIER_MANAGE');
        $supplierId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id');
        $existing->execute(['id' => $supplierId]);
        $supplier = $existing->fetch();
        if ($supplier === false) {
            inv_error(404, 'NOT_FOUND', 'supplier not found');
        }

        $refs = MasterDataSafetyService::checkSupplierReferences($pdo, $supplierId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'SUPPLIER_DELETE_ATTEMPT', 'suppliers', $supplierId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by items/transaction data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Vendor '{$supplier['name']}' memiliki referensi barang/transaksi dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM suppliers WHERE id = :id')->execute(['id' => $supplierId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'SUPPLIER_DELETE_SUCCESS', 'suppliers', $supplierId, ['name' => $supplier['name']], null, null);
        inv_ok(['success' => true], 'Supplier permanently deleted');
    },

    // PHASE V2: Master Bakery Tujuan — a distribution endpoint for OUT
    // transactions, deliberately separate from warehouses/divisions (see
    // docs/PHASE_V2_TECHNICAL_DESIGN.md Section 5). GET is authenticated-
    // only (no special permission) since every STOCK user posting an OUT
    // needs this list; write is gated on MASTER_BAKERY_DESTINATION_MANAGE.
    // PHASE V2.1: extended with optional search/city/route/active/sort —
    // every existing caller passing no query params gets the exact same
    // unfiltered, name-ascending, full-column list as before.
    'GET /bakery-destinations' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(code LIKE :q_c OR name LIKE :q_n)';
            $bind['q_c'] = $bind['q_n'] = '%' . $q . '%';
        }
        $city = trim((string) ($query['city_area'] ?? ''));
        if ($city !== '') {
            $where[] = 'city_area LIKE :city';
            $bind['city'] = '%' . $city . '%';
        }
        $route = trim((string) ($query['route_cluster'] ?? ''));
        if ($route !== '') {
            $where[] = 'route_cluster LIKE :route';
            $bind['route'] = '%' . $route . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 'is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'is_active = 0';
        }
        $sortMap = ['name' => 'name', 'area' => 'city_area', 'newest' => 'created_at', 'oldest' => 'created_at'];
        $sortKey = $sortMap[$query['sort'] ?? 'name'] ?? 'name';
        $dir = ($query['sort'] ?? '') === 'oldest' ? 'ASC' : (($query['sort'] ?? '') === 'newest' ? 'DESC' : (strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC'));
        $sql = 'SELECT * FROM bakery_destinations WHERE ' . implode(' AND ', $where) . " ORDER BY {$sortKey} {$dir}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
    },
    'POST /bakery-destinations' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_BAKERY_DESTINATION_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => BakeryDestinationService::create($tx, $input));
        inv_ok($result, 'Bakery destination created');
    },
    'PUT /bakery-destinations/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_BAKERY_DESTINATION_MANAGE');
        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => BakeryDestinationService::update($tx, (int) $params['id'], $input));
        inv_ok($result, 'Bakery destination updated');
    },
    'DELETE /bakery-destinations/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_BAKERY_DESTINATION_MANAGE');
        $bdId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM bakery_destinations WHERE id = :id');
        $existing->execute(['id' => $bdId]);
        $bd = $existing->fetch();
        if ($bd === false) {
            inv_error(404, 'NOT_FOUND', 'bakery destination not found');
        }

        $refs = MasterDataSafetyService::checkBakeryDestinationReferences($pdo, $bdId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'BAKERY_DESTINATION_DELETE_ATTEMPT', 'bakery_destinations', $bdId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by OUT transactions');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Bakery Tujuan '{$bd['name']}' sudah direferensikan oleh transaksi OUT dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM bakery_destinations WHERE id = :id')->execute(['id' => $bdId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'BAKERY_DESTINATION_DELETE_SUCCESS', 'bakery_destinations', $bdId, ['name' => $bd['name']], null, null);
        inv_ok(['success' => true], 'Bakery destination permanently deleted');
    },

    // PHASE V2: category master (read-only route here; write is
    // MASTER_CATEGORY_MANAGE-gated, added alongside for the same reason
    // suppliers/bakery-destinations need both a list and a manage path).
    // PHASE V2.1: extended with optional search/active/sort/item_count —
    // every existing caller passing no query params gets the exact same
    // unfiltered, name-ascending, full-column list as before (still every
    // category, active or not, by default).
    'GET /categories' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(c.code LIKE :q_c OR c.name LIKE :q_n)';
            $bind['q_c'] = $bind['q_n'] = '%' . $q . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 'c.is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'c.is_active = 0';
        }
        $sortMap = ['name' => 'c.name', 'item_count' => 'item_count'];
        $sortKey = $sortMap[$query['sort'] ?? 'name'] ?? 'c.name';
        $dir = strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $sql = "
            SELECT c.*, COALESCE(ic.cnt, 0) AS item_count
            FROM categories c
            LEFT JOIN (SELECT category_id, COUNT(*) AS cnt FROM items WHERE category_id IS NOT NULL GROUP BY category_id) ic
                ON ic.category_id = c.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$sortKey} {$dir}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
    },
    // PHASE: Edit Barang — the canonical unit list (GR/KG/PCS/KARTON/...)
    // has never had its own list endpoint; Base Unit/Unit Conversion
    // dropdowns need one. Read-only, same bare-auth treatment as
    // GET /categories just above (reference data, not security-sensitive).
    'GET /units' => function () use ($pdo) {
        inv_require_auth();
        inv_ok($pdo->query('SELECT id, code, name FROM units ORDER BY code')->fetchAll(), 'OK');
    },
    'POST /categories' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_CATEGORY_MANAGE');
        $code = trim((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new ValidationException(['code and name cannot be blank']);
        }
        $existing = $pdo->prepare('SELECT id FROM categories WHERE code = :c');
        $existing->execute(['c' => $code]);
        if ($existing->fetchColumn() !== false) {
            throw new ValidationException(["category code '{$code}' already exists"]);
        }
        // Master Data "Tambah Kategori": a second category with the same NAME (any case) would be indistinguishable in every dropdown.
        $sameName = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:n)');
        $sameName->execute(['n' => $name]);
        if ($sameName->fetchColumn() !== false) {
            throw new ValidationException(["category name '{$name}' already exists"]);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : 1;
        $stmt = $pdo->prepare('INSERT INTO categories (code, name, is_active) VALUES (:c, :n, :a)');
        $stmt->execute(['c' => $code, 'n' => $name, 'a' => $isActive]);
        $categoryId = (int) $pdo->lastInsertId();
        AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_CREATE', 'categories', $categoryId, null, ['code' => $code, 'name' => $name], null);
        inv_ok(['success' => true, 'category_id' => $categoryId], 'Category created');
    },
    'PUT /categories/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_CATEGORY_MANAGE');
        $categoryId = (int) $params['id'];
        $existing = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $existing->execute(['id' => $categoryId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'category not found');
        }
        $name = isset($input['name']) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : (int) $before['is_active'];
        $pdo->prepare('UPDATE categories SET name = :n, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'a' => $isActive, 'id' => $categoryId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_UPDATE', 'categories', $categoryId, ['name' => $before['name'], 'is_active' => (int) $before['is_active']], ['name' => $name, 'is_active' => $isActive], null);
        inv_ok(['success' => true, 'category_id' => $categoryId], 'Category updated');
    },
    'DELETE /categories/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_CATEGORY_MANAGE');
        $categoryId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $existing->execute(['id' => $categoryId]);
        $category = $existing->fetch();
        if ($category === false) {
            inv_error(404, 'NOT_FOUND', 'category not found');
        }

        $refs = MasterDataSafetyService::checkCategoryReferences($pdo, $categoryId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_DELETE_ATTEMPT', 'categories', $categoryId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by items');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Kategori '{$category['name']}' memiliki barang terkait dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'CATEGORY_DELETE_SUCCESS', 'categories', $categoryId, ['code' => $category['code'], 'name' => $category['name']], null, null);
        inv_ok(['success' => true], 'Category permanently deleted');
    },

    // PHASE V2.1: extended with optional search/active/sort query params —
    // every existing caller that passes none of them (the original
    // behavior) gets the exact same unfiltered, name-ascending list as
    // before.
    'GET /divisions' => function () use ($pdo, $query) {
        inv_require_auth();
        $where = ['1=1'];
        $bind = [];
        $q = trim((string) ($query['search'] ?? $query['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'name LIKE :q';
            $bind['q'] = '%' . $q . '%';
        }
        $active = strtoupper((string) ($query['active'] ?? ''));
        if ($active === 'ACTIVE') {
            $where[] = 'is_active = 1';
        } elseif ($active === 'INACTIVE') {
            $where[] = 'is_active = 0';
        }
        $dir = strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $sql = 'SELECT * FROM divisions WHERE ' . implode(' AND ', $where) . " ORDER BY name {$dir}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        inv_ok($stmt->fetchAll(), 'OK');
    },
    'PUT /divisions/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_DIVISION_MANAGE');
        $divId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM divisions WHERE id = :id');
        $existing->execute(['id' => $divId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'division not found');
        }

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : (int) $before['is_active'];

        $pdo->prepare('UPDATE divisions SET name = :n, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'a' => $isActive, 'id' => $divId]);

        AuditService::log(
            $pdo, $user['id'], $user['username'],
            (int) $before['is_active'] !== $isActive ? ($isActive === 0 ? 'DIVISION_DEACTIVATE' : 'DIVISION_ACTIVATE') : 'DIVISION_UPDATE',
            'divisions', $divId,
            ['name' => $before['name'], 'is_active' => (int) $before['is_active']],
            ['name' => $name, 'is_active' => $isActive],
            null
        );

        inv_ok(['success' => true, 'division_id' => $divId], 'Division updated');
    },
    'DELETE /divisions/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_DIVISION_MANAGE');
        $divId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM divisions WHERE id = :id');
        $existing->execute(['id' => $divId]);
        $div = $existing->fetch();
        if ($div === false) {
            inv_error(404, 'NOT_FOUND', 'division not found');
        }

        $refs = MasterDataSafetyService::checkDivisionReferences($pdo, $divId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'DIVISION_DELETE_ATTEMPT', 'divisions', $divId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by transaction/history data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Divisi '{$div['name']}' memiliki referensi transaksi/user dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM divisions WHERE id = :id')->execute(['id' => $divId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'DIVISION_DELETE_SUCCESS', 'divisions', $divId, ['name' => $div['name']], null, null);
        inv_ok(['success' => true], 'Division permanently deleted');
    },
    // Units this item may be transacted in (its base unit plus any configured
    // purchase/middle conversions) — Transaction IN/OUT forms need this to
    // offer only valid units rather than free-typed unit ids.
    //
    // PHASE V2.10: each row is extended (never replaced — reference_price/
    // price_source are additive fields) with the Stock IN auto-fill
    // reference price for that specific unit (Part B2/B9), reusing this
    // existing round trip rather than adding a new endpoint. See
    // ItemPriceService::resolveReferencePrice() for the exact-unit-first /
    // derived-fallback resolution and its price-source audit rationale.
    'GET /items/{id}/units' => function (array $params) use ($pdo) {
        inv_require_auth();
        $itemId = (int) $params['id'];
        $stmt = $pdo->prepare(
            'SELECT u.id, u.code, u.name, c.conversion_to_base, c.is_purchase_default
             FROM item_unit_conversions c JOIN units u ON u.id = c.unit_id
             WHERE c.item_id = :item_id AND c.valid_to IS NULL
             ORDER BY c.is_purchase_default DESC, c.conversion_to_base DESC'
        );
        $stmt->execute(['item_id' => $itemId]);
        $units = $stmt->fetchAll();

        foreach ($units as &$unit) {
            $price = ItemPriceService::resolveReferencePrice($pdo, $itemId, (int) $unit['id']);
            $unit['reference_price'] = $price['reference_price'];
            $unit['price_source'] = $price['price_source'];
        }
        unset($unit);

        inv_ok($units, 'OK');
    },

    // ---- PHASE V2.10: multi-unit barcode mappings (Part C) ----
    'GET /item-barcodes' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        // All rows (active + inactive) so the client can distinguish
        // "unknown" from "known but inactive" (Part C8) without a
        // per-scan round trip. Read-only exposure of mapping metadata —
        // never a sensitive read, unlike create/edit below.
        inv_ok(ItemBarcodeService::listAll($pdo), 'OK');
    },

    'POST /item-barcodes' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => ItemBarcodeService::create($tx, $input));
        inv_ok($result, 'Barcode mapping created');
    },

    'PUT /item-barcodes/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => ItemBarcodeService::update($tx, (int) $params['id'], $input));
        inv_ok($result, 'Barcode mapping updated');
    },

    // ---- PHASE V2: per-item-per-warehouse stock policy (min/buffer) ----
    'GET /stock-policy' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $itemId = (int) ($query['item_id'] ?? 0);
        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($itemId <= 0 || $warehouseId <= 0) {
            throw new ValidationException(['item_id and warehouse_id are required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(StockPolicyService::resolve($pdo, $itemId, $warehouseId), 'OK');
    },

    'PUT /stock-policy' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_POLICY_MANAGE');

        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }
        // A STOCK-role user does not hold STOCK_POLICY_MANAGE by default (see
        // database/schema.sql's role_permissions seed), but this scope check
        // runs unconditionally for every role rather than special-casing
        // STOCK — the same "never trust warehouse_id, always re-check scope"
        // discipline applied uniformly regardless of who technically has the
        // permission today.
        inv_require_warehouse_scope($user, $warehouseId);

        $input['updated_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(fn (PDO $tx) => StockPolicyService::upsert($tx, $input));
        inv_ok($result, 'Stock policy saved');
    },

    // ---- PHASE V2: GET /reports/stock — "Laporan Stok" (Section 7 of the
    // technical design). GET /items is intentionally left untouched; this
    // is the paginated/filterable/sortable all-items report. ----
    'GET /reports/stock' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;

        if ($user['role_code'] === 'STOCK') {
            // STOCK is always forced to their own warehouse — never a
            // company-wide rollup, and never another warehouse even if
            // explicitly requested.
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $itemStatusParam = in_array($query['item_status'] ?? '', ['ACTIVE', 'INACTIVE'], true) ? $query['item_status'] : null;
        $reportStatusParam = in_array($query['report_status'] ?? '', ['AMAN', 'WARNING', 'HABIS'], true) ? $query['report_status'] : null;

        $params = [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'q' => $query['q'] ?? null,
            'status' => $query['status'] ?? null,
            'report_status' => $reportStatusParam,
            'item_status' => $itemStatusParam,
            // item_status (tri-state) overrides active_only when given, same
            // convention StockReportService::buildQuery() already documents.
            'active_only' => $itemStatusParam === null && (!isset($query['active_only']) || $query['active_only'] !== '0'),
            'include_zero_stock' => !isset($query['include_zero_stock']) || $query['include_zero_stock'] !== '0',
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => (int) ($query['per_page'] ?? 50),
            'sort' => $query['sort'] ?? 'name',
            'dir' => $query['dir'] ?? 'asc',
        ];

        if (($query['format'] ?? '') === 'csv') {
            $rows = StockReportService::exportAll($pdo, $params);
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';

            // PHASE V2.6D — "Laporan Stok" (the Reporting Pack page) asks
            // for a focused 8-column export (SKU/Nama/Kategori/Satuan/
            // Stok Tersedia/Stok Minimal/Status/Nilai, using the new
            // AMAN/WARNING/HABIS status). The older "Stok Barang" tab
            // shares this same route/endpoint for its own 14-column export
            // (with the original 5-tier status plus buffer/last-movement
            // columns) — `view=report` switches shape ADDITIVELY; omitting
            // it (every existing caller, including Stok Barang) keeps the
            // exact original column set, byte-identical.
            if (($query['view'] ?? '') === 'report') {
                inv_export_csv(
                    ['laporan-stok', $whLabel, date('Y-m-d')],
                    ['SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Stok Tersedia', 'Stok Minimal', 'Status', 'Nilai Stok'],
                    $rows,
                    static fn (array $r) => [
                        $r['sku'], $r['name'], $r['category']['name'] ?? '', $r['unit']['code'],
                        $r['qty_base'], $r['minimum_stock'], $r['report_status'], $r['value'],
                    ]
                );
            }

            inv_export_csv(
                ['laporan-stok', $whLabel, date('Y-m-d')],
                ['SKU', 'Nama Barang', 'Kategori', 'Satuan', 'Qty', 'Nilai', 'Rata-rata Biaya', 'Minimum', 'Buffer', 'Buffer Dikonfigurasi', 'Status', 'Terakhir Masuk', 'Terakhir Keluar', 'Terakhir Bergerak'],
                $rows,
                static fn (array $r) => [
                    $r['sku'], $r['name'], $r['category']['name'] ?? '', $r['unit']['code'],
                    $r['qty_base'], $r['value'], $r['average_cost'], $r['minimum_stock'], $r['buffer_stock'],
                    $r['buffer_configured'] ? 'Ya' : 'Tidak', $r['status'], $r['last_in'], $r['last_out'], $r['last_movement'],
                ]
            );
        }

        inv_ok(StockReportService::list($pdo, $params), 'OK');
    },

    // PHASE V2.6D — "Semua Produk / Aman / Warning / Habis" counters for
    // Laporan Stok, computed over the FULL filtered dataset (never just the
    // current page — see StockReportService::statusCounts()'s own
    // docblock for why this is a separate query from list()'s summary
    // block). Same warehouse-scope enforcement as GET /reports/stock,
    // copied rather than shared to keep each route's auth path
    // independently readable.
    'GET /reports/stock/status-counts' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $itemStatusParam = in_array($query['item_status'] ?? '', ['ACTIVE', 'INACTIVE'], true) ? $query['item_status'] : null;
        inv_ok(StockReportService::statusCounts($pdo, [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'q' => $query['q'] ?? null,
            'item_status' => $itemStatusParam,
            'active_only' => $itemStatusParam === null && (!isset($query['active_only']) || $query['active_only'] !== '0'),
        ]), 'OK');
    },

    // PHASE V2.6D — Kartu Stok (Stock Card): one item's movement history
    // for one warehouse, with a running balance. Reuses the EXISTING
    // InventoryService::ledger() (the same engine the "Mutasi Stok /
    // Ledger" tab already uses) and InventoryService::currentStock() —
    // never a second inventory ledger. ledger() already correctly keeps
    // historical (inventory_effect=0, 1-15 Sep) rows out of the live
    // running balance (see its own docblock) — this route only adds
    // pagination over its already-computed, already-ordered output; the
    // running-balance computation itself is untouched.
    'GET /reports/stock/card' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $itemId = (int) ($query['item_id'] ?? 0);
        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($itemId <= 0 || $warehouseId <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'item_id and warehouse_id are required');
        }
        inv_require_warehouse_scope($user, $warehouseId);

        $current = InventoryService::currentStock($pdo, $itemId, $warehouseId);
        $fullLedger = InventoryService::ledger($pdo, $itemId, $warehouseId);
        $live = array_values(array_filter($fullLedger, static fn (array $r) => !$r['is_historical']));
        $historical = array_values(array_filter($fullLedger, static fn (array $r) => $r['is_historical']));

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($query['per_page'] ?? 50)));
        $total = count($live);
        $offset = ($page - 1) * $perPage;

        inv_ok([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'current' => $current,
            'movements' => array_slice($live, $offset, $perPage),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / max(1, $perPage))],
            'historical' => $historical,
        ], 'OK');
    },

    // ---- PHASE V2: GET /reports/transactions — "History Transaksi"
    // (Section 8 of the technical design). Same warehouse-scope pattern
    // as /reports/stock: STOCK always forced to their own, others may
    // filter by any warehouse or omit it for a company-wide view. ----
    'GET /reports/transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $params = [
            'warehouse_id' => $warehouseId,
            'item_id' => isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'transaction_type' => $query['transaction_type'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'bakery_destination_id' => isset($query['bakery_destination_id']) && $query['bakery_destination_id'] !== '' ? (int) $query['bakery_destination_id'] : null,
            'q' => $query['q'] ?? null,
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => (int) ($query['per_page'] ?? 50),
            'sort' => $query['sort'] ?? 'date',
            'dir' => $query['dir'] ?? 'desc',
        ];

        inv_ok(TransactionHistoryService::list($pdo, $params), 'OK');
    },

    'GET /reports/transactions/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $transactionId = (int) $params['id'];

        // Re-derive the transaction's REAL warehouse from the DB — never
        // trust anything from the request for this scope check.
        $scope = $pdo->prepare('SELECT warehouse_id FROM inventory_transactions WHERE id = :id');
        $scope->execute(['id' => $transactionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'transaction not found');
        }
        inv_require_warehouse_scope($user, (int) $warehouseId);

        $includeAudit = AuthService::hasPermission($pdo, $user['role_code'], 'AUDIT_LOG_VIEW');

        inv_ok(TransactionHistoryService::detail($pdo, $transactionId, $includeAudit), 'OK');
    },

    // ============================================================
    // PHASE V2.3 — "Laporan Nilai Stok & HPP" (Inventory Value & HPP
    // Reconciliation). Strictly read-only, same INVENTORY_VIEW gate as the
    // other reports above, same STOCK-forced-to-own-warehouse pattern
    // (inv_hpp_resolve_warehouse_scope). Every number in the response is
    // computed by InventoryHppReportService straight from the existing
    // ledger/FIFO tables — no new tables, no duplicated FIFO logic. Trace
    // drill-down is deliberately NOT implemented here: every row carries
    // real transaction_id/item_id/warehouse_id/batch_id so the frontend
    // opens them through the EXISTING TraceDrawer/TraceService endpoints.
    // ============================================================
    'GET /reports/inventory-hpp/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $q = isset($query['q']) && $query['q'] !== '' ? (string) $query['q'] : null;

        inv_ok(InventoryHppReportService::summary($pdo, $start, $end, $warehouseId, $categoryId, $q), 'OK');
    },

    'GET /reports/inventory-hpp/warehouses' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryHppReportService::warehouseBreakdown($pdo, $start, $end, $warehouseId), 'OK');
    },

    'GET /reports/inventory-hpp/daily' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $q = isset($query['q']) && $query['q'] !== '' ? (string) $query['q'] : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 10), [10, 25, 50], true) ? (int) ($query['per_page'] ?? 10) : 10;

        inv_ok(InventoryHppReportService::dailyRecap($pdo, $start, $end, $warehouseId, $page, $perPage, $categoryId, $q), 'OK');
    },

    // The "Trace Detail HPP" panel's data for one date. Every returned line
    // carries transaction_id — the frontend opens it via the existing
    // TraceDrawer.openTransaction(), never a second trace implementation.
    'GET /reports/inventory-hpp/day-detail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryHppReportService::dayDetail($pdo, $date, $warehouseId), 'OK');
    },

    // The exact bridge behind the Variance KPI — "clicking Variance" data.
    // Never forces the reconciliation to balance: `unexplained` is returned
    // as computed, and the frontend shows a warning if it isn't ~0.
    'GET /reports/inventory-hpp/variance-bridge' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryHppReportService::varianceBridge($pdo, $start, $end, $warehouseId), 'OK');
    },

    'GET /reports/inventory-hpp/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;
        $q = isset($query['q']) && $query['q'] !== '' ? (string) $query['q'] : null;

        $sheets = InventoryHppReportService::buildExportSheets($pdo, $start, $end, $warehouseId, $categoryId, $q);
        $tmpPath = tempnam(sys_get_temp_dir(), 'hpp_export_');
        try {
            ExcelWriterService::write($tmpPath, $sheets);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="laporan-nilai-stok-hpp-' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($tmpPath));
            readfile($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
        exit;
    },

    // ============================================================
    // PHASE V2.6B — Reporting Pack: Report 2 "Pergerakan Stok Harian" +
    // Report 14 "Rekonsiliasi Arus Stok". Strictly read-only, same
    // INVENTORY_VIEW gate and STOCK-forced-to-own-warehouse pattern as
    // the HPP report above (inv_hpp_resolve_warehouse_scope is fully
    // generic despite its name — reused verbatim, never duplicated).
    // Drill-down carries real transaction_id so the frontend opens it via
    // the EXISTING TraceDrawer — no second trace implementation here.
    // ============================================================
    'GET /reports/movement/daily' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        $result = InventoryMovementReportService::dailyMovement($pdo, $start, $end, $warehouseId);

        // Two logically different tables (live 5-column vs historical
        // disclosure) don't share one header row, so this writes directly
        // rather than through inv_export_csv()'s single-header shape — same
        // BOM/escape/filename-sanitization conventions, never a second
        // ad-hoc CSV writer.
        if (($query['format'] ?? '') === 'csv') {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            $filename = implode('_', array_filter([
                preg_replace('/[^A-Za-z0-9_-]+/', '-', 'pergerakan-stok-harian'),
                preg_replace('/[^A-Za-z0-9_-]+/', '-', $whLabel),
                $start, $end,
            ]));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            inv_csv_write_row($out, ['Tanggal', 'Saldo Awal', 'Barang Masuk', 'Barang Keluar', 'Saldo Akhir', 'Keterangan']);
            foreach ($result['rows'] as $r) {
                inv_csv_write_row($out, [$r['date'], $r['stok_awal'], $r['barang_masuk'], $r['barang_keluar'], $r['stok_akhir'], $r['is_pre_go_live'] ? 'PRE-GO-LIVE (nol)' : '']);
            }
            if (!empty($result['historical'])) {
                inv_csv_write_row($out, []);
                inv_csv_write_row($out, ['HISTORICAL / REPORTING ONLY — Tidak mengubah stok/HPP live']);
                inv_csv_write_row($out, ['Tanggal', 'Historical IN', 'Historical OUT', 'Jumlah Transaksi']);
                foreach ($result['historical'] as $h) {
                    inv_csv_write_row($out, [$h['date'], $h['historical_in'], $h['historical_out'], $h['transaction_count']]);
                }
            }
            fclose($out);
            exit;
        }

        inv_ok($result, 'OK');
    },

    'GET /reports/movement/day-breakdown' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::dayBreakdown($pdo, $date, $warehouseId), 'OK');
    },

    'GET /reports/movement/day-transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        $category = (string) ($query['category'] ?? '');
        if ($date === '' || strtotime($date) === false || $category === '') {
            inv_error(422, 'VALIDATION_ERROR', 'date and category are required');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::categoryTransactions($pdo, $date, $warehouseId, $category), 'OK');
    },

    // MANDATORY CORRECTION A — historical (inventory_effect=0) disclosure
    // drill-down for one date's underlying transactions.
    'GET /reports/movement/historical-transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        inv_ok(InventoryMovementReportService::historicalTransactions($pdo, $date, $warehouseId), 'OK');
    },

    // PERGERAKAN STOK HARIAN (redesign) — item-level qty + value straight from the ledger (MovementDailyReportService).
    // Strictly read-only (GET), INVENTORY_VIEW, warehouse scope enforced server-side like every other report here
    // (a STOCK user is forced to their own warehouse whatever the query says).
    'GET /reports/movement/overview' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        [$start, $end, $wh, $cat, $q, $item] = inv_movement_params($user, $query);
        inv_ok(\App\Services\MovementDailyReportService::overview($pdo, $start, $end, $wh, $cat, $q, $item), 'OK');
    },

    'GET /reports/movement/day-items' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        if ($date === '' || strtotime($date) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'date is required (YYYY-MM-DD)');
        }
        [, , $wh, $cat, $q, $item] = inv_movement_params($user, $query + ['start_date' => $date, 'end_date' => $date]);
        $movement = isset($query['movement']) && $query['movement'] !== '' ? (string) $query['movement'] : null;
        inv_ok(\App\Services\MovementDailyReportService::dayItems(
            $pdo, $date, $wh, $cat, $q, $item, $movement, (string) ($query['sort'] ?? 'sku'), (string) ($query['dir'] ?? 'asc'),
            max(1, (int) ($query['page'] ?? 1)), min(200, max(1, (int) ($query['per_page'] ?? 25))), ($query['moved_only'] ?? '') === '1'
        ), 'OK');
    },

    'GET /reports/movement/item-trail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $date = (string) ($query['date'] ?? '');
        $itemId = (int) ($query['item_id'] ?? 0);
        if ($date === '' || strtotime($date) === false || $itemId <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'date and item_id are required');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(\App\Services\MovementDailyReportService::itemTrail($pdo, $date, $itemId, $warehouseId), 'OK');
    },

    'GET /reports/movement/period-transactions' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        [$start, $end, $wh, $cat, $q, $item] = inv_movement_params($user, $query);
        inv_ok(\App\Services\MovementDailyReportService::periodTransactions(
            $pdo, $start, $end, $wh, $cat, $q, $item, (string) ($query['bucket'] ?? ''),
            max(1, (int) ($query['page'] ?? 1)), min(200, max(1, (int) ($query['per_page'] ?? 25)))
        ), 'OK');
    },

    // Export mirrors what the screen shows: kind=summary (one row per date), detail (one row per item/date with movement),
    // transactions (one row per underlying ledger line). Every file starts with the filters used + generated timestamp.
    'GET /reports/movement/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        [$start, $end, $wh, $cat, $q, $item] = inv_movement_params($user, $query);
        $kind = (string) ($query['kind'] ?? 'summary');
        if (!in_array($kind, ['summary', 'detail', 'transactions'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'kind must be summary, detail or transactions');
        }
        $mode = ($query['mode'] ?? 'nominal') === 'qty' ? 'qty' : 'nominal';
        $whLabel = $wh !== null ? (string) ($pdo->query("SELECT name FROM warehouses WHERE id = " . (int) $wh)->fetchColumn() ?: $wh) : 'Semua Gudang';
        $catLabel = $cat !== null ? (string) ($pdo->query("SELECT name FROM categories WHERE id = " . (int) $cat)->fetchColumn() ?: $cat) : 'Semua Kategori';
        $filename = implode('_', array_filter([
            'pergerakan-stok-' . $kind, preg_replace('/[^A-Za-z0-9_-]+/', '-', $wh !== null ? $whLabel : 'ALL'), $start, $end, $mode,
        ]));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $meta = [
            ['Laporan', 'Pergerakan Stok Harian — ' . $kind], ['Periode', $start . ' s/d ' . $end], ['Gudang', $whLabel], ['Kategori', $catLabel],
            ['Pencarian barang', (string) ($q ?? '')], ['Mode tampilan', $mode === 'qty' ? 'Kuantitas (Qty)' : 'Nominal (Rp)'],
            ['Dibuat', date('Y-m-d H:i:s')], ['Dibuat oleh', (string) $user['username']],
        ];
        foreach ($meta as $m) { inv_csv_write_row($out, $m); }
        inv_csv_write_row($out, []);
        if ($kind === 'summary') {
            $res = \App\Services\MovementDailyReportService::overview($pdo, $start, $end, $wh, $cat, $q, $item);
            inv_csv_write_row($out, ['Tanggal', 'Saldo Awal (Rp)', 'Barang Masuk (Rp)', 'Barang Keluar (Rp)', 'Adjustment / Pergerakan Lain (Rp)', 'Saldo Akhir (Rp)', 'SKU Memiliki Stok', 'SKU Bergerak', 'Transaksi Masuk', 'Transaksi Keluar', 'Transaksi Lain', 'Rekonsiliasi', 'Selisih Ledger (Rp)']
                + ($res['single_item'] ? [13 => 'Satuan', 14 => 'Qty Awal', 15 => 'Qty Masuk', 16 => 'Qty Keluar', 17 => 'Qty Lain', 18 => 'Qty Akhir'] : []));
            foreach ($res['rows'] as $r) {
                $row = [$r['date'], $r['nominal']['opening'], $r['nominal']['masuk'], $r['nominal']['keluar'], $r['nominal']['lain'], $r['nominal']['closing'], $r['counts']['sku_closing'], $r['counts']['sku_moved'], $r['counts']['tx_in'], $r['counts']['tx_out'], $r['counts']['tx_other'], $r['is_pre_go_live'] ? 'PRE-GO-LIVE' : ($r['reconciliation']['ok'] ? 'OK' : 'SELISIH'), $r['reconciliation']['diff']];
                if ($res['single_item']) {
                    $qd = $r['qty'];
                    array_push($row, $res['single_item']['unit'], $qd['opening'] ?? '', $qd['masuk'] ?? '', $qd['keluar'] ?? '', $qd['lain'] ?? '', $qd['closing'] ?? '');
                }
                inv_csv_write_row($out, $row);
            }
            $t = $res['totals'];
            inv_csv_write_row($out, ['TOTAL PERIODE', $t['opening'], $t['masuk'], $t['keluar'], $t['lain'], $t['closing'], $t['sku_closing'], '', $t['tx_in'], $t['tx_out'], $t['tx_other'], $res['reconciliation']['ok'] ? 'OK' : 'SELISIH', '']);
            inv_csv_write_row($out, []);
            inv_csv_write_row($out, ['Kuantitas per satuan (tidak dijumlahkan lintas satuan)']);
            inv_csv_write_row($out, ['Komponen', 'Satuan', 'Qty']);
            foreach (['opening' => 'Saldo Awal', 'masuk' => 'Barang Masuk', 'keluar' => 'Barang Keluar', 'lain' => 'Adjustment / Lain', 'closing' => 'Saldo Akhir'] as $k => $label) {
                foreach ($t['qty_by_unit'][$k] as $u) { inv_csv_write_row($out, [$label, $u['unit'], $u['qty']]); }
            }
            if (!$res['reconciliation']['ok']) {
                inv_csv_write_row($out, []);
                inv_csv_write_row($out, ['PERINGATAN REKONSILIASI']);
                foreach ($res['reconciliation']['issues'] as $i) { inv_csv_write_row($out, [$i['date'] ?? '', $i['type'], $i['difference'], $i['message']]); }
            }
        } elseif ($kind === 'detail') {
            inv_csv_write_row($out, ['Tanggal', 'Kode', 'Nama Barang', 'Kategori', 'Satuan', 'Saldo Awal Qty', 'Saldo Awal Nilai', 'Masuk Qty', 'Masuk Nilai', 'Keluar Qty', 'HPP Keluar Nilai', 'Adjustment Qty', 'Adjustment Nilai', 'Saldo Akhir Qty', 'Saldo Akhir Nilai', 'Jumlah Transaksi']);
            foreach (\App\Services\MovementDailyReportService::detailRows($pdo, $start, $end, $wh, $cat, $q, $item) as $r) {
                inv_csv_write_row($out, [$r['date'], $r['sku'], $r['name'], $r['category'], $r['unit'], $r['opening_qty'], $r['opening_value'], $r['masuk_qty'], $r['masuk_value'], $r['keluar_qty'], $r['hpp_keluar_value'], $r['adjustment_qty'], $r['adjustment_value'], $r['closing_qty'], $r['closing_value'], $r['tx_count']]);
            }
        } else {
            inv_csv_write_row($out, ['Bucket', 'Timestamp', 'Jenis', 'No. Referensi', 'Gudang', 'Kode', 'Nama Barang', 'Qty', 'Nilai (bertanda)', 'Nilai']);
            foreach (['masuk', 'keluar', 'other'] as $bucket) {
                for ($page = 1; $page <= 100; $page++) {
                    $res = \App\Services\MovementDailyReportService::periodTransactions($pdo, $start, $end, $wh, $cat, $q, $item, $bucket, $page, 200);
                    foreach ($res['rows'] as $r) {
                        inv_csv_write_row($out, [$bucket === 'other' ? 'adjustment_lain' : $bucket, $r['timestamp'], $r['type_label'], $r['reference_no'], $r['warehouse_code'], $r['sku'], $r['item_name'], $r['qty'], $r['signed_value'], $r['value']]);
                    }
                    if ($page >= $res['pagination']['total_pages']) { break; }
                }
            }
        }
        fclose($out);
        exit;
    },

    'GET /reports/reconciliation/movement' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        inv_require_reconciliation_range($start, $end);
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);

        $result = InventoryReconciliationReportService::run($pdo, $start, $end, $warehouseId);

        if (($query['format'] ?? '') === 'csv') {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['rekonsiliasi-arus-stok', $whLabel, $start, $end],
                ['Gudang', 'Saldo Awal', 'Pembelian Eksternal', 'Lainnya (Masuk)', 'Transfer IN', 'Adjustment Positif', 'OUT/Pemakaian', 'Transfer OUT', 'Adjustment Negatif', 'Lainnya (Keluar)', 'Saldo Akhir Teoritis', 'Saldo Akhir Aktual', 'Selisih', 'Status'],
                $result['scopes'],
                static fn (array $s) => [
                    $s['warehouse_name'], $s['saldo_awal'], $s['external_purchase'], $s['other_in'],
                    $s['transfer_in'] ?? '', $s['adjustment_positive'], $s['out_usage'], $s['transfer_out'] ?? '',
                    $s['adjustment_negative'], $s['other_out'], $s['theoretical_ending'],
                    $s['actual_available'] ? $s['actual_value'] : 'NOT COMPARABLE',
                    $s['difference'] ?? 'NOT COMPARABLE', $s['status'],
                ]
            );
        }

        inv_ok($result, 'OK');
    },

    // PHASE V2.6B — Report 1 "Ringkasan Inventory" (management overview).
    'GET /reports/summary/inventory' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $start = (string) ($query['start_date'] ?? '');
        $end = (string) ($query['end_date'] ?? '');
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
        }
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $categoryId = isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null;

        $result = InventorySummaryReportService::summary($pdo, $start, $end, $warehouseId, $categoryId);

        if (($query['format'] ?? '') === 'csv') {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            $kv = [
                ['Nilai Awal', $result['beginning_inventory_value']], ['Pembelian Eksternal', $result['external_purchase']],
                ['Lainnya (Masuk)', $result['other_in']], ['Transfer IN', $result['transfer_in'] ?? 'N/A (dieliminasi)'],
                ['Adjustment Positif', $result['adjustment_positive']], ['OUT/Pemakaian', $result['out_usage']],
                ['Transfer OUT', $result['transfer_out'] ?? 'N/A (dieliminasi)'], ['Adjustment Negatif', $result['adjustment_negative']],
                ['Lainnya (Keluar)', $result['other_out']], ['Nilai Akhir', $result['ending_inventory_value']],
                ['HPP FIFO', $result['fifo_hpp']], ['SKU Aktif', $result['active_sku']], ['SKU Ada Stok', $result['sku_with_stock']],
                ['Migration Negative Count', $result['migration_negative_count']], ['Pending Transfers', $result['pending_transfers']],
                ['Active Opname', $result['active_opname']],
            ];
            inv_export_csv(
                ['ringkasan-inventory', $whLabel, $start, $end],
                ['Metrik', 'Nilai'],
                $kv,
                static fn (array $row) => $row
            );
        }

        inv_ok($result, 'OK');
    },

    // ============================================================
    // PHASE V2.6B — remaining Reporting Pack items (Reports 4,5,6,8,9,
    // 10,11,12,13). Report 3 (Laporan Stok) reuses GET /reports/stock
    // verbatim (StockReportService, unchanged) and Report 15 (Audit
    // Transaksi) reuses GET /audit-logs verbatim (TraceService, unchanged)
    // — no new route for either. Every route below is INVENTORY_VIEW-gated
    // read-only, STOCK-forced-to-own-warehouse via inv_hpp_resolve_warehouse_scope.
    // ============================================================

    // Report 4 — Laporan Pembelian: qualifying purchase = type IN, POSTED,
    // never a transfer/production/opening/adjustment (TransactionHistoryService's
    // own type filter already excludes everything else by construction).
    'GET /reports/purchase' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $isExport = ($query['format'] ?? '') === 'csv';
        $params = [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'IN',
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'q' => $query['q'] ?? null,
            'is_historical_import' => isset($query['historical']) && $query['historical'] === '1' ? true : (isset($query['historical']) && $query['historical'] === '0' ? false : null),
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ];
        if ($params['is_historical_import'] === null) {
            unset($params['is_historical_import']);
        }
        $result = TransactionHistoryService::list($pdo, $params);
        // PHASE V2.7.9 — additive costing breakdown, null for any row not
        // posted through the costed purchase flow (see this helper's own
        // docblock).
        $result['rows'] = inv_enrich_purchase_costing($pdo, $result['rows']);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['laporan-pembelian', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['Tanggal', 'Gudang', 'Supplier', 'Referensi', 'SKU', 'Barang', 'Qty Input', 'Satuan Input', 'Base Qty',
                    'Gross', 'Diskon Baris', 'Diskon Invoice', 'Net Purchase', 'PPN', 'Freight',
                    'Harga Satuan (Final)', 'Nilai Pembelian (FIFO Cost)', 'Dibuat Oleh', 'Transaction ID', 'Keterangan'],
                $result['rows'],
                static fn (array $r) => [
                    $r['transaction_date'], $r['warehouse']['name'], $r['supplier']['name'] ?? '', $r['reference_no'],
                    $r['item']['sku'], $r['item']['name'], $r['input_qty'], $r['input_unit']['code'], $r['base_qty'],
                    $r['purchase_costing']['gross_amount'] ?? '', $r['purchase_costing']['line_discount_amount'] ?? '',
                    $r['purchase_costing']['invoice_discount_allocated'] ?? '', $r['purchase_costing']['net_purchase_before_tax'] ?? '',
                    $r['purchase_costing']['ppn_allocated'] ?? '', $r['purchase_costing']['freight_allocated'] ?? '',
                    $r['unit_cost_base'], $r['subtotal'], $r['created_by']['username'], $r['transaction_id'],
                    $r['is_historical'] ? 'HISTORICAL / REPORTING ONLY' : '',
                ]
            );
        }

        inv_ok($result, 'OK');
    },
    'GET /reports/purchase/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        inv_ok(TransactionHistoryService::summary($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'IN',
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ], 'supplier_id'), 'OK');
    },

    // Report 11 — Pembelian per Supplier: same qualifying-purchase
    // definition as Report 4, grouped.
    'GET /reports/purchase/by-supplier' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $rows = TransactionHistoryService::groupedSummary($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'IN',
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ], 'supplier_id', 'supplier_name');

        if (($query['format'] ?? '') === 'csv') {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['pembelian-per-supplier', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['Supplier', 'Jumlah Transaksi', 'Total Pembelian', 'Rata-rata', 'SKU Unik', 'Pembelian Terakhir', '% dari Total'],
                $rows,
                static fn (array $r) => [$r['name'], $r['transaction_count'], $r['total_value'], $r['average_value'], $r['unique_sku'], $r['latest_date'], $r['share_pct']]
            );
        }

        inv_ok($rows, 'OK');
    },

    // Report 5 — Laporan IN/OUT: unified operational view of both types.
    'GET /reports/in-out' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $direction = $query['direction'] ?? '';
        $types = in_array($direction, ['IN', 'OUT'], true) ? [$direction] : ['IN', 'OUT'];
        $isExport = ($query['format'] ?? '') === 'csv';
        $result = TransactionHistoryService::list($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_types' => $types,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'item_id' => isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null,
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'bakery_destination_id' => isset($query['bakery_destination_id']) && $query['bakery_destination_id'] !== '' ? (int) $query['bakery_destination_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'q' => $query['q'] ?? null,
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ]);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['laporan-in-out', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['Tanggal', 'Referensi', 'Tipe', 'Gudang', 'SKU', 'Barang', 'Qty', 'Base Qty', 'Nilai', 'Supplier/Bakery', 'User', 'Status', 'Keterangan'],
                $result['rows'],
                static fn (array $r) => [
                    $r['transaction_date'], $r['reference_no'], $r['transaction_type'], $r['warehouse']['name'],
                    $r['item']['sku'], $r['item']['name'], $r['input_qty'], $r['base_qty'], $r['subtotal'],
                    $r['supplier']['name'] ?? ($r['bakery_destination']['name'] ?? ''), $r['created_by']['username'], $r['status'],
                    $r['is_historical'] ? 'HISTORICAL / REPORTING ONLY' : '',
                ]
            );
        }

        inv_ok($result, 'OK');
    },
    'GET /reports/in-out/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $base = [
            'warehouse_id' => $warehouseId,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ];
        $inSummary = TransactionHistoryService::summary($pdo, $base + ['transaction_type' => 'IN']);
        $outSummary = TransactionHistoryService::summary($pdo, $base + ['transaction_type' => 'OUT']);
        inv_ok([
            'total_in_value' => $inSummary['total_value'], 'total_out_value' => $outSummary['total_value'],
            'net_movement' => round($inSummary['total_value'] - $outSummary['total_value'], 4),
            'transaction_count' => $inSummary['transaction_count'] + $outSummary['transaction_count'],
        ], 'OK');
    },

    // Report 12 — Distribusi per Bakery: qualifying OUT rows with a real
    // bakery_destination_id — a plain warehouse transfer (bakery_destination_id
    // is NULL by the chk_tx_bakery_destination_out_only constraint on any
    // non-OUT type) can never appear here.
    'GET /reports/distribution/bakery' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $rows = TransactionHistoryService::groupedSummary($pdo, [
            'warehouse_id' => $warehouseId, 'transaction_type' => 'OUT',
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
        ], 'bakery_destination_id', 'bakery_destination_name');

        if (($query['format'] ?? '') === 'csv') {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['distribusi-per-bakery', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['Bakery', 'Jumlah Transaksi', 'Total Nilai OUT', 'SKU Unik', 'Distribusi Terakhir'],
                $rows,
                static fn (array $r) => [$r['name'], $r['transaction_count'], $r['total_value'], $r['unique_sku'], $r['latest_date']]
            );
        }

        inv_ok($rows, 'OK');
    },

    // Report 6 — Laporan Transfer.
    'GET /reports/transfer' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $isExport = ($query['format'] ?? '') === 'csv';
        $result = TransferReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'from_warehouse_id' => isset($query['from_warehouse_id']) && $query['from_warehouse_id'] !== '' ? (int) $query['from_warehouse_id'] : null,
            'to_warehouse_id' => isset($query['to_warehouse_id']) && $query['to_warehouse_id'] !== '' ? (int) $query['to_warehouse_id'] : null,
            'status' => $query['status'] ?? null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ]);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['laporan-transfer', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['No. Transfer', 'Tanggal Kirim', 'Dari', 'Ke', 'Status', 'Jumlah SKU', 'Nilai Transfer', 'Lead Time (hari)', 'Dibuat Oleh', 'Diterima Oleh', 'Diterima Pada'],
                $result['rows'],
                static fn (array $r) => [
                    "TRF-{$r['id']}", $r['ship_date'], $r['from_warehouse']['name'], $r['to_warehouse']['name'], $r['status'],
                    $r['item_count'], $r['transfer_value'], $r['lead_time_days'] ?? '', $r['created_by'], $r['received_by'] ?? '', $r['receive_date'] ?? '',
                ]
            );
        }

        inv_ok($result, 'OK');
    },

    // Report 8 — Stock Opname.
    'GET /reports/opname' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        // PHASE V2.14.9.1 — ADMIN Transit (ADMIN + warehouse_id set) must
        // never see another warehouse's opname sessions in this report
        // either; inv_hpp_resolve_warehouse_scope() is shared by unrelated
        // HPP reports so it is not touched — this is an SO-specific
        // override applied only here.
        $warehouseId = inv_so_resolve_warehouse_scope($user, $warehouseId);
        $isExport = ($query['format'] ?? '') === 'csv';
        $result = StockOpnameReportService::list($pdo, [
            'warehouse_id' => $warehouseId, 'status' => $query['status'] ?? null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ]);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['laporan-stock-opname', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['Sesi', 'Gudang', 'Tanggal Sesi', 'Status', 'P1', 'P2', 'Supervisor', 'Total Item', 'Match', 'Mismatch', 'Adjustment +', 'Adjustment -', 'Selisih Nilai', 'Posted Ref', 'Dibuat Oleh', 'Finalisasi'],
                $result['rows'],
                static fn (array $r) => [
                    $r['session_number'] ?? "OPN-{$r['id']}", $r['warehouse']['name'], $r['session_date'], $r['status'],
                    $r['p1'] ?? '-', $r['p2'] ?? '-', $r['supervisor'] ?? '-',
                    $r['item_count'], $r['match_count'], $r['mismatch_count'],
                    $r['adjustment_positive_value'], $r['adjustment_negative_value'], $r['variance_value'],
                    $r['posted_transaction_ref'], $r['created_by'], $r['finalized_at'] ?? '',
                ]
            );
        }

        inv_ok($result, 'OK');
    },

    // "Jejak Stock Opname" drawer (report-opname.js row click): READ-ONLY
    // per-session trace — real lines, Count 01/02, final, variance, dead
    // stock, rusak, HPP and posted adjustments, plus the six KPI figures
    // computed from those same rows. Same permission + warehouse-scope
    // guards as GET /reports/opname/{id}; HPP is exposed under
    // INVENTORY_VIEW exactly like the existing HPP reports.
    'GET /reports/opname/{id}/jejak' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $sessionId = (int) $params['id'];
        $wh = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $wh->execute(['id' => $sessionId]);
        $warehouseId = $wh->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        inv_ok(\App\Services\StockOpnameJejakService::detail($pdo, $sessionId), 'OK');
    },

    // PHASE V2.12C: per-session detail (Section 21) — same permission and
    // CSV convention as the summary report above.
    'GET /reports/opname/{id}' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $sessionId = (int) $params['id'];
        $wh = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $wh->execute(['id' => $sessionId]);
        $warehouseId = $wh->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $detail = StockOpnameReportService::detail($pdo, $sessionId);

        if (($query['format'] ?? '') === 'csv') {
            inv_export_csv(
                ['laporan-stock-opname-detail', $detail['session']['session_number'] ?? "OPN-{$sessionId}"],
                ['SKU', 'Nama Barang', 'Satuan', 'Qty Sistem', 'P1', 'P2', 'Recount', 'Qty Final', 'Selisih', 'Nilai Selisih', 'Status', 'Catatan'],
                $detail['lines'],
                static fn (array $l) => [
                    $l['sku'], $l['name'], $l['unit_code'] ?? '-', $l['system_qty_base'],
                    $l['p1_qty_base'] ?? '', $l['p2_qty_base'] ?? '', $l['recount_qty_base'] ?? '',
                    $l['final_physical_qty_base'] ?? '', $l['difference_qty_base'] ?? '', $l['difference_value'] ?? '',
                    $l['match_status'], $l['notes'] ?? ($l['recount_reason'] ?? ''),
                ]
            );
        }

        inv_ok($detail, 'OK');
    },

    // PHASE V2.16.4 — "Laporan Stock Opname": monthly/session reporting for
    // finance/accounting/audit, separate from the P1/P2 dual-count report
    // above. Same permission (INVENTORY_VIEW) and the same SO-specific
    // warehouse-scope override pattern, applied fresh here rather than
    // modifying the routes above.
    'GET /stock-opname-reports' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $warehouseId = inv_so_resolve_warehouse_scope($user, $warehouseId);

        $result = StockOpnameMonthlyReportService::listSessions($pdo, [
            'warehouse_id' => $warehouseId,
            'month' => isset($query['month']) && $query['month'] !== '' ? (int) $query['month'] : null,
            'year' => isset($query['year']) && $query['year'] !== '' ? (int) $query['year'] : null,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'session_search' => $query['session'] ?? null,
            'status' => $query['status'] ?? 'POSTED',
            'search' => $query['search'] ?? null,
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => (int) ($query['per_page'] ?? 25),
        ]);

        inv_ok($result, 'OK');
    },

    'GET /stock-opname-reports/{id}' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $sessionId = (int) $params['id'];
        $wh = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $wh->execute(['id' => $sessionId]);
        $warehouseId = $wh->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $detail = StockOpnameMonthlyReportService::detail($pdo, $sessionId, [
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'search' => $query['search'] ?? null,
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => (int) ($query['per_page'] ?? 25),
        ]);

        inv_ok($detail, 'OK');
    },

    // PHASE V2.16.6 — report-specific Print/PDF for the monthly report
    // above (A4 landscape, finance-oriented). Deliberately a NEW route
    // rather than reusing GET /stock-opname/{id}/print
    // (StockOpnamePrintService), which is untouched and keeps serving the
    // existing operational discrepancy-only A4-portrait print. Same
    // permission/warehouse-scope pattern as GET /stock-opname-reports/{id}
    // above (INVENTORY_VIEW, not the stricter STOCK_OPNAME_MANAGE the old
    // print route requires) — a new, separate endpoint, so this does not
    // loosen any existing route's security; it also means a VIEWER-only
    // user who can already see this report can now print it too.
    'GET /stock-opname-reports/{id}/print' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $sessionId = (int) $params['id'];
        $wh = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $wh->execute(['id' => $sessionId]);
        $warehouseId = $wh->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        inv_html(StockOpnameMonthlyReportPrintService::renderResult($pdo, $sessionId, $user['username'] ?? null));
    },

    // Report 9 — Adjustment / Selisih.
    'GET /reports/adjustment' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $isExport = ($query['format'] ?? '') === 'csv';
        $result = AdjustmentReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'direction' => in_array($query['direction'] ?? '', ['POSITIVE', 'NEGATIVE'], true) ? $query['direction'] : null,
            'adjustment_type' => $query['adjustment_type'] ?? null,
            'item_id' => isset($query['item_id']) && $query['item_id'] !== '' ? (int) $query['item_id'] : null,
            'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null,
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ]);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['adjustment-selisih', $whLabel, (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
                ['Tanggal', 'Gudang', 'SKU', 'Barang', 'Qty', 'Nilai', 'Arah', 'Alasan', 'Sumber', 'User', 'Status'],
                $result['rows'],
                static fn (array $r) => [
                    $r['date'], $r['warehouse']['name'], $r['item']['sku'], $r['item']['name'],
                    $r['adjustment_qty'], $r['adjustment_value'], $r['direction'], $r['reason'], $r['source_module'], $r['created_by'], $r['status'],
                ]
            );
        }

        inv_ok($result, 'OK');
    },

    // Report 10 — Expired / Near Expired.
    'GET /reports/expiry' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $isExport = ($query['format'] ?? '') === 'csv';
        $result = ExpiryReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'status' => $query['status'] ?? null,
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ]);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['expired-near-expired', $whLabel, date('Y-m-d')],
                ['Gudang', 'SKU', 'Barang', 'Batch', 'Qty Sisa', 'Nilai', 'Tanggal Expiry', 'Sisa Hari', 'Status'],
                $result['rows'],
                static fn (array $r) => [
                    $r['warehouse']['name'], $r['item']['sku'], $r['item']['name'], $r['batch_id'],
                    $r['qty_remaining'], $r['inventory_value'], $r['expiry_date'], $r['days_remaining'], $r['status'],
                ]
            );
        }

        inv_ok($result, 'OK');
    },

    // Report 13 — Slow / No Movement.
    'GET /reports/slow-movement' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $warehouseId);
        $isExport = ($query['format'] ?? '') === 'csv';
        $result = SlowMovementReportService::list($pdo, [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'threshold_days' => (int) ($query['threshold_days'] ?? 30),
            'include_zero_stock' => isset($query['include_zero_stock']) && $query['include_zero_stock'] === '1',
            'page' => $isExport ? 1 : (int) ($query['page'] ?? 1), 'per_page' => $isExport ? 5000 : (int) ($query['per_page'] ?? 50),
        ]);

        if ($isExport) {
            $whLabel = $warehouseId !== null ? ($pdo->query("SELECT code FROM warehouses WHERE id = {$warehouseId}")->fetchColumn() ?: 'ALL') : 'ALL';
            inv_export_csv(
                ['slow-no-movement', $whLabel, (string) $result['threshold_days'] . 'hari', date('Y-m-d')],
                ['Gudang', 'SKU', 'Barang', 'Qty On Hand', 'Nilai', 'Terakhir Masuk', 'Terakhir Keluar', 'Hari Sejak Bergerak', 'Status'],
                $result['rows'],
                static fn (array $r) => [
                    $r['warehouse']['name'], $r['item']['sku'], $r['item']['name'], $r['qty_on_hand'], $r['inventory_value'],
                    $r['last_in'] ?? '', $r['last_out'] ?? '', $r['days_since_movement'] ?? 'never', $r['status'],
                ]
            );
        }

        inv_ok($result, 'OK');
    },

    // Report 15 — Audit Transaksi. PHASE V2.6C: proper server-side
    // pagination via the EXISTING TraceService::browseEvents() (it always
    // supported page/per_page/total/total_pages — the older GET /audit-logs
    // route this report used to piggyback on just never exposed that,
    // capping itself at a flat 500 rows). Neither TraceService nor
    // audit_logs is replaced — this route calls the same, unmodified
    // browseEvents() the Trace Center already relies on. browseEvents()
    // already projects only {id, action_code, entity_type, entity_id,
    // actor, before, after, reason, created_at} — password/session/CSRF
    // fields are never selected into that shape in the first place.
    'GET /reports/audit' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        inv_require_company_wide_audit_scope($user);
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 25)));
        inv_ok(TraceService::browseEvents($pdo, [
            'entity_type' => $query['entity_type'] ?? null,
            'action_code' => $query['action_code'] ?? null,
            'username' => $query['username'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'dir' => $query['dir'] ?? 'desc',
            'page' => (int) ($query['page'] ?? 1),
            'per_page' => $perPage,
        ]), 'OK');
    },
    'GET /reports/audit/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        inv_require_company_wide_audit_scope($user);
        // Export is still bounded (2000, not literally "everything") —
        // audit_logs has no warehouse scope to narrow by, so an unbounded
        // export here is the one place a filter alone can't cap the size.
        $result = TraceService::browseEvents($pdo, [
            'entity_type' => $query['entity_type'] ?? null,
            'action_code' => $query['action_code'] ?? null,
            'username' => $query['username'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'dir' => $query['dir'] ?? 'desc',
            'page' => 1,
            'per_page' => 2000,
        ]);
        inv_export_csv(
            ['audit-transaksi', (string) ($query['date_from'] ?? 'all'), (string) ($query['date_to'] ?? 'all')],
            ['Timestamp', 'User', 'Action', 'Entity Type', 'Entity ID', 'Alasan'],
            $result['rows'],
            static fn (array $r) => [$r['created_at'], $r['actor'], $r['action_code'], $r['entity_type'], $r['entity_id'] ?? '', $r['reason'] ?? '']
        );
    },

    // ---- InventoryService: single source of truth reads (Section 7) ----
    'GET /inventory/current' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(
            InventoryService::currentStock(
                $pdo,
                (int) $query['item_id'],
                $warehouseId
            ),
            'OK'
        );
    },

    'GET /inventory/current/{sku}' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $item = $pdo->prepare('SELECT id FROM items WHERE sku = :sku');
        $item->execute(['sku' => $params['sku']]);
        $itemId = $item->fetchColumn();

        if ($itemId === false) {
            inv_error(404, 'NOT_FOUND', 'SKU not found');
        }

        if (isset($query['warehouse_id'])) {
            $warehouseId = (int) $query['warehouse_id'];
            inv_require_warehouse_scope($user, $warehouseId);

            inv_ok(
                InventoryService::currentStock(
                    $pdo,
                    (int) $itemId,
                    $warehouseId
                ),
                'OK'
            );
        }

        // STOCK user without warehouse_id must still remain inside
        // their assigned warehouse.
        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            inv_ok(
                InventoryService::currentStock(
                    $pdo,
                    (int) $itemId,
                    (int) $user['warehouse_id']
                ),
                'OK'
            );
        }

        inv_ok(
            InventoryService::currentStockAllWarehouses(
                $pdo,
                (int) $itemId
            ),
            'OK'
        );
    },

    'GET /inventory/batches' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(
            InventoryService::batches(
                $pdo,
                (int) $query['item_id'],
                $warehouseId
            ),
            'OK'
        );
    },

    // Main Dashboard (READ-ONLY): one overview call for every card, plus the
    // paginated rows behind a clicked card. INVENTORY_VIEW; a warehouse-scoped
    // STOCK user is forced to their own warehouse, anyone else may ask for one
    // warehouse or the whole company — same rule as the other inventory reports.
    'GET /dashboard/inventory' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $requested = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $requested);
        inv_ok(\App\Services\DashboardInventoryService::overview(
            $pdo, $warehouseId, (string) ($query['period'] ?? 'month'), $query['date_from'] ?? null, $query['date_to'] ?? null
        ), 'OK');
    },

    'GET /dashboard/inventory/detail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $requested = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        $warehouseId = inv_hpp_resolve_warehouse_scope($user, $requested);
        inv_ok(\App\Services\DashboardInventoryService::detail(
            $pdo, (string) ($query['type'] ?? ''), $warehouseId, (string) ($query['period'] ?? 'month'),
            $query['date_from'] ?? null, $query['date_to'] ?? null,
            [
                'q' => $query['q'] ?? null,
                'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
                // the dashboard's own warehouse selector is `warehouse_id`; this one only narrows rows INSIDE a company-wide view
                'warehouse_id' => isset($query['row_warehouse_id']) && $query['row_warehouse_id'] !== '' ? (int) $query['row_warehouse_id'] : null,
                'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
            ]
        ), 'OK');
    },

    'GET /inventory/value' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            inv_ok(
                InventoryService::warehouseDashboardSummary(
                    $pdo,
                    (int) $user['warehouse_id']
                ),
                'OK'
            );
        }

        inv_ok(InventoryService::companyTotalValue($pdo), 'OK');
    },

    'GET /inventory/in-transit' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        if (
            $user['role_code'] === 'STOCK'
            && $user['warehouse_id'] !== null
        ) {
            $summary = InventoryService::warehouseDashboardSummary(
                $pdo,
                (int) $user['warehouse_id']
            );

            inv_ok(
                ['in_transit_value' => $summary['in_transit_value']],
                'OK'
            );
        }

        inv_ok(
            ['in_transit_value' => InventoryService::inTransitValue($pdo)],
            'OK'
        );
    },

    'GET /inventory/ledger' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_warehouse_scope($user, $warehouseId);

        inv_ok(
            InventoryService::ledger(
                $pdo,
                (int) $query['item_id'],
                $warehouseId
            ),
            'OK'
        );
    },

    // STOCK IN V2 — multi-line purchase sheet (per-item PPN + discount,
    // one invoice discount, shipping). `quote` is read-only arithmetic
    // (server = single source of truth for every number shown); `post` runs
    // the unmodified PurchaseCostingGateway + FifoService::postIn() once per
    // item inside ONE database transaction. See PurchaseInvoiceService.
    'POST /stock-in/quote' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $quote = \App\Services\PurchaseInvoiceService::quote($pdo, $input);
        unset($quote['_costing']);
        inv_ok($quote, 'OK');
    },

    'POST /stock-in' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $result = Database::transaction(fn (PDO $tx) => \App\Services\PurchaseInvoiceService::post($tx, $input, (int) $user['id'], (string) $user['username']));
        inv_ok($result, 'Transaction posted');
    },

    // STOCK OUT V2 — table-first issue to a bakery destination that also
    // creates the Delivery Order + Invoice (see StockOutService). quote /
    // markup-defaults / preview are read-only; POST /stock-out posts the real
    // FIFO OUT per item + DO + Invoice atomically. Documents are re-printable.
    'GET /stock-out/markup-defaults' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        $ids = array_filter(explode(',', (string) ($query['category_ids'] ?? '')), 'strlen');
        inv_ok(\App\Services\StockOutService::markupDefaults($pdo, $ids), 'OK');
    },

    'POST /stock-out/quote' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        inv_ok(\App\Services\StockOutService::quote($pdo, $input), 'OK');
    },

    'POST /stock-out/preview/{kind}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        if (!in_array($params['kind'], ['do', 'invoice'], true)) {
            inv_error(404, 'NOT_FOUND', 'unknown preview kind');
        }
        $quote = \App\Services\StockOutService::quote($pdo, $input);
        if (!$quote['valid']) {
            throw new ValidationException($quote['errors']);
        }
        inv_html($params['kind'] === 'do'
            ? \App\Services\StockOutDocumentService::renderDo(\App\Services\StockOutDocumentService::doModelFromQuote($quote, $input, (string) $user['username']))
            : \App\Services\StockOutDocumentService::renderInvoice(\App\Services\StockOutDocumentService::invoiceModelFromQuote($quote, $input)));
    },

    'POST /stock-out' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $result = Database::transaction(fn (PDO $tx) => \App\Services\StockOutService::post($tx, $input, (int) $user['id'], (string) $user['username']));
        inv_ok($result, 'Transaction posted');
    },

    'GET /stock-out/recent' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $scope = ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null) ? (int) $user['warehouse_id'] : null;
        inv_ok(\App\Services\StockOutService::recent($pdo, $scope, (int) ($query['limit'] ?? 30)), 'OK');
    },

    'GET /stock-out/by-transaction/{txId}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $s = \App\Services\StockOutService::byTransaction($pdo, (int) $params['txId']);
        if ($s !== null) { inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']); }
        inv_ok($s, 'OK');
    },

    'GET /stock-out/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $s = \App\Services\StockOutService::summary($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']);
        inv_ok($s, 'OK');
    },

    // DO: any inventory viewer in scope. Invoice (selling prices): only roles that may create a Stock OUT or view Distribusi.
    'GET /stock-out/{id}/print/do' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $s = \App\Services\StockOutService::summary($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']);
        inv_html(\App\Services\StockOutDocumentService::renderDo(\App\Services\StockOutDocumentService::doModel($pdo, (int) $params['id'])));
    },

    'GET /stock-out/{id}/print/invoice' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if (!AuthService::hasPermission($pdo, $user['role_code'], 'TRANSACTION_OUT_CREATE') && !AuthService::hasPermission($pdo, $user['role_code'], 'DISTRIBUTION_VIEW')) {
            inv_error(403, 'FORBIDDEN', 'Invoice hanya dapat dilihat oleh pengguna Stock OUT / Distribusi.');
        }
        $s = \App\Services\StockOutService::summary($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $s['from_warehouse_id']);
        if (empty($s['invoice_id'])) {
            inv_error(404, 'NOT_FOUND', 'delivery order has no invoice');
        }
        inv_html(\App\Services\StockOutDocumentService::renderInvoice(\App\Services\StockOutDocumentService::invoiceModel($pdo, (int) $s['invoice_id'])));
    },

    // PHASE V2.7 — read-only Cost Preview, called by the Transaksi Masuk
    // wizard before POST. Never posts anything; reuses the exact same
    // inv_purchase_costing_preview() helper the real POST below uses, so
    // the preview the user reviews is guaranteed identical to what gets
    // persisted (same code path, not a second implementation).
    'GET /transactions/in/cost-preview' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        foreach (['item_id', 'input_unit_id', 'input_qty', 'unit_price_input', 'transaction_date'] as $f) {
            if (!isset($query[$f]) || $query[$f] === '') {
                inv_error(422, 'VALIDATION_ERROR', "{$f} is required for cost preview");
            }
        }
        $costing = inv_purchase_costing_preview($pdo, $query);
        inv_ok($costing['preview'], 'OK');
    },

    'POST /transactions/in' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_IN_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        // PHASE V2.7 — every genuine Transaksi Masuk (transaction_type
        // defaults to 'IN' inside FifoService::postIn() itself; no other
        // caller of this route ever sends a different type) is now
        // costed. The new discount/PPN/freight fields are all optional
        // and default to NONE/0, which makes the cost preview's output
        // mathematically identical to the raw gross price — a caller
        // that sends none of them (every pre-V2.7 client) gets a
        // byte-identical unit_price_input/unit_cost_base to before this
        // phase. FifoService::postIn() itself is never modified — only
        // what gets passed into it changes.
        $txType = $input['transaction_type'] ?? 'IN';
        $costing = null;
        if ($txType === 'IN') {
            $costing = inv_purchase_costing_preview($pdo, $input);
            $input['unit_price_input'] = $costing['equivalent_unit_price_input'];
        }

        $result = Database::transaction(function (PDO $tx) use ($input, $costing) {
            $posted = FifoService::postIn($tx, $input);
            if ($costing !== null && empty($posted['idempotent_replay'])) {
                inv_persist_purchase_costing($tx, $posted, $input['created_by'], $costing);
            }
            return $posted;
        });
        inv_ok($result, 'Transaction posted');
    },
    'POST /transactions/out' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_OUT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        inv_require_division_scope($user, isset($input['division_id']) ? (int) $input['division_id'] : null);
        if (!empty($input['allow_negative_stock'])) {
            inv_require_permission($pdo, $user, 'STOCK_ALLOW_NEGATIVE');
        }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => FifoService::postOut($tx, $input));
        inv_ok($result, 'Transaction posted');
    },
    'POST /transactions/{id}/void' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSACTION_VOID');
        if (!empty($input['superadmin_override'])) {
            inv_require_permission($pdo, $user, 'TRANSACTION_VOID_LOCKED_PERIOD');
        }
        $input['transaction_id'] = (int) $params['id'];
        $input['voided_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => VoidService::void($tx, $input));
        inv_ok($result, 'Transaction voided');
    },

    'GET /transfer-destinations' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $stmt = $pdo->query(
            'SELECT id, code, name
             FROM warehouses
             WHERE is_active = 1
             ORDER BY name'
        );

        $warehouses = $stmt->fetchAll();

        $sourceWarehouseId = null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $sourceWarehouseId = (int) $user['warehouse_id'];
        }

        inv_ok([
            'source_warehouse_id' => $sourceWarehouseId,
            'warehouses' => $warehouses,
        ], 'OK');
    },

    // ---- Transfers (PHASE C2 Section 1) ----
    'POST /transfers' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $fromWarehouseId = (int) ($input['from_warehouse_id'] ?? 0);
        if ($fromWarehouseId <= 0) {
            throw new ValidationException(['from_warehouse_id is required']);
        }

        // STOCK operator may only dispatch stock from their own warehouse.
        inv_require_warehouse_scope($user, $fromWarehouseId);

        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::create($tx, $input)
        );

        inv_ok($result, 'Transfer created');
    },

    'POST /transfers/{id}/receive' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $transferId = (int) $params['id'];
        $transfer = TransferService::get($pdo, $transferId);

        // Only the DESTINATION warehouse may receive.
        inv_require_warehouse_scope(
            $user,
            (int) $transfer['to_warehouse_id']
        );

        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::receive(
                $tx,
                $transferId,
                $input
            )
        );

        inv_ok($result, 'Transfer received');
    },

    'POST /transfers/{id}/cancel' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $transferId = (int) $params['id'];
        $transfer = TransferService::get($pdo, $transferId);

        // Only the SOURCE warehouse may cancel.
        inv_require_warehouse_scope(
            $user,
            (int) $transfer['from_warehouse_id']
        );

        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::cancel(
                $tx,
                $transferId,
                $input
            )
        );

        inv_ok($result, 'Transfer cancelled');
    },

    // PHASE V2.5: the RECEIVED-transfer correction flow. Privileged-only
    // (TRANSFER_REVERSE is granted to SUPERADMIN/ADMIN, never STOCK — see
    // the migration) — deliberately no per-warehouse scope check beyond the
    // permission gate itself, the same pattern POST /transactions/{id}/void
    // already uses for TRANSACTION_VOID: a correction action's authority
    // comes from the permission, not from being "inside" one of the two
    // warehouses the original transfer touched.
    'POST /transfers/{id}/reverse' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'TRANSFER_REVERSE');

        $transferId = (int) $params['id'];
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];

        $result = Database::transaction(
            fn (PDO $tx) => TransferService::reverse(
                $tx,
                $transferId,
                $input
            )
        );

        inv_ok($result, 'Transfer reversed');
    },

    'GET /transfers' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $scopeWarehouseId = null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $scopeWarehouseId = (int) $user['warehouse_id'];
        }

        inv_ok(
            TransferService::listAll($pdo, $scopeWarehouseId),
            'OK'
        );
    },

    'GET /transfers/pending' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $scopeWarehouseId = null;

        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $scopeWarehouseId = (int) $user['warehouse_id'];
        }

        inv_ok(
            TransferService::listPending($pdo, $scopeWarehouseId),
            'OK'
        );
    },

    'GET /transfers/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_TRANSFER_MANAGE');

        $transfer = TransferService::get(
            $pdo,
            (int) $params['id']
        );

        if ($user['role_code'] === 'STOCK') {
            $ownWarehouseId = (int) ($user['warehouse_id'] ?? 0);

            if ($ownWarehouseId <= 0) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'STOCK user has no warehouse assignment'
                );
            }

            $fromWarehouseId =
                (int) $transfer['from_warehouse_id'];
            $toWarehouseId =
                (int) $transfer['to_warehouse_id'];

            if (
                $ownWarehouseId !== $fromWarehouseId
                && $ownWarehouseId !== $toWarehouseId
            ) {
                inv_error(
                    403,
                    'FORBIDDEN',
                    'Transfer is outside your warehouse scope'
                );
            }
        }

        inv_ok($transfer, 'OK');
    },

    // ---- PHASE V2.11A: Distribution Orders (SCM -> Bakery) ----
    // A genuinely different concept from Transfers above — this always
    // names a bakery_destination_id, never a to_warehouse_id, and real
    // stock only leaves inventory at dispatch() (Part 1/2/3 of the spec).
    'POST /distribution-orders' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_CREATE');
        if (isset($input['from_warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['from_warehouse_id']); }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::create($tx, $input));
        inv_ok($result, 'Delivery order created');
    },

    'GET /distribution-orders' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_VIEW');
        inv_ok(DistributionOrderService::listAll($pdo, $query), 'OK');
    },

    'GET /distribution-orders/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_VIEW');
        inv_ok(DistributionOrderService::get($pdo, (int) $params['id']), 'OK');
    },

    'POST /distribution-orders/{id}/approve' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_APPROVE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::approve($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order approved');
    },

    'POST /distribution-orders/{id}/start-picking' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_APPROVE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::startPicking($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order moved to picking');
    },

    'POST /distribution-orders/{id}/dispatch' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_DISPATCH');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        if (!empty($input['allow_negative_stock'])) {
            inv_require_permission($pdo, $user, 'STOCK_ALLOW_NEGATIVE');
        }
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::dispatch($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order dispatched');
    },

    'POST /distribution-orders/{id}/receive' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_RECEIVE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::receive($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order received');
    },

    'POST /distribution-orders/{id}/complete' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_RECEIVE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::complete($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order completed');
    },

    'POST /distribution-orders/{id}/cancel' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_CREATE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::cancel($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order cancelled');
    },

    'POST /distribution-orders/{id}/reverse' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_REVERSE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionOrderService::reverse($tx, (int) $params['id'], $input));
        inv_ok($result, 'Delivery order reversed');
    },

    // ---- PHASE V2.11B: Pricing Policy (company/category/SKU) ----
    'GET /distribution-pricing-policies' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_PRICING_MANAGE');
        inv_ok(PricingPolicyService::listAll($pdo), 'OK');
    },

    'POST /distribution-pricing-policies' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_PRICING_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => PricingPolicyService::upsert($tx, $input));
        inv_ok($result, 'Pricing policy saved');
    },

    'POST /distribution-pricing-policies/{id}/deactivate' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_PRICING_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => PricingPolicyService::deactivate($tx, (int) $params['id'], $input));
        inv_ok($result, 'Pricing policy deactivated');
    },

    // ---- PHASE V2.11B: Invoices (generated from a dispatched DO) ----
    'GET /distribution-invoices' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_VIEW');
        inv_ok(DistributionInvoiceService::listAll($pdo, $query), 'OK');
    },

    'GET /distribution-invoices/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_VIEW');
        inv_ok(DistributionInvoiceService::get($pdo, (int) $params['id']), 'OK');
    },

    'POST /distribution-invoices' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_CREATE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionInvoiceService::create($tx, $input));
        inv_ok($result, 'Invoice created');
    },

    'POST /distribution-invoices/{id}/issue' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_CREATE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionInvoiceService::issue($tx, (int) $params['id'], $input));
        inv_ok($result, 'Invoice issued');
    },

    'POST /distribution-invoices/{id}/cancel' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_CREATE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionInvoiceService::cancel($tx, (int) $params['id'], $input));
        inv_ok($result, 'Invoice cancelled');
    },

    'POST /distribution-invoices/{id}/lines/{lineId}/override-price' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_PRICING_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => DistributionInvoiceService::overrideLinePrice($tx, (int) $params['id'], (int) $params['lineId'], $input));
        inv_ok($result, 'Invoice line price overridden');
    },

    // ---- PHASE V2.11C: Revenue/Margin/Category/Bakery reports ----
    // Revenue/HPP/margin come only from ISSUED invoice lines joined to the
    // REAL FIFO cost of the dispatch (DistributionReportService) — never
    // estimated from the reference price or the configured policy margin.
    'GET /reports/distribution/lines' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_REPORT_VIEW');
        $result = DistributionReportService::lineDetail($pdo, $query);
        if (($query['format'] ?? '') === 'csv') {
            inv_export_csv(
                ['laporan-distribusi-detail', $query['date_from'] ?? '', $query['date_to'] ?? ''],
                ['No. DO', 'No. Invoice', 'Tanggal Invoice', 'Bakery', 'SKU', 'Nama Barang', 'Kategori', 'Qty', 'Satuan', 'Harga Jual', 'Revenue', 'Actual HPP', 'Actual Margin', 'Margin %'],
                $result,
                static fn (array $r) => [
                    $r['do_number'], $r['invoice_number'], $r['invoice_date'], $r['bakery_name'],
                    $r['sku_snapshot'], $r['item_name_snapshot'], $r['category_name'] ?? '-',
                    $r['qty'], $r['unit_code'], $r['selling_unit_price'], $r['revenue'],
                    $r['actual_hpp'], $r['actual_margin'], $r['actual_margin_pct'],
                ]
            );
        }
        inv_ok($result, 'OK');
    },
    'GET /reports/distribution/summary' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_REPORT_VIEW');
        $result = DistributionReportService::summary($pdo, $query);
        if (($query['format'] ?? '') === 'csv') {
            inv_export_csv(
                ['laporan-distribusi-summary', $query['date_from'] ?? '', $query['date_to'] ?? ''],
                ['Metrik', 'Nilai'],
                [
                    ['Total Revenue', $result['total_revenue']], ['Total HPP', $result['total_hpp']],
                    ['Total Margin', $result['total_margin']], ['Margin %', $result['margin_pct']],
                    ['Total DO', $result['total_do']], ['Total Invoice', $result['total_invoice']],
                    ['Total Bakery Dilayani', $result['total_bakery_served']], ['Jumlah Selisih (Discrepancy)', $result['discrepancy_count']],
                ],
                static fn (array $row) => $row
            );
        }
        inv_ok($result, 'OK');
    },
    'GET /reports/distribution/by-category' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_REPORT_VIEW');
        $result = DistributionReportService::byCategory($pdo, $query);
        if (($query['format'] ?? '') === 'csv') {
            inv_export_csv(
                ['laporan-distribusi-per-kategori', $query['date_from'] ?? '', $query['date_to'] ?? ''],
                ['Kategori', 'Qty Distribusi', 'Revenue', 'HPP', 'Margin', 'Margin %'],
                $result,
                static fn (array $r) => [$r['category_name'] ?? '-', $r['qty_distributed'], $r['revenue'], $r['hpp'], $r['margin'], $r['margin_pct']]
            );
        }
        inv_ok($result, 'OK');
    },
    'GET /reports/distribution/by-bakery' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_REPORT_VIEW');
        $result = DistributionReportService::byBakery($pdo, $query);
        if (($query['format'] ?? '') === 'csv') {
            inv_export_csv(
                ['laporan-distribusi-per-bakery', $query['date_from'] ?? '', $query['date_to'] ?? ''],
                ['Bakery', 'Jml DO', 'Jml Invoice', 'Qty Distribusi', 'Revenue', 'HPP', 'Margin', 'Margin %', 'Jumlah Selisih'],
                $result,
                static fn (array $r) => [
                    $r['bakery_name'], $r['do_count'], $r['invoice_count'], $r['qty_distributed'],
                    $r['revenue'], $r['hpp'], $r['margin'], $r['margin_pct'], $r['discrepancy_count'],
                ]
            );
        }
        inv_ok($result, 'OK');
    },

    // ---- PHASE V2.11C: print documents (clean A4 HTML, Section 18/19) ----
    'GET /distribution-orders/{id}/print' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_VIEW');
        inv_html(DistributionPrintService::renderDeliveryOrder($pdo, (int) $params['id']));
    },
    'GET /distribution-invoices/{id}/print' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'DISTRIBUTION_VIEW');
        inv_html(DistributionPrintService::renderInvoice($pdo, (int) $params['id']));
    },

    // ---- Stock Opname (PHASE C2 Section 2 legacy single-count, PHASE
    // V2.12 dual-count P1/P2 blind counting — see StockOpnameService's
    // class docblock for how the two modes coexist on one schema without
    // ever mixing on the same session) ----
    'POST /stock-opname' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }

        inv_require_so_warehouse_scope($user, $warehouseId);

        // PHASE V2.14.10.1 Gate 2 — FINDINGS_V1 is the default for every
        // session started through the real app; the ONLY other value ever
        // accepted here is the literal 'LEGACY_DUAL_COUNT' (any other/
        // missing value sanitizes to the safe default) — a deliberate,
        // explicit opt-out for a caller not yet on the team/findings model,
        // never a way to smuggle an unrecognized value into the column.
        $countingModel = (($input['counting_model'] ?? 'FINDINGS_V1') === 'LEGACY_DUAL_COUNT') ? 'LEGACY_DUAL_COUNT' : 'FINDINGS_V1';

        $sessionId = Database::transaction(
            fn (PDO $tx) => StockOpnameService::start(
                $tx,
                $warehouseId,
                $user['id'],
                $input['item_ids'] ?? null,
                $countingModel
            )
        );

        inv_ok(['session_id' => $sessionId], 'Opname session started');
    },

    'GET /stock-opname' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sql = 'SELECT * FROM stock_opname_sessions WHERE 1=1';
        $params = [];

        // PHASE V2.14.9.1 — ADMIN Transit (ADMIN + warehouse_id set) is
        // forced to their own warehouse here exactly like STOCK already
        // was, so this list route never returns another warehouse's
        // sessions to a scoped account (enforced server-side, not merely
        // hidden client-side).
        $requestedWarehouseId = isset($query['warehouse_id']) ? (int) $query['warehouse_id'] : null;
        $effectiveWarehouseId = inv_so_resolve_warehouse_scope($user, $requestedWarehouseId);
        if ($effectiveWarehouseId !== null) {
            $sql .= ' AND warehouse_id = :wh';
            $params['wh'] = $effectiveWarehouseId;
        }

        if (isset($query['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $query['status'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        inv_ok($stmt->fetchAll(), 'OK');
    },

    // PHASE V2.14.10 — CORRECTED ELIGIBILITY: "ANY ACTIVE USER may be
    // selected as a Stock Opname counter, provided they are explicitly
    // assigned to that Stock Opname team. Counter authority comes from the
    // SESSION ASSIGNMENT, not from promoting the person's global role."
    // Every active user is listed (not just STOCK_OPNAME_MANAGE-holders);
    // `same_warehouse` tells the UI which candidates are this warehouse's
    // "usual" pool vs. a deliberate cross-warehouse assignment, but it is
    // never a hard filter — the server-side authorization decision lives
    // in StockOpnameService::assertCanCount()/assignTeamMembers(), not
    // here (this route only helps the UI populate the multi-select).
    'GET /stock-opname/eligible-counters' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            throw new ValidationException(['warehouse_id is required']);
        }
        inv_require_so_warehouse_scope($user, $warehouseId);

        $stmt = $pdo->prepare(
            "SELECT u.id, u.username, u.full_name, r.code AS role_code,
                    (u.warehouse_id IS NULL OR u.warehouse_id = :wh) AS same_warehouse
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1
             ORDER BY same_warehouse DESC, u.username"
        );
        $stmt->execute(['wh' => $warehouseId]);
        $rows = $stmt->fetchAll();
        inv_ok(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'username' => $r['username'], 'full_name' => $r['full_name'],
            'role_code' => $r['role_code'], 'same_warehouse' => (bool) $r['same_warehouse'],
        ], $rows), 'OK');
    },

    // PHASE V2.14.11.3 — URGENT HOTFIX: "Petugas Stock Opname" account
    // management. SUPERADMIN ONLY — a role_code check, not merely a
    // permission (matching this codebase's existing pattern for
    // security-sensitive account actions, e.g. POST
    // /warehouse-cutovers/{id}/load) — STOCK_OPNAME_MANAGE alone is
    // deliberately NOT sufficient here, since these routes create/reset/
    // (de)activate LOGIN CREDENTIALS, not Stock Opname data. This is
    // entirely separate from P1/P2 SESSION assignment
    // (POST /stock-opname/{id}/assign-team) — create the account here
    // first, assign it to a session's team second, elsewhere.
    'GET /stock-opname/counter-accounts' => function () use ($pdo) {
        $user = inv_require_auth();
        if ($user['role_code'] !== 'SUPERADMIN') {
            inv_error(403, 'FORBIDDEN', 'Managing Petugas Stock Opname accounts is restricted to SUPERADMIN');
        }
        inv_ok(StockOpnameCounterAccountService::list($pdo), 'OK');
    },

    'POST /stock-opname/counter-accounts' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        if ($user['role_code'] !== 'SUPERADMIN') {
            inv_error(403, 'FORBIDDEN', 'Managing Petugas Stock Opname accounts is restricted to SUPERADMIN');
        }
        $result = Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::create(
            $tx,
            (string) ($input['full_name'] ?? ''),
            (string) ($input['username'] ?? ''),
            (string) ($input['password'] ?? ''),
            !array_key_exists('is_active', $input) || (bool) $input['is_active'],
            (int) $user['id'],
            (string) $user['username']
        ));
        inv_ok($result, 'Petugas Stock Opname account created');
    },

    'POST /stock-opname/counter-accounts/{id}/reset-password' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        if ($user['role_code'] !== 'SUPERADMIN') {
            inv_error(403, 'FORBIDDEN', 'Managing Petugas Stock Opname accounts is restricted to SUPERADMIN');
        }
        Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::resetPassword(
            $tx, (int) $params['id'], (string) ($input['password'] ?? ''), (int) $user['id'], (string) $user['username']
        ));
        inv_ok(['success' => true], 'Password diperbarui');
    },

    'POST /stock-opname/counter-accounts/{id}/activate' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if ($user['role_code'] !== 'SUPERADMIN') {
            inv_error(403, 'FORBIDDEN', 'Managing Petugas Stock Opname accounts is restricted to SUPERADMIN');
        }
        Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::activate($tx, (int) $params['id'], (int) $user['id'], (string) $user['username']));
        inv_ok(['success' => true], 'Petugas diaktifkan');
    },

    'POST /stock-opname/counter-accounts/{id}/deactivate' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if ($user['role_code'] !== 'SUPERADMIN') {
            inv_error(403, 'FORBIDDEN', 'Managing Petugas Stock Opname accounts is restricted to SUPERADMIN');
        }
        Database::transaction(fn (PDO $tx) => StockOpnameCounterAccountService::deactivate($tx, (int) $params['id'], (int) $user['id'], (string) $user['username']));
        inv_ok(['success' => true], 'Petugas dinonaktifkan');
    },

    // PHASE V2.14.10 — "STOCK OPNAME SAYA": any authenticated active user
    // may call this; it only ever returns sessions where THEY personally
    // have an active team membership, across every warehouse — nothing
    // about global role/permission gates this route, by design (mirrors
    // GET /auth/me's "only ever about the caller themselves" safety).
    // Defined BEFORE the 'GET /stock-opname/{id}' wildcard route below
    // (the router matches {id} against ANY non-slash string, including
    // "my-sessions" — same reason eligible-counters is also defined
    // before it) so this static path is never shadowed.
    'GET /stock-opname/my-sessions' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_ok(StockOpnameService::mySessions($pdo, (int) $user['id']), 'OK');
    },

    // PHASE V2.12A: full detail (system qty, both P1/P2, match_status) is
    // only ever handed to a supervisor (STOCK_OPNAME_SUPERVISE) or to a
    // caller viewing a legacy/not-yet-assigned session. A caller who IS
    // this specific session's assigned P1 or P2 counter gets the blind
    // view instead — the same identity that could otherwise call this
    // exact route and see the other side's count (Section 4).
    'GET /stock-opname/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $sessionRow = $scope->fetch();

        if ($sessionRow === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        $canSupervise = AuthService::hasPermission($pdo, $user['role_code'], 'STOCK_OPNAME_SUPERVISE');
        if ($canSupervise) {
            inv_require_so_warehouse_scope($user, (int) $sessionRow['warehouse_id']);
            inv_ok(StockOpnameService::get($pdo, $sessionId), 'OK');
        }

        // PHASE V2.14.10 — a plain assigned P1/P2 team member (ANY global
        // role, including one with no Stock Opname permission at all) gets
        // the blind counter view for their own session — session-scoped
        // ONLY, deliberately bypassing the normal warehouse-scope check
        // (a supervisor may assign a counter across warehouses on purpose;
        // see inv_require_so_counter_or_permission()'s docblock).
        try {
            $role = StockOpnameService::assertIsActiveCounter($pdo, $sessionId, (int) $user['id']);
            inv_ok(StockOpnameService::getForCounter($pdo, $sessionId, $role, (int) $user['id']), 'OK');
        } catch (ValidationException $e) {
            // Not a counter here — fall through to the global-permission path.
        }

        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        inv_require_so_warehouse_scope($user, (int) $sessionRow['warehouse_id']);
        inv_ok(StockOpnameService::get($pdo, $sessionId), 'OK');
    },

    'POST /stock-opname/{id}/count' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $counts = [];
        foreach ((array) ($input['counts'] ?? []) as $row) {
            $counts[(int) $row['item_id']] =
                (float) $row['counted_qty_base'];
        }

        Database::transaction(
            fn (PDO $tx) => StockOpnameService::count(
                $tx,
                $sessionId,
                $counts,
                $user['id']
            )
        );

        inv_ok(
            StockOpnameService::get($pdo, $sessionId),
            'Counts recorded'
        );
    },

    // PHASE V2.12A: assign the two independent blind counters. Warehouse-
    // scoped the same as every other opname route; STOCK_OPNAME_MANAGE is
    // enough (the creator/any operational warehouse user can set up the
    // pairing) — StockOpnameService::assignCounters() itself re-validates
    // that each candidate is actually authorized and warehouse-scoped.
    'POST /stock-opname/{id}/assign-counters' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $assignments = [];
        if (array_key_exists('p1_user_id', $input)) { $assignments['p1_user_id'] = $input['p1_user_id'] !== null ? (int) $input['p1_user_id'] : null; }
        if (array_key_exists('p2_user_id', $input)) { $assignments['p2_user_id'] = $input['p2_user_id'] !== null ? (int) $input['p2_user_id'] : null; }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::assignCounters($tx, $sessionId, $assignments, $user['id'])
        );

        inv_ok($result, 'Counters assigned');
    },

    // PHASE V2.14.10 — multi-user TEAM assignment: sets P1 or P2's FULL
    // membership in one call (the supervisor's multi-select "these N
    // users are now on this team"). Same route-level gate as
    // assign-counters (STOCK_OPNAME_MANAGE + warehouse scope) — assigning
    // a team is still a supervisor-tier action; StockOpnameService::
    // assignTeamMembers() re-validates every candidate (any active user,
    // no global-permission requirement — see its docblock) and the
    // same-user-both-roles rule server-side.
    'POST /stock-opname/{id}/assign-team' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $role = (string) ($input['role'] ?? '');
        if (!in_array(strtolower($role), ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $userIds = array_map('intval', (array) ($input['user_ids'] ?? []));

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::assignTeamMembers($tx, $sessionId, $role, $userIds, (int) $user['id'])
        );

        inv_ok($result, 'Team assigned');
    },

    // PHASE V2.14.10 — session-scoped: only an active team member of the
    // POSTed role may claim/release for it; the route itself performs no
    // global-permission or warehouse-scope check at all (identical
    // reasoning to POST .../count/{role} — see
    // inv_require_so_counter_or_permission()'s docblock). "Ambil Item
    // Berikutnya" when item_id is omitted; a specific scanned/searched
    // item otherwise.
    'POST /stock-opname/{id}/claim' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $role = (string) ($input['role'] ?? '');
        if (!in_array(strtolower($role), ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $itemId = isset($input['item_id']) ? (int) $input['item_id'] : null;

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::claimItem($tx, $sessionId, strtolower($role), (int) $user['id'], $itemId)
        );
        inv_ok($result, 'Item claimed');
    },

    // PHASE V2.14.10 — release a claim: the caller's own claim by default;
    // a supervisor (STOCK_OPNAME_SUPERVISE) may pass supervisor_override
    // to release ANY claim on the line (the "unreachable claimant" escape
    // hatch) — checked here, not assumed by the service method.
    'POST /stock-opname/{id}/release' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $role = (string) ($input['role'] ?? '');
        if (!in_array(strtolower($role), ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }
        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new ValidationException(['item_id is required']);
        }
        $wantsOverride = !empty($input['supervisor_override']);
        $isOverride = false;
        if ($wantsOverride) {
            inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
            $isOverride = true;
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::releaseItem($tx, $sessionId, strtolower($role), $itemId, (int) $user['id'], $isOverride)
        );
        inv_ok($result, 'Claim released');
    },

    // PHASE V2.14.10 — "Tambah Temuan": append-only multi-unit finding.
    // Session-scoped: only an active team member of the POSTed role may
    // submit one (no global-permission/warehouse-scope check at all — see
    // inv_require_so_counter_or_permission()'s docblock, same reasoning as
    // /count/{role} and /claim above).
    // PHASE V2.14.11 CORRECTION — every condition (GOOD/DAMAGED/EXPIRED/
    // DEADSTOCK) now carries its OWN multi-unit breakdown, not just GOOD:
    // the body's `conditions` object must supply all four keys, each an
    // array of {unit_id, qty} (explicit-zero — the same "must be sent,
    // 0 if none" rule V2.14.9.3 enforced for the old scalar fields,
    // generalized to every condition's unit array).
    // PHASE V2.14.10.1 Gate 1/7 — every unit_id mirrors the session's
    // FROZEN snapshot shape (GET .../items/{itemId}/units below), never
    // live item master units; the server is authoritative for every
    // conversion factor and duplicate/foreign unit_ids are rejected
    // server-side.
    // PHASE V2.14.10.1 Gate 4 — claim_token is required and verified
    // inside the same transaction as the insert; a stale/mismatched token
    // is refused with 409 CLAIM_LOST rather than silently writing.
    'POST /stock-opname/{id}/findings' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $role = (string) ($input['role'] ?? '');
        if (!in_array(strtolower($role), ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        $auth = inv_require_so_counter_or_permission($pdo, $user, $sessionId, 'STOCK_OPNAME_MANAGE');
        if ($auth['mode'] === 'privileged') {
            inv_require_so_warehouse_scope($user, (int) $warehouseId);
        }

        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new ValidationException(['item_id is required']);
        }
        $claimToken = (string) ($input['claim_token'] ?? '');
        if (trim($claimToken) === '') {
            throw new ValidationException(['claim_token is required']);
        }
        $conditionsInput = (array) ($input['conditions'] ?? []);
        foreach (['GOOD', 'DAMAGED', 'EXPIRED', 'DEADSTOCK'] as $ct) {
            if (!array_key_exists($ct, $conditionsInput) || !is_array($conditionsInput[$ct]) || $conditionsInput[$ct] === []) {
                inv_error(422, 'VALIDATION_ERROR', "conditions.{$ct} is required (at least one {unit_id, qty} entry; qty 0 if none found)");
            }
        }
        $notes = array_key_exists('notes', $input) ? (string) $input['notes'] : null;

        // PHASE V2.14.11.1 — Checkpoint A audit corrective (Blocker 1B):
        // the client must explicitly name which already-uploaded photo(s)
        // belong to THIS finding, grouped by condition_type, e.g.
        // {"DAMAGED": ["<token>"], "EXPIRED": [], "DEADSTOCK": []}. Never
        // inferred from session/line/role/uploader alone — see
        // StockOpnamePhotoService::attachExplicit().
        $photosInput = (array) ($input['photos'] ?? []);
        $photoTokens = [];
        foreach (['DAMAGED', 'EXPIRED', 'DEADSTOCK'] as $ct) {
            $tokens = (array) ($photosInput[$ct] ?? []);
            $photoTokens[$ct] = array_values(array_map('strval', $tokens));
        }

        // PHASE V2.16.1 — optional explicit counted_at for a backdated
        // entry (counter physically counted yesterday, is only saving the
        // result now). Omitted/blank -> submitFinding() defaults to "now",
        // exactly like before this phase.
        $countedAt = null;
        if (array_key_exists('counted_at', $input) && trim((string) $input['counted_at']) !== '') {
            $countedAt = (string) $input['counted_at'];
            if (strtotime($countedAt) === false) {
                inv_error(422, 'VALIDATION_ERROR', 'counted_at must be a valid date/time');
            }
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::submitFinding($tx, $sessionId, strtolower($role), $itemId, $conditionsInput, $notes, (int) $user['id'], $claimToken, $photoTokens, $countedAt)
        );
        inv_ok($result, 'Finding recorded');
    },

    // PHASE V2.14.11 — photo evidence upload for one DAMAGED/EXPIRED/
    // DEADSTOCK condition, attached later by submitFinding() (see that
    // method's docblock). multipart/form-data (not JSON, like the generic
    // /import/upload route): field "photo" is the file, role/condition_type/
    // claim_token/caption are ordinary form fields. Same session-scoped
    // counter authorization as /findings, and the service itself re-checks
    // the exact same live-claim contract before accepting the file.
    'POST /stock-opname/{id}/items/{itemId}/photos' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $itemId = (int) $params['itemId'];
        $role = (string) ($_POST['role'] ?? '');
        if (!in_array(strtolower($role), ['p1', 'p2'], true)) {
            throw new ValidationException(['role must be p1 or p2']);
        }

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        $auth = inv_require_so_counter_or_permission($pdo, $user, $sessionId, 'STOCK_OPNAME_MANAGE');
        if ($auth['mode'] === 'privileged') {
            inv_require_so_warehouse_scope($user, (int) $warehouseId);
        }

        $conditionType = (string) ($_POST['condition_type'] ?? '');
        $claimToken = (string) ($_POST['claim_token'] ?? '');
        $caption = isset($_POST['caption']) ? (string) $_POST['caption'] : null;
        if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            inv_error(422, 'VALIDATION_ERROR', 'No photo uploaded (expected multipart/form-data field "photo")');
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnamePhotoService::upload($tx, $sessionId, strtolower($role), $itemId, $conditionType, (int) $user['id'], $claimToken, $_FILES['photo']['tmp_name'], $caption)
        );
        inv_ok($result, 'Photo uploaded');
    },

    // PHASE V2.14.11.1 — Checkpoint A audit corrective (Blocker 1D): lets
    // the counter drop a selected-then-reconsidered photo BEFORE Simpan
    // Temuan, so it can never later be named in a /findings "photos"
    // payload by mistake. Only the uploader may remove it, only while it
    // is still pending (finding_id IS NULL, enforced in the service), and
    // only by presenting the exact upload_token they were given back at
    // upload time — the same secure-identifier contract attachExplicit()
    // itself relies on. Once removed the row is gone outright: it can
    // never be attached, by this token or any other route.
    'POST /stock-opname/{id}/photos/{photoId}/remove' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $photoId = (int) $params['photoId'];

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        $auth = inv_require_so_counter_or_permission($pdo, $user, $sessionId, 'STOCK_OPNAME_MANAGE');
        if ($auth['mode'] === 'privileged') {
            inv_require_so_warehouse_scope($user, (int) $warehouseId);
        }

        $token = (string) ($input['token'] ?? '');
        if (trim($token) === '') {
            throw new ValidationException(['token is required']);
        }

        $photo = $pdo->prepare('SELECT session_id FROM stock_opname_finding_photos WHERE id = :id');
        $photo->execute(['id' => $photoId]);
        $photoSessionId = $photo->fetchColumn();
        if ($photoSessionId === false || (int) $photoSessionId !== $sessionId) {
            inv_error(404, 'NOT_FOUND', 'photo not found');
        }

        Database::transaction(
            fn (PDO $tx) => StockOpnamePhotoService::remove($tx, $photoId, $token, (int) $user['id'])
        );
        inv_ok(['removed' => true], 'Photo removed');
    },

    // PHASE V2.14.11 — streams a stored photo's bytes back. storage/ sits
    // outside the document root (see .htaccess), so this route is the
    // ONLY way a photo is ever served; re-checks the same session-scoped
    // counter-or-supervisor authorization as every other Stock Opname
    // route rather than trusting a bare file path.
    'GET /stock-opname/{id}/photos/{photoId}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $photoId = (int) $params['photoId'];

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        $auth = inv_require_so_counter_or_permission($pdo, $user, $sessionId, 'STOCK_OPNAME_MANAGE');
        if ($auth['mode'] === 'privileged') {
            inv_require_so_warehouse_scope($user, (int) $warehouseId);
        }

        $photo = $pdo->prepare('SELECT * FROM stock_opname_finding_photos WHERE id = :id AND session_id = :sid');
        $photo->execute(['id' => $photoId, 'sid' => $sessionId]);
        $photo = $photo->fetch();
        if (!$photo) {
            inv_error(404, 'NOT_FOUND', 'photo not found');
        }
        // PHASE V2.14.11 — blind-count boundary: a plain counter (non-
        // privileged $auth mode) may only ever view a photo belonging to
        // their OWN team's role, never the other team's evidence.
        if ($auth['mode'] !== 'privileged' && strtolower($photo['team_role']) !== strtolower($auth['role'])) {
            inv_error(403, 'FORBIDDEN', 'this photo belongs to the other team');
        }

        $path = StockOpnamePhotoService::absolutePath($photo['storage_path']);
        if (!is_file($path)) {
            inv_error(404, 'NOT_FOUND', 'photo file missing on disk');
        }
        header('Content-Type: ' . $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    },

    // PHASE V2.14.10.1 Gate 1 — the session's FROZEN unit snapshot for one
    // item: the ONLY unit source a FINDINGS_V1 counting screen may read
    // once the session has started (never GET /items/{id}/units again).
    // Session-scoped (any active team member of either role, or a
    // privileged supervisor) — read-only, so no warehouse-scope check is
    // needed beyond the session-scoped grant itself.
    'GET /stock-opname/{id}/items/{itemId}/units' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $itemId = (int) $params['itemId'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        $auth = inv_require_so_counter_or_permission($pdo, $user, $sessionId, 'STOCK_OPNAME_MANAGE');
        if ($auth['mode'] === 'privileged') {
            inv_require_so_warehouse_scope($user, (int) $warehouseId);
        }
        inv_ok(StockOpnameService::getSnapshotUnitsForItem($pdo, $sessionId, $itemId), 'OK');
    },

    // PHASE V2.14.10.1 Gate 5 — on-demand detail behind the lightweight
    // counter list's finding_count: a counter's OWN team's finding history
    // for exactly one item, fetched only when they open its panel.
    'GET /stock-opname/{id}/items/{itemId}/my-findings' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        $sessionId = (int) $params['id'];
        $itemId = (int) $params['itemId'];
        $role = StockOpnameService::assertIsActiveCounter($pdo, $sessionId, (int) $user['id']);
        inv_ok(StockOpnameService::getMyFindingsForItem($pdo, $sessionId, $itemId, $role), 'OK');
    },

    // PHASE V2.14.10.1 Gate 5 — supervisor drilldown for one line, BOTH
    // teams, INCLUDING voided entries: STOCK_OPNAME_SUPERVISE + warehouse-
    // scoped, same tier as GET .../review, fetched only when a supervisor
    // opens "Riwayat Temuan" for that specific SKU.
    'GET /stock-opname/{id}/items/{itemId}/findings' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $itemId = (int) $params['itemId'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        inv_ok(StockOpnameService::getLineFindingsForSupervisor($pdo, $sessionId, $itemId), 'OK');
    },

    // PHASE V2.14.10.1 Gate 2 — explicit, one-way, supervisor-only upgrade
    // of a LEGACY_DUAL_COUNT session to FINDINGS_V1 (see
    // StockOpnameService::upgradeToFindingsMode()'s docblock for the exact
    // zero-counts precondition and what it freezes). Same tier as
    // assign-team — STOCK_OPNAME_MANAGE + warehouse-scoped.
    'POST /stock-opname/{id}/upgrade-to-findings' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::upgradeToFindingsMode($tx, $sessionId, (int) $user['id'])
        );
        inv_ok($result, 'Session upgraded to Team/Findings mode');
    },

    // PHASE V2.14.10 — supervisor-only correction: void (never rewrite) a
    // finding. Requires the physical/condition history to remain fully
    // auditable — see StockOpnameService::voidFinding()'s docblock.
    'POST /stock-opname/{id}/findings/{findingId}/void' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $findingId = (int) $params['findingId'];

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $reason = (string) ($input['reason'] ?? '');

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::voidFinding($tx, $sessionId, $findingId, $reason, (int) $user['id'])
        );
        inv_ok($result, 'Finding voided');
    },

    // PHASE V2.16.1 Section 9 — supervisor-only, audited counted_at
    // backfill for a pre-V2.16.1 finding that has none. Never overwrites
    // an existing counted_at (see StockOpnameService::backfillCountedAt()'s
    // own refusal) — this is remediation for history that predates the
    // concept, not a general-purpose correction tool.
    'POST /stock-opname/{id}/findings/{findingId}/backfill-counted-at' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $findingId = (int) $params['findingId'];

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $countedAt = (string) ($input['counted_at'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        if ($countedAt === '' || strtotime($countedAt) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'counted_at is required and must be a valid date/time');
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::backfillCountedAt($tx, $sessionId, $findingId, $countedAt, (int) $user['id'], $reason)
        );
        inv_ok($result, 'counted_at backfilled');
    },

    // PHASE V2.12A: blind, one-item-at-a-time submission (Section 6/16 —
    // barcode-scan friendly). {role} is 'p1' or 'p2'; the service itself
    // enforces that the caller IS that session's assigned counter.
    'POST /stock-opname/{id}/count/{role}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();

        $sessionId = (int) $params['id'];
        $role = (string) $params['role'];
        if (!in_array($role, ['p1', 'p2'], true)) {
            inv_error(404, 'NOT_FOUND', 'unknown counting role');
        }

        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        // PHASE V2.14.10 — an active team member of THIS exact role
        // bypasses the global-permission + warehouse-scope gate entirely
        // (session-scoped grant); anyone else falls back to the original
        // STOCK_OPNAME_MANAGE + warehouse-scope path unchanged.
        $auth = inv_require_so_counter_or_permission($pdo, $user, $sessionId, 'STOCK_OPNAME_MANAGE');
        if ($auth['mode'] === 'privileged') {
            inv_require_so_warehouse_scope($user, (int) $warehouseId);
        }

        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new ValidationException(['item_id is required']);
        }
        if (!isset($input['counted_qty_base'])) {
            throw new ValidationException(['counted_qty_base is required']);
        }

        // PHASE V2.14.9 — physical-condition classification, submitted
        // together with the qty in this same write-once call.
        // PHASE V2.14.9.3 — StockOpnameService::validateConditions()
        // accepts a literal [] for backward compatibility with old
        // direct-service/internal callers, but that bypass is NOT part of
        // this HTTP contract: a P1/P2 count submitted over this endpoint
        // must always explicitly state all three fields (0 if none found)
        // so an omitted field is never silently written as NULL. Missing
        // any of the three is rejected before submitCount() is ever
        // called — the line is never touched.
        foreach (['rusak_qty', 'expired_qty', 'deadstock_qty'] as $conditionField) {
            if (!array_key_exists($conditionField, $input)) {
                inv_error(422, 'VALIDATION_ERROR', 'Rusak, Expired, dan Deadstock wajib dikirim. Gunakan 0 bila tidak ada.');
            }
        }
        $conditions = [
            'rusak_qty' => $input['rusak_qty'],
            'expired_qty' => $input['expired_qty'],
            'deadstock_qty' => $input['deadstock_qty'],
        ];
        if (array_key_exists('notes', $input)) { $conditions['notes'] = $input['notes']; }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::submitCount($tx, $sessionId, $role, $itemId, (float) $input['counted_qty_base'], $user['id'], $conditions)
        );

        inv_ok($result, 'Count recorded');
    },

    // PHASE V2.12B: supervisor comparison/review dashboard (Section 11).
    'GET /stock-opname/{id}/review' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        inv_ok(StockOpnameService::review($pdo, $sessionId), 'OK');
    },

    // PHASE V2.12B: recount a MISMATCH item — supervisor-only (Section 9,
    // "preferably supervisor").
    'POST /stock-opname/{id}/recount' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new ValidationException(['item_id is required']);
        }
        if (!isset($input['counted_qty_base'])) {
            throw new ValidationException(['counted_qty_base is required']);
        }

        // PHASE V2.14.9.1 — optional final condition resolution carried in
        // the same recount action (see StockOpnameService::recount()).
        $finalConditions = [];
        foreach (['final_rusak_qty', 'final_expired_qty', 'final_deadstock_qty', 'final_notes'] as $key) {
            if (array_key_exists($key, $input)) { $finalConditions[$key] = $input[$key]; }
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::recount($tx, $sessionId, $itemId, (float) $input['counted_qty_base'], (string) ($input['reason'] ?? ''), $user['id'], $finalConditions)
        );

        inv_ok($result, 'Recount recorded');
    },

    // PHASE V2.14.9.1 — supervisor explicitly resolves a Rusak/Expired/
    // Deadstock/Keterangan disagreement between P1 and P2 (independent of
    // match_status — a MATCH qty line can still have a condition-only
    // disagreement). StockOpnameService::resolveConditions() itself
    // refuses this until the line's physical qty is resolved (MATCH or
    // RECOUNTED).
    'POST /stock-opname/{id}/resolve-conditions' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new ValidationException(['item_id is required']);
        }

        $finalValues = [];
        foreach (['final_rusak_qty', 'final_expired_qty', 'final_deadstock_qty', 'final_notes'] as $key) {
            if (array_key_exists($key, $input)) { $finalValues[$key] = $input[$key]; }
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::resolveConditions($tx, $sessionId, $itemId, $finalValues, $user['id'])
        );

        inv_ok($result, 'Condition values resolved');
    },

    // PHASE V2.12B: supervisor excuses a still-unresolved item from
    // blocking finalize (Section 10).
    'POST /stock-opname/{id}/exclude' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            throw new ValidationException(['item_id is required']);
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::excludeUncounted($tx, $sessionId, $itemId, (string) ($input['reason'] ?? ''), $user['id'])
        );

        inv_ok($result, 'Item excluded');
    },

    // PHASE V2.12B: FINALIZE is a supervisory action (Section 11/19).
    'POST /stock-opname/{id}/finalize' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::finalize(
                $tx,
                $sessionId,
                $user['id']
            )
        );

        inv_ok($result, 'Opname finalized');
    },

    // PHASE V2.12B: POST is a supervisory action (Section 12/19).
    'POST /stock-opname/{id}/post' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];

        $scope = $pdo->prepare(
            'SELECT warehouse_id
             FROM stock_opname_sessions
             WHERE id = :id'
        );
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();

        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }

        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $overrides = [];
        foreach ((array) ($input['cost_overrides'] ?? []) as $itemId => $cost) {
            $overrides[(int) $itemId] = (float) $cost;
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::post(
                $tx,
                $sessionId,
                $user['id'],
                $overrides
            )
        );

        inv_ok($result, 'Opname posted');
    },

    // PHASE V2.12B: cancellation before POST — supervisory action
    // (Section 23).
    'POST /stock-opname/{id}/cancel' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');

        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameService::cancel($tx, $sessionId, (string) ($input['reason'] ?? ''), $user['id'])
        );

        inv_ok($result, 'Opname session cancelled');
    },

    // PHASE V2.12C: print documents (clean A4 discrepancy report, Section 22).
    'GET /stock-opname/{id}/print' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_MANAGE');
        $sessionId = (int) $params['id'];
        $wh = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $wh->execute(['id' => $sessionId]);
        $warehouseId = $wh->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        inv_html(StockOpnamePrintService::renderResult($pdo, $sessionId));
    },

    // ============================================================
    // PHASE V2.16 — Stock Opname Excel REFERENCE import + Final SO
    // export. REFERENCE/RECONCILIATION ONLY (see
    // StockOpnameReferenceImportService/StockOpnameFinalExportService
    // docblocks): never touches inventory_batches, never edits an
    // existing P1/P2/recount/finding, never unblocks
    // FINDINGS_V1_CHECKPOINT_B_REQUIRED. Same permission/warehouse-scope
    // gate as finalize/post/cancel above — a counter (no
    // STOCK_OPNAME_SUPERVISE) gets 403, never this session's reference/
    // financial data.
    // ============================================================
    'POST /stock-opname/{id}/reference-import' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            inv_error(422, 'VALIDATION_ERROR', 'No file uploaded (expected multipart/form-data field "file")');
        }
        $originalName = basename((string) $_FILES['file']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'Only .xlsx or .csv files are accepted');
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameReferenceImportService::import($tx, $sessionId, $_FILES['file']['tmp_name'], $originalName, (int) $user['id'])
        );
        inv_ok($result, 'Reference imported');
    },

    'GET /stock-opname/{id}/reference-batches' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $stmt = $pdo->prepare('SELECT * FROM stock_opname_reference_batches WHERE session_id = :sid ORDER BY uploaded_at DESC');
        $stmt->execute(['sid' => $sessionId]);
        inv_ok($stmt->fetchAll(), 'OK');
    },

    'GET /stock-opname/{id}/reference-rows' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $status = isset($query['mapping_status']) && $query['mapping_status'] !== '' ? (string) $query['mapping_status'] : null;
        $sql = 'SELECT * FROM stock_opname_reference_rows WHERE session_id = :sid';
        $args = ['sid' => $sessionId];
        if ($status !== null) {
            $sql .= ' AND mapping_status = :status';
            $args['status'] = $status;
        }
        $sql .= ' ORDER BY import_batch_id DESC, source_row_reference ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        inv_ok($stmt->fetchAll(), 'OK');
    },

    'POST /stock-opname/{id}/reference-rows/{rowId}/map' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $itemId = (int) ($input['item_id'] ?? 0);
        if ($itemId <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'item_id is required');
        }
        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameReferenceImportService::manualMapRow($tx, $sessionId, (int) $params['rowId'], $itemId, (int) $user['id'])
        );
        inv_ok($result, 'Row mapped');
    },

    'POST /stock-opname/{id}/reference-item-mappings' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $code = (string) ($input['source_code'] ?? '');
        $itemId = (int) ($input['item_id'] ?? 0);
        $notes = isset($input['notes']) && $input['notes'] !== '' ? (string) $input['notes'] : null;
        $id = Database::transaction(
            fn (PDO $tx) => StockOpnameReferenceImportService::approveItemMapping($tx, $code, $itemId, (int) $user['id'], $notes)
        );
        inv_ok(['mapping_id' => $id], 'Mapping approved');
    },

    'POST /stock-opname/{id}/reference-movements' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $itemId = (int) ($input['item_id'] ?? 0);
        $type = (string) ($input['movement_type'] ?? '');
        $qty = (float) ($input['qty_base'] ?? 0);
        $effectiveAt = (string) ($input['effective_at'] ?? '');
        $docRef = isset($input['document_reference']) && $input['document_reference'] !== '' ? (string) $input['document_reference'] : null;
        $reason = (string) ($input['reason'] ?? '');
        if ($effectiveAt === '' || strtotime($effectiveAt) === false) {
            inv_error(422, 'VALIDATION_ERROR', 'effective_at is required and must be a valid date/time');
        }
        $id = Database::transaction(
            fn (PDO $tx) => StockOpnameReferenceImportService::recordMovement($tx, $sessionId, $itemId, $type, $qty, $effectiveAt, $docRef, $reason, (int) $user['id'])
        );
        inv_ok(['movement_id' => $id], 'Movement recorded');
    },

    'GET /stock-opname/{id}/export/draft' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $path = StockOpnameFinalExportService::draft($pdo, $sessionId, (int) $user['id']);
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="draft-final-so-' . $sessionId . '-' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } finally {
            @unlink($path);
        }
    },

    'GET /stock-opname/{id}/export/final' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $path = StockOpnameFinalExportService::official($pdo, $sessionId, (int) $user['id']);
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="final-so-' . $sessionId . '-' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } finally {
            @unlink($path);
        }
    },

    // ============================================================
    // PHASE V2.16.1 — "STOK BUKU SO" EOD reconciliation. Same
    // STOCK_OPNAME_SUPERVISE + warehouse-scope gate as V2.16 above; same
    // REFERENCE/RECONCILIATION-ONLY guarantee (never touches
    // inventory_batches/stock_adjustments/an existing finding).
    // ============================================================
    'POST /stock-opname/{id}/baseline-import' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            inv_error(422, 'VALIDATION_ERROR', 'No file uploaded (expected multipart/form-data field "file")');
        }
        $originalName = basename((string) $_FILES['file']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'Only .xlsx or .csv files are accepted');
        }
        // Admin-confirmed coverage per movement stream (Section 3) — never
        // inferred from the file itself.
        $coverage = [
            'inout_through' => (string) ($_POST['baseline_inout_through'] ?? ''),
            'scaling_through' => (string) ($_POST['baseline_scaling_through'] ?? ''),
            'adjustment_through' => (string) ($_POST['baseline_adjustment_through'] ?? ''),
        ];

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameBookStockService::importBaseline($tx, $sessionId, $_FILES['file']['tmp_name'], $originalName, (int) $user['id'], $coverage)
        );
        inv_ok($result, 'Baseline Stok SCM imported');
    },

    'POST /stock-opname/{id}/movement-import' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);

        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            inv_error(422, 'VALIDATION_ERROR', 'No file uploaded (expected multipart/form-data field "file")');
        }
        $originalName = basename((string) $_FILES['file']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'Only .xlsx or .csv files are accepted');
        }

        $result = Database::transaction(
            fn (PDO $tx) => StockOpnameBookStockService::importMovements($tx, $sessionId, $_FILES['file']['tmp_name'], $originalName, (int) $user['id'])
        );
        inv_ok($result, 'Movement file imported');
    },

    'GET /stock-opname/{id}/eod-reconciliation' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        inv_ok(StockOpnameBookStockService::reconciliation($pdo, $sessionId), 'OK');
    },

    // Section 15/B UI — a never-silent breakdown of every imported
    // movement row by its inclusion_status, so an excluded row is always
    // inspectable rather than just absent from the Book Stock EOD total.
    'GET /stock-opname/{id}/movement-status-breakdown' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $stmt = $pdo->prepare('SELECT inclusion_status, COUNT(*) AS cnt FROM stock_opname_reference_movements WHERE session_id = :sid GROUP BY inclusion_status');
        $stmt->execute(['sid' => $sessionId]);
        $counts = array_fill_keys(['INCLUDED', 'ALREADY_IN_BASELINE', 'AFTER_SO_CUTOFF', 'UNMATCHED_ITEM', 'UNIT_MISMATCH', 'INVALID_DATE', 'DUPLICATE', 'NEEDS_REVIEW'], 0);
        foreach ($stmt->fetchAll() as $r) {
            $counts[$r['inclusion_status']] = (int) $r['cnt'];
        }
        inv_ok($counts, 'OK');
    },

    'GET /stock-opname/{id}/export/eod-draft' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $path = StockOpnameFinalExportService::draftReconciliationExport($pdo, $sessionId, (int) $user['id']);
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="draft-eod-reconciliation-' . $sessionId . '-' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } finally {
            @unlink($path);
        }
    },

    'GET /stock-opname/{id}/export/eod-final' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_OPNAME_SUPERVISE');
        $sessionId = (int) $params['id'];
        $scope = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        $scope->execute(['id' => $sessionId]);
        $warehouseId = $scope->fetchColumn();
        if ($warehouseId === false) {
            inv_error(404, 'NOT_FOUND', 'opname session not found');
        }
        inv_require_so_warehouse_scope($user, (int) $warehouseId);
        $path = StockOpnameFinalExportService::officialReconciliationExport($pdo, $sessionId, (int) $user['id']);
        try {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="final-eod-reconciliation-' . $sessionId . '-' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } finally {
            @unlink($path);
        }
    },

    // ---- Stock Adjustments (PHASE C2 Section 3) ----
    'POST /stock-adjustments' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'STOCK_ADJUSTMENT_CREATE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => StockAdjustmentService::post($tx, $input));
        inv_ok($result, 'Adjustment posted');
    },

    // ---- Production / Racik (PHASE C2 Section 4) ----
    'POST /production' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'PRODUCTION_MANAGE');
        if (isset($input['warehouse_id'])) { inv_require_warehouse_scope($user, (int) $input['warehouse_id']); }
        inv_require_division_scope($user, isset($input['division_id']) ? (int) $input['division_id'] : null);
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        $result = Database::transaction(fn (PDO $tx) => ProductionService::create($tx, $input));
        inv_ok($result, 'Production posted');
    },
    'GET /production/{id}' => function (array $params) use ($pdo) {
        inv_require_auth();
        inv_ok(ProductionService::get($pdo, (int) $params['id']), 'OK');
    },

    // ---- Book Closing (PHASE C2 Section 5 / PHASE D0.4 next_closeable_period) ----
    'POST /book-closing/preview' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'BOOK_CLOSING_MANAGE');
        inv_ok(BookClosingService::preview($pdo, $input['period_start'], $input['period_end']), 'OK');
    },
    'POST /book-closing/close' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'BOOK_CLOSING_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => BookClosingService::close($tx, $input['period_start'], $input['period_end'], $user['id']));
        inv_ok($result, 'Period closed');
    },
    'GET /book-closing' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(BookClosingService::listAll($pdo), 'OK');
    },
    'GET /book-closing/next-closeable' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(['next_closeable_period_start' => BookClosingService::nextCloseablePeriodStart($pdo)], 'OK');
    },

    // ---- Reconciliation (PHASE E3) ----
    'GET /reconciliation' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'RECONCILIATION_VIEW');
        inv_ok(ReconciliationService::run($pdo), 'OK');
    },

    // ---- System Health (PHASE G25 — post-go-live monitoring) ----
    'GET /system/health' => function () use ($pdo) {
        $user = inv_require_auth();
        if (!in_array($user['role_code'], ['ADMIN', 'SUPERADMIN'], true)) {
            inv_error(403, 'FORBIDDEN', 'System health is restricted to ADMIN/SUPERADMIN');
        }
        inv_ok(SystemHealthService::check($pdo), 'OK');
    },

    // ---- Audit Log (PHASE D13) ----
    'GET /audit-logs' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');

        $where = [];
        $params = [];
        if (!empty($query['date_from'])) { $where[] = 'a.created_at >= :date_from'; $params['date_from'] = $query['date_from'] . ' 00:00:00'; }
        if (!empty($query['date_to'])) { $where[] = 'a.created_at <= :date_to'; $params['date_to'] = $query['date_to'] . ' 23:59:59'; }
        if (!empty($query['username'])) { $where[] = 'a.username_snapshot LIKE :username'; $params['username'] = '%' . $query['username'] . '%'; }
        if (!empty($query['action_code'])) { $where[] = 'a.action_code = :action_code'; $params['action_code'] = $query['action_code']; }
        if (!empty($query['entity_type'])) { $where[] = 'a.entity_type = :entity_type'; $params['entity_type'] = $query['entity_type']; }
        $sql = 'SELECT a.* FROM audit_logs a' . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY a.id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        inv_ok($stmt->fetchAll(), 'OK');
    },

    // ============================================================
    // PHASE V2.2 — Trace Center. Strictly read-only (GET only, TraceService
    // only ever SELECTs — see its own docblock for the architecture
    // decision: every correlation here is an existing FK, no new tables).
    // Gated on the same AUDIT_LOG_VIEW permission GET /audit-logs already
    // uses — this is audit/governance visibility, not a new access tier.
    // A STOCK user's warehouse is always re-derived from their own account
    // row, never trusted from the request.
    // ============================================================
    'GET /trace/search' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $q = trim((string) ($query['q'] ?? ''));
        if ($q === '') {
            inv_ok([], 'OK');
        }
        $validTypes = ['item', 'transaction', 'supplier', 'bakery_destination', 'category', 'warehouse', 'transfer', 'user', 'opname', 'production', 'opening', 'import', 'role'];
        $type = in_array($query['type'] ?? '', $validTypes, true) ? $query['type'] : null;
        $limit = min(50, max(1, (int) ($query['limit'] ?? 20)));
        inv_ok(TraceService::search($pdo, $q, $type, $limit), 'OK');
    },

    'GET /trace/events' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 25), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 25) : 25;
        inv_ok(TraceService::browseEvents($pdo, [
            'entity_type' => $query['entity_type'] ?? null,
            'action_code' => $query['action_code'] ?? null,
            'username' => $query['username'] ?? null,
            'date_from' => $query['date_from'] ?? null,
            'date_to' => $query['date_to'] ?? null,
            'dir' => $query['dir'] ?? 'desc',
            'page' => $page,
            'per_page' => $perPage,
        ]), 'OK');
    },

    'GET /trace/entity' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $type = (string) ($query['type'] ?? '');
        $id = (int) ($query['id'] ?? 0);
        if ($id <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'id is required');
        }
        if ($type === 'warehouse') {
            inv_require_warehouse_scope($user, $id);
        }
        $result = TraceService::entityTrace($pdo, $type, $id);
        if ($type === 'stock_policy' && isset($result['overview']['warehouse_id'])) {
            inv_require_warehouse_scope($user, (int) $result['overview']['warehouse_id']);
        }
        inv_ok($result, 'OK');
    },

    'GET /trace/transaction/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::transactionTrace($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $result['transaction']['warehouse_id']);
        inv_ok($result, 'OK');
    },

    'GET /trace/inventory' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $itemId = (int) ($query['item_id'] ?? 0);
        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        if ($itemId <= 0) {
            inv_error(422, 'VALIDATION_ERROR', 'item_id is required');
        }
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            $warehouseId = (int) $user['warehouse_id'];
        } elseif ($warehouseId === null) {
            inv_error(422, 'VALIDATION_ERROR', 'warehouse_id is required');
        } else {
            inv_require_warehouse_scope($user, $warehouseId);
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 50), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 50) : 50;
        inv_ok(TraceService::inventoryTrace($pdo, $itemId, $warehouseId, $page, $perPage), 'OK');
    },

    // ============================================================
    // PHASE V2.2B — closes the coverage gap the owner flagged after V2.2:
    // Transfer/Opname/Production/Opening were PARTIAL (traceable only via
    // their underlying transaction, not directly), Import/User/Role were
    // dead ends. Same read-only, AUDIT_LOG_VIEW-gated pattern as the block
    // above. User/Role additionally require SUPERADMIN/ADMIN — account and
    // permission structure is more sensitive than inventory movement, so
    // audit-view alone isn't enough for those two.
    // ============================================================
    'GET /trace/transfer/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::transferTrace($pdo, (int) $params['id']);
        if ($user['role_code'] === 'STOCK') {
            $wh = (int) ($user['warehouse_id'] ?? 0);
            $inScope = $wh > 0 && ($wh === (int) $result['transfer']['from_warehouse_id'] || $wh === (int) $result['transfer']['to_warehouse_id']);
            if (!$inScope) {
                inv_error(403, 'FORBIDDEN', 'Transfer is outside your assigned warehouse');
            }
        }
        inv_ok($result, 'OK');
    },

    'GET /trace/opname/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::opnameTrace($pdo, (int) $params['id']);
        inv_require_so_warehouse_scope($user, (int) $result['session']['warehouse_id']);
        inv_ok($result, 'OK');
    },

    'GET /trace/production/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::productionTrace($pdo, (int) $params['id']);
        inv_require_warehouse_scope($user, (int) $result['production']['warehouse_id']);
        inv_ok($result, 'OK');
    },

    'GET /trace/opening/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $result = TraceService::openingTrace($pdo, (int) $params['id']);
        // An opening batch can span multiple warehouses; a STOCK user only
        // ever sees the lines for their own warehouse, never the others —
        // same "silently scope, don't 403 the whole record" behavior as
        // GET /items/report and GET /trace/inventory.
        if ($user['role_code'] === 'STOCK') {
            $wh = (int) ($user['warehouse_id'] ?? 0);
            $result['lines'] = array_values(array_filter(
                $result['lines'],
                static fn ($l) => (int) ($l['staging_line']['warehouse_id'] ?? 0) === $wh
            ));
            if ($result['lines'] === []) {
                inv_error(403, 'FORBIDDEN', 'This opening batch has no lines in your assigned warehouse');
            }
        }
        inv_ok($result, 'OK');
    },

    'GET /trace/import/{id}' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'AUDIT_LOG_VIEW');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 50), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 50) : 50;
        inv_ok(TraceService::importTrace($pdo, (int) $params['id'], $page, $perPage), 'OK');
    },

    'GET /trace/user/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if (!in_array($user['role_code'], ['ADMIN', 'SUPERADMIN'], true)) {
            inv_error(403, 'FORBIDDEN', 'User trace is restricted to ADMIN/SUPERADMIN');
        }
        inv_ok(TraceService::userTrace($pdo, (int) $params['id']), 'OK');
    },

    'GET /trace/role/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        if (!in_array($user['role_code'], ['ADMIN', 'SUPERADMIN'], true)) {
            inv_error(403, 'FORBIDDEN', 'Role trace is restricted to ADMIN/SUPERADMIN');
        }
        inv_ok(TraceService::roleTrace($pdo, (int) $params['id']), 'OK');
    },

    // ---- Import module (PHASE E/E2). `file_path` must be a path already on
    // the server's disk (e.g. placed by a separate upload step) — this pass
    // does not implement multipart file upload handling itself (PHASE D12
    // gap, documented in docs/PHASE_C2_ENDPOINTS.md). ----
    // PHASE D12: real multipart upload — closes the "file_path must already be
    // on disk" gap noted in docs/PHASE_C2_ENDPOINTS.md. Saved under a
    // dedicated, non-web-reachable storage/imports/ directory with a
    // generated name (never the client's own filename) so this can't be used
    // to overwrite or traverse into anything else on disk.
    'POST /import/upload' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            inv_error(422, 'VALIDATION_ERROR', 'No file uploaded (expected multipart/form-data field "file")');
        }
        $originalName = basename((string) $_FILES['file']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'Only .csv or .xlsx files are accepted');
        }
        $storageDir = __DIR__ . '/../storage/imports';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = $storageDir . '/' . $storedName;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            inv_error(500, 'INTERNAL_ERROR', 'Failed to store uploaded file');
        }
        inv_ok(['file_path' => $destination, 'file_name' => $originalName], 'Uploaded');
    },

    // PHASE D12: preview staged rows before commit (generic import_batches/import_rows path).
    'GET /import/batches/{id}/rows' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $batch = $pdo->prepare('SELECT * FROM import_batches WHERE id = :id');
        $batch->execute(['id' => (int) $params['id']]);
        $batch = $batch->fetch();
        if (!$batch) {
            inv_error(404, 'NOT_FOUND', 'import batch not found');
        }
        $rows = $pdo->prepare('SELECT * FROM import_rows WHERE import_batch_id = :id ORDER BY row_no');
        $rows->execute(['id' => (int) $params['id']]);
        inv_ok(['batch' => $batch, 'rows' => $rows->fetchAll()], 'OK');
    },
    // PHASE D12: preview for the Opening Stock path, which stages into stock_opening_lines instead.
    'GET /import/opening-stock/{id}/rows' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $opening = $pdo->prepare('SELECT * FROM stock_openings WHERE id = :id');
        $opening->execute(['id' => (int) $params['id']]);
        $opening = $opening->fetch();
        if (!$opening) {
            inv_error(404, 'NOT_FOUND', 'stock opening batch not found');
        }
        $lines = $pdo->prepare(
            'SELECT sol.*, i.sku, i.name FROM stock_opening_lines sol JOIN items i ON i.id = sol.item_id
             WHERE stock_opening_id = :id ORDER BY sol.id'
        );
        $lines->execute(['id' => (int) $params['id']]);
        inv_ok(['opening' => $opening, 'lines' => $lines->fetchAll()], 'OK');
    },

    // PHASE V2.6A: "Download Template Excel" — headers come straight from
    // ImportTemplateService, which is built from the exact accepted-column
    // list of the four importer services above (never a hand-maintained
    // copy that can drift). Read-only, generates the file fresh on every
    // request, no staging/commit/inventory effect whatsoever.
    'GET /import/template/{type}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $type = strtoupper($params['type']);
        if (!in_array($type, ['MASTER_ITEM', 'SUPPLIER', 'DIVISION', 'WAREHOUSE', 'LIVE_TRANSACTION', 'MINIMUM_STOCK'], true)) {
            inv_error(404, 'NOT_FOUND', 'Unknown import template type');
        }
        $sheets = ImportTemplateService::build($type);
        $tmpPath = tempnam(sys_get_temp_dir(), 'import_template_');
        try {
            ExcelWriterService::write($tmpPath, $sheets);
            $fileName = 'template-import-' . strtolower(str_replace('_', '-', $type)) . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($tmpPath));
            readfile($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
        exit;
    },

    'POST /import/master-item/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportMasterItemService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/master-item/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportMasterItemService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    // PHASE V2.8 — "Import Transaksi Live": IN/OUT only, real FIFO effect
    // (never historical). Same IMPORT_MANAGE gate as every other import
    // type above — ADMIN/SUPERADMIN only, consistent with the rest of this
    // module (neither role is warehouse/division-scoped, so no further
    // per-row scope check is needed on top of ImportLiveTransactionService's
    // own warehouse-active check). MUST be declared before the generic
    // 'POST /import/{type}/...' routes below — the router matches
    // parameterized routes in array order and 'live-transaction' would
    // otherwise be swallowed by {type} there first.
    'POST /import/live-transaction/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportLiveTransactionService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/live-transaction/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportLiveTransactionService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    // PHASE V2.9 — bulk "Minimum Stock per Gudang" import. Same
    // IMPORT_MANAGE gate as every other import type (ADMIN/SUPERADMIN
    // already hold STOCK_POLICY_MANAGE too, per role_permissions). MUST
    // be declared before the generic 'POST /import/{type}/...' routes
    // below, same reason as live-transaction above.
    'POST /import/minimum-stock/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportStockPolicyService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/minimum-stock/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportStockPolicyService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    'POST /import/{type}/stage' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $type = strtoupper($params['type']);
        if (!in_array($type, ['SUPPLIER', 'DIVISION', 'WAREHOUSE'], true)) {
            inv_error(404, 'NOT_FOUND', 'Unknown import type');
        }
        $id = ImportSimpleMasterService::stage($pdo, $type, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/{type}/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $type = strtoupper($params['type']);
        if (!in_array($type, ['SUPPLIER', 'DIVISION', 'WAREHOUSE'], true)) {
            inv_error(404, 'NOT_FOUND', 'Unknown import type');
        }
        $result = Database::transaction(fn (PDO $tx) => ImportSimpleMasterService::commit($tx, $type, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    'POST /import/opening-stock/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportOpeningStockService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['stock_opening_id' => $id], 'Staged');
    },
    'POST /import/opening-stock/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportOpeningStockService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },
    // PHASE G-DATA 2 Section 12: GO_LIVE_READY gate for a staged final
    // opening batch — read-only, never mutates data.
    'GET /import/opening-stock/{id}/reconciliation' => function (array $params) use ($pdo) {
        inv_require_auth();
        inv_ok(OpeningReconciliationService::report($pdo, (int) $params['id']), 'OK');
    },
    // PHASE G-DATA 2 Section 11: the 8 historical movement-reconciliation
    // rows, kept deliberately separate from unit-conversion and opening
    // questions. Read-only list; verified_final_opening is filled in via a
    // separate step once the owner's final stock file confirms it.
    'GET /movement-reconciliation-reviews' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(MovementReconciliationReviewService::list($pdo), 'OK');
    },
    // POLICY CORRECTION — the admin review list for the owner-approved
    // migration-negative whitelist: current balance, MIGRATION_NEGATIVE_REVIEW/
    // NEEDS_STOCK_OPNAME status (computed live from current stock), and the
    // historical_* evidence to drill back into from the live negative
    // opening. One endpoint, read by the dashboard, stock list, SKU detail,
    // and reconciliation report alike (they can also all read the same
    // flags directly off GET /items/{id}/stock via InventoryService).
    'GET /migration-negative-review' => function () use ($pdo) {
        inv_require_auth();
        inv_ok(MigrationNegativeStockService::reviewList($pdo), 'OK');
    },

    'POST /import/historical/stage' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $id = ImportHistoricalTransactionService::stage($pdo, $input['file_path'], $input['file_name'] ?? basename($input['file_path']), $user['id']);
        inv_ok(['import_batch_id' => $id], 'Staged');
    },
    'POST /import/historical/{id}/commit' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'IMPORT_MANAGE');
        $result = Database::transaction(fn (PDO $tx) => ImportHistoricalTransactionService::commit($tx, (int) $params['id'], $user['id']));
        inv_ok($result, 'Committed');
    },

    // ============================================================
    // PHASE V2.1 — Master Barang enhanced list + safe edit/delete.
    // GET /items/report is a NEW, additive endpoint — GET /items stays
    // byte-for-byte untouched (docs/PHASE_4_TASK_B_GET_ITEMS_DEBT.md).
    // Reuses StockReportService::list()/exportAll() — same single source
    // of truth as "Stok Barang" — extended this phase with supplier_id,
    // item_status (tri-state Semua/Aktif/Tidak Aktif), stock_status
    // (Ada Stok/Stok 0/Need Attention), and an updated_at sort key. Sort
    // keys are looked up in a fixed whitelist array (StockReportService::
    // SORTABLE) — an unrecognized sort value silently falls back to name,
    // never concatenated into SQL.
    // ============================================================
    'GET /items/report' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $warehouseId = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            if ($warehouseId !== null) {
                inv_require_warehouse_scope($user, $warehouseId);
            } else {
                $warehouseId = (int) $user['warehouse_id'];
            }
        } elseif ($warehouseId !== null) {
            inv_require_warehouse_scope($user, $warehouseId);
        }

        $itemStatus = strtoupper((string) ($query['active'] ?? ''));
        $itemStatus = in_array($itemStatus, ['ACTIVE', 'INACTIVE'], true) ? $itemStatus : null;
        $stockStatus = strtoupper((string) ($query['stock_status'] ?? ''));
        $stockStatus = in_array($stockStatus, ['HAS_STOCK', 'ZERO_STOCK', 'NEEDS_ATTENTION'], true) ? $stockStatus : null;
        $sort = $query['sort'] ?? 'name';
        $validSorts = ['name', 'sku', 'qty', 'value', 'status', 'updated_at'];
        $sort = in_array($sort, $validSorts, true) ? $sort : 'name';
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = in_array((int) ($query['per_page'] ?? 25), [25, 50, 100], true) ? (int) ($query['per_page'] ?? 25) : 25;

        $params = [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'supplier_id' => isset($query['supplier_id']) && $query['supplier_id'] !== '' ? (int) $query['supplier_id'] : null,
            'q' => $query['search'] ?? $query['q'] ?? null,
            'status' => in_array($query['status'] ?? '', ['SAFE', 'LOW', 'CRITICAL', 'OUT_OF_STOCK', 'MIGRATION_NEGATIVE_REVIEW'], true) ? $query['status'] : null,
            'stock_status' => $stockStatus,
            'item_status' => $itemStatus,
            'active_only' => $itemStatus === null, // no tri-state override given -> default to active-only, same convention as GET /reports/stock
            'include_zero_stock' => true,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
        ];

        inv_ok(StockReportService::list($pdo, $params), 'OK');
    },

    // Controlled master-data fields only — name/category/supplier/barcode/
    // notes/status. base_unit_id and minimum_stock (FIFO-sensitive /
    // stock-policy-owned) are deliberately never accepted here; minimum/
    // buffer stay on the existing PUT /stock-policy workflow.
    // PHASE: Edit Barang — extends this SAME route (not a parallel
    // endpoint) with base_unit_id/unit_conversion/price, all optional and
    // additive to the existing name/category/supplier/barcode/notes/
    // status fields above. Still MASTER_ITEM_MANAGE-gated, unchanged.
    'PUT /items/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $itemId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM items WHERE id = :id');
        $existing->execute(['id' => $itemId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'item not found');
        }

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $categoryId = array_key_exists('category_id', $input) ? ($input['category_id'] !== null ? (int) $input['category_id'] : null) : $before['category_id'];
        if ($categoryId !== null) {
            $catExists = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
            $catExists->execute(['id' => $categoryId]);
            if ((int) $catExists->fetchColumn() === 0) {
                throw new ValidationException(["category_id {$categoryId} does not exist"]);
            }
        }
        $supplierId = array_key_exists('default_supplier_id', $input) ? ($input['default_supplier_id'] !== null ? (int) $input['default_supplier_id'] : null) : $before['default_supplier_id'];
        // Supplier must be selected from the existing supplier master —
        // never free text, never auto-created from Edit Barang. Blank
        // (null) remains explicitly allowed.
        if ($supplierId !== null) {
            $supExists = $pdo->prepare('SELECT COUNT(*) FROM suppliers WHERE id = :id');
            $supExists->execute(['id' => $supplierId]);
            if ((int) $supExists->fetchColumn() === 0) {
                throw new ValidationException(["default_supplier_id {$supplierId} does not exist in the supplier master"]);
            }
        }
        $barcode = array_key_exists('barcode', $input) ? (($input['barcode'] === null || trim((string) $input['barcode']) === '') ? null : trim((string) $input['barcode'])) : $before['barcode'];
        $notes = array_key_exists('notes', $input) ? (($input['notes'] === null) ? null : trim((string) $input['notes'])) : $before['notes'];
        $status = array_key_exists('status', $input) ? strtoupper((string) $input['status']) : $before['status'];
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            throw new ValidationException(["status must be ACTIVE or INACTIVE, got '{$status}'"]);
        }

        // Base unit — only when explicitly requested AND different from
        // the current value. UnitConversionService::changeBaseUnit()
        // itself refuses once the item has posted transactions
        // (locked_at) — never forced/bypassed here.
        $baseUnitId = (int) $before['base_unit_id'];
        if (array_key_exists('base_unit_id', $input) && $input['base_unit_id'] !== null) {
            $newBaseUnitId = (int) $input['base_unit_id'];
            if ($newBaseUnitId !== $baseUnitId) {
                $unitExists = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id = :id');
                $unitExists->execute(['id' => $newBaseUnitId]);
                if ((int) $unitExists->fetchColumn() === 0) {
                    throw new ValidationException(["base_unit_id {$newBaseUnitId} is not a recognized unit"]);
                }
                UnitConversionService::changeBaseUnit($pdo, $itemId, $newBaseUnitId);
                $baseUnitId = $newBaseUnitId;
            }
        }

        // Unit conversion — reuses an EXISTING canonical unit (never a
        // free-typed new one; the frontend's dropdown is populated from
        // GET /units) and the existing versioned-conversion mechanism
        // (openNewVersion() closes whatever was open and opens the new
        // factor — never a raw UPDATE on a closed/historical version).
        if (array_key_exists('unit_conversion', $input) && is_array($input['unit_conversion'])) {
            $uc = $input['unit_conversion'];
            $ucUnitId = (int) ($uc['unit_id'] ?? 0);
            $ucFactor = (float) ($uc['conversion_to_base'] ?? 0);
            if ($ucUnitId <= 0) {
                throw new ValidationException(['unit_conversion.unit_id is required']);
            }
            $unitExists = $pdo->prepare('SELECT COUNT(*) FROM units WHERE id = :id');
            $unitExists->execute(['id' => $ucUnitId]);
            if ((int) $unitExists->fetchColumn() === 0) {
                throw new ValidationException(["unit_conversion.unit_id {$ucUnitId} is not a recognized unit"]);
            }
            if ($ucFactor <= 0) {
                throw new ValidationException(['unit_conversion.conversion_to_base must be > 0']);
            }
            if ($ucUnitId === $baseUnitId && abs($ucFactor - 1.0) > 0.0000001) {
                throw new ValidationException(['the base unit\'s own conversion factor is always 1 and cannot be set to anything else']);
            }
            $openConv = UnitConversionService::getActiveConversion($pdo, $itemId, $ucUnitId, date('Y-m-d H:i:s'));
            if ($openConv === null || abs((float) $openConv['conversion_to_base'] - $ucFactor) > 0.0000001) {
                UnitConversionService::openNewVersion(
                    $pdo, $itemId, $ucUnitId, $ucFactor, date('Y-m-d H:i:s'), $user['id'],
                    'Edit Barang', (bool) ($uc['is_purchase_default'] ?? false)
                );
            }
        }

        $pdo->prepare('UPDATE items SET name = :n, category_id = :c, default_supplier_id = :s, barcode = :b, notes = :notes, status = :status WHERE id = :id')
            ->execute(['n' => $name, 'c' => $categoryId, 's' => $supplierId, 'b' => $barcode, 'notes' => $notes, 'status' => $status, 'id' => $itemId]);

        AuditService::log(
            $pdo, $user['id'], $user['username'],
            $before['status'] !== $status ? ($status === 'INACTIVE' ? 'ITEM_DEACTIVATE' : 'ITEM_ACTIVATE') : 'ITEM_UPDATE',
            'items', $itemId,
            ['name' => $before['name'], 'category_id' => $before['category_id'], 'default_supplier_id' => $before['default_supplier_id'], 'barcode' => $before['barcode'], 'status' => $before['status'], 'base_unit_id' => (int) $before['base_unit_id']],
            ['name' => $name, 'category_id' => $categoryId, 'default_supplier_id' => $supplierId, 'barcode' => $barcode, 'status' => $status, 'base_unit_id' => $baseUnitId],
            null
        );

        // Price / HPP — append-only to item_price_history, NEVER a
        // destructive overwrite of an earlier row. Only accepted for a
        // unit this item has a KNOWN conversion for (base unit, or an
        // already-open purchase/middle conversion — including the one
        // just set above in this same request) — never guessed/invented.
        // Zero is explicitly allowed (an intentionally free/zero-cost
        // item); negative is always rejected.
        if (array_key_exists('price', $input) && is_array($input['price'])) {
            $priceUnitId = (int) ($input['price']['unit_id'] ?? 0);
            $pricePerUnit = $input['price']['price_per_unit'] ?? null;
            if ($priceUnitId <= 0) {
                throw new ValidationException(['price.unit_id is required']);
            }
            if ($pricePerUnit === null || !is_numeric($pricePerUnit)) {
                throw new ValidationException(['price.price_per_unit is required and must be numeric']);
            }
            $pricePerUnit = (float) $pricePerUnit;
            if ($pricePerUnit < 0) {
                throw new ValidationException(['price.price_per_unit cannot be negative']);
            }

            if ($priceUnitId === $baseUnitId) {
                $conversionToBase = 1.0;
            } else {
                $conv = UnitConversionService::getActiveConversion($pdo, $itemId, $priceUnitId, date('Y-m-d H:i:s'));
                if ($conv === null) {
                    throw new ValidationException(["price.unit_id {$priceUnitId} has no existing valid unit conversion for this item — add the conversion first; price is never auto-converted without one"]);
                }
                $conversionToBase = (float) $conv['conversion_to_base'];
            }
            $unitCostBase = round($pricePerUnit / $conversionToBase, 4);

            $oldLatest = $pdo->prepare('SELECT unit_cost_base FROM item_price_history WHERE item_id = :id ORDER BY effective_date DESC, id DESC LIMIT 1');
            $oldLatest->execute(['id' => $itemId]);
            $oldUnitCostBase = $oldLatest->fetchColumn();

            $pdo->prepare(
                'INSERT INTO item_price_history (item_id, supplier_id, unit_id, price_per_unit, unit_cost_base, effective_date, created_at)
                 VALUES (:item_id, :supplier_id, :unit_id, :price_per_unit, :unit_cost_base, NOW(), NOW())'
            )->execute([
                'item_id' => $itemId, 'supplier_id' => $supplierId, 'unit_id' => $priceUnitId,
                'price_per_unit' => $pricePerUnit, 'unit_cost_base' => $unitCostBase,
            ]);

            AuditService::log(
                $pdo, $user['id'], $user['username'], 'ITEM_PRICE_UPDATE', 'items', $itemId,
                ['unit_cost_base' => $oldUnitCostBase !== false ? (float) $oldUnitCostBase : null],
                ['unit_cost_base' => $unitCostBase, 'price_per_unit' => $pricePerUnit, 'unit_id' => $priceUnitId],
                'Edit Barang'
            );
        }

        inv_ok(['success' => true, 'item_id' => $itemId], 'Item updated');
    },

    'DELETE /items/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $itemId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM items WHERE id = :id');
        $existing->execute(['id' => $itemId]);
        $item = $existing->fetch();
        if ($item === false) {
            inv_error(404, 'NOT_FOUND', 'item not found');
        }

        $refs = MasterDataSafetyService::checkItemReferences($pdo, $itemId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'ITEM_DELETE_ATTEMPT', 'items', $itemId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by transaction/history data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Barang '{$item['name']}' memiliki referensi transaksi/history dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        Database::transaction(function (PDO $tx) use ($itemId) {
            // Owned structural setup data, not business history (see the
            // comment on MasterDataSafetyService::checkItemReferences) —
            // removed here, in the same transaction as the item itself,
            // rather than relying on a DB-level cascade this schema does
            // not define.
            $tx->prepare('DELETE FROM item_unit_conversions WHERE item_id = :id')->execute(['id' => $itemId]);
            $tx->prepare('DELETE FROM items WHERE id = :id')->execute(['id' => $itemId]);
        });
        AuditService::log($pdo, $user['id'], $user['username'], 'ITEM_DELETE_SUCCESS', 'items', $itemId, ['name' => $item['name'], 'sku' => $item['sku']], null, null);
        inv_ok(['success' => true], 'Item permanently deleted');
    },

    // ============================================================
    // PHASE V2.1 — Master Gudang enhanced list + safe edit/delete.
    // Never creates or lists Karang Tengah unless it already exists as a
    // real row — this endpoint just reads whatever warehouses table
    // already has, the same as GET /warehouses always has.
    // ============================================================
    'GET /warehouses/report' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');

        $singleWarehouseId = null;
        if ($user['role_code'] === 'STOCK') {
            if (empty($user['warehouse_id'])) {
                inv_error(403, 'FORBIDDEN', 'STOCK user has no warehouse assignment');
            }
            $singleWarehouseId = (int) $user['warehouse_id'];
        }

        $active = strtoupper((string) ($query['active'] ?? ''));
        $active = in_array($active, ['ACTIVE', 'INACTIVE'], true) ? $active : null;
        $sort = in_array($query['sort'] ?? '', ['name', 'sku_count', 'qty', 'value'], true) ? $query['sort'] : 'name';

        $rows = WarehouseReportService::list($pdo, [
            'q' => $query['search'] ?? $query['q'] ?? null,
            'active' => $active,
            'sort' => $sort,
            'dir' => strtolower((string) ($query['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            'warehouse_id' => $singleWarehouseId,
        ]);
        inv_ok(['rows' => $rows, 'total' => count($rows)], 'OK');
    },

    // Master Data "Tambah ..." — the three creates that had no endpoint (Barang, Gudang, Divisi).
    // Same permissions the matching PUT/DELETE already require; the whole creation is one transaction
    // (item + conversions + price + audit all-or-nothing). Supplier / Bakery / Kategori already had POST routes.
    'POST /items' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_ITEM_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        inv_ok(Database::transaction(fn (PDO $tx) => \App\Services\MasterRecordService::createItem($tx, $input)), 'Item created');
    },
    'POST /warehouses' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_WAREHOUSE_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        inv_ok(Database::transaction(fn (PDO $tx) => \App\Services\MasterRecordService::createWarehouse($tx, $input)), 'Warehouse created');
    },
    'POST /divisions' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_DIVISION_MANAGE');
        $input['created_by'] = $user['id'];
        $input['username'] = $user['username'];
        inv_ok(Database::transaction(fn (PDO $tx) => \App\Services\MasterRecordService::createDivision($tx, $input)), 'Division created');
    },

    'PUT /warehouses/{id}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_WAREHOUSE_MANAGE');
        $whId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM warehouses WHERE id = :id');
        $existing->execute(['id' => $whId]);
        $before = $existing->fetch();
        if ($before === false) {
            inv_error(404, 'NOT_FOUND', 'warehouse not found');
        }

        $name = array_key_exists('name', $input) ? trim((string) $input['name']) : $before['name'];
        if ($name === '') {
            throw new ValidationException(['name cannot be blank']);
        }
        $isActive = array_key_exists('is_active', $input) ? (int) (bool) $input['is_active'] : (int) $before['is_active'];

        // PHASE V2.13.1: generic, server-side activation lock — enforced
        // here regardless of the caller's MASTER_WAREHOUSE_MANAGE
        // permission, and regardless of what the UI does or doesn't show.
        WarehouseGuardService::assertActivationAllowed(
            $whId,
            (int) $before['is_active'],
            (int) $before['activation_locked'],
            $isActive
        );

        $pdo->prepare('UPDATE warehouses SET name = :n, is_active = :a WHERE id = :id')
            ->execute(['n' => $name, 'a' => $isActive, 'id' => $whId]);

        AuditService::log(
            $pdo, $user['id'], $user['username'],
            (int) $before['is_active'] !== $isActive ? ($isActive === 0 ? 'WAREHOUSE_DEACTIVATE' : 'WAREHOUSE_ACTIVATE') : 'WAREHOUSE_UPDATE',
            'warehouses', $whId,
            ['name' => $before['name'], 'is_active' => (int) $before['is_active']],
            ['name' => $name, 'is_active' => $isActive],
            null
        );

        inv_ok(['success' => true, 'warehouse_id' => $whId], 'Warehouse updated');
    },

    'DELETE /warehouses/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'MASTER_WAREHOUSE_MANAGE');
        $whId = (int) $params['id'];

        $existing = $pdo->prepare('SELECT * FROM warehouses WHERE id = :id');
        $existing->execute(['id' => $whId]);
        $wh = $existing->fetch();
        if ($wh === false) {
            inv_error(404, 'NOT_FOUND', 'warehouse not found');
        }

        // PHASE V2.13.2: a locked warehouse's incomplete cutover is itself
        // the reason it must not be permanently removed — checked BEFORE
        // the reference/FK safety check below, since a locked warehouse
        // with zero dependent rows would otherwise pass that check.
        WarehouseGuardService::assertDeletionAllowed($whId, (int) $wh['activation_locked']);

        $refs = MasterDataSafetyService::checkWarehouseReferences($pdo, $whId);
        if ($refs['blocked']) {
            AuditService::log($pdo, $user['id'], $user['username'], 'WAREHOUSE_DELETE_ATTEMPT', 'warehouses', $whId, null, ['blocked_reasons' => $refs['reasons']], 'referenced by transaction/history data');
            inv_error(422, 'DELETE_BLOCKED_HAS_REFERENCES', "Gudang '{$wh['name']}' memiliki stok/riwayat transaksi dan tidak dapat dihapus. Nonaktifkan sebagai gantinya. (" . implode('; ', $refs['reasons']) . ')');
        }

        $pdo->prepare('DELETE FROM warehouses WHERE id = :id')->execute(['id' => $whId]);
        AuditService::log($pdo, $user['id'], $user['username'], 'WAREHOUSE_DELETE_SUCCESS', 'warehouses', $whId, ['code' => $wh['code'], 'name' => $wh['name']], null, null);
        inv_ok(['success' => true], 'Warehouse permanently deleted');
    },

    // ============================================================
    // PHASE V2.14 — GENERIC controlled warehouse cutover workflow (not
    // Karang-Tengah-specific). Development/staging use only in this
    // phase: nothing here activates a warehouse or is invoked by any
    // other part of this application against production.
    // ============================================================
    'POST /warehouse-cutovers' => function () use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        $id = WarehouseCutoverService::create($pdo, $input + ['created_by' => $user['id'], 'username' => $user['username']]);
        inv_ok(['cutover_id' => $id], 'Cutover created', 201);
    },

    'GET /warehouse-cutovers/{id}' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        $cutover = WarehouseCutoverService::get($pdo, (int) $params['id']);
        $unresolved = WarehouseCutoverService::unresolvedCounts($pdo, (int) $params['id']);
        $summary = WarehouseCutoverService::summary($pdo, (int) $params['id']);
        inv_ok(['cutover' => $cutover, 'unresolved' => $unresolved, 'summary' => $summary], 'OK');
    },

    'GET /warehouse-cutovers/{id}/lines' => function (array $params) use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        $lines = WarehouseCutoverService::listLines($pdo, (int) $params['id'], $query);
        inv_ok(['rows' => $lines, 'total' => count($lines)], 'OK');
    },

    'POST /warehouse-cutovers/{id}/import' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        if (empty($input['file_path'])) {
            inv_error(422, 'VALIDATION_ERROR', 'file_path is required (upload via POST /import/upload first)');
        }
        $summary = WarehouseCutoverImportService::import($pdo, (int) $params['id'], $input['file_path']);
        AuditService::log($pdo, $user['id'], $user['username'], 'CUTOVER_IMPORT', 'warehouse_cutovers', (int) $params['id'], null, $summary, null);
        inv_ok($summary, 'Reconciliation workbook imported');
    },

    'POST /warehouse-cutovers/{id}/match-items' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        $counts = WarehouseCutoverService::matchItems($pdo, (int) $params['id']);
        AuditService::log($pdo, $user['id'], $user['username'], 'CUTOVER_MATCH_ITEMS', 'warehouse_cutovers', (int) $params['id'], null, $counts, null);
        inv_ok($counts, 'Item matching complete');
    },

    'PUT /warehouse-cutovers/{id}/lines/{lineId}' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        WarehouseCutoverService::resolveLine($pdo, (int) $params['id'], (int) $params['lineId'], $input + ['actor_id' => $user['id'], 'actor_username' => $user['username']]);
        inv_ok(['success' => true], 'Cutover line resolved');
    },

    // PHASE V2.14.1 — bulk decision import (e.g. from the Business Decision
    // workbook, converted to JSON rows client-side or by a future importer).
    // Never applies to production data — same dev/staging-only scope as the
    // rest of this workflow.
    'POST /warehouse-cutovers/{id}/import-decisions' => function (array $params) use ($pdo, $input) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        if (empty($input['decisions']) || !is_array($input['decisions'])) {
            inv_error(422, 'VALIDATION_ERROR', 'decisions (a non-empty array) is required');
        }
        $results = WarehouseCutoverService::importDecisions($pdo, (int) $params['id'], $input['decisions'], $user['id'], $user['username']);
        inv_ok(['results' => $results], 'Decision import processed');
    },

    'POST /warehouse-cutovers/{id}/approve' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        WarehouseCutoverService::approve($pdo, (int) $params['id'], $user['id'], $user['username']);
        inv_ok(['success' => true], 'Cutover approved');
    },

    'GET /warehouse-cutovers/{id}/preview' => function (array $params) use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        inv_ok(WarehouseCutoverService::previewApprovedOpening($pdo, (int) $params['id']), 'OK');
    },

    // SUPERADMIN only — not merely permission-gated, matching this
    // codebase's existing pattern for irreversible/high-risk actions
    // (System Health, User/Role trace, TRANSACTION_VOID_LOCKED_PERIOD,
    // etc.). Development/staging use only — see this section's docblock.
    'POST /warehouse-cutovers/{id}/load' => function (array $params) use ($pdo, $config) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'WAREHOUSE_CUTOVER_MANAGE');
        if ($user['role_code'] !== 'SUPERADMIN') {
            inv_error(403, 'FORBIDDEN', 'Loading a cutover opening balance is restricted to SUPERADMIN');
        }
        // PHASE V2.14.2 — pre-deploy safety hotfix: a hard, backend-
        // authoritative block on posting a real opening balance while
        // APP_ENV=production (the config default when unset — i.e. this
        // fails closed, not open), checked BEFORE loadOpening() ever runs.
        // SUPERADMIN cannot bypass this. Local/test/trial/staging
        // environments (any APP_ENV other than the literal string
        // 'production') are unaffected. Lifted only by an explicit future
        // release once the organization approves a real production cutover.
        if (($config['app']['env'] ?? 'production') === 'production') {
            inv_error(422, 'CUTOVER_LOAD_DISABLED', 'Load opening production belum diaktifkan. Gunakan release cutover yang telah disetujui.');
        }
        $result = WarehouseCutoverService::loadOpening($pdo, (int) $params['id'], $user['id'], $user['username']);
        inv_ok($result, 'Opening balance loaded');
    },
];

// ---- dispatch: exact match first, then {param} patterns ----
$key = "{$method} {$path}";
if (isset($routes[$key])) {
    $routes[$key]();
    exit;
}

foreach ($routes as $routeKey => $handler) {
    [$routeMethod, $routePath] = explode(' ', $routeKey, 2);
    if ($routeMethod !== $method || !str_contains($routePath, '{')) {
        continue;
    }
    $pattern = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $routePath) . '$#';
    if (preg_match($pattern, $path, $matches)) {
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler($params);
        exit;
    }
}

inv_error(404, 'NOT_FOUND', "No route for {$key}");
