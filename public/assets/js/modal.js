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

    return { confirm, prompt };
})();
