/**
 * PHASE V2 — mobile drawer toggle.
 * PHASE V2.6A — accordion behavior added on top. Which INDIVIDUAL links are
 * visible per role is still decided declaratively by Auth.applyRoleVisibility()
 * reading each link's data-require-permission/data-require-role attribute
 * (same mechanism the old horizontal tab bar already used) — none of the
 * accordion logic below touches that; it only opens/closes whole GROUPS.
 * Tab activation/dispatch stays in app.js's activateTab().
 *
 * Accordion rules (owner spec):
 *  - Dashboard is always visible, never collapsible.
 *  - Every other group starts collapsed; clicking its header opens it.
 *  - Only one group is open at a time.
 *  - The group containing the currently active link always auto-opens,
 *    and active-route wins over whatever was last persisted.
 *  - The last manually-opened group is remembered in localStorage per
 *    browser so a reload doesn't dump the user back to fully collapsed —
 *    but only used when no route is active inside a collapsible group yet.
 *
 * UI2 — collapsible rail. The header button (#sidebar-toggle-btn) now works at every width:
 *  - <= 900px  : unchanged off-canvas drawer (overlay, closes on choice / backdrop).
 *  - > 900px   : expanded (248px, icons + labels) <-> collapsed rail (icons only, ~68px).
 *                An explicit choice is remembered in localStorage ('inv_sidebar_collapsed'
 *                = '1' | '0'); without one the rail is the default up to 1000px wide and
 *                on portrait tablets up to 1100px, expanded otherwise (iPad landscape). In the rail, every item gets a
 *                tooltip (hover / keyboard focus) and a group header opens its submenu as a
 *                flyout. Navigation, role visibility and the accordion state are untouched:
 *                the rail is purely a presentation state (class .sidebar-collapsed).
 */
