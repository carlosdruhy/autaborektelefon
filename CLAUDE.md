# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Internal web application for Auta Borek a.s. tracking phone-based service requests (telefonické požadavky). PHP 8+ backend, vanilla JS frontend, MySQL database. Hosted at `https://tel.auto-borek.cz` on shared WebGlobe hosting. No build tools — files are deployed by direct upload via FTP/SFTP.

## No build step

There is no build step. Files are deployed by direct FTP/SFTP upload.

## Running tests

Requires PHP 8.1+ and a local MySQL server. Create a `telefon_test` database (empty — the schema is created automatically on first run).

```bash
composer install
vendor\bin\phpunit          # all suites
vendor\bin\phpunit --testsuite Unit          # pure-function tests only (no DB)
vendor\bin\phpunit --testsuite Integration   # DB tests (requires MySQL)
vendor\bin\phpunit tests/Integration/AnonymizationTest.php  # single file
```

Override the default DB credentials (`root` / no password / `127.0.0.1`):

```bash
TEST_DB_HOST=localhost TEST_DB_USER=myuser TEST_DB_PASS=secret vendor\bin\phpunit
```

Or edit the `<php><env>` block in `phpunit.xml`.

Test layout:
- `tests/Unit/UtilityTest.php` — pure functions: `h()`, `normalizeSpz()`, `truncateForLog()`, `generateToken()`, `nowUtc()`
- `tests/Integration/AnonymizationTest.php` — GDPR anonymization (highest risk: irreversible)
- `tests/Integration/RateLimitTest.php` — brute-force rate limiting (security critical)
- `tests/Integration/SmsQueueTest.php` — SMS queue schema and anonymization interaction

## Architecture

```
/
├── includes/          # Shared PHP: config, DB singleton, functions, auth
├── api/               # JSON API endpoints (session-auth)
│   ├── requests.php   # CRUD + 8 actions for requests
│   ├── sms.php        # SMS queue (enqueue, list, bridge pull/confirm)
│   ├── settings.php   # Read app settings as JSON
│   └── stats.php      # Stats by technician and by age
├── admin/             # Admin-only HTML pages
│   ├── api/users.php  # User management API
│   ├── users.php      # User management UI
│   ├── stats.php      # Statistics UI
│   ├── sms.php        # All-SMS overview (admin)
│   └── settings.php   # System settings + TRB140 + GDPR anonymisation
├── assets/
│   ├── css/style.css  # All custom CSS (Bootstrap 5 overrides + components)
│   ├── js/app.js      # Dashboard SPA logic (~850 lines)
│   └── js/admin.js    # Admin pages JS
├── dashboard.php      # Main SPA shell (requires login)
├── login.php / logout.php / forgot-password.php / reset-password.php
├── install.php        # One-time DB setup (blocked by install.lock)
├── migrate-sms.sql    # Run once in phpMyAdmin to add SMS queue
├── sms-bridge.ps1     # PowerShell script for local PC → TRB140 SMS sending
├── sw.js              # PWA service worker (network-first, caches shell)
└── manifest.json      # PWA manifest
```

## Key patterns

### PHP includes order
Every PHP page starts with:
```php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
startSecureSession();
requireLogin(); // or requireAdmin()
checkSessionTimeout();
touchSession();
```

### API endpoints
All `api/*.php` files return JSON via `jsonOk($data)` / `jsonErr($msg, $code)`. CSRF is verified with `verifyCsrf()` (reads `X-CSRF-Token` header for XHR, or `$body['csrf']` for JSON body). Bridge endpoints in `api/sms.php` skip session auth and use a shared secret key from settings instead.

### Database conventions
- All timestamps stored as UTC (`nowUtc()` → `gmdate('Y-m-d H:i:s')`), displayed in `Europe/Prague` via `toLocalTime()`
- Soft delete: `deleted_at IS NULL` filter on all queries; never physical DELETE. Exception: `handleGet()` in `api/requests.php` omits this filter so admins can open deleted records in the modal
- Admin filter `status=deleted` returns `deleted_at IS NOT NULL` records; `action_type=restore` reverses soft delete — both are admin-only
- Audit trail: every state mutation is wrapped in a DB transaction and calls `logAudit()` to write to `tel_request_history`
- Settings: key-value table `tel_settings`, accessed via `getSetting()` / `setSetting()` / `getSettings()`

### CSRF
- Session pages: token in `$_SESSION['csrf_token']`, injected into `APP.csrfToken` JS global, sent as `X-CSRF-Token` header on every `apiPost()` call
- Login form uses a separate `$_SESSION['csrf_login']` token in a hidden field (not header-based)

### Request lifecycle / states
`new` → `in_progress` (assign) → `resolved` | `pending` → `resolved` → `reopened` → `in_progress`
Each transition is a POST to `api/requests.php?action=<action>` with `expected_updated_at` for optimistic concurrency (returns HTTP 409 on conflict).

