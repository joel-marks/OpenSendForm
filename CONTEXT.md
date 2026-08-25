# OpenSendForm — current state

Last updated: 2026-08-25 (feature/increment-6a-installer, Claude Code)

## Status
The public submission endpoint is live end-to-end and RELAYS BY EMAIL: a
versioned v1 API (token endpoint, CORS preflights, `POST
/v1/form/{form_key}/submit`) drives an ordered validation/abuse pipeline;
passing submissions are stored and one in-request SMTP send is attempted, with
failures retried by an operator cron. Increment 4 added optional per-form
Cloudflare Turnstile. 5a/5b/5c built the ADMIN stack: argon2id auth with
optional TOTP 2FA + recovery codes, a Pico.css design system, forms/submissions
CRUD, account management and the single-tenant admin model. Increment 6a adds
the BROWSER INSTALLER ENGINE: a merged config factory, a written config file +
lock, an environment requirements check, and a CSRF-protected wizard that picks
a database, runs migrations, creates the first admin and self-locks. Test suite
green (336 tests). CI runs it on every PR/push.

## Product definition
Free, open-source, self-hostable form-to-email service for shared cPanel/PHP
hosting. One installation serves many websites via embedded snippet + JS posting
to a central endpoint. Validated, abuse-filtered, relayed by authenticated SMTP
to the site owner.

## Decisions locked
- Name: OpenSendForm. Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer /
  SQLite default (MySQL optional via PDO). Server-rendered admin UI on
  Pico.css; no JS framework, no build step; JS is enhancement-only.
- All SQL portable across sqlite + mysql (portable types; date arithmetic in
  PHP, not SQL). Timestamps stored as UTC `Y-m-d H:i:s` TEXT.
- Form keys are PUBLIC identifiers, stored plain. A "form" is the unit of
  config (key, recipient, origins, toggles).
- Response contract (FROZEN — embed JS builds against it): JSON only.
  Success `{"ok":true}`; failure `{"ok":false,"error":{"code","message"}}`.
  HTTP: 200 ok, 400 validation, 403 key/origin, 405 method, 413 too large,
  429 rate limited.
- Bot-facing checks fail SILENTLY; an authentic but EXPIRED token returns an
  honest `400 token_expired`.
- Storage: metadata always stored. Content is always stored as the in-flight
  delivery payload; `store_content` means "retain content after successful
  delivery" (cleared on `sent` unless on; `failed`/`dead` keep it until purge).

## Mail relay (Increment 3) — condensed; see HISTORY for detail
- `Mail\MessageBuilder`: From ALWAYS the service address; Reply-To the
  submitter's `email` only when valid; no other submitter data in a header.
- `Mail\DeliveryService`: `attemptDelivery()` (success → `sent` + clearContent
  unless store_content; failure → `failed`+backoff, `dead` at MAIL_MAX_ATTEMPTS)
  and `retryDue()`. Fake-clock testable; FakeMailer scriptable. Config:
  MAIL_ENABLED, SMTP_*, MAIL_MAX_ATTEMPTS (5), MAIL_RETRY_BACKOFF_MINUTES.
  `bin/osf` mail:retry / mail:test. With no SMTP host the app runs storage-only.

## Turnstile (Increment 4) — condensed; see HISTORY for detail
- Optional PER FORM, both-or-neither (`turnstile_sitekey` + `turnstile_secret`,
  migration 006, `FormRepository::setTurnstile()`). Verifier interface + Curl
  impl + fake; `TurnstileStage` fail-open. Secret NEVER exposed by any endpoint.
  `bin/osf form:turnstile`.

## Admin auth + design system (5a/5b) — condensed; see HISTORY for detail
- Migration 007 `admins`. `src/Auth/*`: PasswordHasher (argon2id), Base32, Totp
  (RFC 6238), RecoveryCodes, AdminRepository. Session seam `SessionInterface`
  (NativeSession lazy-start, hardened; FakeSession for tests). `AuthService`
  (rate limits, idle 30m / absolute 12h, pending-TOTP 300s). `Csrf` per session.
- Vendored MIT assets (pinned in-file, no CDN): Pico.css v2.0.6, qrcode-generator
  v1.4.4. Our `admin.css` (brand palette light+dark, WCAG AA), `theme.js`
  (pre-paint), `admin.js` (copy, segmented TOTP, client QR, recovery gate).
- `AdminController`/`AdminRoutes`/`TemplateRenderer`+`h()`; `AdminView` chrome;
  `Flash`. SecurityHeadersMiddleware (strict CSP) wraps /admin; AuthMiddleware
  protects all but login/totp. Every screen works with JS off; no inline
  scripts/styles/handlers (regression-tested). Dashboard, Forms CRUD (secret
  write-only, both-or-neither), Submissions (paginated, METADATA ONLY, retry).

## Admin account mgmt + 2FA lifecycle (5c) — condensed; see HISTORY for detail
- SINGLE-TENANT: every admin co-operates on one installation, sees all
  forms/submissions; no roles. No deletion — retirement is deactivation.
- Migration 008 `admins.is_active`. `AccountController`+`account.php` (change
  name / email [needs current pw] / password [session rotated]). `AdminsController`
  +`admins.php` (roster, create, deactivate/reactivate). GUARD: last active admin
  can never be deactivated. AuthService refuses inactive admin (generic Invalid)
  and invalidates a live session on the next request. Dashboard 2FA nudge; full
  2FA disable flow (current pw AND current code); recovery UX consistent.

