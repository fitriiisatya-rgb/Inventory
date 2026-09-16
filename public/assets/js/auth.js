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

    // Cosmetic-only mirror of docs/API_CONTRACT.md's role->permission table,
    // used purely to hide buttons the server would reject anyway. The real
    // gate is always the backend's role_permissions check on every request.
    const ROLE_PERMISSIONS = {
        VIEWER: ['INVENTORY_VIEW', 'AUDIT_LOG_VIEW', 'RECONCILIATION_VIEW'],
        STOCK: ['INVENTORY_VIEW', 'TRANSACTION_IN_CREATE', 'TRANSACTION_OUT_CREATE', 'WAREHOUSE_TRANSFER_MANAGE', 'STOCK_OPNAME_MANAGE', 'STOCK_ADJUSTMENT_CREATE'],
        DIVISION: ['INVENTORY_VIEW', 'TRANSACTION_OUT_CREATE', 'PRODUCTION_MANAGE'],
        ADMIN: ['*'],
        SUPERADMIN: ['*'],
    };

    function user() {
        return currentUser;
    }

    function hasPermission(permCode) {
        if (!currentUser) return false;
        const granted = ROLE_PERMISSIONS[currentUser.role_code] || [];
        return granted.includes('*') || granted.includes(permCode);
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

    async function login(username, password) {
        const data = await InvApi.login(username, password);
        // /auth/login only returns {username, role, csrf_token} — fetch the
        // full session-scoped profile (division_id/warehouse_id) via /auth/me
        // rather than assuming its shape.
        InvApi.setCsrfToken(data.csrf_token);
        await tryResumeSession();
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
    }

    return { user, hasPermission, hasRole, tryResumeSession, login, logout, applyRoleVisibility };
})();
