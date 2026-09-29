// Shared fetch helper: attaches CSRF token to every state-changing call
// and normalizes error handling for the plain-JS admin pages.
window.SO = (function () {
  const csrfToken = document.body.dataset.csrf || null;

  async function api(url, options = {}) {
    const opts = Object.assign({ headers: {} }, options);
    if (opts.body && !(opts.body instanceof FormData)) {
      opts.headers['Content-Type'] = 'application/json';
    }
    if (csrfToken && options.method && options.method !== 'GET') {
      opts.headers['X-CSRF-Token'] = csrfToken;
    }
    const res = await fetch(url, opts);
    let data = null;
    try { data = await res.json(); } catch (e) { /* no body */ }
    if (!res.ok) {
      const err = new Error((data && data.error) || `HTTP ${res.status}`);
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
  }

  return { api, escapeHtml };
})();
