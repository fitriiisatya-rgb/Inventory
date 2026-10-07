<?php
declare(strict_types=1);

/**
 * Reports v3 — the READ-ONLY (GET) routes of the five reports (Pergerakan, IN / OUT, Pembelian, Nilai HPP, Stock Opname export) and their request helpers.
 *
 * Loaded by public/index.php (ONE include placed just before the dispatcher): `$routes = array_merge($routes, require .../ReportsV3Routes.php)`. A key defined here REPLACES an
 * older route of the same key, so deployments that already carry earlier versions of these routes need no edit of their blocks. Every helper is namespaced rv3_* so it can never
 * clash with a function an earlier release put into index.php. No route in this file writes anything.
 *
 * Runs inside index.php's scope: $pdo and $query are the front controller's own variables; inv_ok / inv_error / inv_require_auth / inv_require_permission /
 * inv_hpp_resolve_warehouse_scope are the core helpers.
 */

// Included from anywhere other than the front controller (a CLI script that loads every service file) this file is inert: it returns no routes and defines nothing it needs
// the front controller's variables for.
if (!isset($routes, $pdo, $query) || !is_array($routes)) {
    return [];
}

use App\Services\AuthService;
use App\Services\ValidationException;

foreach (['InventoryHppReportService', 'InventoryValuationService', 'MovementDailyReportService', 'InOutReportService', 'PurchaseReportService', 'StockOpnameAuditReportService', 'ExcelWriterService', 'ReportExportService', 'MovementReportV3Service'] as $__svc) {
    if (is_file(__DIR__ . "/{$__svc}.php")) {
        require_once __DIR__ . "/{$__svc}.php";
    }
}

/**
 * Laporan Pembelian (redesign): request parsing for GET /reports/purchase-v2/*. The warehouse is resolved through inv_hpp_resolve_warehouse_scope(), so a
 * warehouse-limited user can never widen the scope by editing the query string. Supplier / category / item filters only ever NARROW the result.
 */
function rv3_pur_filters(array $user, array $query): array
{
    $start = (string) ($query['start_date'] ?? '');
    $end = (string) ($query['end_date'] ?? '');
    if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
        inv_error(422, 'VALIDATION_ERROR', 'start_date and end_date are required and start_date must not be after end_date');
    }
    $wh = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
    $wh = inv_hpp_resolve_warehouse_scope($user, $wh);
    $int = static fn (string $k): ?int => isset($query[$k]) && $query[$k] !== '' ? (int) $query[$k] : null;
    return [
        'start_date' => $start, 'end_date' => $end, 'warehouse_id' => $wh, 'supplier_id' => $int('supplier_id'), 'category_id' => $int('category_id'), 'item_id' => $int('item_id'),
        'q' => isset($query['q']) && trim((string) $query['q']) !== '' ? trim((string) $query['q']) : null, 'historical' => (string) ($query['historical'] ?? ''),
        'bucket' => (string) ($query['bucket'] ?? 'day'), 'inv_q' => (string) ($query['inv_q'] ?? ''), 'sort' => (string) ($query['sort'] ?? 'date'), 'dir' => (string) ($query['dir'] ?? 'desc'),
        'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 25),
    ];
}

/**
 * Laporan Nilai Stok & HPP (dual valuation FIFO + Average): request parsing for GET /reports/inventory-valuation*. The warehouse is resolved through
 * inv_hpp_resolve_warehouse_scope(), so a warehouse-limited user can never widen the scope by editing the query string. Read-only.
 */
function rv3_val_filters(array $user, array $query): array
{
    $wh = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
    $wh = inv_hpp_resolve_warehouse_scope($user, $wh);
    $int = static fn (string $k): ?int => isset($query[$k]) && $query[$k] !== '' ? (int) $query[$k] : null;
    return [
        'start_date' => (string) ($query['start_date'] ?? ($query['date_from'] ?? '')), 'end_date' => (string) ($query['end_date'] ?? ($query['date_to'] ?? '')),
        'method' => (string) ($query['method'] ?? 'fifo'), 'view' => (string) ($query['view'] ?? 'item'), 'bucket' => (string) ($query['bucket'] ?? 'day'),
        'warehouse_id' => $wh, 'category_id' => $int('category_id'), 'item_id' => $int('item_id'),
        'q' => isset($query['q']) && trim((string) $query['q']) !== '' ? trim((string) $query['q']) : null,
        'sort' => (string) ($query['sort'] ?? 'name'), 'dir' => (string) ($query['dir'] ?? 'asc'), 'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
    ];
}

