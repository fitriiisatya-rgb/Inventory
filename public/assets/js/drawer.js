/**
 * PHASE V2 — generic slide-over detail panel (item detail, transaction
 * detail). Supports optional tabs (e.g. Overview/FIFO Layers/Movement).
 * One drawer instance lives in the DOM at a time; opening a new one
 * replaces the previous content rather than stacking.
 */
const Drawer = (() => {
    let backdrop = null;
    let panel = null;

    function ensure() {
        if (panel) return;
        backdrop = UI.el('div', { class: 'drawer-backdrop' });
        panel = UI.el('div', { class: 'drawer' });
        backdrop.addEventListener('click', close);
        document.body.appendChild(backdrop);
        document.body.appendChild(panel);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    }

    /**
     * @param {{title:string, tabs?: {key:string,label:string,render:(body:HTMLElement)=>void}[], render?: (body:HTMLElement)=>void}} opts
     */
    function open(opts) {
        ensure();
        panel.innerHTML = '';
        const closeBtn = UI.el('button', { class: 'drawer-close', 'aria-label': 'Tutup' }, '✕');
        closeBtn.addEventListener('click', close);
        const header = UI.el('div', { class: 'drawer-header' }, [UI.el('div', { class: 'drawer-title' }, opts.title), closeBtn]);
        panel.appendChild(header);

        const body = UI.el('div', { class: 'drawer-body' });

        if (opts.tabs && opts.tabs.length) {
            const tabsBar = UI.el('div', { class: 'drawer-tabs' });
            let active = opts.tabs[0].key;
            const renderActive = () => {
                body.innerHTML = '';
                const tab = opts.tabs.find((t) => t.key === active);
                if (tab) tab.render(body);
            };
            opts.tabs.forEach((t) => {
                const btn = UI.el('button', { class: `drawer-tab${t.key === active ? ' active' : ''}` }, t.label);
                btn.addEventListener('click', () => {
                    active = t.key;
                    tabsBar.querySelectorAll('.drawer-tab').forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    renderActive();
                });
                tabsBar.appendChild(btn);
            });
            panel.appendChild(tabsBar);
            panel.appendChild(body);
            renderActive();
        } else {
            panel.appendChild(body);
            if (opts.render) opts.render(body);
        }

        backdrop.classList.add('open');
        panel.classList.add('open');
    }

    function close() {
        if (!panel) return;
        backdrop.classList.remove('open');
        panel.classList.remove('open');
    }

    /** Helper: a 2-column key/value grid for an "Overview"-style drawer section. */
    function kv(pairs) {
        const grid = UI.el('div', { class: 'drawer-kv' });
        pairs.forEach(([k, v]) => {
            grid.appendChild(UI.el('div', { class: 'k' }, k));
            const vNode = UI.el('div', { class: 'v' });
            if (v instanceof Node) vNode.appendChild(v); else vNode.textContent = v;
            grid.appendChild(vNode);
        });
        return grid;
    }

    function section(title, contentNode) {
        return UI.el('div', { class: 'drawer-section' }, [UI.el('div', { class: 'drawer-section-title' }, title), contentNode]);
    }

    return { open, close, kv, section };
})();
