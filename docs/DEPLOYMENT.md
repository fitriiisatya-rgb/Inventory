# Deployment Instructions

## H.0 Two systems run side by side — this is a hard requirement

The new MySQL system is deployed as a **completely separate application on
a new domain/subdomain**, with its **own empty MySQL database**. It never
shares a domain, session, cookie, cache, or database with the existing
Google Sheets system while both are live.

```
OLD SYSTEM                         NEW SYSTEM
existing-domain.example.com        inventory2.example.com  (name TBD at deploy time)
  → inventory.html                   → /public (this repo's API + adapted frontend)
  → Google Apps Script                → PHP API
  → Google Sheets                     → MySQL (new, empty database)
  stays live, unchanged                development → dummy test → clean import → parallel test
```

Rules enforced by this design (do not weaken any of these without the
user's explicit sign-off):

- **No automatic redirect** from the old domain to the new one during
  development/parallel-test. Nothing in `/public` or `/api` issues a
  redirect based on the old domain — routing is self-contained under `/api`.
- **No automatic sync** between the two systems. The new backend has no
  code path that reads from or writes to Google Sheets/Apps Script
  (verified in `docs/LEGACY_DEPENDENCIES.md`).
- **No shared browser state.** Different origins mean `localStorage`,
  cookies and caches are already isolated by the browser; nothing in this
  codebase tries to read the old domain's storage.
- **New MySQL database, never the old production data source.** See
  Section 2 of the original brief — the schema starts empty
  (`database/schema.sql`) and stays empty of business data until the
  reconciliation-gated import flow (Phase E) is run deliberately.

## H.1 Environment configuration — nothing hardcoded

All environment-specific values come from `.env` (loaded by
`config/config.php`), never from source:

```
APP_ENV=production
APP_URL=https://NEW-DOMAIN-GOES-HERE   # for CORS/cookie scoping only
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_mysql
DB_USERNAME=...
DB_PASSWORD=...
```

Copy `config/.env.example` to `.env` at deploy time and fill in the real
values; `.env` itself is git-ignored and must never be committed. The
frontend adapter (Phase D) calls the API via the **relative path**
`/api/...` exclusively — no domain is ever embedded in JS, so the same
frontend bundle works unchanged whatever the final domain turns out to be.

## H.2 Web server / PHP

Tested against PHP 8.4 CLI in this environment; targets PHP 8.1+ (uses
`readonly` properties, `match`, constructor property promotion — all 8.1+).
Required extensions: `pdo_mysql`. No composer dependencies are required to
run the current codebase (a deliberate choice — see `config/config.php`'s
`.env` loader — so a plain aaPanel PHP install works without a Packagist
network dependency).

1. Create the vhost/subdomain in aaPanel (or your panel of choice) pointing
   its **document root at `/public`** (not the repo root). This means
   `.env`, `/config`, `/services`, `/database`, `/tests` are never
   web-reachable at all, regardless of `.htaccess` — the safest form of
   "credential file not accessible from web" (Section H.5).
2. If the panel forces document root = repo root instead, the provided
   root `.htaccess` rewrites every request into `/public/` as a second
   line of defense — but prefer option 1.
3. Enable HTTPS (Let's Encrypt via aaPanel is fine) before any real
   credentials are used; the app assumes `SESSION_COOKIE_SECURE=true` in
   production, which requires HTTPS or login will silently fail to persist
   a session cookie.

## H.3 Database

```bash
mysql -u root -p -e "CREATE DATABASE inventory_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p inventory_mysql < database/schema.sql
```

This creates all tables and seeds only `roles`, `permissions`,
`role_permissions`, `units`, and `system_settings` — no items, suppliers,
divisions, warehouses, stock, or transactions (Section 2/3 of the brief).
Create the first SUPERADMIN user via `migration/install.php` (Phase E),
never by hand-editing seed SQL with a real password.

## H.4 Session / cookie / CORS hardening (already wired in code)

- `services/AuthService::bootSession()` sets `Secure`, `HttpOnly`, and
  `SameSite` from `.env` — flip `SESSION_COOKIE_SAMESITE=Lax` only if the
  frontend truly must be cross-site from the API (uncommon; default is
  `Strict`).
- `public/index.php` only ever sends `Access-Control-Allow-Origin` for an
  origin present in `CORS_ALLOWED_ORIGINS` — leave that empty for a
  same-origin deployment (frontend and API on the same new domain, the
  simplest and recommended setup) and it sends no CORS header at all.
- `display_errors` is forced off unless `APP_DEBUG=true`; all errors are
  written to `LOG_PATH` instead (default `storage/logs/app.log`) via
  `set_exception_handler` in `public/index.php` — a production client never
  sees a PHP stack trace (Section 25).

## H.5 Protecting `.env` and internal directories

Confirm directly after deploy that these all return 403/404, not file
contents:

```
https://NEW-DOMAIN/.env
https://NEW-DOMAIN/config/config.php
https://NEW-DOMAIN/database/schema.sql
https://NEW-DOMAIN/services/FifoService.php
```

With document root = `/public` (recommended, H.2.1) this is automatic. If
using an Nginx vhost instead of Apache, mirror `public/.htaccess`'s intent
with:

```nginx
root /path/to/repo/public;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

(`.htaccess` rules are Apache-only and are ignored by Nginx — the `root`
directive above is what actually keeps `/config`, `/services`, `/database`
unreachable.)

## H.6 Parallel-run / cutover checklist (Section 28-29 of the brief)

1. Deploy new domain + empty MySQL — done by following H.1-H.5.
2. Run `tests/run.php` (or the future PHPUnit port) against dummy data —
   never against a copy of production data.
3. Clear dummy data (`TRUNCATE` the business tables, re-run
   `database/schema.sql`'s seed section only).
4. Upload clean master via the Import module (Phase E) — Supplier /
   Division / Warehouse / Master Barang, in that order.
5. Validate master (reconciliation report — Section 29).
6. Upload clean Opening Stock, validated before commit.
7. Optionally import historical transactions with
   `is_historical_import=1, inventory_effect=0` (reporting-only, Section 15).
8. Run the reconciliation report; **GO LIVE is blocked while it shows any
   ERROR row** (negative stock, zero-cost stock, duplicate/unknown SKU,
   unknown warehouse — Section 29).
9. Run the new system and the existing Google Sheets system side by side
   ("parallel test") — the old system keeps being the operational one.
10. Only after the user explicitly signs off on reconciliation does the new
    MySQL system become the production source of truth; the old system is
    not shut down until the user gives that explicit instruction (per the
    mid-migration requirement added to this project).