/**
 * Laporan IN / OUT / Transfer: request parsing for GET /reports/io/*. The warehouse is resolved through inv_hpp_resolve_warehouse_scope(), so a warehouse-limited
 * user can never widen the scope by editing the query string; every other filter only NARROWS. Read-only.
 */
function rv3_io_filters(array $user, array $query): array
{
    $wh = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
    $wh = inv_hpp_resolve_warehouse_scope($user, $wh);
    $int = static fn (string $k): ?int => isset($query[$k]) && $query[$k] !== '' ? (int) $query[$k] : null;
    $str = static fn (string $k): string => isset($query[$k]) ? trim((string) $query[$k]) : '';
    return [
        'start_date' => (string) ($query['start_date'] ?? ($query['date_from'] ?? '')), 'end_date' => (string) ($query['end_date'] ?? ($query['date_to'] ?? '')),
        'warehouse_id' => $wh, 'category_id' => $int('category_id'), 'item_id' => $int('item_id'), 'q' => $str('q') !== '' ? $str('q') : null, 'gq' => $str('gq'),
        'supplier_id' => $int('supplier_id'), 'bakery_destination_id' => $int('bakery_destination_id'), 'division_id' => $int('division_id'),
        'from_warehouse_id' => $int('from_warehouse_id'), 'to_warehouse_id' => $int('to_warehouse_id'), 'status' => $str('status'),
        'in_source' => $str('in_source'), 'out_source' => $str('out_source'), 'view' => $str('view'), 'bucket' => $str('bucket') !== '' ? $str('bucket') : 'day',
        'sort' => $str('sort'), 'dir' => $str('dir') !== '' ? $str('dir') : 'desc', 'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 25),
    ];
}

/**
 * Reports v3: ONE delivery path for every report export. The same sheets feed both outputs — the xlsx download (named Laporan_<Report>_<period>.xlsx, typed cells) and, with
 * format=json, the tables the print view ("Cetak") renders — so Excel, print and the screen filters can never disagree. Read-only.
 */
function rv3_rv3_deliver(array $query, string $title, string $fileBase, string $period, array $meta, array $sheets): void
{
    $file = \App\Services\ReportExportService::fileName($fileBase, $period);
    if (($query['format'] ?? '') === 'json') {
        $pairs = [];
        foreach ($meta as $k => $v) {
            $pairs[] = [(string) $k, (string) $v];
        }
        inv_ok(\App\Services\ReportExportService::toPayload($title, $file, $pairs, $sheets), 'OK');
    }
    \App\Services\ReportExportService::streamXlsx($sheets, $file);
    exit;
}

/**
 * Pergerakan Stok Harian (redesign): common query parsing. Returns [start, end, warehouse(scope-resolved), category, q, item].
 * The warehouse is resolved through inv_hpp_resolve_warehouse_scope(), so a warehouse-limited user can never widen the scope
 * by editing the query string.
 */
function rv3_movement_params(array $user, array $query): array
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

/**
 * Laporan Stock Opname (audit redesign): request parsing for GET /reports/opname-audit/*. The warehouse filter is resolved through the same
 * two guards GET /reports/opname uses (inv_hpp_resolve_warehouse_scope + the Stock-Opname-specific override), so a STOCK user or a
 * warehouse-scoped ADMIN can never widen the scope by editing the query string.
 */
