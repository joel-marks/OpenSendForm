# OpenSendForm — sprint history (append-only)

## 2026-08-20 — Sprint 0: bootstrap
- Branch: main (direct; pre-code, human-pushed)
- Repo initialised, devcontainer (PHP 8.1 + Node 20 + Claude Code +
  Mailpit sidecar) added, standing orders (CLAUDE.md) and state files
  seeded. No application code. No tests.

## 2026-08-21 — Devcontainer hotfix (PR #1)
- Branch: fix/devcontainer-yarn-key → merged to main via PR #1.
- Removed a stale Yarn apt repository/signing key from the base image
  Dockerfile that broke `apt-get update` during container build.
  Recorded here for completeness; work predates Increment 0.

## 2026-08-21 — Increment 0: application skeleton
- Branch: feature/increment-0-skeleton (off latest main).
- Housekeeping: tracked `.devcontainer/devcontainer-lock.json` and the
  updated `.claude/settings.json` (adds `git checkout` permission).
- Composer project `opensendform/opensendform` (MIT, PHP >=8.1). Deps:
  slim/slim ^4, slim/psr7, php-di/php-di, phpunit (dev) — exactly the
  authorised set. Scripts: `test` (phpunit), `serve` (php built-in
  server on :8080 from public/).
- Front controller `public/index.php` → `src/AppFactory.php`, which
  builds the Slim app + php-di container and is drivable from tests via
  `$app->handle()` (no HTTP serving in the factory).
- `src/Config.php`: defaults + env overrides (APP_ENV, SMTP_HOST,
  SMTP_PORT, DB_DSN); default DB `sqlite:var/data/opensendform.sqlite`;
  blank/false env values fall back to defaults; no secrets in code.
- Storage: `src/Storage/Database.php` (thin PDO wrapper, exceptions on,
  prepared statements only, sqlite + mysql) and
  `src/Storage/MigrationRunner.php` (numbered .sql, ordered, tracked in
  `schema_migrations`, idempotent, per-migration transaction).
  `migrations/001_create_schema_migrations.sql` creates the tracking
  table only — no domain tables.
- One route: `GET /health` → 200 `{"status":"ok","version":"0.0.1"}`;
  version sourced from `src/Version.php` (single constant).
- `var/` contents gitignored, directory kept via `.gitkeep`; vendor/
  remains ignored.
- Tests (PHPUnit, phpunit.xml at root): `/health` via the factory;
  migration runner clean apply + idempotent second run on in-memory
  sqlite; Config env-override behaviour.
- README: added a Development "Getting started" section (reopen in
  container, composer install/test/serve, Mailpit at localhost:8025).
- Test results: **OK — 8 tests, 22 assertions, all green.** Smoke-tested
  `/health` via `php -S` (200 + expected JSON; unknown route → 404).
- Deviations from prompt: none. No new/unauthorised Composer deps.
  QUESTIONS.md unchanged (no blockers).

## 2026-08-23 — Increment 1: schema + form/API-key model
- Branch: feature/increment-1-schema (off latest main).
- Housekeeping: composer `test` script now runs `XDEBUG_MODE=off
  phpunit`, silencing the Xdebug step-debug "could not connect" warnings
  during the phpunit run. (A single warning still emits from composer's
  own process before the script runs; the phpunit run itself is clean.)
- Migration 002 (`forms`): id PK, form_key TEXT NOT NULL UNIQUE, name,
  recipient_email, allowed_origins (JSON array TEXT), store_content
  INTEGER DEFAULT 0, retention_days INTEGER DEFAULT 30, is_active
  INTEGER DEFAULT 1, created_at, updated_at. Portable types only.
- Migration 003 (`submissions`): id PK, form_id, created_at, remote_ip,
  origin (nullable), user_agent (nullable), status DEFAULT 'received',
  content (nullable). Index idx_submissions_form_created on
  (form_id, created_at).
- `src/Form/FormKey.php`: `generate()` → `osf_` + 32 lowercase hex from
  `random_bytes(16)`. Keys are public identifiers, stored plain.
- `src/Form/FormRepository.php`: createForm (key gen + filter_var email
  validation + origin normalisation/validation), findByKey (active
  only), findById, listForms, setActive, normaliseOrigins. Origins
  reduced to scheme+host+optional port (lower-cased, trailing slash
  stripped, path/query/fragment/credentials rejected, deduped);
  http/https only. Prepared statements throughout.
- `src/Submission/SubmissionRepository.php`: recordSubmission (content
  stored only when form.store_content = 1), purgeExpired (per-form
  cutoff computed in PHP, portable DELETE), findById.
- `bin/osf`: executable CLI (plain argv, no libs) — form:create,
  form:list, form:enable ID, form:disable ID; prints generated key on
  create; usage text on no/unknown command. Applies pending migrations
  before dispatch.
- Tests added: forms/submissions migrations apply cleanly + idempotently
  and create the tables/index; FormKey format + 1000-generation
  uniqueness; FormRepository create/find/list/setActive, origin
  normalisation, and rejection of bad emails and bad origins (path,
  no scheme, non-http scheme, query, empty list, empty name), findByKey
  excluding inactive forms; SubmissionRepository content toggle both
  ways and purgeExpired honouring differing retention_days across two
  forms. Existing MigrationRunnerTest updated to expect versions
  [1, 2, 3].
- Test results: **OK — 30 tests, 1074 assertions, all green.** CLI
  smoke-tested (create/list/enable/disable, bad email, bad id, usage).
- Deviations from prompt: none. No new Composer deps. Existing routes
  untouched, no HTTP endpoints added. QUESTIONS.md unchanged (no
  blockers).