### Frontend SPA (app.js)
- `APP` global object (defined inline in `dashboard.php`) carries `csrfToken`, `apiBase`, `currentUser`, `smsEnabled`, `colorThresholds`, etc.
- `apiGet(path)` and `apiPost(path, body)` — path is relative to `APP.apiBase` (`/api`)
- Cards are re-rendered on every poll via `loadRequests()` + `createRequestCard()`
- Modal detail is loaded fresh on each open via `openRequestModal(id)` → `apiGet('/requests.php?action=get&id=...')`
- User preferences persist in `localStorage` via `KEYS` constants: `AB_TEL_REFRESH`, `AB_TEL_SORT`, `AB_TEL_FILTER`, `AB_TEL_SOUND`, `AB_TEL_THEME`
- Draft technician notes persist in `sessionStorage` keyed by request ID
- Dark mode: Bootstrap 5.3 `data-bs-theme="dark"` on `<html>`, toggled by `initDarkMode()`, stored in `AB_TEL_THEME`. An inline `<script>` in `<head>` applies the theme before CSS loads (prevents flash)
- Keyboard shortcuts: `N` = nový požadavek, `/` = hledání, `?` = nápověda — implemented in `initKeyboardShortcuts()`; ignored when focus is in INPUT/TEXTAREA/SELECT

### SMS subsystem
- `api/sms.php` has two auth modes: session (enqueue + list) and API-key (bridge pull/confirm)
- Local PC bridge (`sms-bridge.ps1`) polls `?action=pending`, calls TRB140 HTTP API, then posts results to `?action=confirm`
- `sms_count` is added to request queries via a subquery on `tel_sms_queue`

## Brand and UI constraints

- Brand colours: antracit `#3d3d3d` (primary/navbar), azure `#00AEEF` (accent) — defined as CSS variables `--color-primary` and `--color-accent`
- Font: Barlow (Google Fonts CDN) — loaded via `@import` in `style.css`
- Bootstrap 5.3 CDN + Bootstrap Icons 1.11 CDN
- CSP in `.htaccess`: when adding any new external resource origin (fonts, scripts, styles), update the `Content-Security-Policy` header in `.htaccess` — Google Fonts requires `fonts.googleapis.com` in `style-src` and `fonts.gstatic.com` in `font-src`
- All user-supplied strings rendered in HTML must go through `h()` (PHP) or `esc()` (JS)

### Asset cache-busting
All CSS/JS links use `assetUrl('assets/css/style.css')` (defined in `includes/functions.php`) which appends `?v=<filemtime>`. After FTP upload the timestamp changes → browser fetches fresh file automatically. Never use bare relative paths for CSS/JS assets.

## Database tables

| Table | Purpose |
|---|---|
| `tel_users` | Accounts (`role`: user/admin, `can_reopen`, `is_active`) |
| `tel_requests` | Service requests (5 statuses, soft delete via `deleted_at`) |
| `tel_request_history` | Immutable audit log of all mutations |
| `tel_settings` | Key-value app configuration |
| `tel_password_resets` | Time-limited reset tokens (24 h) |
| `tel_rate_limits` | Login/reset brute-force protection with exponential backoff |
| `tel_vehicles` | Optional vehicle metadata keyed by normalised SPZ |
| `tel_sms_queue` | Outbound SMS queue (`pending` / `sent` / `failed`) — created by `migrate-sms.sql` |

## Typed helper functions (PHPStan level 9)

All code is clean at PHPStan level 9. Use these helpers instead of direct array/PDO access on `mixed` types:

| Function | Use for |
|---|---|
| `arrStr($arr, $key, $default)` | string from mixed array (superglobals, PDO rows) |
| `arrInt($arr, $key, $default)` | int from mixed array |
| `arrStrNull($arr, $key)` | `?string` — nullable DB fields |
| `pdoFetch($stmt)` | `array<string,mixed>\|false` instead of `$stmt->fetch()` |
| `pdoFetchAll($stmt)` | `list<array<string,mixed>>` instead of `$stmt->fetchAll()` |
| `getSettingStr($key, $default)` | typed string from `tel_settings` |
| `getSettingInt($key, $default)` | typed int from `tel_settings` |

Never use `@var`, `assert()`, type casts on `mixed`, or `@phpstan-ignore`.

## Deployment notes

- The `install.lock` file blocks re-running `install.php` — delete it only to reinstall
- `migrate-sms.sql` must be run once in phpMyAdmin to add the SMS table and default settings
- `sms-bridge.ps1` lives on the local Windows PC at the firm, not on the server
- `logs/` and `includes/` directories are blocked by `.htaccess` and `admin/.htaccess`
- `includes/config.php` is in `.gitignore` (contains DB credentials) — never commit it; maintain separately on server
