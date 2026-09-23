/**
 * Thin fetch wrapper for the new PHP/MySQL API — the ONLY way any frontend
 * module talks to the server. Always calls a RELATIVE path (/api/...), so
 * this file is identical regardless of which domain serves it.
 *
 * Contract (frozen — see docs/API_CONTRACT.md):
 *   success: {success:true, data, message}
 *   error:   {success:false, error:{code, message}}
 * Every caller should branch on err.code, never on err.message text.
 *
 * CRITICAL RULE (Phase D): if the API is unreachable at all (network
 * failure, server down), this throws a distinct NetworkError so the UI can
 * show "Sistem sedang tidak dapat terhubung ke server." — it must never
 * silently fall back to a cached/local value.
 */
const InvApi = (() => {
    let csrfToken = null;

    class ApiError extends Error {
        constructor(code, message, status, details) {
            super(message);
            this.code = code;
            this.status = status;
            // PHASE V2.5: VOID_HAS_DOWNSTREAM_DEPENDENCIES / TRANSFER_REVERSAL_HAS_DOWNSTREAM_DEPENDENCIES
            // carry a `dependencies` list — surfaced here so the UI can list
            // the blocking transaction IDs instead of just the message text.
            this.dependencies = details && details.dependencies ? details.dependencies : undefined;
        }
    }
    class NetworkError extends Error {
        constructor() {
            super('Sistem sedang tidak dapat terhubung ke server.');
            this.code = 'NETWORK_ERROR';
        }
    }

    function setCsrfToken(token) {
        csrfToken = token || null;
    }

    // Drops undefined/null/'' entries before building a query string, so an
    // optional-params call site can pass a sparse object without every
    // caller having to filter it first.
    function qs(params) {
        const clean = {};
        Object.keys(params || {}).forEach((k) => {
            if (params[k] !== undefined && params[k] !== null && params[k] !== '') clean[k] = params[k];
        });
        const s = new URLSearchParams(clean).toString();
        return s ? `?${s}` : '';
    }

    async function request(method, path, body, options = {}) {
        let res;
        try {
            const headers = {};
            if (body !== undefined) headers['Content-Type'] = 'application/json';
            if (csrfToken && method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
            res = await fetch(`/api${path}`, {
                method,
                credentials: 'include',
                headers,
                body: body !== undefined ? JSON.stringify(body) : undefined,
            });
        } catch (networkFailure) {
            throw new NetworkError();
        }

        let payload;
        try {
            payload = await res.json();
        } catch (parseFailure) {
            throw new ApiError('INVALID_RESPONSE', 'Invalid server response', res.status);
        }

        if (!payload.success) {
            const code = payload.error?.code || 'UNKNOWN_ERROR';
            const message = payload.error?.message || `Request failed (${res.status})`;
            throw new ApiError(code, message, res.status, payload.error);
        }
        return payload.data;
    }

    async function upload(path, file, extraFields = {}) {
        const form = new FormData();
        form.append('file', file);
        for (const [k, v] of Object.entries(extraFields)) form.append(k, v);
        let res;
        try {
            const headers = {};
            if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
            res = await fetch(`/api${path}`, { method: 'POST', credentials: 'include', headers, body: form });
        } catch (e) {
            throw new NetworkError();
        }
        const payload = await res.json().catch(() => ({ success: false, error: { code: 'INVALID_RESPONSE', message: 'Invalid server response' } }));
        if (!payload.success) {
            throw new ApiError(payload.error?.code || 'UNKNOWN_ERROR', payload.error?.message || 'Upload failed', res.status);
        }
        return payload.data;
    }

    // RFC4122-ish v4 UUID for idempotency keys (Section 12). Every posting
    // action generates exactly ONE of these per user click and reuses the
    // SAME value on a network-failure retry — never a fresh one.
    function newRequestUuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    return {
        ApiError, NetworkError, setCsrfToken, newRequestUuid,

        // ---- auth ----
        login: (username, password) => request('POST', '/auth/login', { username, password }),
        logout: () => request('POST', '/auth/logout'),
        me: () => request('GET', '/auth/me'),
        changePassword: (currentPassword, newPassword) => request('POST', '/auth/change-password', { current_password: currentPassword, new_password: newPassword }),

        // ---- master ----
        listItems: () => request('GET', '/items'),
        listWarehouses: () => request('GET', '/warehouses'),
        // PHASE V2.1: optional params (search/active/sort/dir) — omitted
        // entirely, every call below stays byte-identical to before.
        listSuppliers: (params = {}) => request('GET', `/suppliers${qs(params)}`),
        listDivisions: (params = {}) => request('GET', `/divisions${qs(params)}`),
        itemUnits: (itemId) => request('GET', `/items/${itemId}/units`),

        // ---- PHASE V2.10: multi-unit barcode mappings (Transaction UX Upgrade) ----
        listItemBarcodes: () => request('GET', '/item-barcodes'),
        createItemBarcode: (payload) => request('POST', '/item-barcodes', payload),
        updateItemBarcode: (id, payload) => request('PUT', `/item-barcodes/${id}`, payload),

        // ---- PHASE V2: category master ----
        listCategories: (params = {}) => request('GET', `/categories${qs(params)}`),
        createCategory: (payload) => request('POST', '/categories', payload),
        updateCategory: (id, payload) => request('PUT', `/categories/${id}`, payload),
        deleteCategory: (id) => request('DELETE', `/categories/${id}`),

        // ---- PHASE V2: vendor/supplier CRUD (GET /suppliers above stays read-only/full-list) ----
        createSupplier: (payload) => request('POST', '/suppliers', payload),
        updateSupplier: (id, payload) => request('PUT', `/suppliers/${id}`, payload),
        deleteSupplier: (id) => request('DELETE', `/suppliers/${id}`),

        // ---- PHASE V2: bakery destination master ----
        listBakeryDestinations: (params = {}) => request('GET', `/bakery-destinations${qs(params)}`),
        createBakeryDestination: (payload) => request('POST', '/bakery-destinations', payload),
        updateBakeryDestination: (id, payload) => request('PUT', `/bakery-destinations/${id}`, payload),
        deleteBakeryDestination: (id) => request('DELETE', `/bakery-destinations/${id}`),

        // ---- PHASE V2.1: Master Barang / Gudang / Divisi enhanced list + safe edit/delete ----
        itemsReport: (params = {}) => request('GET', `/items/report${qs(params)}`),
        updateItem: (id, payload) => request('PUT', `/items/${id}`, payload),
        deleteItem: (id) => request('DELETE', `/items/${id}`),
        warehousesReport: (params = {}) => request('GET', `/warehouses/report${qs(params)}`),
        updateWarehouse: (id, payload) => request('PUT', `/warehouses/${id}`, payload),
        deleteWarehouse: (id) => request('DELETE', `/warehouses/${id}`),
        updateDivision: (id, payload) => request('PUT', `/divisions/${id}`, payload),
        deleteDivision: (id) => request('DELETE', `/divisions/${id}`),

        // ---- PHASE V2.2: Trace Center (read-only) ----
        traceSearch: (q, type) => request('GET', `/trace/search${qs({ q, type })}`),
        traceEvents: (params = {}) => request('GET', `/trace/events${qs(params)}`),
        traceEntity: (type, id) => request('GET', `/trace/entity${qs({ type, id })}`),
        traceTransaction: (id) => request('GET', `/trace/transaction/${id}`),
        traceInventory: (itemId, warehouseId, params = {}) => request('GET', `/trace/inventory${qs({ item_id: itemId, warehouse_id: warehouseId, ...params })}`),

        // ---- PHASE V2.2B: coverage-completion trace endpoints (read-only) ----
        traceTransfer: (id) => request('GET', `/trace/transfer/${id}`),
        traceOpname: (id) => request('GET', `/trace/opname/${id}`),
        traceProduction: (id) => request('GET', `/trace/production/${id}`),
        traceOpening: (id) => request('GET', `/trace/opening/${id}`),
        traceImport: (id, params = {}) => request('GET', `/trace/import/${id}${qs(params)}`),
        traceUser: (id) => request('GET', `/trace/user/${id}`),
        traceRole: (id) => request('GET', `/trace/role/${id}`),

        // ---- PHASE V2.3: Laporan Nilai Stok & HPP (read-only) ----
        hppSummary: (params) => request('GET', `/reports/inventory-hpp/summary${qs(params)}`),
        hppWarehouses: (params) => request('GET', `/reports/inventory-hpp/warehouses${qs(params)}`),
        hppDaily: (params) => request('GET', `/reports/inventory-hpp/daily${qs(params)}`),
        hppDayDetail: (params) => request('GET', `/reports/inventory-hpp/day-detail${qs(params)}`),
        hppVarianceBridge: (params) => request('GET', `/reports/inventory-hpp/variance-bridge${qs(params)}`),
        hppExportUrl: (params) => `/api/reports/inventory-hpp/export${qs(params)}`,

        // ---- PHASE V2.6B: Pergerakan Stok Harian + Rekonsiliasi Arus Stok (read-only) ----
        movementDaily: (params) => request('GET', `/reports/movement/daily${qs(params)}`),
        movementDayBreakdown: (params) => request('GET', `/reports/movement/day-breakdown${qs(params)}`),
        movementDayTransactions: (params) => request('GET', `/reports/movement/day-transactions${qs(params)}`),
        movementHistoricalTransactions: (params) => request('GET', `/reports/movement/historical-transactions${qs(params)}`),
        reconciliationMovement: (params) => request('GET', `/reports/reconciliation/movement${qs(params)}`),
        summaryInventory: (params) => request('GET', `/reports/summary/inventory${qs(params)}`),

        // ---- PHASE V2.6B: remaining Reporting Pack ----
        purchaseReport: (params) => request('GET', `/reports/purchase${qs(params)}`),
        purchaseReportSummary: (params) => request('GET', `/reports/purchase/summary${qs(params)}`),
        purchaseBySupplier: (params) => request('GET', `/reports/purchase/by-supplier${qs(params)}`),
        inOutReport: (params) => request('GET', `/reports/in-out${qs(params)}`),
        inOutReportSummary: (params) => request('GET', `/reports/in-out/summary${qs(params)}`),
        bakeryDistribution: (params) => request('GET', `/reports/distribution/bakery${qs(params)}`),
        transferReport: (params) => request('GET', `/reports/transfer${qs(params)}`),
        opnameReport: (params) => request('GET', `/reports/opname${qs(params)}`),
        adjustmentReport: (params) => request('GET', `/reports/adjustment${qs(params)}`),
        expiryReport: (params) => request('GET', `/reports/expiry${qs(params)}`),
        slowMovementReport: (params) => request('GET', `/reports/slow-movement${qs(params)}`),
        auditReport: (params) => request('GET', `/reports/audit${qs(params)}`),

        // ---- PHASE V2.6C: CSV export URLs (opened via window.open, same
        // CSRF-exempt-GET pattern as hppExportUrl/importTemplateUrl above) ----
        summaryInventoryExportUrl: (params) => `/api/reports/summary/inventory${qs(Object.assign({}, params, { format: 'csv' }))}`,
        movementDailyExportUrl: (params) => `/api/reports/movement/daily${qs(Object.assign({}, params, { format: 'csv' }))}`,
        reconciliationExportUrl: (params) => `/api/reports/reconciliation/movement${qs(Object.assign({}, params, { format: 'csv' }))}`,
        purchaseReportExportUrl: (params) => `/api/reports/purchase${qs(Object.assign({}, params, { format: 'csv' }))}`,
        purchaseBySupplierExportUrl: (params) => `/api/reports/purchase/by-supplier${qs(Object.assign({}, params, { format: 'csv' }))}`,
        inOutReportExportUrl: (params) => `/api/reports/in-out${qs(Object.assign({}, params, { format: 'csv' }))}`,
        bakeryDistributionExportUrl: (params) => `/api/reports/distribution/bakery${qs(Object.assign({}, params, { format: 'csv' }))}`,
        transferReportExportUrl: (params) => `/api/reports/transfer${qs(Object.assign({}, params, { format: 'csv' }))}`,
        opnameReportExportUrl: (params) => `/api/reports/opname${qs(Object.assign({}, params, { format: 'csv' }))}`,
        adjustmentReportExportUrl: (params) => `/api/reports/adjustment${qs(Object.assign({}, params, { format: 'csv' }))}`,
        expiryReportExportUrl: (params) => `/api/reports/expiry${qs(Object.assign({}, params, { format: 'csv' }))}`,
        slowMovementReportExportUrl: (params) => `/api/reports/slow-movement${qs(Object.assign({}, params, { format: 'csv' }))}`,
        auditReportExportUrl: (params) => `/api/reports/audit/export${qs(params)}`,

        // ---- PHASE V2: per-item-per-warehouse stock policy ----
        getStockPolicy: (itemId, warehouseId) => request('GET', `/stock-policy?item_id=${itemId}&warehouse_id=${warehouseId}`),
        saveStockPolicy: (payload) => request('PUT', '/stock-policy', payload),

        // ---- PHASE V2: reports ----
        stockReport: (params = {}) => request('GET', `/reports/stock?${new URLSearchParams(params).toString()}`),
        stockReportCsvUrl: (params = {}) => `/api/reports/stock?${new URLSearchParams({ ...params, format: 'csv' }).toString()}`,
        // ---- PHASE V2.6D: Laporan Stok all-product + Kartu Stok ----
        stockStatusCounts: (params = {}) => request('GET', `/reports/stock/status-counts${qs(params)}`),
        stockCard: (params = {}) => request('GET', `/reports/stock/card${qs(params)}`),
        stockReportExportUrl: (params = {}) => `/api/reports/stock${qs({ ...params, view: 'report', format: 'csv' })}`,
        transactionReport: (params = {}) => request('GET', `/reports/transactions?${new URLSearchParams(params).toString()}`),
        transactionDetail: (id) => request('GET', `/reports/transactions/${id}`),

        // ---- inventory (single source of truth) ----
        currentStock: (itemId, warehouseId) => request('GET', `/inventory/current?item_id=${itemId}&warehouse_id=${warehouseId}`),
        currentStockBySku: (sku, warehouseId) => request('GET', `/inventory/current/${encodeURIComponent(sku)}${warehouseId ? `?warehouse_id=${warehouseId}` : ''}`),
        batches: (itemId, warehouseId) => request('GET', `/inventory/batches?item_id=${itemId}&warehouse_id=${warehouseId}`),
        companyValue: () => request('GET', '/inventory/value'),
        inTransitValue: () => request('GET', '/inventory/in-transit'),
        ledger: (itemId, warehouseId) => request('GET', `/inventory/ledger?item_id=${itemId}&warehouse_id=${warehouseId}`),

        // ---- transactions ----
        postTransactionIn: (payload) => request('POST', '/transactions/in', payload),
        // ---- PHASE V2.7: purchase costing (Cost Preview before POST) ----
        purchaseCostPreview: (params) => request('GET', `/transactions/in/cost-preview${qs(params)}`),
        postTransactionOut: (payload) => request('POST', '/transactions/out', payload),
        voidTransaction: (transactionId, payload) => request('POST', `/transactions/${transactionId}/void`, payload),

        // ---- PHASE V2.11A: Distribution Orders (SCM -> Bakery) ----
        listDistributionOrders: (params = {}) => request('GET', `/distribution-orders${qs(params)}`),
        getDistributionOrder: (id) => request('GET', `/distribution-orders/${id}`),
        createDistributionOrder: (payload) => request('POST', '/distribution-orders', payload),
        approveDistributionOrder: (id) => request('POST', `/distribution-orders/${id}/approve`, {}),
        startPickingDistributionOrder: (id) => request('POST', `/distribution-orders/${id}/start-picking`, {}),
        dispatchDistributionOrder: (id, payload) => request('POST', `/distribution-orders/${id}/dispatch`, payload),
        receiveDistributionOrder: (id, payload) => request('POST', `/distribution-orders/${id}/receive`, payload),
        completeDistributionOrder: (id) => request('POST', `/distribution-orders/${id}/complete`, {}),
        cancelDistributionOrder: (id, payload) => request('POST', `/distribution-orders/${id}/cancel`, payload),
        reverseDistributionOrder: (id, payload) => request('POST', `/distribution-orders/${id}/reverse`, payload),

        // ---- transfers ----
        transferDestinations: () => request('GET', '/transfer-destinations'),
        createTransfer: (payload) => request('POST', '/transfers', payload),
        receiveTransfer: (id, payload) => request('POST', `/transfers/${id}/receive`, payload),
        cancelTransfer: (id, payload) => request('POST', `/transfers/${id}/cancel`, payload),
        reverseTransfer: (id, payload) => request('POST', `/transfers/${id}/reverse`, payload),
        listTransfers: () => request('GET', '/transfers'),
        listPendingTransfers: () => request('GET', '/transfers/pending'),
        getTransfer: (id) => request('GET', `/transfers/${id}`),

        // ---- stock opname ----
        listOpnameSessions: (filters = {}) => {
            const qs = new URLSearchParams(filters).toString();
            return request('GET', `/stock-opname${qs ? `?${qs}` : ''}`);
        },
        startOpname: (payload) => request('POST', '/stock-opname', payload),
        getOpname: (id) => request('GET', `/stock-opname/${id}`),
        countOpname: (id, counts) => request('POST', `/stock-opname/${id}/count`, { counts }),
        finalizeOpname: (id) => request('POST', `/stock-opname/${id}/finalize`),
        postOpname: (id, costOverrides) => request('POST', `/stock-opname/${id}/post`, { cost_overrides: costOverrides || {} }),

        // ---- stock adjustments ----
        postAdjustment: (payload) => request('POST', '/stock-adjustments', payload),

        // ---- production ----
        postProduction: (payload) => request('POST', '/production', payload),
        getProduction: (id) => request('GET', `/production/${id}`),

        // ---- book closing ----
        previewClosing: (periodStart, periodEnd) => request('POST', '/book-closing/preview', { period_start: periodStart, period_end: periodEnd }),
        closePeriod: (periodStart, periodEnd) => request('POST', '/book-closing/close', { period_start: periodStart, period_end: periodEnd }),
        listClosings: () => request('GET', '/book-closing'),
        nextCloseablePeriod: () => request('GET', '/book-closing/next-closeable'),

        // ---- reconciliation ----
        reconciliation: () => request('GET', '/reconciliation'),

        // ---- audit ----
        auditLogs: (filters = {}) => {
            const qs = new URLSearchParams(filters).toString();
            return request('GET', `/audit-logs${qs ? `?${qs}` : ''}`);
        },

        // ---- import ----
        // PHASE V2.6A — Download Template Excel. GET is CSRF-exempt (see
        // index.php's CSRF gate), so this is opened directly via
        // window.open()/an <a href>, same pattern as hppExportUrl above.
        importTemplateUrl: (type) => `/api/import/template/${type}`,
        uploadImportFile: (file) => upload('/import/upload', file),
        stageMasterItem: (filePath, fileName) => request('POST', '/import/master-item/stage', { file_path: filePath, file_name: fileName }),
        commitMasterItem: (id) => request('POST', `/import/master-item/${id}/commit`),
        stageSimpleMaster: (type, filePath, fileName) => request('POST', `/import/${type}/stage`, { file_path: filePath, file_name: fileName }),
        commitSimpleMaster: (type, id) => request('POST', `/import/${type}/${id}/commit`),
        stageOpeningStock: (filePath, fileName) => request('POST', '/import/opening-stock/stage', { file_path: filePath, file_name: fileName }),
        commitOpeningStock: (id) => request('POST', `/import/opening-stock/${id}/commit`),
        stageHistorical: (filePath, fileName) => request('POST', '/import/historical/stage', { file_path: filePath, file_name: fileName }),
        commitHistorical: (id) => request('POST', `/import/historical/${id}/commit`),
        stageLiveTransaction: (filePath, fileName) => request('POST', '/import/live-transaction/stage', { file_path: filePath, file_name: fileName }),
        commitLiveTransaction: (id) => request('POST', `/import/live-transaction/${id}/commit`),
        stageMinimumStock: (filePath, fileName) => request('POST', '/import/minimum-stock/stage', { file_path: filePath, file_name: fileName }),
        commitMinimumStock: (id) => request('POST', `/import/minimum-stock/${id}/commit`),
        previewImportBatch: (id) => request('GET', `/import/batches/${id}/rows`),
        previewOpeningStockBatch: (id) => request('GET', `/import/opening-stock/${id}/rows`),
    };
})();
