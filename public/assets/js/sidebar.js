/**
 * PHASE V2 — sidebar mobile toggle only. Which links are visible per role
 * is still decided declaratively by Auth.applyRoleVisibility() reading
 * each link's data-require-permission/data-require-role attribute (same
 * mechanism the old horizontal tab bar already used) — this file never
 * duplicates that logic, it only handles open/close on narrow viewports.
 * Tab activation/dispatch stays in app.js's activateTab().
 */
(() => {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggleBtn = document.getElementById('sidebar-toggle-btn');
    if (!sidebar || !backdrop || !toggleBtn) return;

    function open() {
        sidebar.classList.add('open');
        backdrop.classList.add('open');
    }
    function close() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('open');
    }

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.contains('open') ? close() : open();
    });
    backdrop.addEventListener('click', close);
    sidebar.addEventListener('click', (e) => {
        if (e.target.closest('.sidebar-link')) close();
    });
})();