## Browser installer engine (Increment 6a) — this sprint
- INSTALLED-STATE MODEL: installed iff BOTH `var/config.php` and
  `var/install.lock` exist (`Install\Paths::isInstalled()`). Not installed →
  every non-install route (public API included) redirects to /install; installed
  → all /install routes 404 (routing via `InstallStateMiddleware`, plus a hard
  check at the top of every controller step). Exception: /install/done stays
  reachable, self-guarded by a one-time session flag. `var/install.lock` holds
  the install timestamp + app version; re-install = delete the lock by hand.
- CONFIG REFACTOR: `Config::fromFile(path)` and the merged `Config::load(file,
  env)` — precedence defaults < file < environment (env always wins, so the dev
  container is unchanged; pure-env when no file). `Config::generateSecret()` =
  64 hex (32 random bytes). New `DB_USER`/`DB_PASS` keys + `dbUser()/dbPass()`
  (null when empty) threaded into `Database::connect`. `var/config.php` returns
  a PHP array, written atomically (temp+rename, 0600), header notes env override.
- REQUIREMENTS (`Install\Requirements` + injectable `EnvironmentProbe`/
  SystemProbe): pass/warn/fail rows with plain-language remedy. PHP≥8.1 (fail),
  pdo_sqlite (fail only if pdo_mysql also absent, else warn), pdo_mysql (warn),
  openssl (fail), curl (warn), var/ + var/data/ writable (fail), HTTPS (warn).
- INSTALLER SERVICE (`Install\InstallerService`, `DbConnector` seam +
  PdoDbConnector): validate DB choice (sqlite default path / mysql host-port-
  dbname-user-pass with a live connection test → friendly failure), migrate,
  create first admin (via AdminRepository, ≥12 twice), then COMMIT — write config
  then lock atomically; a lock-write failure rolls back the config so no partial
  install remains. Written config sets MAIL_ENABLED=0, APP_ENV=production.
- WIZARD (`Install\InstallController`/`InstallRoutes`, `templates/install/*`,
  reusing the admin design system + strict CSP): GET /install (welcome +
  requirements; Continue disabled on any fail), GET/POST /install/database,
  GET/POST /install/admin, GET/POST /install/finish (review + atomic commit),
  GET /install/done. Step order enforced via session; jumping ahead bounces to
  the earliest incomplete step. Every POST CSRF-protected; no secret (DB or
  admin password) is ever echoed back into a field.
- `bin/osf install:status` reports installed/not-installed + config/lock presence
  + lock timestamp/version (no DB touch, no secrets). `OSF_BASE_DIR` relocates the
  writable var/ tree (Paths honours it; front controller + CLI agree on it).

## Submission pipeline order (enforced + tested)
method/body size → field hygiene → form lookup by URL key → origin allowlist
→ per-IP then per-form rate limits → honeypot → token → Turnstile (optional)
→ email (syntax + MX/A) → store → delivery (terminal, always succeeds).
Locked by SubmitPipelineOrderTest.

## What exists now
Increments 0–5c as before, plus 6a: `src/Install/{Paths,EnvironmentProbe,
SystemProbe,Requirements,DbConnector,PdoDbConnector,InstallerService,
InstallerException,InstallController,InstallRoutes,InstallStateMiddleware}.php`;
`templates/install/{layout,welcome,database,admin,finish,done}.php`; Config
refactor + DB_USER/DB_PASS; AppFactory install wiring (8th param = install
Paths, gating opt-in); public/index.php + bin/osf boot via merged factory;
`bin/osf install:status`; tests `tests/Install/*`, `tests/ConfigMergeTest.php`,
`tests/Cli/CliInstallStatusTest.php`, support `FakeProbe`/`FakeDbConnector`.
Only hard Composer dep remains phpmailer/phpmailer ^6.

## Known gaps / not built (by design this sprint)
- SMTP/DNS email-setup wizard (6b), embed snippet/JS (7), synthetic monitoring,
  zip packaging/release layout, upgrade/migration-on-update — later increments.
- Password RESET by email, roles/permissions, account deletion, audit log.
- Real MySQL live-test and NativeSession's real `$_SESSION` path stay un-unit-
  tested (network/globals); covered via fakes.
- front-end assets run through PHP reference + no-inline tests only; no DOM harness.

## Open items
- QUESTIONS.md: all prior items RESOLVED; no new blocking questions this sprint.
- Increment 6b (SMTP/DNS wizard) turns MAIL_ENABLED on from the admin panel;
  6a intentionally ships mail off with the success screen pointing there.

## Planned increment sequence (subject to revision)
0. Skeleton. 1. Schema. 2. Pipeline. 3. SMTP relay. 4. Turnstile. 5a. Admin
auth. 5b. Design system + CRUD. 5c. Account mgmt + 2FA lifecycle. — ALL DONE
6a. Browser installer engine (requirements, config, DB, first admin, self-lock).
— DONE
6b. Email-setup (SMTP/DNS) wizard. 7. Embed snippet + JS. 8. Synthetic
monitoring + alerting.
