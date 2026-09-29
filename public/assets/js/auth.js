/**
 * D1 — Login/session. Server-side authorization is always re-checked by the
 * backend regardless of what this file hides in the UI; role-based hiding
 * here is cosmetic convenience only, never the actual access control.
 * sessionStorage is used ONLY to remember the current tab across a reload
 * (cosmetic UI state) — never for inventory/session data itself, which is
 * always re-fetched from /auth/me.
 */
const Auth = (() => {
    let currentUser = null;

    function user() {
        return currentUser;
    }

    // PHASE V2.14.3 — hasPermission() now reads currentUser.permissions,
    // the exact list of permission codes the backend returned for this
    // role (POST /auth/login / GET /auth/me, hydrated fresh from
    // role_permissions on every call — see AuthService::permissionsForRole()).
    // There is deliberately no second, hard-coded role->permission map here
    // any more: this hiding logic is cosmetic convenience only, and it must
    // read the SAME current DB grants the backend's own
    // inv_require_permission()/AuthService::hasPermission() enforce, or it
    // silently drifts out of sync (exactly the bug this phase fixes). The
    // backend remains the only real authorization boundary regardless.
    function hasPermission(permCode) {
        if (!currentUser) return false;
        const granted = currentUser.permissions || [];
        return granted.includes(permCode);
    }

    function hasRole(...roles) {
        return !!(currentUser && roles.includes(currentUser.role_code));
    }

    async function tryResumeSession() {
        try {
            const data = await InvApi.me();
            if (!data) {
                currentUser = null;
                return false;
            }
            currentUser = data;
            InvApi.setCsrfToken(data.csrf_token);
            return true;
        } catch (err) {
            currentUser = null;
            return false;
        }
    }

    // PHASE V2.2 — a distinct error type so app.js's login handler can show
    // a friendly message rather than letting callers fall through to
    // Auth.user() being null (the exact "Auth.user().must_change_password"
    // crash the owner flagged: POST /auth/login succeeding but the
    // follow-up GET /auth/me failing to establish a session).
    class SessionNotEstablishedError extends Error {
        constructor() {
            super('Sesi login tidak berhasil dibuat. Silakan coba kembali.');
            this.code = 'SESSION_NOT_ESTABLISHED';
        }
    }

    async function login(username, password) {
        const data = await InvApi.login(username, password);
        // /auth/login only returns {username, role, csrf_token} — fetch the
        // full session-scoped profile (division_id/warehouse_id) via /auth/me
        // rather than assuming its shape.
        InvApi.setCsrfToken(data.csrf_token);
        await tryResumeSession();
        if (!currentUser) {
            throw new SessionNotEstablishedError();
        }
        return currentUser;
    }

    async function logout() {
        try {
            await InvApi.logout();
        } finally {
            currentUser = null;
            InvApi.setCsrfToken(null);
        }
    }

    function applyRoleVisibility() {
        document.querySelectorAll('[data-require-permission]').forEach((node) => {
            const required = node.getAttribute('data-require-permission').split(',').map((s) => s.trim());
            const allowed = required.some((p) => hasPermission(p));
            node.style.display = allowed ? '' : 'none';
        });
        document.querySelectorAll('[data-require-role]').forEach((node) => {
            const roles = node.getAttribute('data-require-role').split(',').map((s) => s.trim());
            node.style.display = hasRole(...roles) ? '' : 'none';
        });
        // PHASE V2.14.11.3 — once every individual link's visibility above
        // is settled, hide any sidebar GROUP (header + submenu wrapper)
        // left with zero visible .sidebar-link children, so an
        // under-privileged role (OPNAME_COUNTER chief among them, but
        // this applies to any role) never sees a dead, permanently-empty
        // accordion section. A group with at least one visible link
        // (e.g. "Stock Opname" for OPNAME_COUNTER — only "Stock Opname
        // Saya" is visible, "Stock Opname" admin is not) stays visible.
        // Generic and role-agnostic by construction — no role name is
        // ever referenced here.
        document.querySelectorAll('.sidebar-group').forEach((group) => {
            const links = group.querySelectorAll('.sidebar-link');
            if (links.length === 0) return;
            const anyVisible = Array.from(links).some((link) => link.style.display !== 'none');
            group.style.display = anyVisible ? '' : 'none';
        });
    }

    return { user, hasPermission, hasRole, tryResumeSession, login, logout, applyRoleVisibility };
})();
