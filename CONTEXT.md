# OpenSendForm — current state

Last updated: 2026-08-21 (Increment 0, Claude Code)

## Status
Application skeleton in place. Slim 4 app boots via a testable factory,
a single `/health` route responds, config loads with env overrides, and
a PDO storage layer plus migration runner are working. Test suite green
(8 tests). No domain features yet.

## Product definition
Free, open-source, self-hostable form-to-email service for shared
cPanel/PHP hosting. One installation serves many websites via embedded
snippet + JS posting to a central endpoint. Validated, abuse-filtered,
relayed by authenticated SMTP to the site owner.

## Decisions locked
- Name: OpenSendForm. Domains opensendform.com/.org are project/docs
  site only — no installation depends on them at runtime.
- Stack: PHP 8.1+ / Slim 4 / Composer / PHPMailer / SQLite default
  (MySQL optional via PDO). Server-rendered admin UI, no JS frameworks.
- Distribution: release zip with vendored dependencies + browser-based
  installer (WordPress pattern: upload, extract, visit /install,
  installer self-locks). Softaculous is a later ambition.
- Admin panel: required. Argon2id passwords, TOTP 2FA, CSRF tokens,
  rate-limited login, hardened sessions.
- Public endpoint defences: per-client API keys, origin allowlists,
  Cloudflare Turnstile (optional per form), honeypot, minimum
  time-to-submit, per-IP and per-key rate limits, payload caps, strict
  validation, email header injection prevention.
- Mail policy: always From: the service domain, Reply-To: the
  submitter. Never From: the submitter (DMARC).
- Submitter email verification: REJECTED (deliverability risk + mail-
  bomb attack surface). Replaced by: MX/DNS check on submitter domain,
  client-side typo suggestion, optional disposable-domain blocklist.
- No "send me a copy" feature ever (backscatter vector).
- Storage: metadata always stored; message-content storage is a
  per-form toggle with configurable retention purge.
- Dev email: Mailpit only.

## What exists now (Increment 0)
- `composer.json` — deps: slim/slim ^4, slim/psr7, php-di/php-di,
  phpunit (dev). Scripts: `composer test`, `composer serve`.
- `public/index.php` — front controller; the only place that serves HTTP.
- `src/AppFactory.php` — builds the Slim app + php-di container; callable
  from tests without serving (uses `$app->handle()`).
- `src/Routes.php` — route registration; currently only `GET /health`.
- `src/Version.php` — single source of the version string (`0.0.1`).
- `src/Config.php` — defaults + env overrides for APP_ENV, SMTP_HOST,
  SMTP_PORT, DB_DSN. Default DB: `sqlite:var/data/opensendform.sqlite`.
  Blank/false env values fall back to defaults. No secrets in code.
- `src/Storage/Database.php` — thin PDO wrapper (exceptions on, prepared
  statements only, sqlite + mysql). Creates the sqlite dir on connect.
- `src/Storage/MigrationRunner.php` — numbered `.sql` files in
  `migrations/`, applied in order, tracked in `schema_migrations`,
  idempotent, each migration wrapped in a transaction.
- `migrations/001_create_schema_migrations.sql` — bookkeeping table only.
- `phpunit.xml` + `tests/` — Health, MigrationRunner, Config coverage.
- `var/` (gitignored contents, kept via `.gitkeep`) holds runtime data.

## Known gaps / not built (by design this sprint)
- No submission endpoint, forms/keys schema, mail sending, admin UI,
  installer, or any route beyond `/health`.

## Open items
- Increment 1 (schema + form/API-key model) not yet run.

## Planned increment sequence (subject to revision)
0. Composer/Slim skeleton, PHPUnit harness, SQLite storage layer,
   dev-server script. — DONE
1. Schema + form/API-key model.
2. Submission endpoint + validation/abuse middleware stack.
3. SMTP relay (PHPMailer) with retry.
4. Turnstile integration.
5. Admin panel + auth (argon2id, TOTP, CSRF).
6. Browser installer + environment autodetection.
7. Embed snippet + JS.
8. Synthetic monitoring + alerting.