function rv3_soa_filters(array $user, array $query): array
{
    $wh = isset($query['warehouse_id']) && $query['warehouse_id'] !== '' ? (int) $query['warehouse_id'] : null;
    $wh = rv3_so_resolve_warehouse_scope($user, inv_hpp_resolve_warehouse_scope($user, $wh));
    return [
        'date_from' => $query['date_from'] ?? null, 'date_to' => $query['date_to'] ?? null, 'warehouse_id' => $wh,
        'status' => $query['status'] ?? null, 'q' => $query['q'] ?? null,
        'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 25),
    ];
}

function rv3_soa_line_filters(array $query): array
{
    return [
        'q' => $query['item_q'] ?? ($query['q'] ?? null), 'condition' => $query['condition'] ?? null, 'match_status' => $query['match_status'] ?? null,
        'page' => (int) ($query['page'] ?? 1), 'per_page' => (int) ($query['per_page'] ?? 50),
    ];
}

function rv3_require_warehouse_scope(array $user, int $warehouseId): void
{
    try {
        AuthService::assertWarehouseScope($user, $warehouseId);
    } catch (ValidationException $e) {
        inv_error(403, 'FORBIDDEN', $e->getMessage());
    }
}

/**
 * PHASE V2.14.9.1 — same rule as rv3_require_so_warehouse_scope(), but for
 * a LIST-style Stock Opname route that has no single target warehouse to
 * validate against: an ADMIN scoped to a warehouse has their filter FORCED
 * to that warehouse (never silently shown another warehouse's sessions,
 * and never merely hidden client-side), exactly mirroring how STOCK is
 * already forced today. SUPERADMIN and an unscoped ADMIN see whatever
 * $requestedWarehouseId (or lack thereof) they asked for, unchanged.
 */
function rv3_so_resolve_warehouse_scope(array $user, ?int $requestedWarehouseId): ?int
{
    if ($user['role_code'] === 'ADMIN' && $user['warehouse_id'] !== null) {
        return (int) $user['warehouse_id'];
    }
    if ($user['role_code'] === 'STOCK' && $user['warehouse_id'] !== null) {
        return (int) $user['warehouse_id'];
    }
    return $requestedWarehouseId;
}

function rv3_soa_session_ids(PDO $pdo, array $user, array $query): array
{
    if (isset($query['session_ids']) && $query['session_ids'] !== '') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $query['session_ids'])), static fn (int $i) => $i > 0)));
        if (count($ids) > \App\Services\StockOpnameAuditReportService::MAX_SESSIONS) {
            inv_error(422, 'VALIDATION_ERROR', 'too many sessions requested');
        }
        $st = $pdo->prepare('SELECT warehouse_id FROM stock_opname_sessions WHERE id = :id');
        foreach ($ids as $id) {
            $st->execute(['id' => $id]);
            $wh = $st->fetchColumn();
            if ($wh === false) {
                inv_error(404, 'NOT_FOUND', "opname session {$id} not found");
            }
            rv3_require_so_warehouse_scope($user, (int) $wh);
        }
        return $ids;
    }
    return array_slice(\App\Services\StockOpnameAuditReportService::sessionIds($pdo, rv3_soa_filters($user, $query)), 0, \App\Services\StockOpnameAuditReportService::MAX_SESSIONS);
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
function rv3_require_so_warehouse_scope(array $user, int $warehouseId): void
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
    rv3_require_warehouse_scope($user, $warehouseId);
}

