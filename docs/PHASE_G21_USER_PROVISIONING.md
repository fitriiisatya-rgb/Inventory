# Phase G21 — User Account Provisioning

**Principle:** legacy passwords are never migrated. Every account on the
new system is created fresh, with a temporary generated password, and is
forced to set its own password before it can do anything else.

## Provisioning a new account

```bash
php scripts/provision_user.php <username> "<full name>" <role_code> [division_code] [warehouse_code]
```

- `role_code`: `SUPERADMIN` | `ADMIN` | `STOCK` | `DIVISION` | `VIEWER`
- `division_code` / `warehouse_code`: only meaningful for `DIVISION`- and
  `STOCK`-scoped accounts respectively; omit for unscoped roles.

The script prints the generated temporary password to stdout **exactly
once**. It is never written to a log file or stored anywhere in plaintext
— only its bcrypt hash is persisted (`users.password_hash`). Whoever runs
this script is responsible for relaying that password to the account
holder through a secure channel (in person, a password manager share,
etc — never plain email/chat in a real go-live) and then discarding it.

## First account (bootstrap)

The very first `SUPERADMIN` account is still created via the existing
`migration/install.php` (interactive, operator types their own password —
this is the initial bootstrap mechanism, a different scenario from
provisioning an account *for* someone else, and is unchanged by this
phase). Every account after that — including every other `SUPERADMIN` —
should go through `provision_user.php`.

## Forced password change (server-enforced, not just a UI nag)

Every account `provision_user.php` creates starts with
`must_change_password = 1`. While that flag is set:

- `inv_require_auth()` in `public/index.php` rejects **every** endpoint
  except `POST /auth/change-password` with `403 PASSWORD_CHANGE_REQUIRED`
  — enforced once, centrally, so no individual route (of the ~40 in this
  API) needed its own check added.
- `GET /auth/me` and `POST /auth/logout` remain reachable regardless (they
  never required a "not pending" session to begin with).
- The frontend (`public/assets/js/app.js`) checks `must_change_password`
  immediately after login/`/auth/me` and shows a dedicated "Ganti Password
  Wajib" screen instead of the app shell — but this is convenience, not
  the actual gate; a user who bypassed the frontend entirely and called
  the API directly would still be blocked server-side.
- `POST /auth/change-password` (`{current_password, new_password}`)
  verifies the current password, requires the new one to be ≥ 8
  characters, updates the hash, and clears `must_change_password`.

## Minimum roles for go-live (per the cutover checklist)

- `SUPERADMIN` — at least one (the bootstrap account)
- `ADMIN` — day-to-day operational admin(s)
- `VIEWER` — read-only reporting access
- `STOCK` per warehouse — one per physical warehouse that needs its own
  scoped staff account (`warehouse_code` argument)
- `DIVISION` — only if a division-scoped account is actually needed
  (`division_code` argument)

None of these are created by this batch of work — provisioning real
accounts happens in Phase G-DATA / G18, once the target warehouses and
divisions actually exist from the real import.

## Verified in this session

- `provision_user.php` create a `STOCK`-role account with a generated
  16-character temporary password.
- Logging in with that password returns `must_change_password: true` and
  is rejected with `403 PASSWORD_CHANGE_REQUIRED` on every other endpoint
  (`GET /items` tested directly via curl).
- `POST /auth/change-password` with the correct current password succeeds;
  logging in again afterward returns `must_change_password: false` and the
  account has full normal access.
- The same flow was exercised end-to-end through the actual browser UI via
  Playwright: forced login → change-password screen shown → redirected to
  login → new password works → app shell loads.