(() => {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggleBtn = document.getElementById('sidebar-toggle-btn');

    const STORAGE_KEY = 'inv_sidebar_open_group';
    const ARROW_COLLAPSED = '›'; // ›
    const ARROW_EXPANDED = '⌄';  // ⌄

    function mobileOpen() {
        if (!sidebar || !backdrop) return;
        sidebar.classList.add('open');
        backdrop.classList.add('open');
    }
    function mobileClose() {
        if (!sidebar || !backdrop) return;
        sidebar.classList.remove('open');
        backdrop.classList.remove('open');
    }
    function isMobileViewport() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    const COLLAPSE_KEY = 'inv_sidebar_collapsed';
    // Without a stored choice the rail is the default on narrow-landscape (<=1000px) and on portrait
    // tablets up to 1100px wide (iPad Pro 12.9" portrait); iPad landscape (>=1024px) starts expanded.
    function railByDefault() {
        const w = window.innerWidth;
        return w <= 1000 || (w <= 1100 && window.innerHeight > w);
    }

    if (sidebar && backdrop && toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            if (isMobileViewport()) {
                sidebar.classList.contains('open') ? mobileClose() : mobileOpen();
                syncToggleA11y();
                return;
            }
            setCollapsed(!sidebar.classList.contains('sidebar-collapsed'), true);
        });
        backdrop.addEventListener('click', () => { mobileClose(); syncToggleA11y(); });
    }

    if (!sidebar) return;

    // ------------------------------------------------------------------ collapsible rail (UI2)
    const tip = document.createElement('div');
    tip.className = 'sidebar-tip';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    document.body.appendChild(tip);

    function isRail() { return sidebar.classList.contains('sidebar-collapsed'); }
    function storedPref() {
        try { return localStorage.getItem(COLLAPSE_KEY); } catch (e) { return null; }
    }
    function wantsCollapsed() {
        const p = storedPref();
        if (p === '1') return true;
        if (p === '0') return false;
        return railByDefault();
    }
    function syncToggleA11y() {
        if (!toggleBtn) return;
        if (isMobileViewport()) {
            const open = sidebar.classList.contains('open');
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
            toggleBtn.title = open ? 'Tutup menu' : 'Buka menu';
        } else {
            const expanded = !isRail();
            toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', expanded ? 'Ciutkan menu samping' : 'Perluas menu samping');
            toggleBtn.title = expanded ? 'Ciutkan menu samping' : 'Perluas menu samping';
        }
    }
    function closeFlyouts() {
        sidebar.querySelectorAll('.sidebar-group.flyout').forEach((g) => {
            g.classList.remove('flyout');
            const sm = g.querySelector('.sidebar-submenu');
            if (sm) { sm.style.top = ''; sm.style.left = ''; }
        });
    }
    function hideTip() { tip.hidden = true; }
    function applyCollapse() {
        const collapse = !isMobileViewport() && wantsCollapsed();
        sidebar.classList.toggle('sidebar-collapsed', collapse);
        document.body.classList.toggle('sidebar-rail', collapse);
        closeFlyouts();
        hideTip();
        syncToggleA11y();
    }
    function setCollapsed(collapsed, remember) {
        if (remember) { try { localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0'); } catch (e) { /* cosmetic only */ } }
        applyCollapse();
        // widths of anything laid out against the content area (e.g. pinned action bars) follow the transition
        setTimeout(() => window.dispatchEvent(new Event('resize')), 230);
    }

    // accessible name + tooltip text for every collapsed item
    sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
        // name without the decorative emoji icon (.icon span)
        const label = Array.from(link.childNodes).filter((n) => n.nodeType === 3).map((n) => n.textContent).join(' ').replace(/\s+/g, ' ').trim() || link.textContent.replace(/\s+/g, ' ').trim();
        link.dataset.tip = label;
        link.setAttribute('aria-label', label);
        // the links are <a> without href: make them keyboard-reachable and activatable (Enter / Space)
        if (!link.hasAttribute('tabindex')) link.setAttribute('tabindex', '0');
        if (!link.hasAttribute('role')) link.setAttribute('role', 'link');
        link.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); link.click(); } });
    });
    sidebar.querySelectorAll('.sidebar-group-header').forEach((h) => {
        const label = (h.querySelector('.sidebar-group-label') || h).textContent.replace(/\s+/g, ' ').trim();
        h.dataset.tip = label;
    });
    const overview = sidebar.querySelector('.sidebar-group-static .sidebar-group-label');
    if (overview) overview.dataset.tip = overview.textContent.trim();

    function showTip(el) {
        if (!isRail() || !el.dataset.tip) return;
        const r = el.getBoundingClientRect();
        tip.textContent = el.dataset.tip;
        tip.hidden = false;
        tip.style.left = `${r.right + 10}px`;
        tip.style.top = `${r.top + r.height / 2}px`;
    }
    sidebar.addEventListener('mouseover', (e) => { const t = e.target.closest('.sidebar-link, .sidebar-group-header'); if (t) showTip(t); });
    sidebar.addEventListener('mouseout', (e) => { if (e.target.closest('.sidebar-link, .sidebar-group-header')) hideTip(); });
    sidebar.addEventListener('focusin', (e) => { const t = e.target.closest('.sidebar-link, .sidebar-group-header'); if (t) showTip(t); });
    sidebar.addEventListener('focusout', hideTip);
    sidebar.addEventListener('scroll', () => { hideTip(); closeFlyouts(); }, { passive: true });

    function toggleFlyout(group) {
        const open = group.classList.contains('flyout');
        closeFlyouts();
        hideTip();
        if (open) return;
        group.classList.add('flyout');
        const sm = group.querySelector('.sidebar-submenu');
        const header = group.querySelector('.sidebar-group-header');
        if (!sm || !header) return;
        const hr = header.getBoundingClientRect();
        sm.style.left = `${sidebar.getBoundingClientRect().right + 6}px`;
        sm.style.top = `${hr.top}px`;
        const h = sm.getBoundingClientRect().height;
        sm.style.top = `${Math.max(8, Math.min(hr.top, window.innerHeight - h - 8))}px`;
    }
    document.addEventListener('mousedown', (e) => {
        if (!isRail()) return;
        if (!e.target.closest('.sidebar')) closeFlyouts();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeFlyouts(); hideTip(); } });
    window.addEventListener('resize', () => { applyCollapse(); });
    applyCollapse();

    const groups = Array.from(sidebar.querySelectorAll('.sidebar-group-collapsible'));

    function setGroupState(group, expanded) {
        const header = group.querySelector('.sidebar-group-header');
        const submenu = group.querySelector('.sidebar-submenu');
        const arrow = group.querySelector('.accordion-arrow');
        if (!header || !submenu) return;
        header.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        submenu.hidden = !expanded;
        if (arrow) arrow.textContent = expanded ? ARROW_EXPANDED : ARROW_COLLAPSED;
        group.classList.toggle('open', expanded);
    }

    function openGroup(targetGroup, opts) {
        const persist = !opts || opts.persist !== false;
        groups.forEach((group) => setGroupState(group, group === targetGroup));
        if (targetGroup && persist) {
            try { localStorage.setItem(STORAGE_KEY, targetGroup.dataset.group || ''); } catch (e) { /* ignore, cosmetic only */ }
        }
    }

    function closeAllGroups() {
        groups.forEach((group) => setGroupState(group, false));
    }

    groups.forEach((group) => {
        const header = group.querySelector('.sidebar-group-header');
        if (!header) return;
        header.addEventListener('click', () => {
            if (isRail()) { toggleFlyout(group); return; }
            const isOpen = header.getAttribute('aria-expanded') === 'true';
            if (isOpen) {
                setGroupState(group, false);
                try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
            } else {
                openGroup(group);
            }
        });
    });

    // Mobile/tablet: once a real navigation choice is made inside a
    // submenu, auto-close the drawer (kept from the pre-V2.6A behavior).
    sidebar.addEventListener('click', (e) => {
        if (e.target.closest('.sidebar-link')) {
            closeFlyouts();
            hideTip();
            if (isMobileViewport()) { mobileClose(); syncToggleA11y(); }
        }
    });

    // Active-route-always-wins: whenever app.js's activateTab() flips a
    // .sidebar-link's `active` class, auto-open the group that contains it
    // and mark the group visually via .has-active. This observes DOM state
    // rather than requiring app.js to call into sidebar.js, so tab
    // activation logic (owned by app.js) is never duplicated or touched.
    function syncActiveGroup() {
        const activeLink = sidebar.querySelector('.sidebar-link.active');
        groups.forEach((group) => group.classList.remove('has-active'));
        if (!activeLink) return;
        const group = activeLink.closest('.sidebar-group-collapsible');
        if (group) {
            group.classList.add('has-active');
            const header = group.querySelector('.sidebar-group-header');
            if (header && header.getAttribute('aria-expanded') !== 'true') openGroup(group);
        } else {
            // Active link lives in the non-collapsible Dashboard group —
            // collapsible groups keep whatever the user/localStorage chose.
        }
    }

    const observer = new MutationObserver((mutations) => {
        const relevant = mutations.some((m) => m.target.classList && m.target.classList.contains('sidebar-link'));
        if (relevant) syncActiveGroup();
    });
    sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
        observer.observe(link, { attributes: true, attributeFilter: ['class'] });
    });

    // Initial state: honor an already-active link (e.g. a future
    // server-rendered active tab) first, else fall back to the
    // last-persisted group, else stay fully collapsed.
    (function initialOpen() {
        const activeLink = sidebar.querySelector('.sidebar-link.active');
        const activeGroup = activeLink ? activeLink.closest('.sidebar-group-collapsible') : null;
        if (activeGroup) {
            syncActiveGroup();
            return;
        }
        let savedKey = null;
        try { savedKey = localStorage.getItem(STORAGE_KEY); } catch (e) { /* ignore */ }
        if (savedKey) {
            const savedGroup = groups.find((g) => g.dataset.group === savedKey);
            if (savedGroup) { openGroup(savedGroup, { persist: false }); return; }
        }
        closeAllGroups();
    })();
})();
