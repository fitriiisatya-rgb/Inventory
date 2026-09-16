/**
 * Thin fetch wrapper for the new PHP/MySQL API. This replaces
 * pushToServer()/pullFromServer()/SYNC_URL from the legacy inventory.html —
 * see docs/PHASE_D_MIGRATION_GUIDE.md for exactly which legacy call sites
 * each method here is meant to replace.
 *
 * Always calls a RELATIVE path (/api/...) — no domain is ever hardcoded, so
 * this file is identical on every environment (Section H.1, DEPLOYMENT.md).
 */
const InvApi = (() => {
    async function request(method, path, body) {
        const res = await fetch(`/api${path}`, {
            method,
            credentials: 'include',
            headers: body ? { 'Content-Type': 'application/json' } : {},
            body: body ? JSON.stringify(body) : undefined,
        });
        const payload = await res.json().catch(() => ({ success: false, message: 'Invalid server response' }));
        if (!res.ok || !payload.success) {
            const err = new Error(payload.message || `Request failed (${res.status})`);
            err.status = res.status;
            err.data = payload.data;
            throw err;
        }
        return payload.data;
    }

    // RFC4122-ish v4 UUID for the idempotency key (Section 12) — every
    // posting call must generate exactly one of these per user action, and
    // resend the SAME value on retry, never a fresh one.
    function newRequestUuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    return {
        login: (username, password) => request('POST', '/auth/login', { username, password }),
        logout: () => request('POST', '/auth/logout'),
        me: () => request('GET', '/auth/me'),

        listItems: () => request('GET', '/items'),
        listWarehouses: () => request('GET', '/warehouses'),
        listSuppliers: () => request('GET', '/suppliers'),
        listDivisions: () => request('GET', '/divisions'),

        currentStock: (itemId, warehouseId) =>
            request('GET', `/inventory/current?item_id=${itemId}&warehouse_id=${warehouseId}`),
        batches: (itemId, warehouseId) =>
            request('GET', `/inventory/batches?item_id=${itemId}&warehouse_id=${warehouseId}`),

        postTransactionIn: (payload) =>
            request('POST', '/transactions/in', { transaction_uuid: newRequestUuid(), ...payload }),
        postTransactionOut: (payload) =>
            request('POST', '/transactions/out', { transaction_uuid: newRequestUuid(), ...payload }),

        newRequestUuid,
    };
})();