return [
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
    // LAPORAN NILAI STOK & HPP (dual valuation FIFO + Average) — READ-ONLY (GET), INVENTORY_VIEW, warehouse scope enforced server-side. FIFO = the operational method
    // (actual layers / allocations); Average = analytical moving weighted average. The existing /reports/inventory-hpp/* routes are untouched.
    'GET /reports/inventory-valuation' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        try {
            inv_ok(\App\Services\InventoryValuationService::overview($pdo, rv3_val_filters($user, $query)), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    'GET /reports/inventory-valuation/item' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        try {
            inv_ok(\App\Services\InventoryValuationService::itemDetail($pdo, rv3_val_filters($user, $query)), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        } catch (\App\Services\NotFoundException $e) {
            inv_error(404, 'NOT_FOUND', $e->getMessage());
        }
    },

    // Excel for the SELECTED method (+ one FIFO-vs-Average comparison sheet); same filters as the screen.
    'GET /reports/inventory-valuation/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $f = rv3_val_filters($user, $query);
        try {
            $name = static fn (string $t, string $c, ?int $id) => $id === null ? 'Semua' : (string) ($GLOBALS['pdo']->query("SELECT {$c} FROM {$t} WHERE id = " . (int) $id)->fetchColumn() ?: $id);
            $meta = [
                'Laporan' => 'Laporan Nilai HPP', 'Metode Penilaian' => $f['method'] === 'average' ? 'Average' : 'FIFO', 'Tampilan' => $f['view'] === 'day' ? 'Per Hari' : 'Per Barang',
                'Periode' => $f['start_date'] . ' s/d ' . $f['end_date'], 'Gudang' => $name('warehouses', 'name', $f['warehouse_id']),
                'Kategori' => $name('categories', 'name', $f['category_id']), 'Pencarian barang' => (string) ($f['q'] ?? ''), 'Dibuat' => date('Y-m-d H:i:s'), 'Dibuat oleh' => (string) $user['username'],
                'Catatan' => $f['method'] === 'average' ? 'Analytical Average — tidak mengubah FIFO operasional (moving weighted average, read-only).' : 'Metode operasional sistem: FIFO (layer stok nyata). Average hanya pembanding analitis.',
            ];
            $sheets = \App\Services\InventoryValuationService::exportWorkbook($pdo, $f, $meta);
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
        $methodLabel = $f['method'] === 'average' ? 'Average' : 'FIFO';
        rv3_rv3_deliver($query, 'Laporan Nilai HPP', 'Laporan_Nilai_HPP_' . $methodLabel . '_' . ($f['view'] === 'day' ? 'Per_Hari' : 'Per_Barang'),
            \App\Services\ReportExportService::periodLabel($f['start_date'], $f['end_date']), $meta, $sheets);
    },

    // PERGERAKAN STOK HARIAN (redesign) — item-level qty + value straight from the ledger (MovementDailyReportService).
    // Strictly read-only (GET), INVENTORY_VIEW, warehouse scope enforced server-side like every other report here
    // (a STOCK user is forced to their own warehouse whatever the query says).
    // LAPORAN PERGERAKAN STOK (reports v3) — READ-ONLY (GET), INVENTORY_VIEW, warehouse scope via rv3_movement_params. v3/overview = the approved daily overview + Transfer IN / OUT as their own
    // columns (identity: Stok Awal + IN − OUT + Transfer IN − Transfer OUT + Adjustment = Stok Akhir); v3/export = Excel (or, with format=json, the print tables) of the same filters.
    'GET /reports/movement/v3/overview' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        [$start, $end, $wh, $cat, $q, $item] = rv3_movement_params($user, $query);
        try {
            inv_ok(\App\Services\MovementReportV3Service::overview($pdo, $start, $end, $wh, $cat, $q, $item), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    'GET /reports/movement/v3/items' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        [$start, $end, $wh, $cat, $q, $item] = rv3_movement_params($user, $query);
        try {
            inv_ok(\App\Services\MovementReportV3Service::itemsPage($pdo, $start, $end, $wh, $cat, $q, $item, (string) ($query['sort'] ?? 'sku'), (string) ($query['dir'] ?? 'asc'), (int) ($query['page'] ?? 1), (int) ($query['per_page'] ?? 25), isset($query['move']) ? (string) $query['move'] : null), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    'GET /reports/movement/v3/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        [$start, $end, $wh, $cat, $q, $item] = rv3_movement_params($user, $query);
        $whLabel = $wh !== null ? (string) ($pdo->query('SELECT name FROM warehouses WHERE id = ' . (int) $wh)->fetchColumn() ?: $wh) : 'Semua Gudang';
        $catLabel = $cat !== null ? (string) ($pdo->query('SELECT name FROM categories WHERE id = ' . (int) $cat)->fetchColumn() ?: $cat) : 'Semua Kategori';
        $meta = [
            'Laporan' => 'Laporan Pergerakan Stok', 'Periode' => $start . ' s/d ' . $end, 'Gudang' => $whLabel, 'Kategori' => $catLabel, 'Pencarian barang' => (string) ($q ?? ''),
            'Tampilan' => ($query['mode'] ?? 'nominal') === 'qty' ? 'Kuantitas (Qty) — per satuan, tidak dijumlahkan lintas satuan' : 'Nominal (Rp)',
            'Dibuat' => date('Y-m-d H:i:s'), 'Dibuat oleh' => (string) $user['username'],
            'Catatan' => 'Nilai memakai biaya yang tercatat di ledger (FIFO). Transfer antar gudang: per gudang tampil IN / OUT; company-wide net nol (selisih = barang dalam perjalanan).',
        ];
        try {
            $sheets = \App\Services\MovementReportV3Service::workbook($pdo, $start, $end, $wh, $cat, $q, $item, $meta);
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
        rv3_rv3_deliver($query, 'Laporan Pergerakan Stok', 'Laporan_Pergerakan_Stok', \App\Services\ReportExportService::rangeLabel($start, $end), $meta, $sheets);
    },

    // Report 4 — Laporan Pembelian: qualifying purchase = type IN, POSTED,
    // never a transfer/production/opening/adjustment (TransactionHistoryService's
    // own type filter already excludes everything else by construction).
    // LAPORAN PEMBELIAN (redesign) — READ-ONLY (GET), INVENTORY_VIEW, warehouse scope enforced server-side like every other report here. Real Stock IN V2
    // invoice financials (PurchaseReportService); the existing /reports/purchase* routes are untouched.
    'GET /reports/purchase-v2/overview' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        try {
            inv_ok(\App\Services\PurchaseReportService::overview($pdo, rv3_pur_filters($user, $query)), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    'GET /reports/purchase-v2/invoices' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        try {
            inv_ok(\App\Services\PurchaseReportService::invoices($pdo, rv3_pur_filters($user, $query)), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    'GET /reports/purchase-v2/items' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        try {
            inv_ok(\App\Services\PurchaseReportService::items($pdo, rv3_pur_filters($user, $query)), 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    // One invoice (all of its lines) by its transaction ids; every transaction must be a Stock IN inside the caller's warehouse scope.
    'GET /reports/purchase-v2/invoice-detail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($query['tx_ids'] ?? ''))), static fn (int $i) => $i > 0)));
        if ($ids === [] || count($ids) > 300) {
            inv_error(422, 'VALIDATION_ERROR', 'tx_ids is required (1-300 transaction ids)');
        }
        $st = $pdo->prepare("SELECT warehouse_id FROM inventory_transactions WHERE id = :id AND transaction_type = 'IN'");
        foreach ($ids as $id) {
            $st->execute(['id' => $id]);
            $wh = $st->fetchColumn();
            if ($wh === false) {
                inv_error(404, 'NOT_FOUND', "purchase transaction {$id} not found");
            }
            rv3_require_warehouse_scope($user, (int) $wh);
        }
        try {
            inv_ok(\App\Services\PurchaseReportService::invoiceDetail($pdo, $ids), 'OK');
        } catch (\App\Services\NotFoundException $e) {
            inv_error(404, 'NOT_FOUND', $e->getMessage());
        }
    },

    // kind = workbook (Ringkasan / Detail Invoice / Detail Barang / Baris Invoice-Barang, .xlsx) | invoices | items | lines (.csv). Same rows + columns as the screen.
    'GET /reports/purchase-v2/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $f = rv3_pur_filters($user, $query);
        $kind = (string) ($query['kind'] ?? 'workbook');
        $svc = \App\Services\PurchaseReportService::class;
        try {
            if ($kind === 'workbook') {
                $name = static fn (string $t, string $c, ?int $id) => $id === null ? 'Semua' : (string) ($GLOBALS['pdo']->query("SELECT {$c} FROM {$t} WHERE id = " . (int) $id)->fetchColumn() ?: $id);
                $meta = [
                    'Laporan' => 'Laporan Pembelian', 'Periode' => $f['start_date'] . ' s/d ' . $f['end_date'], 'Gudang' => $name('warehouses', 'name', $f['warehouse_id']),
                    'Supplier' => $name('suppliers', 'name', $f['supplier_id']), 'Kategori' => $name('categories', 'name', $f['category_id']), 'Pencarian barang' => (string) ($f['q'] ?? ''),
                    'Data' => $f['historical'] === '1' ? 'Historis saja' : ($f['historical'] === 'all' ? 'Live + Historis' : 'Live saja'), 'Dibuat' => date('Y-m-d H:i:s'), 'Dibuat oleh' => (string) $user['username'],
                    'Catatan' => 'Nilai invoice (pembayaran), bukan nilai persediaan FIFO. Total hanya invoice POSTED; invoice VOID dilaporkan terpisah.',
                ];
                rv3_rv3_deliver($query, 'Laporan Pembelian', 'Laporan_Pembelian', \App\Services\ReportExportService::periodLabel($f['start_date'], $f['end_date']), $meta, $svc::exportWorkbook($pdo, $f, $meta));
            }
            if (!in_array($kind, ['invoices', 'items', 'lines'], true)) {
                inv_error(422, 'VALIDATION_ERROR', 'unknown export kind');
            }
            $t = $svc::exportTable($pdo, $kind, $f);
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
        inv_export_csv(['laporan-pembelian', $kind, $f['start_date'], $f['end_date']], $t['headers'], $t['rows'], static fn (array $r) => $r);
    },

    // LAPORAN IN / OUT / TRANSFER — READ-ONLY (GET), INVENTORY_VIEW, warehouse scope enforced server-side (rv3_io_filters). One service, three tabs: in | out | transfer.
    // The older /reports/in-out* and /reports/transfer routes above stay untouched.
    'GET /reports/io/options' => function () use ($pdo) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $scope = inv_hpp_resolve_warehouse_scope($user, null);
        inv_ok(\App\Services\InOutReportService::options($pdo, $user['role_code'] === 'STOCK' ? $scope : null), 'OK');
    },

    'GET /reports/io/overview' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $f = rv3_io_filters($user, $query);
        try {
            $svc = \App\Services\InOutReportService::class;
            inv_ok(match ((string) ($query['tab'] ?? 'in')) {
                'in' => $svc::inOverview($pdo, $f), 'out' => $svc::outOverview($pdo, $f), 'transfer' => $svc::trfOverview($pdo, $f),
                default => inv_error(422, 'VALIDATION_ERROR', 'tab must be in, out or transfer'),
            }, 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    'GET /reports/io/list' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $f = rv3_io_filters($user, $query);
        try {
            $svc = \App\Services\InOutReportService::class;
            inv_ok(match ((string) ($query['tab'] ?? 'in')) {
                'in' => $svc::inList($pdo, $f), 'out' => $svc::outList($pdo, $f), 'transfer' => $svc::trfList($pdo, $f),
                default => inv_error(422, 'VALIDATION_ERROR', 'tab must be in, out or transfer'),
            }, 'OK');
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
    },

    // Item-level detail of ONE document: in -> tx_ids (comma separated), out -> do_id | tx_id (with the FIFO layers), transfer -> id.
    'GET /reports/io/detail' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $scope = inv_hpp_resolve_warehouse_scope($user, null);
        $svc = \App\Services\InOutReportService::class;
        $int = static fn (string $k): ?int => isset($query[$k]) && $query[$k] !== '' ? (int) $query[$k] : null;
        try {
            switch ((string) ($query['tab'] ?? 'in')) {
                case 'in':
                    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($query['tx_ids'] ?? ''))), static fn ($v) => $v > 0));
                    if (!$ids) {
                        inv_error(422, 'VALIDATION_ERROR', 'tx_ids is required');
                    }
                    inv_ok($svc::inDetail($pdo, array_slice($ids, 0, 500), $scope), 'OK');
                    break;
                case 'out':
                    inv_ok($svc::outDetail($pdo, $int('do_id'), $int('tx_id'), $scope), 'OK');
                    break;
                case 'transfer':
                    $id = $int('id');
                    if ($id === null || $id <= 0) {
                        inv_error(422, 'VALIDATION_ERROR', 'id is required');
                    }
                    inv_ok($svc::trfDetail($pdo, $id, $scope), 'OK');
                    break;
                default:
                    inv_error(422, 'VALIDATION_ERROR', 'tab must be in, out or transfer');
            }
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        } catch (\App\Services\NotFoundException $e) {
            inv_error(404, 'NOT_FOUND', $e->getMessage());
        }
    },

    // Excel (or, with format=json, the print tables) of the SELECTED tab with the same filters as the screen; tab=all = one workbook with all three tabs (export totals == screen totals by construction).
    'GET /reports/io/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $f = rv3_io_filters($user, $query);
        $tab = (string) ($query['tab'] ?? 'in');
        if (!in_array($tab, ['in', 'out', 'transfer', 'all'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'tab must be in, out, transfer or all');
        }
        try {
            $name = static fn (string $t, string $c, ?int $id) => $id === null ? 'Semua' : (string) ($GLOBALS['pdo']->query("SELECT {$c} FROM {$t} WHERE id = " . (int) $id)->fetchColumn() ?: $id);
            $titles = ['in' => 'Barang Masuk (IN)', 'out' => 'Barang Keluar (OUT)', 'transfer' => 'Transfer Antar Gudang', 'all' => 'Semua Tab (Barang Masuk, Barang Keluar, Transfer)'];
            $meta = [
                'Laporan' => 'Laporan IN / OUT', 'Tab' => $titles[$tab], 'Periode' => $f['start_date'] . ' s/d ' . $f['end_date'], 'Gudang' => $name('warehouses', 'name', $f['warehouse_id']),
                'Kategori' => $name('categories', 'name', $f['category_id']), 'Pencarian barang' => (string) ($f['q'] ?? ''), 'Pencarian tabel' => $f['gq'],
            ];
            if ($tab === 'in') {
                $meta += ['Supplier' => $name('suppliers', 'name', $f['supplier_id']), 'Jenis IN' => $f['in_source'] ?: 'Semua', 'Status' => $f['status'] ?: 'Semua'];
            } elseif ($tab === 'out') {
                $meta += ['Bakery Tujuan' => $name('bakery_destinations', 'name', $f['bakery_destination_id']), 'Divisi' => $name('divisions', 'name', $f['division_id']),
                    'Jenis OUT' => $f['out_source'] ?: 'Semua', 'Status' => $f['status'] ?: 'Semua'];
            } elseif ($tab === 'transfer') {
                $meta += ['Gudang Asal' => $name('warehouses', 'name', $f['from_warehouse_id']), 'Gudang Tujuan' => $name('warehouses', 'name', $f['to_warehouse_id']), 'Status' => $f['status'] ?: 'Semua'];
            } else {
                $meta += ['Catatan filter' => 'Filter khusus tab (supplier, bakery, divisi, status, jenis, gudang asal/tujuan) hanya menyempit tab miliknya; filter umum berlaku di semua tab.'];
            }
            $meta += ['Dibuat' => date('Y-m-d H:i:s'), 'Dibuat oleh' => (string) $user['username'],
                'Catatan' => 'Hanya data nyata dari sistem; baris VOID/CANCELLED/REVERSED ditampilkan tetapi tidak dihitung pada total. Nilai tidak diketahui ditulis "—", bukan 0.'];
            $svc = \App\Services\InOutReportService::class;
            $sheets = match ($tab) { 'in' => $svc::inExport($pdo, $f, $meta), 'out' => $svc::outExport($pdo, $f, $meta), 'transfer' => $svc::trfExport($pdo, $f, $meta), default => $svc::exportAll($pdo, $f, $meta) };
        } catch (\App\Services\ValidationException $e) {
            inv_error(422, 'VALIDATION_ERROR', implode('; ', $e->errors));
        }
        $base = ['in' => 'Laporan_IN_OUT_Barang_Masuk', 'out' => 'Laporan_IN_OUT_Barang_Keluar', 'transfer' => 'Laporan_IN_OUT_Transfer', 'all' => 'Laporan_IN_OUT_Semua_Tab'][$tab];
        rv3_rv3_deliver($query, 'Laporan IN / OUT — ' . $titles[$tab], $base, \App\Services\ReportExportService::periodLabel($f['start_date'], $f['end_date']), $meta, $sheets);
    },

    // kind = workbook (6 sheets + Info, .xlsx, reports-v3 typed workbook; ?format=json = print tables) | sessions | items | evidence | history | adjustments | audit (.csv). Same rows and column catalogue as the screen.
    'GET /reports/opname-audit/export' => function () use ($pdo, $query) {
        $user = inv_require_auth();
        inv_require_permission($pdo, $user, 'INVENTORY_VIEW');
        $kind = (string) ($query['kind'] ?? 'workbook');
        $ids = rv3_soa_session_ids($pdo, $user, $query);
        $lineFilters = rv3_soa_line_filters($query);
        $svc = \App\Services\StockOpnameAuditReportService::class;
        if ($kind === 'workbook') {
            // Reports v3 delivery: typed cells (numbers / Rupiah / real dates), frozen header + filter, Laporan_Stock_Opname_<SO number | period>.xlsx; ?format=json = the print tables.
            $f = rv3_soa_filters($user, $query);
            $whLabel = $f['warehouse_id'] !== null ? ($pdo->query('SELECT name FROM warehouses WHERE id = ' . (int) $f['warehouse_id'])->fetchColumn() ?: 'Semua') : 'Semua Gudang';
            $sessNos = [];
            foreach ($ids as $sid) {
                $sessNos[] = (string) $svc::build($pdo, (int) $sid)['session_row']['session_number'];
            }
            $explicit = isset($query['session_ids']) && $query['session_ids'] !== '';
            $meta = [
                'Laporan' => 'Laporan Stock Opname', 'Periode' => ($query['date_from'] ?? 'semua') . ' s/d ' . ($query['date_to'] ?? 'semua'), 'Gudang' => $whLabel,
                'Status' => ($query['status'] ?? '') !== '' ? (string) $query['status'] : 'Semua', 'Pencarian' => (string) ($query['q'] ?? ''),
                'Sesi dipilih' => $explicit ? implode(', ', $sessNos) : 'Semua sesi sesuai filter',
                'Filter item' => trim(($lineFilters['q'] ?? '') . ' ' . ($lineFilters['condition'] ?? '') . ' ' . ($lineFilters['match_status'] ?? '')),
                'Jumlah sesi' => (string) count($ids), 'Dibuat' => date('Y-m-d H:i:s'), 'Dibuat oleh' => (string) $user['username'],
                'Catatan' => 'Evidence berupa referensi URL (/api/reports/opname-audit/photo/{id}); gambar tidak disematkan.',
            ];
            $period = ($explicit && count($ids) === 1 && $sessNos !== []) ? $sessNos[0] : \App\Services\ReportExportService::rangeLabel($query['date_from'] ?? null, $query['date_to'] ?? null);
            rv3_rv3_deliver($query, 'Laporan Stock Opname', 'Laporan_Stock_Opname', $period, $meta, $svc::exportWorkbook($pdo, $ids, $lineFilters, $meta));
        }
        if (!in_array($kind, ['sessions', 'items', 'evidence', 'history', 'adjustments', 'audit'], true)) {
            inv_error(422, 'VALIDATION_ERROR', 'unknown export kind');
        }
        $t = $svc::exportTable($pdo, $kind, $ids, $lineFilters);
        inv_export_csv(['laporan-stock-opname', $kind, date('Ymd')], $t['headers'], $t['rows'], static fn (array $r) => $r);
    },
];
