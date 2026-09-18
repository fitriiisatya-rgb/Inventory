/**
 * Shared UI helpers: toasts, modal open/close, number/date formatting,
 * connection-lost banner. Pure DOM/presentation — no business logic,
 * no direct fetch calls (that's api-client.js's job only).
 */
const UI = (() => {
    let toastContainer = null;

    function ensureToastContainer() {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }

    function toast(message, type = 'info', durationMs = 4000) {
        const container = ensureToastContainer();
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        container.appendChild(el);
        setTimeout(() => el.remove(), durationMs);
    }

    function showConnectionBanner(show) {
        let banner = document.getElementById('connection-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'connection-banner';
            banner.className = 'connection-banner';
            banner.textContent = 'Sistem sedang tidak dapat terhubung ke server.';
            document.body.prepend(banner);
        }
        banner.classList.toggle('show', show);
    }

    function openModal(id) {
        document.getElementById(id)?.classList.add('open');
    }
    function closeModal(id) {
        document.getElementById(id)?.classList.remove('open');
    }

    function formatMoney(value) {
        const n = Number(value) || 0;
        return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
    }
    function formatNumber(value, decimals = 2) {
        const n = Number(value) || 0;
        return n.toLocaleString('id-ID', { maximumFractionDigits: decimals });
    }
    function formatDate(value) {
        if (!value) return '-';
        const d = new Date(value.replace(' ', 'T'));
        if (isNaN(d.getTime())) return value;
        return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function badgeClass(status) {
        const map = {
            PENDING: 'badge-pending', RECEIVED: 'badge-received', CANCELLED: 'badge-cancelled',
            OPEN: 'badge-open', FINALIZED: 'badge-finalized', POSTED: 'badge-posted',
            VOID: 'badge-void', LOCKED: 'badge-locked', PASS: 'badge-pass',
            WARNING: 'badge-warning', ERROR: 'badge-error',
        };
        return map[String(status).toUpperCase()] || 'badge-pending';
    }

    // PHASE V2 — stock-status pill (SAFE/LOW/CRITICAL/OUT_OF_STOCK/
    // MIGRATION_NEGATIVE_REVIEW), Indonesian labels per the owner's spec.
    // Never recomputes the status itself — always renders exactly what the
    // API returned (StockPolicyService::stockStatus() is the one place
    // status is decided).
    const STOCK_STATUS_LABELS = {
        SAFE: 'Aman', LOW: 'Warning', CRITICAL: 'Kritis',
        OUT_OF_STOCK: 'Habis', MIGRATION_NEGATIVE_REVIEW: 'Review',
    };
    function stockStatusBadge(status) {
        const cls = `badge-status-${String(status).toLowerCase()}`;
        const label = STOCK_STATUS_LABELS[status] || status;
        return el('span', { class: `badge ${cls}` }, label);
    }
    function bufferCell(bufferConfigured, bufferValue) {
        if (bufferConfigured) return document.createTextNode(formatNumber(bufferValue));
        return el('span', { class: 'buffer-unconfigured-note' }, 'Buffer belum dikonfigurasi');
    }

    // Generic error → toast for any InvApi.ApiError/NetworkError thrown by a caller.
    function handleApiError(err) {
        if (err && err.code === 'NETWORK_ERROR') {
            showConnectionBanner(true);
            toast(err.message, 'error');
            return;
        }
        toast((err && err.message) || 'Terjadi kesalahan', 'error');
    }

    // D14: client-side export of whatever rows/columns the caller already
    // fetched from the API for the view on screen — never a stale or
    // separately-accumulated local dataset.
    function exportCsv(filename, columns, rows) {
        const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
        const lines = [columns.map(([, label]) => escape(label)).join(',')];
        rows.forEach((row) => {
            lines.push(columns.map(([key]) => escape(typeof key === 'function' ? key(row) : row[key])).join(','));
        });
        const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function el(tag, attrs = {}, children = []) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') node.className = v;
            else if (k === 'html') node.innerHTML = v;
            else node.setAttribute(k, v);
        }
        for (const child of [].concat(children)) {
            if (child == null) continue;
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        }
        return node;
    }

    return {
        toast, showConnectionBanner, openModal, closeModal,
        formatMoney, formatNumber, formatDate, badgeClass,
        stockStatusBadge, bufferCell,
        handleApiError, el, exportCsv,
    };
})();
