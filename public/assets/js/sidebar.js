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

    if (sidebar && backdrop && toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.contains('open') ? mobileClose() : mobileOpen();
        });
        backdrop.addEventListener('click', mobileClose);
    }

    if (!sidebar) return;

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
        if (e.target.closest('.sidebar-link') && isMobileViewport()) mobileClose();
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
