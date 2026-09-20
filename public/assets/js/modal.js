/**
 * PHASE V2 — Promise-based confirm/prompt replacement for the browser's
 * native confirm()/prompt(), per the global UX rule ("no browser prompt()
 * in production workflow"). Reuses the existing .modal/.modal-content CSS
 * (app.css) rather than introducing new modal markup conventions.
 *
 * Usage:
 *   const ok = await Modal.confirm({ title: 'Batalkan?', message: '...' });
 *   const reason = await Modal.prompt({ title: 'Alasan', label: 'Alasan pembatalan', required: true });
 */
const Modal = (() => {
    function build(titleText, bodyNode, buttons) {
        const overlay = UI.el('div', { class: 'modal open' });
        const content = UI.el('div', { class: 'modal-content' }, [
            UI.el('h3', {}, titleText),
            bodyNode,
            UI.el('div', { style: 'display:flex; gap:10px; justify-content:flex-end; margin-top:18px;' }, buttons),
        ]);
        overlay.appendChild(content);
        document.body.appendChild(overlay);
        return overlay;
    }

    function close(overlay) {
        overlay.remove();
    }

    /** @returns {Promise<boolean>} */
    function confirm({ title = 'Konfirmasi', message = '', confirmLabel = 'Lanjutkan', cancelLabel = 'Batal', danger = false } = {}) {
        return new Promise((resolve) => {
            const body = UI.el('div', {}, [UI.el('p', { style: 'color:var(--text2); font-size:0.9rem; line-height:1.5; white-space:pre-line;' }, message)]);
            let overlay;
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary' }, cancelLabel);
            const confirmBtn = UI.el('button', { class: danger ? 'btn btn-danger' : 'btn btn-primary' }, confirmLabel);
            cancelBtn.addEventListener('click', () => { close(overlay); resolve(false); });
            confirmBtn.addEventListener('click', () => { close(overlay); resolve(true); });
            overlay = build(title, body, [cancelBtn, confirmBtn]);
            confirmBtn.focus();
        });
    }

    /** @returns {Promise<string|null>} null if cancelled */
    function prompt({ title = 'Input', message = '', label = '', placeholder = '', required = false, confirmLabel = 'Simpan', cancelLabel = 'Batal' } = {}) {
        return new Promise((resolve) => {
            const input = UI.el('input', { type: 'text', placeholder });
            const errorNode = UI.el('div', { class: 'alert alert-error', style: 'display:none; margin-top:8px;' });
            const bodyChildren = [];
            if (message) bodyChildren.push(UI.el('p', { style: 'color:var(--text2); font-size:0.9rem; margin-bottom:10px;' }, message));
            if (label) bodyChildren.push(UI.el('label', {}, label));
            bodyChildren.push(input, errorNode);
            const body = UI.el('div', {}, bodyChildren);

            let overlay;
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary' }, cancelLabel);
            const confirmBtn = UI.el('button', { class: 'btn btn-primary' }, confirmLabel);
            cancelBtn.addEventListener('click', () => { close(overlay); resolve(null); });
            const submit = () => {
                const value = input.value.trim();
                if (required && !value) {
                    errorNode.textContent = 'Wajib diisi.';
                    errorNode.style.display = 'block';
                    input.focus();
                    return;
                }
                close(overlay);
                resolve(value);
            };
            confirmBtn.addEventListener('click', submit);
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
            overlay = build(title, body, [cancelBtn, confirmBtn]);
            input.focus();
        });
    }

    /** Single-button informational dialog (e.g. surfacing a server-side block reason verbatim). @returns {Promise<void>} */
    function alert({ title = 'Informasi', message = '', okLabel = 'Mengerti' } = {}) {
        return new Promise((resolve) => {
            const body = UI.el('div', {}, [UI.el('p', { style: 'color:var(--text2); font-size:0.9rem; line-height:1.5; white-space:pre-line;' }, message)]);
            let overlay;
            const okBtn = UI.el('button', { class: 'btn btn-primary' }, okLabel);
            okBtn.addEventListener('click', () => { close(overlay); resolve(); });
            overlay = build(title, body, [okBtn]);
            okBtn.focus();
        });
    }

    /**
     * PHASE V2.5 — destructive-correction confirmation dialog (Void
     * Transaksi / Reverse Transfer / Batalkan Transfer). Shows a read-only
     * info block (Jenis/Tanggal/Gudang/Reference/... whatever the caller
     * passes as `infoRows`), an optional warning line, and a required
     * reason textarea with a minimum-length gate — the confirm button stays
     * disabled until the reason is long enough, so the server-side check is
     * never the first feedback the user sees.
     * @returns {Promise<string|null>} the trimmed reason, or null if cancelled
     */
    function form({
        title = 'Konfirmasi', infoRows = [], warning = '', reasonLabel = 'Alasan', reasonPlaceholder = '',
        minReasonLength = 5, confirmLabel = 'Konfirmasi', cancelLabel = 'Batal', danger = true,
    } = {}) {
        return new Promise((resolve) => {
            const bodyChildren = [];
            if (infoRows.length) {
                bodyChildren.push(UI.el('div', { class: 'table-wrapper', style: 'margin-bottom:14px;' }, [
                    UI.el('table', {}, [
                        UI.el('tbody', {}, infoRows.map(([k, v]) => UI.el('tr', {}, [
                            UI.el('td', { style: 'color:var(--text3); white-space:nowrap;' }, k),
                            UI.el('td', {}, v instanceof Node ? v : String(v)),
                        ]))),
                    ]),
                ]));
            }
            if (warning) {
                bodyChildren.push(UI.el('div', { class: 'alert alert-error', style: 'margin-bottom:12px; white-space:pre-line;' }, warning));
            }
            bodyChildren.push(UI.el('label', {}, `${reasonLabel} *`));
            const textarea = UI.el('textarea', { rows: '3', placeholder: reasonPlaceholder, style: 'width:100%; resize:vertical; font-family:inherit;' });
            const errorNode = UI.el('div', { class: 'alert alert-error', style: 'display:none; margin-top:8px;' });
            bodyChildren.push(textarea, errorNode);
            const body = UI.el('div', {}, bodyChildren);

            let overlay;
            const cancelBtn = UI.el('button', { class: 'btn btn-secondary' }, cancelLabel);
            const confirmBtn = UI.el('button', { class: danger ? 'btn btn-danger' : 'btn btn-primary' }, confirmLabel);
            cancelBtn.addEventListener('click', () => { close(overlay); resolve(null); });
            const submit = () => {
                const value = textarea.value.trim();
                if (value.length < minReasonLength) {
                    errorNode.textContent = `Wajib diisi, minimal ${minReasonLength} karakter.`;
                    errorNode.style.display = 'block';
                    textarea.focus();
                    return;
                }
                close(overlay);
                resolve(value);
            };
            confirmBtn.addEventListener('click', submit);
            overlay = build(title, body, [cancelBtn, confirmBtn]);
            textarea.focus();
        });
    }

    return { confirm, prompt, alert, form };
})();
