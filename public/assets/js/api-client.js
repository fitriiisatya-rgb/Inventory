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
        constructor(code, message, status) {
            super(message);
            this.code = code;
            this.status = status;
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
            throw new ApiError(code, message, res.status);
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

        // ---- master ----
        listItems: () => request('GET', '/items'),
        listWarehouses: () => request('GET', '/warehouses'),
        listSuppliers: () => request('GET', '/suppliers'),
        listDivisions: () => request('GET', '/divisions'),
        itemUnits: (itemId) => request('GET', `/items/${itemId}/units`),

        // ---- inventory (single source of truth) ----
        currentStock: (itemId, warehouseId) => request('GET', `/inventory/current?item_id=${itemId}&warehouse_id=${warehouseId}`),
        currentStockBySku: (sku, warehouseId) => request('GET', `/inventory/current/${encodeURIComponent(sku)}${warehouseId ? `?warehouse_id=${warehouseId}` : ''}`),
        batches: (itemId, warehouseId) => request('GET', `/inventory/batches?item_id=${itemId}&warehouse_id=${warehouseId}`),
        companyValue: () => request('GET', '/inventory/value'),
        inTransitValue: () => request('GET', '/inventory/in-transit'),
        ledger: (itemId, warehouseId) => request('GET', `/inventory/ledger?item_id=${itemId}&warehouse_id=${warehouseId}`),

        // ---- transactions ----
        postTransactionIn: (payload) => request('POST', '/transactions/in', payload),
        postTransactionOut: (payload) => request('POST', '/transactions/out', payload),
        voidTransaction: (transactionId, payload) => request('POST', `/transactions/${transactionId}/void`, payload),

        // ---- transfers ----
        createTransfer: (payload) => request('POST', '/transfers', payload),
        receiveTransfer: (id, payload) => request('POST', `/transfers/${id}/receive`, payload),
        cancelTransfer: (id, payload) => request('POST', `/transfers/${id}/cancel`, payload),
        listTransfers: () => request('GET', '/transfers'),
        listPendingTransfers: () => request('GET', '/transfers/pending'),
        getTransfer: (id) => request('GET', `/transfers/${id}`),

        // ---- stock opname ----
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
        uploadImportFile: (file) => upload('/import/upload', file),
        stageMasterItem: (filePath, fileName) => request('POST', '/import/master-item/stage', { file_path: filePath, file_name: fileName }),
        commitMasterItem: (id) => request('POST', `/import/master-item/${id}/commit`),
        stageSimpleMaster: (type, filePath, fileName) => request('POST', `/import/${type}/stage`, { file_path: filePath, file_name: fileName }),
        commitSimpleMaster: (type, id) => request('POST', `/import/${type}/${id}/commit`),
        stageOpeningStock: (filePath, fileName) => request('POST', '/import/opening-stock/stage', { file_path: filePath, file_name: fileName }),
        commitOpeningStock: (id) => request('POST', `/import/opening-stock/${id}/commit`),
        stageHistorical: (filePath, fileName) => request('POST', '/import/historical/stage', { file_path: filePath, file_name: fileName }),
        commitHistorical: (id) => request('POST', `/import/historical/${id}/commit`),
        previewImportBatch: (id) => request('GET', `/import/batches/${id}/rows`),
        previewOpeningStockBatch: (id) => request('GET', `/import/opening-stock/${id}/rows`),
    };
})();
